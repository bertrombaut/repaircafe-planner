<?php
/**
 * Plugin Name: RepairCafe Planner
 * Description: Repair Café agenda + vrijwilligers (login) + aanmelden/afmelden (24u regel) + max vrijwilligers per event + instellingen.
 * Version: 2.3.2
 */

if (!defined('ABSPATH')) exit;

require_once plugin_dir_path(__FILE__) . 'includes/admin.php';
require_once plugin_dir_path(__FILE__) . 'includes/database.php';
require_once plugin_dir_path(__FILE__) . 'includes/repairs.php';

/**
 * Hoofdklasse van de plugin.
 *
 * Doet:
 * - registreert hooks, shortcodes en adminpagina's
 * - beheert evenementen
 * - verwerkt aan- en afmeldingen van vrijwilligers
 * - toont lijsten, knoppen en overzichten
 *
 * Waarom zo gebouwd:
 * - alle hoofdlogica blijft centraal in 1 klasse
 * - losse bestanden in /includes vullen dit aan
 */
class RepairCafePlanner {

    /**
     * Naam van de aanmeldtabel zonder WordPress-prefix.
     */
    const TABLE = 'rc_signups';

    /**
     * WordPress-rol voor vrijwilligers.
     */
    const ROLE  = 'rc_volunteer';

    /**
     * Optie-naam voor contactpersoon bij te laat afmelden.
     */
    const OPTION_CONTACT = 'rc_late_unsubscribe_contact';

    /**
     * Startpunt van de plugin.
     *
     * Doet:
     * - koppelt alle WordPress hooks, filters en shortcodes
     *
     * In:
     * - niets
     *
     * Uit:
     * - niets
     *
     * Waarom zo gebouwd:
     * - bij het maken van de klasse wordt direct alles geregistreerd
     */
    public function __construct() {
        register_activation_hook(__FILE__, [$this, 'activate']);
        register_deactivation_hook(__FILE__, [$this, 'deactivate']);

        add_action('init', [$this, 'register_post_type']);
        add_action('add_meta_boxes', [$this, 'add_metaboxes']);
        add_action('save_post', [$this, 'save_event_meta']);
        add_action('init', [$this, 'handle_actions']);

        add_action('admin_menu', [$this, 'admin_menu']);
        add_action('admin_init', [$this, 'register_settings']);
        add_shortcode('repaircafe_events', [$this, 'shortcode_events']);
        add_shortcode('rc_my_signups', [$this, 'shortcode_my_signups']);
        add_shortcode('rc_login_form', [$this, 'shortcode_login_form']);
        add_shortcode('rc_lost_password_form', [$this, 'shortcode_lost_password_form']);
        add_shortcode('repaircafe_calendar', function() {
            return repaircafe_render_calendar();
        });

        add_action('wp_enqueue_scripts', [$this, 'enqueue_styles']);
        add_filter('the_content', [$this, 'add_back_button_to_event']);
        add_filter('wp_nav_menu_objects', [$this, 'filter_menu_items'], 10, 2);
        add_action('admin_init', [$this, 'block_volunteer_backend']);
        add_filter('show_admin_bar', [$this, 'hide_admin_bar_for_volunteers']);
        add_action('admin_init', [$this, 'handle_admin_signup_actions']);
        add_action('show_user_profile', [$this, 'render_attendance_start_field']);
        add_action('edit_user_profile', [$this, 'render_attendance_start_field']);
        add_action('personal_options_update', [$this, 'save_attendance_start_field']);
        add_action('edit_user_profile_update', [$this, 'save_attendance_start_field']);
    }

    /**
     * Geeft de volledige databasetabelnaam terug.
     *
     * Doet:
     * - plakt de WordPress-prefix voor de tabelnaam
     *
     * In:
     * - niets
     *
     * Uit:
     * - string met volledige tabelnaam
     *
     * Waarom zo gebouwd:
     * - voorkomt harde tabelnamen en werkt met elke WP-prefix
     */
    private function table_name() {
        global $wpdb;
        return $wpdb->prefix . self::TABLE;
    }

    /**
     * Draait bij activatie van de plugin.
     *
     * Doet:
     * - maakt de hoofd-aanmeldtabel aan
     * - roept extra tabellen aan uit includes/database.php als die functie bestaat
     * - maakt vrijwilligersrol aan
     * - zet standaard contactpersoon
     * - registreert post type en ververst rewrite regels
     *
     * In:
     * - niets
     *
     * Uit:
     * - niets
     */
    public function activate() {
        $this->create_table();

        if (function_exists('repaircafe_create_tables')) {
            repaircafe_create_tables();
        }

        add_role(self::ROLE, 'Vrijwilliger', ['read' => true]);

        if (get_option(self::OPTION_CONTACT) === false) {
            add_option(self::OPTION_CONTACT, 'Bert Rombaut');
        }

        $this->register_post_type();
        flush_rewrite_rules();
    }

    /**
     * Draait bij deactivatie van de plugin.
     *
     * Doet:
     * - ververst rewrite regels
     *
     * In:
     * - niets
     *
     * Uit:
     * - niets
     */
    public function deactivate() {
        flush_rewrite_rules();
    }

    /**
     * Maakt de hoofd-aanmeldtabel aan of werkt die bij.
     *
     * Doet:
     * - maakt tabel voor event-aanmeldingen
     * - bewaart ook expertise_id per aanmelding
     *
     * In:
     * - niets
     *
     * Uit:
     * - niets
     *
     * Waarom zo gebouwd:
     * - dbDelta kan veilig tabellen aanmaken of aanpassen
     * - UNIQUE KEY voorkomt dubbele aanmelding voor hetzelfde event en dezelfde gebruiker
     */
    private function create_table() {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $table = $this->table_name();
        $charset = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE $table (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            event_id BIGINT(20) UNSIGNED NOT NULL,
            user_id BIGINT(20) UNSIGNED NOT NULL,
            expertise_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY event_user (event_id, user_id),
            KEY event_id (event_id),
            KEY user_id (user_id),
            KEY expertise_id (expertise_id)
        ) $charset;";

        dbDelta($sql);
    }

    /**
     * Registreert het custom post type voor Repair Café evenementen.
     *
     * Doet:
     * - maakt post type rc_event aan
     *
     * In:
     * - niets
     *
     * Uit:
     * - niets
     */
    public function register_post_type() {
        $labels = [
            'name'                  => 'Repair Cafés',
            'singular_name'         => 'Evenement',
            'menu_name'             => 'Repair Cafés',
            'name_admin_bar'        => 'Evenement',
            'add_new'               => 'Evenement toevoegen',
            'add_new_item'          => 'Nieuw evenement toevoegen',
            'edit_item'             => 'Evenement bewerken',
            'new_item'              => 'Nieuw evenement',
            'view_item'             => 'Evenement bekijken',
            'view_items'            => 'Evenementen bekijken',
            'search_items'          => 'Evenementen zoeken',
            'not_found'             => 'Geen evenementen gevonden',
            'not_found_in_trash'    => 'Geen evenementen gevonden in de prullenbak',
            'all_items'             => 'Evenementen',
            'archives'              => 'Evenement archief',
            'attributes'            => 'Evenement eigenschappen',
            'insert_into_item'      => 'In evenement invoegen',
            'uploaded_to_this_item' => 'Geüpload naar dit evenement',
            'featured_image'        => 'Uitgelichte afbeelding',
            'set_featured_image'    => 'Uitgelichte afbeelding instellen',
            'remove_featured_image' => 'Uitgelichte afbeelding verwijderen',
            'use_featured_image'    => 'Als uitgelichte afbeelding gebruiken',
            'filter_items_list'     => 'Evenementenlijst filteren',
            'items_list_navigation' => 'Evenementenlijst navigatie',
            'items_list'            => 'Evenementenlijst',
            'item_published'        => 'Evenement gepubliceerd',
            'item_updated'          => 'Evenement bijgewerkt',
        ];

        register_post_type('rc_event', [
            'labels' => $labels,
            'public' => true,
            'menu_icon' => 'dashicons-hammer',
            'supports' => ['title', 'editor'],
            'show_in_rest' => false,
        ]);
    }

    /* -------------------- Settings -------------------- */

    /**
     * Registreert plugin-instellingen in WordPress.
     *
     * Doet:
     * - registreert de naam van de contactpersoon voor laat afmelden
     *
     * In:
     * - niets
     *
     * Uit:
     * - niets
     */
    public function register_settings() {
        register_setting('rc_settings_group', self::OPTION_CONTACT, [
            'type' => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'default' => 'Bert Rombaut',
        ]);
    }

    /**
     * Haalt de ingestelde contactpersoon op.
     *
     * Doet:
     * - leest de naam uit de opties
     * - valt terug op standaardnaam als leeg
     *
     * In:
     * - niets
     *
     * Uit:
     * - string met naam
     */
    private function get_contact_name(): string {
        $v = (string) get_option(self::OPTION_CONTACT, 'Bert Rombaut');
        $v = trim($v);
        return $v !== '' ? $v : 'Bert Rombaut';
    }

    /* -------------------- Metaboxes -------------------- */

    /**
     * Voegt alle metaboxes toe aan het event-scherm.
     *
     * Doet:
     * - datum/tijd
     * - locatie
     * - algemeen maximum
     * - expertises per evenement
     *
     * In:
     * - niets
     *
     * Uit:
     * - niets
     */
    public function add_metaboxes() {
        add_meta_box('rc_event_datetime', 'Event datum & tijd', [$this, 'render_datetime_metabox'], 'rc_event', 'side');
        add_meta_box('rc_event_location', 'Locatie', [$this, 'render_location_metabox'], 'rc_event', 'side');
        add_meta_box('rc_event_limits', 'Vrijwilligers', [$this, 'render_limits_metabox'], 'rc_event', 'side');
        add_meta_box('rc_event_expertises', 'Expertises per evenement', [$this, 'render_event_expertises_metabox'], 'rc_event', 'normal', 'default');
    }

    /**
     * Toont de metabox voor datum en tijd.
     *
     * In:
     * - $post: huidig event-object
     *
     * Uit:
     * - HTML
     */
    public function render_datetime_metabox($post) {
        $date = get_post_meta($post->ID, '_rc_event_date', true);
        $time = get_post_meta($post->ID, '_rc_event_time', true);
        wp_nonce_field('rc_save_event_meta', 'rc_event_nonce');

        echo '<p><label><strong>Datum</strong></label><br>';
        echo '<input type="date" name="rc_event_date" value="' . esc_attr($date) . '" style="width:100%;"></p>';

        echo '<p><label><strong>Tijd</strong></label><br>';
        echo '<input type="time" name="rc_event_time" value="' . esc_attr($time) . '" style="width:100%;"></p>';
    }

    /**
     * Toont de metabox voor locatiegegevens.
     *
     * In:
     * - $post: huidig event-object
     *
     * Uit:
     * - HTML
     */
    public function render_location_metabox($post) {
        $name    = get_post_meta($post->ID, '_rc_location_name', true);
        $address = get_post_meta($post->ID, '_rc_location_address', true);
        $city    = get_post_meta($post->ID, '_rc_location_city', true);

        echo '<p><label><strong>Naam</strong></label><br>';
        echo '<input type="text" name="rc_location_name" value="' . esc_attr($name) . '" style="width:100%;" placeholder="Bijv. Dorpshuis X"></p>';

        echo '<p><label><strong>Adres</strong></label><br>';
        echo '<input type="text" name="rc_location_address" value="' . esc_attr($address) . '" style="width:100%;" placeholder="Straat + nr"></p>';

        echo '<p><label><strong>Plaats</strong></label><br>';
        echo '<input type="text" name="rc_location_city" value="' . esc_attr($city) . '" style="width:100%;" placeholder="Renkum"></p>';
    }

    /**
     * Toont de metabox voor algemeen maximum aantal vrijwilligers.
     *
     * In:
     * - $post: huidig event-object
     *
     * Uit:
     * - HTML
     *
     * Waarom zo gebouwd:
     * - leeg laten betekent geen algemene limiet
     */
    public function render_limits_metabox($post) {
        $max = get_post_meta($post->ID, '_rc_max_volunteers', true);
        $max = ($max === '') ? '' : (int) $max;

        echo '<p><label><strong>Max vrijwilligers</strong></label><br>';
        echo '<input type="number" min="0" step="1" name="rc_max_volunteers" value="' . esc_attr($max) . '" style="width:100%;" placeholder="Bijv. 12"></p>';
        echo '<p style="margin:8px 0 0;color:#666;font-size:12px;">Leeg = geen limiet.</p>';
    }

    /**
     * Toont de metabox voor expertises die nodig zijn per evenement.
     *
     * Doet:
     * - haalt alle beschikbare expertises op
     * - toont per expertise een checkbox en maximum aantal
     * - vult bestaande waarden van dit event alvast in
     *
     * In:
     * - $post: huidig event-object
     *
     * Uit:
     * - HTML
     */
    public function render_event_expertises_metabox($post) {
        global $wpdb;

        $expertises_table     = $wpdb->prefix . 'rcp_expertises';
        $event_expertises_tbl = $wpdb->prefix . 'rcp_event_expertises';

        $expertises = $wpdb->get_results("SELECT id, name FROM {$expertises_table} ORDER BY name ASC");

        if (empty($expertises)) {
            echo '<p>Er zijn nog geen expertises aangemaakt. Voeg die eerst toe via Repair Cafés → Expertises.</p>';
            return;
        }

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT expertise_id, max_volunteers
                 FROM {$event_expertises_tbl}
                 WHERE event_id = %d",
                $post->ID
            )
        );

        $selected = [];
        foreach ($rows as $row) {
            $selected[(int) $row->expertise_id] = (int) $row->max_volunteers;
        }

        echo '<p>Kies welke expertises nodig zijn voor dit evenement en geef per expertise het maximum aantal vrijwilligers op.</p>';
        echo '<table class="widefat striped" style="max-width:700px;">';
        echo '<thead><tr><th>Actief</th><th>Expertise</th><th>Max vrijwilligers</th></tr></thead>';
        echo '<tbody>';

        foreach ($expertises as $expertise) {
            $expertise_id = (int) $expertise->id;
            $is_checked   = array_key_exists($expertise_id, $selected);
            $max_value    = $is_checked ? (int) $selected[$expertise_id] : 1;

            echo '<tr>';
            echo '<td style="width:90px;">';
            echo '<input type="checkbox" name="rc_event_expertises[' . esc_attr($expertise_id) . '][enabled]" value="1" ' . checked($is_checked, true, false) . '>';
            echo '</td>';
            echo '<td>' . esc_html($expertise->name) . '</td>';
            echo '<td style="width:180px;">';
            echo '<input type="number" min="1" step="1" name="rc_event_expertises[' . esc_attr($expertise_id) . '][max]" value="' . esc_attr($max_value) . '" style="width:100px;">';
            echo '</td>';
            echo '</tr>';
        }

        echo '</tbody>';
        echo '</table>';
    }

    /**
     * Slaat alle event-meta en expertise-instellingen op.
     *
     * Doet:
     * - controleert nonce, rechten en autosave
     * - slaat datum, tijd en locatie op
     * - slaat algemeen maximum op
     * - wist eerst bestaande event-expertises
     * - zet daarna de nieuw ingestuurde expertises opnieuw weg
     *
     * In:
     * - $post_id: ID van het event
     *
     * Uit:
     * - niets
     *
     * Waarom zo gebouwd:
     * - eerst verwijderen en daarna opnieuw opslaan houdt de koppelingen simpel en schoon
     */
    public function save_event_meta($post_id) {
        global $wpdb;

        if (get_post_type($post_id) !== 'rc_event') return;
        if (!isset($_POST['rc_event_nonce']) || !wp_verify_nonce($_POST['rc_event_nonce'], 'rc_save_event_meta')) return;
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
        if (!current_user_can('edit_post', $post_id)) return;

        update_post_meta($post_id, '_rc_event_date', sanitize_text_field($_POST['rc_event_date'] ?? ''));
        update_post_meta($post_id, '_rc_event_time', sanitize_text_field($_POST['rc_event_time'] ?? ''));
        update_post_meta($post_id, '_rc_location_name', sanitize_text_field($_POST['rc_location_name'] ?? ''));
        update_post_meta($post_id, '_rc_location_address', sanitize_text_field($_POST['rc_location_address'] ?? ''));
        update_post_meta($post_id, '_rc_location_city', sanitize_text_field($_POST['rc_location_city'] ?? ''));

        $raw = isset($_POST['rc_max_volunteers']) ? trim((string) $_POST['rc_max_volunteers']) : '';
        if ($raw === '') {
            delete_post_meta($post_id, '_rc_max_volunteers');
        } else {
            $max = max(0, (int) $raw);
            update_post_meta($post_id, '_rc_max_volunteers', $max);
        }

        $event_expertises_tbl = $wpdb->prefix . 'rcp_event_expertises';
        $wpdb->delete(
            $event_expertises_tbl,
            ['event_id' => (int) $post_id],
            ['%d']
        );

        $submitted_expertises = isset($_POST['rc_event_expertises']) && is_array($_POST['rc_event_expertises'])
            ? $_POST['rc_event_expertises']
            : [];

        foreach ($submitted_expertises as $expertise_id => $data) {
            $expertise_id = (int) $expertise_id;
            $enabled      = isset($data['enabled']) ? 1 : 0;
            $max          = isset($data['max']) ? max(1, (int) $data['max']) : 1;

            if (!$enabled || $expertise_id <= 0) {
                continue;
            }

            $wpdb->insert(
                $event_expertises_tbl,
                [
                    'event_id'       => (int) $post_id,
                    'expertise_id'   => $expertise_id,
                    'max_volunteers' => $max,
                ],
                ['%d', '%d', '%d']
            );
        }
    }

    /* -------------------- Helpers -------------------- */

    /**
     * Berekent de starttijd van een event als timestamp.
     *
     * In:
     * - $event_id: ID van het event
     *
     * Uit:
     * - int timestamp
     * - 0 als datum ontbreekt of ongeldig is
     */
    private function event_start_ts($event_id) {
        $date = get_post_meta($event_id, '_rc_event_date', true);
        $time = get_post_meta($event_id, '_rc_event_time', true);

        if (!$date) return 0;
        if (!$time) $time = '00:00';

        $ts = strtotime($date . ' ' . $time);
        return $ts ? (int) $ts : 0;
    }

    /**
     * Controleert of afmelden nog mag.
     *
     * Regel:
     * - afmelden mag alleen tot 24 uur voor de start
     *
     * In:
     * - $event_id: ID van het event
     *
     * Uit:
     * - true als afmelden mag
     * - false als dat niet meer mag
     */
    private function can_unsubscribe($event_id) {
        $start = $this->event_start_ts($event_id);
        if (!$start) return false;

        $now = (int) current_time('timestamp');
        return $now <= ($start - 86400);
    }

    /**
     * Bouwt de locatie-opmaak voor een event.
     *
     * In:
     * - $event_id: ID van het event
     *
     * Uit:
     * - string met HTML-regelafbrekingen
     */
    private function format_location($event_id) {
        $name    = trim((string) get_post_meta($event_id, '_rc_location_name', true));
        $address = trim((string) get_post_meta($event_id, '_rc_location_address', true));
        $city    = trim((string) get_post_meta($event_id, '_rc_location_city', true));

        $parts = array_filter([$name, $address, $city]);
        return implode('<br>', array_map('esc_html', $parts));
    }

    /**
     * Haalt het algemene maximum aantal vrijwilligers op.
     *
     * In:
     * - $event_id: ID van het event
     *
     * Uit:
     * - int maximum
     * - null als geen limiet is ingesteld
     */
    private function get_max_volunteers($event_id) {
        $v = get_post_meta($event_id, '_rc_max_volunteers', true);
        if ($v === '' || $v === null) return null;
        $n = (int) $v;
        if ($n <= 0) return null;
        return $n;
    }

    /**
     * Controleert of een event vol zit op algemeen niveau.
     *
     * Uit:
     * - momenteel altijd false
     *
     * Waarom zo gebouwd:
     * - de echte blokkering loopt nu via expertise-capaciteit
     * - deze functie is blijven bestaan als plek voor algemene limietlogica
     */
    private function is_full($event_id) {
        return false;
    }

    /**
     * Haalt per expertise de status van een event op.
     *
     * Doet:
     * - leest welke expertises op dit event actief zijn
     * - telt hoeveel vrijwilligers per expertise zijn aangemeld
     * - berekent vrije plekken en vol/niet vol
     *
     * In:
     * - $event_id: ID van het event
     *
     * Uit:
     * - array met objecten:
     *   expertise_id, name, count, max_volunteers, free, is_full
     *
     * Waarom zo gebouwd:
     * - de telling loopt via user_expertises en signups zodat per expertise capaciteit bewaakt kan worden
     */
    private function get_event_expertise_statuses($event_id) {
        global $wpdb;

        $table = $this->table_name();

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT ee.expertise_id, e.name, ee.max_volunteers,
                    COUNT(DISTINCT s.user_id) AS count
             FROM {$wpdb->prefix}rcp_event_expertises ee
             LEFT JOIN {$wpdb->prefix}rcp_expertises e
                ON ee.expertise_id = e.id
             LEFT JOIN {$wpdb->prefix}rcp_user_expertises ue
                ON ue.expertise_id = ee.expertise_id
             LEFT JOIN {$table} s
                ON s.user_id = ue.user_id AND s.event_id = ee.event_id
             WHERE ee.event_id = %d
             GROUP BY ee.expertise_id, e.name, ee.max_volunteers
             ORDER BY e.name ASC",
            $event_id
        ));

        if (!$rows) {
            return [];
        }

        $result = [];

        foreach ($rows as $row) {
            $count = (int) $row->count;
            $max   = (int) $row->max_volunteers;
            $free  = max(0, $max - $count);

            $result[] = (object) [
                'expertise_id'   => (int) $row->expertise_id,
                'name'           => (string) $row->name,
                'count'          => $count,
                'max_volunteers' => $max,
                'free'           => $free,
                'is_full'        => ($free <= 0),
            ];
        }

        return $result;
    }

    /**
     * Haalt de eerste gekoppelde expertise van een gebruiker op.
     *
     * In:
     * - $user_id: ID van de gebruiker
     *
     * Uit:
     * - int expertise_id
     * - 0 als niets gevonden is
     *
     * Waarom zo gebouwd:
     * - deze plugin gebruikt hier één primaire expertise voor aanmelden
     */
    private function get_primary_user_expertise_id($user_id) {
        global $wpdb;

        $expertise_id = $wpdb->get_var($wpdb->prepare(
            "SELECT expertise_id
             FROM {$wpdb->prefix}rcp_user_expertises
             WHERE user_id = %d
             LIMIT 1",
            $user_id
        ));

        return $expertise_id ? (int) $expertise_id : 0;
    }

    /**
     * Geeft de reden terug waarom aanmelden geblokkeerd is.
     *
     * Doet:
     * - controleert of event-expertises bestaan
     * - controleert of gebruiker een expertise heeft
     * - controleert of die expertise op het event voorkomt
     * - controleert of er nog plek is binnen die expertise
     *
     * In:
     * - $event_id: ID van het event
     * - $user_id: ID van de gebruiker
     *
     * Uit:
     * - lege string als aanmelden mag
     * - tekst met blokkeringsreden als aanmelden niet mag
     */
    private function get_signup_block_reason($event_id, $user_id) {
        $event_expertises = $this->get_event_expertise_statuses($event_id);

        if (empty($event_expertises)) {
            return 'Voor dit evenement zijn nog geen expertises ingesteld.';
        }

        $user_expertise_id = $this->get_primary_user_expertise_id($user_id);

        if ($user_expertise_id <= 0) {
            return 'Je hebt nog geen expertise gekoppeld aan je account.';
        }

        foreach ($event_expertises as $row) {
            if ((int) $row->expertise_id === $user_expertise_id) {
                if ($row->is_full) {
                    return 'Helaas, voor jouw expertise is er vandaag geen plek meer. We zien je graag de volgende keer.';
                }

                return '';
            }
        }

        return 'Jouw expertise past niet bij dit evenement.';
    }

    /**
     * Bouwt het HTML-blok met expertise-statussen.
     *
     * In:
     * - $event_id: ID van het event
     *
     * Uit:
     * - HTML-string
     *
     * Waarom zo gebouwd:
     * - 1 centrale renderer voorkomt dubbele opmaakcode op meerdere plekken
     */
    private function render_expertise_statuses($event_id) {
        $rows = $this->get_event_expertise_statuses($event_id);

        if (empty($rows)) {
            return '';
        }

        $out = "<div class='rc-expertises'>";
        $out .= "<strong>Benodigde Vrijwilligers</strong>";
        $out .= "<ul class='rc-expertise-list'>";

        foreach ($rows as $row) {
            if ($row->is_full) {
                $status_text = 'Vol';
            } else {
                $count = (int) $row->count;
                $free  = (int) $row->free;
                $name  = $row->name;

                $plek_word = ($free === 1) ? 'plek' : 'plekken';

                if ($count === 1) {
                    $status_text = '1 ' . $name . ' heeft zich aangemeld, nog ' . $free . ' ' . $plek_word . ' over';
                } else {
                    $status_text = $count . ' ' . $name . ' hebben zich aangemeld, nog ' . $free . ' ' . $plek_word . ' over';
                }
            }

            $out .= "<li class='rc-expertise-item'>";

            // Groen vinkje bij vrije plek, rood kruis als die expertise vol is.
            $dot = $row->is_full
                ? "<span style='margin-right:10px;vertical-align:middle;display:inline-flex;align-items:center;'><svg width='28' height='28' viewBox='0 0 24 24' aria-hidden='true' xmlns='http://www.w3.org/2000/svg'><path fill='#e60000' d='M18.3 5.71a1 1 0 0 1 0 1.41L13.41 12l4.89 4.88a1 1 0 1 1-1.41 1.42L12 13.41l-4.88 4.89a1 1 0 0 1-1.42-1.41L10.59 12 5.7 7.12A1 1 0 0 1 7.12 5.7L12 10.59l4.89-4.88a1 1 0 0 1 1.41 0Z'/></svg></span>"
                : "<span style='margin-right:10px;vertical-align:middle;display:inline-flex;align-items:center;'><svg width='28' height='28' viewBox='0 0 24 24' aria-hidden='true' xmlns='http://www.w3.org/2000/svg'><path fill='#00a000' d='M9.55 18.3 4.7 13.46a1 1 0 1 1 1.41-1.42l3.44 3.44 8.34-8.34a1 1 0 1 1 1.41 1.41L10.96 18.3a1 1 0 0 1-1.41 0Z'/></svg></span>";

            $out .= "<span class='rc-expertise-name'>" . $dot . esc_html($row->name) . "</span>";
            $out .= "<span class='rc-expertise-meta'>" . esc_html($status_text) . "</span>";
            $out .= "</li>";
        }

        $out .= "</ul>";
        $out .= "</div>";

        return $out;
    }

    /**
     * Controleert of een gebruiker al is aangemeld voor een event.
     *
     * In:
     * - $event_id: ID van het event
     * - $user_id: ID van de gebruiker
     *
     * Uit:
     * - true of false
     */
    private function is_signed_up($event_id, $user_id) {
        global $wpdb;
        $table = $this->table_name();

        $sql = $wpdb->prepare(
            "SELECT id FROM $table WHERE event_id = %d AND user_id = %d LIMIT 1",
            $event_id,
            $user_id
        );

        return (bool) $wpdb->get_var($sql);
    }

    /**
     * Telt het totaal aantal aanmeldingen voor een event.
     *
     * In:
     * - $event_id: ID van het event
     *
     * Uit:
     * - int aantal aanmeldingen
     */
    private function signup_count($event_id) {
        global $wpdb;
        $table = $this->table_name();

        $sql = $wpdb->prepare(
            "SELECT COUNT(*) FROM $table WHERE event_id = %d",
            $event_id
        );

        return (int) $wpdb->get_var($sql);
    }

    /**
     * Voert een aanmelding uit.
     *
     * Doet:
     * - voorkomt dubbele aanmelding
     * - controleert algemene en expertise-blokkades
     * - slaat expertise_id mee op in signup-tabel
     *
     * In:
     * - $event_id: ID van het event
     * - $user_id: ID van de gebruiker
     *
     * Uit:
     * - true bij succes
     * - false bij mislukken
     */
    private function do_signup($event_id, $user_id) {
        global $wpdb;
        $table = $this->table_name();

        if ($this->is_signed_up($event_id, $user_id)) {
            return true;
        }

        if ($this->is_full($event_id)) {
            return false;
        }

        $expertise_id = $this->get_primary_user_expertise_id($user_id);
        if ($expertise_id <= 0) {
            return false;
        }

        $block_reason = $this->get_signup_block_reason($event_id, $user_id);
        if ($block_reason !== '') {
            return false;
        }

        $res = $wpdb->insert(
            $table,
            [
                'event_id'     => (int) $event_id,
                'user_id'      => (int) $user_id,
                'expertise_id' => (int) $expertise_id,
                'created_at'   => current_time('mysql'),
            ],
            ['%d', '%d', '%d', '%s']
        );

        return (bool) $res;
    }

    /**
     * Voert een afmelding uit.
     *
     * Doet:
     * - stopt als de 24-uursregel is overschreden
     * - verwijdert daarna de signup
     *
     * In:
     * - $event_id: ID van het event
     * - $user_id: ID van de gebruiker
     *
     * Uit:
     * - true bij succes
     * - false bij mislukken
     */
    private function do_unsubscribe($event_id, $user_id) {
        global $wpdb;
        $table = $this->table_name();

        if (!$this->can_unsubscribe($event_id)) {
            return false;
        }

        $res = $wpdb->delete(
            $table,
            [
                'event_id' => (int) $event_id,
                'user_id'  => (int) $user_id,
            ],
            ['%d', '%d']
        );

        return $res !== false;
    }

    /**
     * Stuurt gebruiker terug naar vorige pagina met melding.
     *
     * In:
     * - $msg: tekstmelding
     *
     * Uit:
     * - redirect
     *
     * Waarom zo gebouwd:
     * - dezelfde terugstuur-logica wordt op meerdere plekken gebruikt
     */
    private function redirect_back($msg) {
        $ref = wp_get_referer();
        if (!$ref) {
            $ref = home_url('/');
        }

        $url = add_query_arg(['rc_msg' => rawurlencode($msg)], $ref);
        wp_safe_redirect($url);
        exit;
    }

    /**
     * Bouwt de HTML-opmaak voor e-mails.
     *
     * In:
     * - $title: kop van de mail
     * - $intro: inleidende tekst
     * - $rows: label => waarde regels
     * - $footer: afsluitende tekst
     *
     * Uit:
     * - HTML-string
     *
     * Waarom zo gebouwd:
     * - alle e-mails krijgen hiermee dezelfde nette opmaak
     */
    private function get_email_template($title, $intro, $rows = [], $footer = '') {
        $rows_html = '';

        foreach ($rows as $label => $value) {
            if ($value === '' || $value === null) continue;

            $rows_html .= '
                <tr>
                    <td style="padding:8px 0;font-weight:600;width:140px;vertical-align:top;">' . esc_html($label) . '</td>
                    <td style="padding:8px 0;vertical-align:top;">' . nl2br(esc_html($value)) . '</td>
                </tr>';
        }

        $footer_html = $footer !== ''
            ? '<p style="margin:24px 0 0 0;color:#555;line-height:1.6;">' . nl2br(esc_html($footer)) . '</p>'
            : '';

        return '
            <div style="margin:0;padding:32px 16px;background:#f3f4f6;font-family:Arial,Helvetica,sans-serif;color:#1f2937;">
            <div style="max-width:680px;margin:0 auto;background:#ffffff;border:1px solid #e5e7eb;border-radius:18px;overflow:hidden;box-shadow:0 4px 14px rgba(0,0,0,0.06);">
                <div style="background:#f46e16;padding:24px 28px;color:#ffffff;">
                    <h1 style="margin:0;font-size:24px;line-height:1.3;">Repair Café Renkum/Heelsum</h1>
                </div>

                <div style="padding:32px 28px 28px 28px;">
                    <h2 style="margin:0 0 16px 0;font-size:22px;line-height:1.3;color:#111827;">' . esc_html($title) . '</h2>
                    <p style="margin:0 0 20px 0;color:#374151;line-height:1.7;">' . nl2br(esc_html($intro)) . '</p>

                    <div style="background:#fff7f2;border:1px solid #fed7aa;border-radius:14px;padding:18px 20px;">
                        <table style="width:100%;border-collapse:collapse;">
                            ' . $rows_html . '
                        </table>
                    </div>

                    ' . $footer_html . '
                </div>

                <div style="padding:18px 28px;background:#fafafa;border-top:1px solid #e5e7eb;color:#6b7280;font-size:13px;text-align:center;">
                    Dit is een automatische e-mail van Repair Café Renkum/Heelsum.
                </div>
            </div>
        </div>';
    }

    /**
     * Verstuurt bevestigingsmails na aanmelden.
     *
     * Doet:
     * - mail naar vrijwilliger
     * - mail naar vast admin-adres
     *
     * In:
     * - $event_id: ID van het event
     * - $user_id: ID van de gebruiker
     *
     * Uit:
     * - niets
     */
    private function send_signup_emails($event_id, $user_id) {
        $user = get_user_by('id', (int) $user_id);
        if (!$user) return;

        $event_title = get_the_title($event_id);
        $event_date  = get_post_meta($event_id, '_rc_event_date', true);
        $event_time  = get_post_meta($event_id, '_rc_event_time', true);
        $location    = wp_strip_all_tags(str_replace('<br>', ', ', $this->format_location($event_id)));

        $pretty_date = '';
        if ($event_date) {
            $pretty_date = date_i18n('l d-m-Y', strtotime($event_date));
        }

        $expertise_id   = $this->get_primary_user_expertise_id($user_id);
        $expertise_name = '';

        if ($expertise_id > 0) {
            global $wpdb;
            $expertise_name = (string) $wpdb->get_var($wpdb->prepare(
                "SELECT name FROM {$wpdb->prefix}rcp_expertises WHERE id = %d LIMIT 1",
                $expertise_id
            ));
        }

        $headers = ['Content-Type: text/html; charset=UTF-8'];

        $subject_user = 'Bevestiging aanmelding Repair Café';
        $message_user = $this->get_email_template(
            'Je aanmelding is bevestigd',
            'Beste ' . $user->display_name . ",\n\nBedankt voor je aanmelding. Hieronder vind je de gegevens.",
            [
                'Evenement' => $event_title,
                'Datum'     => $pretty_date,
                'Tijd'      => $event_time,
                'Locatie'   => $location,
                'Expertise' => $expertise_name,
            ],
            "We zien je graag bij het Repair Café.\n\nMet vriendelijke groet,\nRepair Café Renkum"
        );

        wp_mail($user->user_email, $subject_user, $message_user, $headers);

        $admin_email = 'info@repaircaferenkum.nl';
        if ($admin_email) {
            $subject_admin = 'Nieuwe aanmelding Repair Café';
            $message_admin = $this->get_email_template(
                'Nieuwe aanmelding ontvangen',
                'Er is een nieuwe vrijwilliger aangemeld.',
                [
                    'Vrijwilliger' => $user->display_name,
                    'E-mail'       => $user->user_email,
                    'Evenement'    => $event_title,
                    'Datum'        => $pretty_date,
                    'Tijd'         => $event_time,
                    'Locatie'      => $location,
                    'Expertise'    => $expertise_name,
                ],
                "Deze e-mail is automatisch verzonden vanuit de Repair Café Planner."
            );

            wp_mail($admin_email, $subject_admin, $message_admin, $headers);
        }
    }

    /**
     * Verstuurt bevestigingsmails na afmelden.
     *
     * Doet:
     * - mail naar vrijwilliger
     * - mail naar vast admin-adres
     *
     * In:
     * - $event_id: ID van het event
     * - $user_id: ID van de gebruiker
     *
     * Uit:
     * - niets
     */
    private function send_unsubscribe_emails($event_id, $user_id) {
        $user = get_user_by('id', (int) $user_id);
        if (!$user) return;

        $event_title = get_the_title($event_id);
        $event_date  = get_post_meta($event_id, '_rc_event_date', true);
        $event_time  = get_post_meta($event_id, '_rc_event_time', true);
        $location    = wp_strip_all_tags(str_replace('<br>', ', ', $this->format_location($event_id)));

        $pretty_date = '';
        if ($event_date) {
            $pretty_date = date_i18n('l d-m-Y', strtotime($event_date));
        }

        $expertise_id   = $this->get_primary_user_expertise_id($user_id);
        $expertise_name = '';

        if ($expertise_id > 0) {
            global $wpdb;
            $expertise_name = (string) $wpdb->get_var($wpdb->prepare(
                "SELECT name FROM {$wpdb->prefix}rcp_expertises WHERE id = %d LIMIT 1",
                $expertise_id
            ));
        }

        $headers = ['Content-Type: text/html; charset=UTF-8'];

        $subject_user = 'Bevestiging afmelding Repair Café';
        $message_user = $this->get_email_template(
            'Je afmelding is verwerkt',
            'Beste ' . $user->display_name . ",\n\nJe afmelding is goed ontvangen en verwerkt.",
            [
                'Evenement' => $event_title,
                'Datum'     => $pretty_date,
                'Tijd'      => $event_time,
                'Locatie'   => $location,
                'Expertise' => $expertise_name,
            ],
            "Hopelijk zien we je een volgende keer weer.\n\nMet vriendelijke groet,\nRepair Café Renkum"
        );

        wp_mail($user->user_email, $subject_user, $message_user, $headers);

        $admin_email = 'info@repaircaferenkum.nl';
        if ($admin_email) {
            $subject_admin = 'Afmelding Repair Café';
            $message_admin = $this->get_email_template(
                'Afmelding ontvangen',
                'Er is een vrijwilliger afgemeld.',
                [
                    'Vrijwilliger' => $user->display_name,
                    'E-mail'       => $user->user_email,
                    'Evenement'    => $event_title,
                    'Datum'        => $pretty_date,
                    'Tijd'         => $event_time,
                    'Locatie'      => $location,
                    'Expertise'    => $expertise_name,
                ],
                "Deze e-mail is automatisch verzonden vanuit de Repair Café Planner."
            );

            wp_mail($admin_email, $subject_admin, $message_admin, $headers);
        }
    }

    /* -------------------- Actions: signup/unsubscribe -------------------- */

    /**
     * Verwerkt frontend acties voor aanmelden en afmelden.
     *
     * Doet:
     * - leest rc_action en event_id uit request
     * - controleert login en nonce
     * - voert aan- of afmelding uit
     * - verstuurt mails
     * - stuurt gebruiker terug met melding
     *
     * In:
     * - request-data uit URL of formulier
     *
     * Uit:
     * - redirect of niets
     */
    public function handle_actions() {
        if (empty($_REQUEST['rc_action'])) return;

        $action   = sanitize_text_field($_REQUEST['rc_action'] ?? '');
        $event_id = isset($_REQUEST['event_id']) ? (int) $_REQUEST['event_id'] : 0;

        if (!$event_id) return;

        if (!is_user_logged_in()) {
            wp_safe_redirect(home_url('/inloggen/'));
            exit;
        }

        $user_id = get_current_user_id();
        $nonce   = $_REQUEST['_wpnonce'] ?? '';

        if (!wp_verify_nonce($nonce, 'rc_' . $action . '_' . $event_id)) {
            wp_die('Ongeldige beveiligingscheck.');
        }

        if ($action === 'signup') {
            $ok = $this->do_signup($event_id, $user_id);

            if ($ok) {
                $this->send_signup_emails($event_id, $user_id);
                $this->redirect_back('Aangemeld ✅');
            } else {
                $reason = $this->get_signup_block_reason($event_id, $user_id);

                if ($reason !== '') {
                    $this->redirect_back($reason);
                }

                $this->redirect_back('Dit event zit vol. ❌');
            }
        }

        if ($action === 'unsubscribe') {
            if (!$this->can_unsubscribe($event_id)) {
                $contact = $this->get_contact_name();
                $this->redirect_back('Afmelden binnen 24 uur dat het evenement begint kan niet, graag contact opnemen met ' . $contact . '.');
            }

            $ok = $this->do_unsubscribe($event_id, $user_id);

            if ($ok) {
                $this->send_unsubscribe_emails($event_id, $user_id);
            }

            $this->redirect_back($ok ? 'Afgemeld ✅' : 'Afmelden mislukt ❌');
        }
    }

    /* -------------------- Shortcodes -------------------- */

    /**
     * Shortcode voor overzicht van toekomstige events.
     *
     * Doet:
     * - toont melding uit rc_msg
     * - haalt toekomstige events op
     * - toont datum, tijd, locatie, inhoud, expertises en aangemelden
     * - toont aan/afmeldknop
     *
     * Uit:
     * - HTML-string
     */
    public function shortcode_events() {
        global $wpdb;
        $today = date('Y-m-d');

        $out = '';
        if (!empty($_GET['rc_msg'])) {
            $out .= '<div class="rc-msg">' . esc_html(rawurldecode($_GET['rc_msg'])) . '</div>';
        }

        $q = new WP_Query([
            'post_type'      => 'rc_event',
            'posts_per_page' => 50,
            'meta_key'       => '_rc_event_date',
            'orderby'        => 'meta_value',
            'order'          => 'ASC',
            'meta_query'     => [[
                'key'     => '_rc_event_date',
                'value'   => $today,
                'compare' => '>=',
                'type'    => 'DATE',
            ]],
        ]);

        if (!$q->have_posts()) {
            return $out . '<p>Geen toekomstige evenementen gevonden.</p>';
        }

        while ($q->have_posts()) {
            $q->the_post();
            $id = get_the_ID();

            $date  = get_post_meta($id, '_rc_event_date', true);
            $time  = get_post_meta($id, '_rc_event_time', true);
            $count = $this->signup_count($id);
            $max   = $this->get_max_volunteers($id);

            $out .= "<div class='rc-card'>";
            $out .= "<h3>" . esc_html(get_the_title()) . "</h3>";

            if ($date) {
                $ts     = strtotime($date);
                $pretty = date_i18n('l d-m-Y', $ts);

                $out .= "<p class='rc-meta'>" . esc_html($pretty);
                if ($time) {
                    $out .= " <small>om</small> " . esc_html($time);
                }

                if ($max !== null) {
                    $out .= " <small>·</small> <small>" . esc_html($count) . "/" . esc_html($max) . " plekken</small>";
                } else {
                    $out .= " <small>·</small> <small>" . esc_html($count) . " aanmeldingen</small>";
                }

                $out .= "</p>";
            }

            $loc = $this->format_location($id);
            if ($loc) {
                $out .= "<p class='rc-loc'><strong>Locatie:</strong><br>$loc</p>";
            }

            $out .= $this->render_expertise_statuses($id);

            $out .= "<div>" . wpautop(wp_kses_post(get_the_content())) . "</div>";
            $out .= "<p style='margin-top:20px;'>
<a href='" . esc_url(home_url('/repair-cafe-dagen/')) . "' class='rc-btn'>← Terug naar kalender</a>
</p>";

            $signups = $wpdb->get_results($wpdb->prepare(
                "SELECT u.ID, u.display_name
                 FROM {$this->table_name()} s
                 LEFT JOIN {$wpdb->users} u ON s.user_id = u.ID
                 WHERE s.event_id = %d
                 ORDER BY s.created_at ASC",
                $id
            ));

            if ($signups) {
                $out .= "<div class='rc-signups'><strong>Aangemeld:</strong><ul>";

                foreach ($signups as $s) {
                    $expertise = $wpdb->get_var($wpdb->prepare(
                        "SELECT e.name
                         FROM {$this->table_name()} s2
                         LEFT JOIN {$wpdb->prefix}rcp_expertises e ON s2.expertise_id = e.id
                         WHERE s2.user_id = %d AND s2.event_id = %d
                         LIMIT 1",
                        $s->ID,
                        $id
                    ));

                    $name = $s->display_name;
                    if ($expertise) {
                        $name .= ' (' . $expertise . ')';
                    }

                    $out .= "<li>" . esc_html($name) . "</li>";
                }

                $out .= "</ul></div>";
            }

            $out .= "<div class='rc-actions'>" . $this->render_buttons($id) . "</div>";
            $out .= "</div>";
        }

        wp_reset_postdata();
        return $out;
    }

    /**
     * Shortcode voor "mijn aanmeldingen".
     *
     * Doet:
     * - toont alleen aanmeldingen van ingelogde gebruiker
     * - splitst in toekomstige en eerdere events
     *
     * Uit:
     * - HTML-string
     */
    public function shortcode_my_signups() {
        if (!is_user_logged_in()) {
            $login = home_url('/inloggen/');
            return '<p><a class="rc-btn" href="' . esc_url($login) . '">Log in om je aanmeldingen te bekijken</a></p>';
        }

        global $wpdb;
        $table   = $this->table_name();
        $user_id = get_current_user_id();
        $today   = date('Y-m-d');

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT event_id, created_at FROM $table WHERE user_id = %d ORDER BY created_at DESC",
            $user_id
        ));

        if (!$rows) {
            return '<p>Je hebt nog geen aanmeldingen.</p>';
        }

        $future = [];
        $past   = [];

        foreach ($rows as $r) {
            $event_id = (int) $r->event_id;

            if (get_post_type($event_id) !== 'rc_event') continue;

            $title = get_the_title($event_id);
            $date  = get_post_meta($event_id, '_rc_event_date', true);
            $time  = get_post_meta($event_id, '_rc_event_time', true);
            $loc   = $this->format_location($event_id);

            $item  = "<div class='rc-card'>";
            $item .= "<h3>" . esc_html($title) . "</h3>";

            if ($date) {
                $ts     = strtotime($date);
                $pretty = date_i18n('l d-m-Y', $ts);

                $item .= "<p class='rc-meta'>" . esc_html($pretty);
                if ($time) {
                    $item .= " <small>om</small> " . esc_html($time);
                }
                $item .= "</p>";
            }

            if ($loc) {
                $item .= "<p class='rc-loc'><strong>Locatie:</strong><br>$loc</p>";
            }

            $item .= $this->render_expertise_statuses($event_id);
            $item .= "<div class='rc-actions'>" . $this->render_buttons($event_id, true) . "</div>";
            $item .= "</div>";

            if ($date && $date >= $today) {
                $future[] = $item;
            } else {
                $past[] = $item;
            }
        }

        $out = "<div class='rc-my'>";

        $out .= "<h2>Toekomstige events waarvoor ik ben aangemeld</h2>";
        if ($future) {
            $out .= implode('', $future);
        } else {
            $out .= "<p>Je hebt geen toekomstige aanmeldingen.</p>";
        }

        $out .= "<h2 style='margin-top:30px;'>Alle events waar ik ooit ben aangemeld</h2>";
        if ($past) {
            $out .= implode('', $past);
        } else {
            $out .= "<p>Je hebt nog geen eerdere aanmeldingen.</p>";
        }

        $out .= "</div>";

        return $out;
    }

    /**
     * Bouwt de juiste knop of melding voor een event.
     *
     * Doet:
     * - toont login-knop als gebruiker niet is ingelogd
     * - toont aanmelden als gebruiker nog niet aangemeld is
     * - toont blokkade-melding als aanmelden niet mag
     * - toont afmelden als gebruiker al aangemeld is
     * - toont 24-uursmelding als afmelden te laat is
     *
     * In:
     * - $event_id: ID van het event
     * - $compact: wordt nu niet gebruikt in de logica, maar blijft als parameter bestaan
     *
     * Uit:
     * - HTML-string
     */
    private function render_buttons($event_id, $compact = false) {
        if (!is_user_logged_in()) {
            $login = home_url('/inloggen/');
            return '<p><a class="rc-btn" href="' . esc_url($login) . '">Log in om de Repair Cafe Dagen te bekijken</a></p>';
        }

        $user_id = get_current_user_id();
        $signed  = $this->is_signed_up($event_id, $user_id);

        if (!$signed) {
            if ($this->is_full($event_id)) {
                return '<span class="rc-note">Dit event zit vol.</span>';
            }

            $block_reason = $this->get_signup_block_reason($event_id, $user_id);
            if ($block_reason !== '') {
                return '<span class="rc-note">' . esc_html($block_reason) . '</span>';
            }

            $url = add_query_arg([
                'rc_action' => 'signup',
                'event_id'  => $event_id,
                '_wpnonce'  => wp_create_nonce('rc_signup_' . $event_id),
            ], home_url('/'));

            return '<a class="rc-btn" href="' . esc_url($url) . '">Aanmelden</a>';
        }

        if (!$this->can_unsubscribe($event_id)) {
            $contact = $this->get_contact_name();
            return '<span class="rc-note">Afmelden binnen 24 uur dat het evenement begint kan niet, graag contact opnemen met ' . esc_html($contact) . '.</span>';
        }

        $url = add_query_arg([
            'rc_action' => 'unsubscribe',
            'event_id'  => $event_id,
            '_wpnonce'  => wp_create_nonce('rc_unsubscribe_' . $event_id),
        ], home_url('/'));

        return '<a class="rc-btn rc-btn-secondary" href="' . esc_url($url) . '">Afmelden</a>';
    }

    /**
     * Shortcode voor loginformulier.
     *
     * Uit:
     * - HTML-string
     */
    public function shortcode_login_form() {
        if (is_user_logged_in()) {
            return '<p>Je bent al ingelogd.</p>';
        }

        $args = [
            'echo'           => false,
            'remember'       => true,
            'redirect'       => home_url('/repair-cafe-dagen/'),
            'form_id'        => 'rc-loginform',
            'id_username'    => 'rc-user-login',
            'id_password'    => 'rc-user-pass',
            'id_remember'    => 'rc-rememberme',
            'id_submit'      => 'rc-login-submit',
            'label_username' => 'E-mailadres of gebruikersnaam',
            'label_password' => 'Wachtwoord',
            'label_remember' => 'Ingelogd blijven',
            'label_log_in'   => 'Inloggen',
        ];

        $out  = "<div class='rc-card'>";
        $out .= "<h3>Inloggen</h3>";
        $out .= wp_login_form($args);
        $out .= "<p style='margin-top:10px;'><a href='" . esc_url(home_url('/wachtwoord-vergeten/')) . "'>Wachtwoord vergeten?</a></p>";
        $out .= "</div>";

        return $out;
    }

    /**
     * Filtert menu-items op basis van loginstatus.
     *
     * Doet:
     * - ingelogd: alleen toegestane items laten staan
     * - uitgelogd: beschermde items verbergen
     * - voegt uitloggen-item toe als gebruiker is ingelogd
     *
     * In:
     * - $items: menu-items
     * - $args: menu-args
     *
     * Uit:
     * - aangepaste menu-items
     */
    public function filter_menu_items($items, $args) {
        $allowed_logged_in = ['repair cafe dagen', 'mijn aanmeldingen'];

        foreach ($items as $key => $item) {
            $title = strtolower(trim($item->title));

            if (is_user_logged_in()) {
                if (!in_array($title, $allowed_logged_in, true)) {
                    unset($items[$key]);
                }
            } else {
                if (in_array($title, $allowed_logged_in, true)) {
                    unset($items[$key]);
                }
            }
        }

        if (is_user_logged_in()) {
            $logout_url = wp_logout_url(home_url('/'));

            $logout_item = (object) [
                'ID' => 999999,
                'title' => 'Uitloggen',
                'url' => $logout_url,
                'menu_item_parent' => 0,
                'type' => '',
                'object' => '',
                'object_id' => 0,
                'db_id' => 0,
                'classes' => ['menu-item', 'menu-item-logout']
            ];

            $items[] = $logout_item;
        }

        return $items;
    }

    /**
     * Blokkeert vrijwilligers uit de WordPress-backend.
     *
     * Doet:
     * - laat admins door
     * - laat AJAX door
     * - stuurt overige ingelogde gebruikers naar frontend
     *
     * Uit:
     * - redirect of niets
     */
    public function block_volunteer_backend() {
        if (!is_user_logged_in()) {
            return;
        }

        if (wp_doing_ajax()) {
            return;
        }

        if (current_user_can('manage_options')) {
            return;
        }

        wp_safe_redirect(home_url('/repair-cafe-dagen/'));
        exit;
    }

    /**
     * Verbergt de admin bar voor niet-admins.
     *
     * In:
     * - $show: huidige status
     *
     * Uit:
     * - false voor vrijwilligers
     * - originele waarde voor admins
     */
    public function hide_admin_bar_for_volunteers($show) {
        if (current_user_can('manage_options')) {
            return $show;
        }

        return false;
    }

    /**
     * Shortcode voor wachtwoord-vergeten formulier.
     *
     * Doet:
     * - toont formulier
     * - controleert nonce
     * - roept WordPress resetfunctie aan
     * - toont terugknop na verzenden
     *
     * Uit:
     * - HTML-string
     */
    public function shortcode_lost_password_form() {
        if (is_user_logged_in()) {
            return '<p>Je bent al ingelogd.</p>';
        }

        $message = '';

        if (!empty($_POST['rc_lost_password_submit'])) {
            $nonce_ok = isset($_POST['rc_lost_password_nonce']) && wp_verify_nonce($_POST['rc_lost_password_nonce'], 'rc_lost_password_action');

            if (!$nonce_ok) {
                $message = '<p class="rc-note">De aanvraag kon niet worden gecontroleerd. Probeer het opnieuw.</p>';
            } else {
                $user_login = isset($_POST['user_login']) ? sanitize_text_field(wp_unslash($_POST['user_login'])) : '';

                if ($user_login === '') {
                    $message = '<p class="rc-note">Vul je e-mailadres of gebruikersnaam in.</p>';
                } else {
                    $result = retrieve_password($user_login);

                    if (is_wp_error($result)) {
                        $message = '<p class="rc-note">Er kon geen resetmail worden verstuurd. Controleer je gegevens en probeer het opnieuw.</p>';
                    } else {
                        $message = '<p class="rc-note">Als je gegevens bij ons bekend zijn, is er een e-mail naar je verstuurd met een link om je wachtwoord opnieuw in te stellen.</p>';
                    }
                }
            }
        }

        $out  = "<div class='rc-card'>";
        $out .= "<h3>Wachtwoord vergeten?</h3>";
        $out .= "<p>Vul je e-mailadres of gebruikersnaam in. Je ontvangt daarna een e-mail om je wachtwoord opnieuw in te stellen.</p>";
        $out .= $message;

        if (empty($_POST['rc_lost_password_submit'])) {
            $out .= "<form method='post'>";
            $out .= wp_nonce_field('rc_lost_password_action', 'rc_lost_password_nonce', true, false);
            $out .= "<p><input type='text' name='user_login' placeholder='E-mailadres of gebruikersnaam' required style='padding:10px;width:100%;max-width:340px;'></p>";
            $out .= "<p><button type='submit' name='rc_lost_password_submit' value='1' class='rc-btn'>Verstuur resetlink</button></p>";
            $out .= "</form>";
        } else {
            $out .= "<p style='margin-top:20px;'>
    <a href='" . esc_url(home_url('/inloggen/')) . "' 
       style='display:inline-block;padding:12px 20px;background:#f46e16;color:#fff;border-radius:6px;text-decoration:none;font-weight:600;'>
       ← Terug naar inloggen
    </a>
</p>";
        }

        $out .= "</div>";

        return $out;
    }

    /**
     * Verwerkt admin-acties om vrijwilligers handmatig aan of af te melden.
     *
     * Doet:
     * - alleen voor admins
     * - controleert nonce
     * - gebruikt dezelfde signup-logica als frontend
     * - redirect terug naar admin-overzicht met melding
     *
     * Uit:
     * - redirect of niets
     */
    public function handle_admin_signup_actions() {
        if (!current_user_can('manage_options')) {
            return;
        }

        if (empty($_GET['rc_admin_action']) || empty($_GET['event_id']) || empty($_GET['user_id'])) {
            return;
        }

        $action   = sanitize_text_field($_GET['rc_admin_action']);
        $event_id = (int) $_GET['event_id'];
        $user_id  = (int) $_GET['user_id'];
        $nonce    = $_GET['_wpnonce'] ?? '';

        if (!wp_verify_nonce($nonce, 'rc_admin_' . $action . '_' . $event_id . '_' . $user_id)) {
            wp_die('Ongeldige beveiligingscheck.');
        }

        if ($action === 'signup_user') {
            $ok = $this->do_signup($event_id, $user_id);

            if ($ok) {
                $this->send_signup_emails($event_id, $user_id);
                wp_safe_redirect(admin_url('edit.php?post_type=rc_event&page=rc_signups&rc_msg=' . rawurlencode('Vrijwilliger aangemeld ✅')));
                exit;
            }

            wp_safe_redirect(admin_url('edit.php?post_type=rc_event&page=rc_signups&rc_msg=' . rawurlencode('Aanmelden mislukt ❌')));
            exit;
        }

        if ($action === 'unsubscribe_user') {
            global $wpdb;

            $res = $wpdb->delete(
                $this->table_name(),
                [
                    'event_id' => $event_id,
                    'user_id'  => $user_id,
                ],
                ['%d', '%d']
            );

            if ($res !== false) {
                wp_safe_redirect(admin_url('edit.php?post_type=rc_event&page=rc_signups&rc_msg=' . rawurlencode('Vrijwilliger afgemeld ✅')));
                exit;
            }

            wp_safe_redirect(admin_url('edit.php?post_type=rc_event&page=rc_signups&rc_msg=' . rawurlencode('Afmelden mislukt ❌')));
            exit;
        }
    }

    /* -------------------- Admin menu -------------------- */

    /**
     * Registreert admin-submenu's onder Repair Cafés.
     *
     * Doet:
     * - Aanmeldingen
     * - Instellingen
     * - Opkomst vrijwilligers
     *
     * In:
     * - niets
     *
     * Uit:
     * - niets
     */
    public function admin_menu() {
        add_submenu_page(
            'edit.php?post_type=rc_event',
            'Aanmeldingen',
            'Aanmeldingen',
            'manage_options',
            'rc_signups',
            [$this, 'admin_signups_page']
        );

        add_submenu_page(
            'edit.php?post_type=rc_event',
            'Instellingen',
            'Instellingen',
            'manage_options',
            'rc_settings',
            [$this, 'settings_page']
        );

        add_submenu_page(
            'edit.php?post_type=rc_event',
            'Opkomst vrijwilligers',
            'Opkomst vrijwilligers',
            'manage_options',
            'rc_attendance_overview',
            [$this, 'attendance_overview_page']
        );
    }

    /**
     * Toont instellingenpagina.
     *
     * Doet:
     * - toont formulier voor contactpersoon bij laat afmelden
     *
     * Uit:
     * - HTML
     */
    public function settings_page() {
        echo '<div class="wrap"><h1>Repair Café instellingen</h1>';
        echo '<form method="post" action="options.php">';
        settings_fields('rc_settings_group');
        echo '<table class="form-table"><tr>';
        echo '<th scope="row">Contactpersoon bij te laat afmelden</th>';
        echo '<td><input type="text" name="' . esc_attr(self::OPTION_CONTACT) . '" value="' . esc_attr($this->get_contact_name()) . '" style="width:320px;"></td>';
        echo '</tr></table>';
        submit_button();
        echo '</form></div>';
    }

    /**
     * Toont adminpagina met alle aanmeldingen per event.
     *
     * Doet:
     * - toont events
     * - toont wie is aangemeld
     * - toont stand per expertise
     * - laat admin vrijwilligers aan- of afmelden
     *
     * Uit:
     * - HTML
     */
    public function admin_signups_page() {
        if (!current_user_can('manage_options')) return;

        $events = get_posts([
            'post_type'   => 'rc_event',
            'numberposts' => 50,
            'orderby'     => 'date',
            'order'       => 'DESC',
        ]);

        echo '<div class="wrap"><h1>Aanmeldingen</h1>';
        if (!empty($_GET['rc_msg'])) {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html(rawurldecode($_GET['rc_msg'])) . '</p></div>';
        }
        echo '<p>Per event zie je hieronder wie er aangemeld is.</p>';

        if (!$events) {
            echo '<p>Geen events gevonden.</p></div>';
            return;
        }

        global $wpdb;
        $table = $this->table_name();

        echo '<div style="display:flex;flex-direction:column;gap:14px;">';

        foreach ($events as $e) {
            $event_id = $e->ID;
            $date     = get_post_meta($event_id, '_rc_event_date', true);
            $time     = get_post_meta($event_id, '_rc_event_time', true);
            $max      = $this->get_max_volunteers($event_id);

            $pretty = $date ? date_i18n('l d-m-Y', strtotime($date)) : '';
            $count  = $this->signup_count($event_id);

            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT user_id, created_at FROM $table WHERE event_id = %d ORDER BY created_at ASC",
                $event_id
            ));

            $all_volunteers = get_users([
                'role__in' => [self::ROLE, 'administrator'],
                'orderby'  => 'display_name',
                'order'    => 'ASC',
            ]);

            $expertise_counts = $wpdb->get_results($wpdb->prepare(
                "SELECT ee.expertise_id, e.name, ee.max_volunteers,
                        COUNT(DISTINCT s.id) AS count
                 FROM {$wpdb->prefix}rcp_event_expertises ee
                 LEFT JOIN {$wpdb->prefix}rcp_expertises e ON ee.expertise_id = e.id
                 LEFT JOIN {$wpdb->prefix}rcp_user_expertises ue ON ue.expertise_id = ee.expertise_id
                 LEFT JOIN {$table} s
                    ON s.user_id = ue.user_id AND s.event_id = ee.event_id
                 WHERE ee.event_id = %d
                 GROUP BY ee.expertise_id, e.name, ee.max_volunteers
                 ORDER BY e.name ASC",
                $event_id
            ));

            echo '<div style="border:1px solid #ddd;background:#fff;padding:14px;border-radius:10px;">';
            echo '<h2 style="margin:0 0 6px 0;">' . esc_html(get_the_title($event_id)) . '</h2>';

            if ($pretty) {
                echo '<div><strong>Wanneer:</strong> ' . esc_html($pretty) . ($time ? ' om ' . esc_html($time) : '') . '</div>';
            }

            echo '<div><strong>Aanmeldingen:</strong> ' . esc_html($count) . ($max !== null ? ' / ' . esc_html($max) : '') . '</div>';

            if ($expertise_counts) {
                echo '<ul style="margin:6px 0 0 15px;">';
                foreach ($expertise_counts as $exp) {
                    $free = max(0, (int) $exp->max_volunteers - (int) $exp->count);
                    echo '<li>' . esc_html($exp->name) . ': ' . esc_html($exp->count) . '/' . esc_html($exp->max_volunteers) . ' bezet, ' . esc_html($free) . ' vrij</li>';
                }
                echo '</ul>';
            }

            if (!$rows) {
                echo '<div style="margin-top:8px;color:#666;">(Nog geen aanmeldingen)</div>';
            } else {
                echo '<ol style="margin-top:8px;">';

                foreach ($rows as $r) {
                    $u = get_user_by('id', (int) $r->user_id);
                    if (!$u) continue;

                    $name  = $u->display_name ?: $u->user_login;
                    $email = $u->user_email ?: '';

                    $expertise = $wpdb->get_var($wpdb->prepare(
                        "SELECT e.name
                         FROM {$this->table_name()} s2
                         LEFT JOIN {$wpdb->prefix}rcp_expertises e ON s2.expertise_id = e.id
                         WHERE s2.user_id = %d AND s2.event_id = %d
                         LIMIT 1",
                        (int) $r->user_id,
                        $event_id
                    ));

                    echo '<li>';
                    echo esc_html($name);

                    if ($expertise) {
                        echo ' <span style="color:#666;">(' . esc_html($expertise) . ')</span>';
                    }

                    if ($email) {
                        echo ' <span style="color:#666;">- ' . esc_html($email) . '</span>';
                    }

                    echo '</li>';
                }

                echo '</ol>';

                if ($all_volunteers) {
                    echo '<div style="margin-top:14px;"><strong>Vrijwilligers beheren:</strong></div>';
                    echo '<ul style="margin-top:8px;">';

                    foreach ($all_volunteers as $volunteer) {
                        $is_signed = $this->is_signed_up($event_id, $volunteer->ID);

                        echo '<li style="margin-bottom:8px;">';
                        echo esc_html($volunteer->display_name);

                        if ($volunteer->user_email) {
                            echo ' <span style="color:#666;">- ' . esc_html($volunteer->user_email) . '</span>';
                        }

                        echo ' ';

                        if ($is_signed) {
                            $url = wp_nonce_url(
                                admin_url('edit.php?post_type=rc_event&page=rc_signups&rc_admin_action=unsubscribe_user&event_id=' . $event_id . '&user_id=' . $volunteer->ID),
                                'rc_admin_unsubscribe_user_' . $event_id . '_' . $volunteer->ID
                            );

                            echo '<a href="' . esc_url($url) . '" class="button">Afmelden</a>';
                        } else {
                            $url = wp_nonce_url(
                                admin_url('edit.php?post_type=rc_event&page=rc_signups&rc_admin_action=signup_user&event_id=' . $event_id . '&user_id=' . $volunteer->ID),
                                'rc_admin_signup_user_' . $event_id . '_' . $volunteer->ID
                            );

                            echo '<a href="' . esc_url($url) . '" class="button button-primary">Aanmelden</a>';
                        }

                        echo '</li>';
                    }

                    echo '</ul>';
                }
            }

            echo '</div>';
        }

        echo '</div></div>';
    }

    /**
     * Voegt extra event-info en terugknop toe aan losse eventpagina.
     *
     * Doet:
     * - alleen voor rc_event
     * - toont datum, tijd, locatie, expertise-status, aangemelden en knoppen
     * - plakt onder bestaande content een terugknop
     *
     * In:
     * - $content: bestaande berichtinhoud
     *
     * Uit:
     * - aangepaste inhoud
     */
    public function add_back_button_to_event($content) {
        if (get_post_type() !== 'rc_event') {
            return $content;
        }

        $event_id = get_the_ID();

        $date = get_post_meta($event_id, '_rc_event_date', true);
        $time = get_post_meta($event_id, '_rc_event_time', true);
        $loc  = get_post_meta($event_id, '_rc_location_name', true);
        $addr = get_post_meta($event_id, '_rc_location_address', true);
        $city = get_post_meta($event_id, '_rc_location_city', true);

        $info = "<div style='margin-top:10px;'>";

        if ($date) {
            $pretty = date_i18n('l d-m-Y', strtotime($date));
            $info .= "<p><strong>Datum:</strong> $pretty";
            if ($time) {
                $info .= " om $time";
            }
            $info .= "</p>";
        }

        if ($loc || $addr || $city) {
            $info .= "<p><strong>Locatie:</strong><br>";
            if ($loc)  $info .= $loc . "<br>";
            if ($addr) $info .= $addr . "<br>";
            if ($city) $info .= $city;
            $info .= "</p>";
        }

        $info .= $this->render_expertise_statuses($event_id);

        global $wpdb;

        $signups = $wpdb->get_results($wpdb->prepare(
            "SELECT u.ID, u.display_name
             FROM {$this->table_name()} s
             LEFT JOIN {$wpdb->users} u ON s.user_id = u.ID
             WHERE s.event_id = %d
             ORDER BY s.created_at ASC",
            $event_id
        ));

        if ($signups) {
            $info .= "<div class='rc-signups'><strong>Aangemeld:</strong><ul>";

            foreach ($signups as $s) {
                $expertise = $wpdb->get_var($wpdb->prepare(
                    "SELECT e.name
                     FROM {$this->table_name()} s2
                     LEFT JOIN {$wpdb->prefix}rcp_expertises e ON s2.expertise_id = e.id
                     WHERE s2.user_id = %d AND s2.event_id = %d
                     LIMIT 1",
                    $s->ID,
                    $event_id
                ));

                $name = $s->display_name;
                if ($expertise) {
                    $name .= ' (' . $expertise . ')';
                }

                $info .= "<li>" . esc_html($name) . "</li>";
            }

            $info .= "</ul></div>";
        }

        $info .= "<div class='rc-actions'>" . $this->render_buttons($event_id) . "</div>";
        $info .= "</div>";

        $button = "<p style='margin-top:20px;'>
<a href='" . esc_url(home_url('/repair-cafe-dagen/')) . "' class='rc-btn'>← Terug naar kalender</a>
</p>";

        return $content . $info . $button;
    }

    /**
     * Toont adminveld voor beginstand aanwezigheden op gebruikersprofiel.
     *
     * Doet:
     * - alleen zichtbaar voor admins
     * - laat handmatig ingevoerde startwaarde zien
     *
     * In:
     * - $user: WordPress gebruiker
     *
     * Uit:
     * - HTML
     */
    public function render_attendance_start_field($user) {
        if (!current_user_can('manage_options')) {
            return;
        }

        $value = get_user_meta($user->ID, 'rc_attendance_start_count', true);
        $value = ($value === '') ? 0 : (int) $value;
        ?>
        <h2>Repair Café aanwezigheden</h2>
        <table class="form-table">
            <tr>
                <th><label for="rc_attendance_start_count">Beginstand aanwezigheden</label></th>
                <td>
                    <input type="number" min="0" step="1" name="rc_attendance_start_count" id="rc_attendance_start_count" value="<?php echo esc_attr($value); ?>" class="regular-text">
                    <p class="description">Vul hier het aantal eerdere keren in dat deze vrijwilliger al aanwezig is geweest.</p>
                </td>
            </tr>
        </table>
        <?php
    }

    /**
     * Slaat beginstand aanwezigheden op bij gebruiker.
     *
     * In:
     * - $user_id: ID van de gebruiker
     *
     * Uit:
     * - niets
     */
    public function save_attendance_start_field($user_id) {
        if (!current_user_can('manage_options')) {
            return;
        }

        $value = isset($_POST['rc_attendance_start_count']) ? (int) $_POST['rc_attendance_start_count'] : 0;
        if ($value < 0) {
            $value = 0;
        }

        update_user_meta($user_id, 'rc_attendance_start_count', $value);
    }

    /**
     * Toont admin-overzicht van opkomst van vrijwilligers.
     *
     * Doet:
     * - telt handmatige beginstand
     * - telt historische planner-aanmeldingen voor verlopen events
     * - toont totaal per gebruiker
     *
     * Uit:
     * - HTML
     *
     * Waarom zo gebouwd:
     * - oude aanwezigheden van vóór de planner kunnen zo toch worden meegenomen
     */
    public function attendance_overview_page() {
        if (!current_user_can('manage_options')) return;

        global $wpdb;

        $users = get_users([
            'role__in' => [self::ROLE, 'administrator'],
            'orderby'  => 'display_name',
            'order'    => 'ASC',
        ]);

        echo '<div class="wrap"><h1>Opkomst vrijwilligers</h1>';
        echo '<table class="widefat striped" style="max-width:900px;">';
        echo '<thead><tr>';
        echo '<th>Naam</th>';
        echo '<th>E-mail</th>';
        echo '<th>Beginstand</th>';
        echo '<th>Gekomen via planner</th>';
        echo '<th>Totaal</th>';
        echo '</tr></thead><tbody>';

        if (!$users) {
            echo '<tr><td colspan="5">Geen vrijwilligers gevonden.</td></tr>';
        } else {
            foreach ($users as $user) {
                $start = (int) get_user_meta($user->ID, 'rc_attendance_start_count', true);

                $count = (int) $wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(*)
                     FROM {$this->table_name()} s
                     INNER JOIN {$wpdb->posts} p ON s.event_id = p.ID
                     INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
                     WHERE s.user_id = %d
                     AND p.post_type = 'rc_event'
                     AND pm.meta_key = '_rc_event_date'
                     AND pm.meta_value < %s",
                    $user->ID,
                    current_time('Y-m-d')
                ));

                $total = $start + $count;

                echo '<tr>';
                echo '<td>' . esc_html($user->display_name) . '</td>';
                echo '<td>' . esc_html($user->user_email) . '</td>';
                echo '<td>' . esc_html($start) . '</td>';
                echo '<td>' . esc_html($count) . '</td>';
                echo '<td><strong>' . esc_html($total) . '</strong></td>';
                echo '</tr>';
            }
        }

        echo '</tbody></table></div>';
    }

    /* -------------------- Styles -------------------- */

    /**
     * Registreert en laadt inline CSS voor frontend weergave.
     *
     * Doet:
     * - maakt 1 lege stylesheet-handle aan
     * - hangt daar inline CSS aan
     *
     * Uit:
     * - niets
     *
     * Waarom zo gebouwd:
     * - snel en centraal zonder los CSS-bestand
     */
    public function enqueue_styles() {
        wp_register_style('repaircafe-planner-inline', false);
        wp_enqueue_style('repaircafe-planner-inline');

        wp_add_inline_style('repaircafe-planner-inline', "
            .rc-msg{padding:10px;border:1px solid #ddd;margin:10px 0;border-radius:10px;background:#fff;}
            .rc-card{border:1px solid #e5e5e5;padding:20px;border-radius:12px;margin-bottom:20px;background:#fafafa;}
            .rc-card h3{margin-top:0;}
            .rc-meta{font-weight:600;margin:0 0 8px 0;}
            .rc-meta small{font-weight:400;color:#666;}
            .rc-loc{margin:0 0 10px 0;color:#333;}
            .rc-expertises{margin:0 0 14px 0;padding:12px;border:1px solid #e3e3e3;border-radius:10px;background:#fff;}
            .rc-expertise-list{list-style:none;margin:8px 0 0 0;padding:0;}
            .rc-expertise-item{display:flex;justify-content:space-between;gap:12px;padding:8px 0;border-bottom:1px solid #f0f0f0;}
            .rc-expertise-item:last-child{border-bottom:none;padding-bottom:0;}
            .rc-expertise-name{font-weight:600;color:#222;}
            .rc-expertise-meta{color:#666;font-size:14px;text-align:right;}
            .rc-actions{margin-top:12px;}
            .rc-btn{display:inline-block;padding:9px 14px;border-radius:8px;text-decoration:none;border:1px solid #f46e16;background:#f46e16;color:#fff !important;font-weight:600;}
            .rc-btn:hover{filter:brightness(0.95);}
            .rc-btn-secondary{background:#fff;color:#f46e16 !important;border:1px solid #f46e16;}
            .rc-note{display:inline-block;padding:10px;border:1px solid #ddd;border-radius:10px;background:#fff;color:#333;}
            @media (max-width: 640px){
                .rc-expertise-item{display:block;}
                .rc-expertise-meta{display:block;text-align:left;margin-top:4px;}
            }
        ");
    }
}

new RepairCafePlanner();

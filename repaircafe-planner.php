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

class RepairCafePlanner {

    const TABLE = 'rc_signups';
    const ROLE  = 'rc_volunteer';
    const OPTION_CONTACT = 'rc_late_unsubscribe_contact';

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
    }

    private function table_name() {
        global $wpdb;
        return $wpdb->prefix . self::TABLE;
    }

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

    public function deactivate() {
        flush_rewrite_rules();
    }

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
    public function register_settings() {
        register_setting('rc_settings_group', self::OPTION_CONTACT, [
            'type' => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'default' => 'Bert Rombaut',
        ]);
    }

    private function get_contact_name(): string {
        $v = (string) get_option(self::OPTION_CONTACT, 'Bert Rombaut');
        $v = trim($v);
        return $v !== '' ? $v : 'Bert Rombaut';
    }

    /* -------------------- Metaboxes -------------------- */
    public function add_metaboxes() {
        add_meta_box('rc_event_datetime', 'Event datum & tijd', [$this, 'render_datetime_metabox'], 'rc_event', 'side');
        add_meta_box('rc_event_location', 'Locatie', [$this, 'render_location_metabox'], 'rc_event', 'side');
        add_meta_box('rc_event_limits', 'Vrijwilligers', [$this, 'render_limits_metabox'], 'rc_event', 'side');
        add_meta_box('rc_event_expertises', 'Expertises per evenement', [$this, 'render_event_expertises_metabox'], 'rc_event', 'normal', 'default');
    }

    public function render_datetime_metabox($post) {
        $date = get_post_meta($post->ID, '_rc_event_date', true);
        $time = get_post_meta($post->ID, '_rc_event_time', true);
        wp_nonce_field('rc_save_event_meta', 'rc_event_nonce');

        echo '<p><label><strong>Datum</strong></label><br>';
        echo '<input type="date" name="rc_event_date" value="' . esc_attr($date) . '" style="width:100%;"></p>';

        echo '<p><label><strong>Tijd</strong></label><br>';
        echo '<input type="time" name="rc_event_time" value="' . esc_attr($time) . '" style="width:100%;"></p>';
    }

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

    public function render_limits_metabox($post) {
        $max = get_post_meta($post->ID, '_rc_max_volunteers', true);
        $max = ($max === '') ? '' : (int) $max;

        echo '<p><label><strong>Max vrijwilligers</strong></label><br>';
        echo '<input type="number" min="0" step="1" name="rc_max_volunteers" value="' . esc_attr($max) . '" style="width:100%;" placeholder="Bijv. 12"></p>';
        echo '<p style="margin:8px 0 0;color:#666;font-size:12px;">Leeg = geen limiet.</p>';
    }

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
    private function event_start_ts($event_id) {
        $date = get_post_meta($event_id, '_rc_event_date', true);
        $time = get_post_meta($event_id, '_rc_event_time', true);

        if (!$date) return 0;
        if (!$time) $time = '00:00';

        $ts = strtotime($date . ' ' . $time);
        return $ts ? (int) $ts : 0;
    }

    private function can_unsubscribe($event_id) {
        $start = $this->event_start_ts($event_id);
        if (!$start) return false;

        $now = (int) current_time('timestamp');
        return $now <= ($start - 86400);
    }

    private function format_location($event_id) {
        $name    = trim((string) get_post_meta($event_id, '_rc_location_name', true));
        $address = trim((string) get_post_meta($event_id, '_rc_location_address', true));
        $city    = trim((string) get_post_meta($event_id, '_rc_location_city', true));

        $parts = array_filter([$name, $address, $city]);
        return implode('<br>', array_map('esc_html', $parts));
    }

    private function get_max_volunteers($event_id) {
        $v = get_post_meta($event_id, '_rc_max_volunteers', true);
        if ($v === '' || $v === null) return null;
        $n = (int) $v;
        if ($n <= 0) return null;
        return $n;
    }

    private function is_full($event_id) {
    return false;
}

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

    private function signup_count($event_id) {
        global $wpdb;
        $table = $this->table_name();

        $sql = $wpdb->prepare(
            "SELECT COUNT(*) FROM $table WHERE event_id = %d",
            $event_id
        );

        return (int) $wpdb->get_var($sql);
    }

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

        private function redirect_back($msg) {
        $ref = wp_get_referer();
        if (!$ref) {
            $ref = home_url('/');
        }

        $url = add_query_arg(['rc_msg' => rawurlencode($msg)], $ref);
        wp_safe_redirect($url);
        exit;
    }

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

    public function shortcode_my_signups() {
       if (!is_user_logged_in()) {
    $login = home_url('/inloggen/');
    return '<p><a class="rc-btn" href="' . esc_url($login) . '">Log in om je aanmeldingen te bekijken</a></p>';
}

        global $wpdb;
        $table   = $this->table_name();
        $user_id = get_current_user_id();

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT event_id, created_at FROM $table WHERE user_id = %d ORDER BY created_at DESC",
            $user_id
        ));

        if (!$rows) {
            return '<p>Je hebt nog geen aanmeldingen.</p>';
        }

        $out = "<div class='rc-my'>";

        foreach ($rows as $r) {
            $event_id = (int) $r->event_id;

            if (get_post_type($event_id) !== 'rc_event') continue;

            $status = get_post_status($event_id);
            if (!in_array($status, ['publish', 'future'], true)) continue;

            $title = get_the_title($event_id);
            $date  = get_post_meta($event_id, '_rc_event_date', true);
            $time  = get_post_meta($event_id, '_rc_event_time', true);
            $loc   = $this->format_location($event_id);

            $out .= "<div class='rc-card'>";
            $out .= "<h3>" . esc_html($title) . "</h3>";

            if ($date) {
                $ts     = strtotime($date);
                $pretty = date_i18n('l d-m-Y', $ts);

                $out .= "<p class='rc-meta'>" . esc_html($pretty);
                if ($time) {
                    $out .= " <small>om</small> " . esc_html($time);
                }
                $out .= "</p>";
            }

            if ($loc) {
                $out .= "<p class='rc-loc'><strong>Locatie:</strong><br>$loc</p>";
            }

            $out .= $this->render_expertise_statuses($event_id);

            $out .= "<div class='rc-actions'>" . $this->render_buttons($event_id, true) . "</div>";
            $out .= "</div>";
        }

                $out .= "</div>";
        return $out;
    }

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

    public function filter_menu_items($items, $args) {
        $allowed_logged_in = ['repair cafe dagen', 'aangemeld'];

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

    public function hide_admin_bar_for_volunteers($show) {
        if (current_user_can('manage_options')) {
            return $show;
        }

        return false;
    }

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
    
    /* -------------------- Admin menu -------------------- */
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
    }

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

    public function admin_signups_page() {
        if (!current_user_can('manage_options')) return;

        $events = get_posts([
            'post_type'   => 'rc_event',
            'numberposts' => 50,
            'orderby'     => 'date',
            'order'       => 'DESC',
        ]);

        echo '<div class="wrap"><h1>Aanmeldingen</h1>';
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
            }

            echo '</div>';
        }

        echo '</div></div>';
    }

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
    
    /* -------------------- Styles -------------------- */
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

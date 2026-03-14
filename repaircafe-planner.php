<?php
/**
 * Plugin Name: RepairCafe Planner
 * Description: Repair Café agenda + vrijwilligers (login) + aanmelden/afmelden (24u regel) + max vrijwilligers per event + instellingen.
 * Version: 2.3.0
 */

if (!defined('ABSPATH')) exit;

require_once plugin_dir_path(__FILE__) . 'includes/admin.php';
require_once plugin_dir_path(_FILE_) . 'includes/database.php';
register_activation_hook(__FILE__, 'repaircafe_create_planner_table');
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

        add_shortcode('rc_events', [$this, 'shortcode_events']);
        add_shortcode('rc_my_signups', [$this, 'shortcode_my_signups']);

        add_action('wp_enqueue_scripts', [$this, 'enqueue_styles']);
    }

    private function table_name() {
        global $wpdb;
        return $wpdb->prefix . self::TABLE;
    }

    public function activate() {
        $this->create_table();
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
            created_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY event_user (event_id, user_id),
            KEY event_id (event_id),
            KEY user_id (user_id)
        ) $charset;";

        dbDelta($sql);
    }

    public function register_post_type() {
        register_post_type('rc_event', [
            'label' => 'Repair Cafés',
            'public' => true,
            'menu_icon' => 'dashicons-hammer',
            'supports' => ['title', 'editor'],
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
        add_meta_box('rc_event_datetime','Event datum & tijd',[$this,'render_datetime_metabox'],'rc_event','side');
        add_meta_box('rc_event_location','Locatie',[$this,'render_location_metabox'],'rc_event','side');
        add_meta_box('rc_event_limits','Vrijwilligers',[$this,'render_limits_metabox'],'rc_event','side');
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
        $max = ($max === '') ? '' : (int)$max;

        echo '<p><label><strong>Max vrijwilligers</strong></label><br>';
        echo '<input type="number" min="0" step="1" name="rc_max_volunteers" value="' . esc_attr($max) . '" style="width:100%;" placeholder="Bijv. 12"></p>';
        echo '<p style="margin:8px 0 0;color:#666;font-size:12px;">Leeg = geen limiet.</p>';
    }

    public function save_event_meta($post_id) {
        if (get_post_type($post_id) !== 'rc_event') return;
        if (!isset($_POST['rc_event_nonce']) || !wp_verify_nonce($_POST['rc_event_nonce'], 'rc_save_event_meta')) return;
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
        if (!current_user_can('edit_post', $post_id)) return;

        update_post_meta($post_id, '_rc_event_date', sanitize_text_field($_POST['rc_event_date'] ?? ''));
        update_post_meta($post_id, '_rc_event_time', sanitize_text_field($_POST['rc_event_time'] ?? ''));
        update_post_meta($post_id, '_rc_location_name', sanitize_text_field($_POST['rc_location_name'] ?? ''));
        update_post_meta($post_id, '_rc_location_address', sanitize_text_field($_POST['rc_location_address'] ?? ''));
        update_post_meta($post_id, '_rc_location_city', sanitize_text_field($_POST['rc_location_city'] ?? ''));

        // Max volunteers (empty = no limit)
        $raw = isset($_POST['rc_max_volunteers']) ? trim((string)$_POST['rc_max_volunteers']) : '';
        if ($raw === '') {
            delete_post_meta($post_id, '_rc_max_volunteers');
        } else {
            $max = max(0, (int)$raw);
            update_post_meta($post_id, '_rc_max_volunteers', $max);
        }
    }

    /* -------------------- Helpers -------------------- */
    private function event_start_ts($event_id) {
        $date = get_post_meta($event_id, '_rc_event_date', true);
        $time = get_post_meta($event_id, '_rc_event_time', true);
        if (!$date) return 0;
        if (!$time) $time = '00:00';
        $ts = strtotime($date . ' ' . $time);
        return $ts ? (int)$ts : 0;
    }

    private function can_unsubscribe($event_id) {
        $start = $this->event_start_ts($event_id);
        if (!$start) return false;
        $now = (int) current_time('timestamp');
        return $now <= ($start - 86400);
    }

    private function format_location($event_id) {
        $name    = trim((string)get_post_meta($event_id, '_rc_location_name', true));
        $address = trim((string)get_post_meta($event_id, '_rc_location_address', true));
        $city    = trim((string)get_post_meta($event_id, '_rc_location_city', true));
        $parts = array_filter([$name, $address, $city]);
        return implode('<br>', array_map('esc_html', $parts));
    }

    private function get_max_volunteers($event_id) {
        $v = get_post_meta($event_id, '_rc_max_volunteers', true);
        if ($v === '' || $v === null) return null; // no limit
        $n = (int)$v;
        if ($n <= 0) return null;
        return $n;
    }

    private function is_full($event_id) {
        $max = $this->get_max_volunteers($event_id);
        if ($max === null) return false;
        return $this->signup_count($event_id) >= $max;
    }

    private function is_signed_up($event_id, $user_id) {
        global $wpdb;
        $table = $this->table_name();
        $sql = $wpdb->prepare("SELECT id FROM $table WHERE event_id=%d AND user_id=%d LIMIT 1", $event_id, $user_id);
        return (bool) $wpdb->get_var($sql);
    }

    private function signup_count($event_id) {
        global $wpdb;
        $table = $this->table_name();
        $sql = $wpdb->prepare("SELECT COUNT(*) FROM $table WHERE event_id=%d", $event_id);
        return (int) $wpdb->get_var($sql);
    }

    private function do_signup($event_id, $user_id) {
        global $wpdb;
        $table = $this->table_name();

        // If already signed up: ok
        if ($this->is_signed_up($event_id, $user_id)) return true;

        // Check capacity
        if ($this->is_full($event_id)) return false;

        $res = $wpdb->insert($table, [
            'event_id' => (int)$event_id,
            'user_id' => (int)$user_id,
            'created_at' => current_time('mysql'),
        ], ['%d','%d','%s']);

        return (bool)$res;
    }

    private function do_unsubscribe($event_id, $user_id) {
        global $wpdb;
        $table = $this->table_name();
        if (!$this->can_unsubscribe($event_id)) return false;

        $res = $wpdb->delete($table, [
            'event_id' => (int)$event_id,
            'user_id' => (int)$user_id,
        ], ['%d','%d']);

        return $res !== false;
    }

    private function redirect_back($msg) {
        $ref = wp_get_referer();
        if (!$ref) $ref = home_url('/');
        $url = add_query_arg(['rc_msg' => rawurlencode($msg)], $ref);
        wp_safe_redirect($url);
        exit;
    }

    /* -------------------- Actions: signup/unsubscribe -------------------- */
    public function handle_actions() {
        if (empty($_GET['rc_action'])) return;

        $action = sanitize_text_field($_GET['rc_action']);
        $event_id = isset($_GET['event_id']) ? (int) $_GET['event_id'] : 0;
        if (!$event_id) return;

        if (!is_user_logged_in()) {
            $this->redirect_back('Log in om je aan te melden.');
        }

        $user_id = get_current_user_id();
        $nonce = $_GET['_wpnonce'] ?? '';
        if (!wp_verify_nonce($nonce, 'rc_' . $action . '_' . $event_id)) {
            wp_die('Ongeldige beveiligingscheck.');
        }

        if ($action === 'signup') {
            $ok = $this->do_signup($event_id, $user_id);
            if ($ok) {
                $this->redirect_back('Aangemeld ✅');
            } else {
                $this->redirect_back('Dit event zit vol. ❌');
            }
        }

        if ($action === 'unsubscribe') {
            if (!$this->can_unsubscribe($event_id)) {
                $contact = $this->get_contact_name();
                $this->redirect_back('Afmelden binnen 24 uur dat het evenement begint kan niet, graag contact opnemen met ' . $contact . '.');
            }
            $ok = $this->do_unsubscribe($event_id, $user_id);
            $this->redirect_back($ok ? 'Afgemeld ✅' : 'Afmelden mislukt ❌');
        }
    }

    /* -------------------- Shortcodes -------------------- */
    public function shortcode_events() {
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
                'type'    => 'DATE'
            ]]
        ]);

        if (!$q->have_posts()) {
            return $out . "<p>Geen toekomstige evenementen gevonden.</p>";
        }

        while ($q->have_posts()) {
            $q->the_post();
            $id = get_the_ID();

            $date = get_post_meta($id, '_rc_event_date', true);
            $time = get_post_meta($id, '_rc_event_time', true);
            $count = $this->signup_count($id);
            $max = $this->get_max_volunteers($id);

            $out .= "<div class='rc-card'>";
            $out .= "<h3>" . esc_html(get_the_title()) . "</h3>";

            if ($date) {
                $ts = strtotime($date);
                $pretty = date_i18n('l d-m-Y', $ts);
                $out .= "<p class='rc-meta'>" . esc_html($pretty);
                if ($time) $out .= " <small>om</small> " . esc_html($time);
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

            $out .= "<div>" . wpautop(wp_kses_post(get_the_content())) . "</div>";
            $out .= "<div class='rc-actions'>" . $this->render_buttons($id) . "</div>";
            $out .= "</div>";
        }

        wp_reset_postdata();
        return $out;
    }

    public function shortcode_my_signups() {
        if (!is_user_logged_in()) {
            $login = wp_login_url(get_permalink());
            return '<p>Log in om je aanmeldingen te zien. <a href="' . esc_url($login) . '">Inloggen</a></p>';
        }

        global $wpdb;
        $table = $this->table_name();
        $user_id = get_current_user_id();

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT event_id, created_at FROM $table WHERE user_id=%d ORDER BY created_at DESC",
            $user_id
        ));

        if (!$rows) return "<p>Je hebt nog geen aanmeldingen.</p>";

        $out = "<div class='rc-my'>";

        foreach ($rows as $r) {
            $event_id = (int) $r->event_id;
            if (get_post_type($event_id) !== 'rc_event') continue;

            $title = get_the_title($event_id);
            $date  = get_post_meta($event_id, '_rc_event_date', true);
            $time  = get_post_meta($event_id, '_rc_event_time', true);
            $loc   = $this->format_location($event_id);

            $out .= "<div class='rc-card'>";
            $out .= "<h3>" . esc_html($title) . "</h3>";

            if ($date) {
                $ts = strtotime($date);
                $pretty = date_i18n('l d-m-Y', $ts);
                $out .= "<p class='rc-meta'>" . esc_html($pretty);
                if ($time) $out .= " <small>om</small> " . esc_html($time);
                $out .= "</p>";
            }

            if ($loc) $out .= "<p class='rc-loc'><strong>Locatie:</strong><br>$loc</p>";

            $out .= "<div class='rc-actions'>" . $this->render_buttons($event_id, true) . "</div>";
            $out .= "</div>";
        }

        $out .= "</div>";
        return $out;
    }

    private function render_buttons($event_id, $compact=false) {
        if (!is_user_logged_in()) {
            $login = wp_login_url(get_permalink());
            return '<a class="rc-btn" href="' . esc_url($login) . '">Inloggen om aan te melden</a>';
        }

        $user_id = get_current_user_id();
        $signed  = $this->is_signed_up($event_id, $user_id);

        if (!$signed) {
            if ($this->is_full($event_id)) {
                return '<span class="rc-note">Dit event zit vol.</span>';
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
            'post_type' => 'rc_event',
            'numberposts' => 50,
            'orderby' => 'date',
            'order' => 'DESC',
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
            $date = get_post_meta($event_id, '_rc_event_date', true);
            $time = get_post_meta($event_id, '_rc_event_time', true);
            $max = $this->get_max_volunteers($event_id);

            $pretty = $date ? date_i18n('l d-m-Y', strtotime($date)) : '';
            $count = $this->signup_count($event_id);

            echo '<div style="border:1px solid #ddd;background:#fff;padding:14px;border-radius:10px;">';
            echo '<h2 style="margin:0 0 6px 0;">' . esc_html(get_the_title($event_id)) . '</h2>';
            if ($pretty) {
                echo '<div><strong>Wanneer:</strong> ' . esc_html($pretty) . ($time ? ' om ' . esc_html($time) : '') . '</div>';
            }
            echo '<div><strong>Aanmeldingen:</strong> ' . esc_html($count) . ($max !== null ? ' / ' . esc_html($max) : '') . '</div>';

            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT user_id, created_at FROM $table WHERE event_id=%d ORDER BY created_at ASC",
                $event_id
            ));

            if (!$rows) {
                echo '<div style="margin-top:8px;color:#666;">(Nog geen aanmeldingen)</div>';
            } else {
                echo '<ol style="margin-top:8px;">';
                foreach ($rows as $r) {
                    $u = get_user_by('id', (int)$r->user_id);
                    if (!$u) continue;
                    $name = $u->display_name ?: $u->user_login;
                    echo '<li>' . esc_html($name) . ' <span style="color:#666;">(' . esc_html($u->user_email) . ')</span></li>';
                }
                echo '</ol>';
            }

            echo '</div>';
        }

        echo '</div></div>';
    }

    /* -------------------- Styles -------------------- */
    public function enqueue_styles() {
        wp_add_inline_style('wp-block-library', "
            .rc-msg{padding:10px;border:1px solid #ddd;margin:10px 0;border-radius:10px;background:#fff;}
            .rc-card{border:1px solid #e5e5e5;padding:20px;border-radius:12px;margin-bottom:20px;background:#fafafa;}
            .rc-card h3{margin-top:0;}
            .rc-meta{font-weight:600;margin:0 0 8px 0;}
            .rc-meta small{font-weight:400;color:#666;}
            .rc-loc{margin:0 0 10px 0;color:#333;}
            .rc-actions{margin-top:12px;}
            .rc-btn{display:inline-block;padding:9px 14px;border-radius:8px;text-decoration:none;border:1px solid #2c7be5;background:#2c7be5;color:#fff;font-weight:600;}
            .rc-btn:hover{filter:brightness(0.95);}
            .rc-btn-secondary{background:#fff;color:#2c7be5;}
            .rc-note{display:inline-block;padding:10px;border:1px solid #ddd;border-radius:10px;background:#fff;color:#333;}
        ");
    }
}

new RepairCafePlanner();
?>

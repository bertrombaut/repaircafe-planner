<?php
if (!defined('ABSPATH')) exit;

function repaircafe_create_tables() {
    global $wpdb;

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';

    $charset_collate      = $wpdb->get_charset_collate();
    $expertises_table     = $wpdb->prefix . 'rcp_expertises';
    $user_expertises_tbl  = $wpdb->prefix . 'rcp_user_expertises';
    $event_expertises_tbl = $wpdb->prefix . 'rcp_event_expertises';

    $sql = [];

    // Expertises (globaal)
    $sql[] = "CREATE TABLE $expertises_table (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        name VARCHAR(100) NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY name (name)
    ) $charset_collate;";

    // User expertises
    $sql[] = "CREATE TABLE $user_expertises_tbl (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        user_id BIGINT UNSIGNED NOT NULL,
        expertise_id BIGINT UNSIGNED NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY user_expertise (user_id, expertise_id),
        KEY user_id (user_id),
        KEY expertise_id (expertise_id)
    ) $charset_collate;";

    // Event expertises + max
    $sql[] = "CREATE TABLE $event_expertises_tbl (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        event_id BIGINT UNSIGNED NOT NULL,
        expertise_id BIGINT UNSIGNED NOT NULL,
        max_volunteers INT UNSIGNED NOT NULL DEFAULT 1,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY event_expertise (event_id, expertise_id),
        KEY event_id (event_id),
        KEY expertise_id (expertise_id)
    ) $charset_collate;";

    foreach ($sql as $query) {
        dbDelta($query);
    }
}

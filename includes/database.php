<?php
if (!defined('ABSPATH')) exit;

function repaircafe_create_tables() {
    global $wpdb;

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';

    $charset_collate = $wpdb->get_charset_collate();

    $events_table        = $wpdb->prefix . 'rcp_events';
    $expertises_table    = $wpdb->prefix . 'rcp_expertises';
    $user_expertises_tbl = $wpdb->prefix . 'rcp_user_expertises';
    $registrations_table = $wpdb->prefix . 'rcp_registrations';

    $sql = [];

    $sql[] = "CREATE TABLE $events_table (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        title VARCHAR(200) NOT NULL,
        event_date DATE NOT NULL,
        start_time TIME NULL,
        end_time TIME NULL,
        location VARCHAR(200) NULL,
        description TEXT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id)
    ) $charset_collate;";

    $sql[] = "CREATE TABLE $expertises_table (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        name VARCHAR(100) NOT NULL,
        PRIMARY KEY (id),
        UNIQUE KEY name (name)
    ) $charset_collate;";

    $sql[] = "CREATE TABLE $user_expertises_tbl (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        user_id BIGINT UNSIGNED NOT NULL,
        expertise_id BIGINT UNSIGNED NOT NULL,
        PRIMARY KEY (id),
        UNIQUE KEY user_expertise (user_id, expertise_id)
    ) $charset_collate;";

    $sql[] = "CREATE TABLE $registrations_table (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        event_id BIGINT UNSIGNED NOT NULL,
        user_id BIGINT UNSIGNED NOT NULL,
        status VARCHAR(20) NOT NULL DEFAULT 'registered',
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY event_user (event_id, user_id)
    ) $charset_collate;";

    foreach ($sql as $query) {
        dbDelta($query);
    }
}

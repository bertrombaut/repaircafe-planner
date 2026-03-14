<?php
if (!defined('ABSPATH')) {
    exit;
}

function repaircafe_create_planner_table() {
    global $wpdb;

    $table_name = $wpdb->prefix . 'repaircafe_planner';
    $charset_collate = $wpdb->get_charset_collate();

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';

    $sql = "CREATE TABLE $table_name (
        id mediumint(9) NOT NULL AUTO_INCREMENT,
        customer_name varchar(255) NOT NULL,
        email varchar(255) DEFAULT '' NOT NULL,
        phone varchar(50) DEFAULT '' NOT NULL,
        device_type varchar(255) DEFAULT '' NOT NULL,
        repair_description text NOT NULL,
        appointment_date date NOT NULL,
        appointment_time time NOT NULL,
        status varchar(50) DEFAULT 'nieuw' NOT NULL,
        created_at datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
        PRIMARY KEY (id)
    ) $charset_collate;";

    dbDelta($sql);
}

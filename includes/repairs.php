<?php
if (!defined('ABSPATH')) {
    exit;
}

function repaircafe_add_repair($data) {
    global $wpdb;

    $table = $wpdb->prefix . 'repaircafe_planner';

    $wpdb->insert(
        $table,
        array(
            'customer_name' => $data['customer_name'],
            'email' => $data['email'],
            'phone' => $data['phone'],
            'device_type' => $data['device_type'],
            'repair_description' => $data['repair_description'],
            'appointment_date' => $data['appointment_date'],
            'appointment_time' => $data['appointment_time'],
            'status' => 'nieuw'
        )
    );
}

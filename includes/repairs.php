<?php
if ( ! defined('ABSPATH') ) exit;


/*
EVENTS
*/

function repaircafe_get_events() {

    global $wpdb;

    $table = $wpdb->prefix . 'rcp_events';

    return $wpdb->get_results(
        "SELECT * FROM {$table} ORDER BY event_date ASC, start_time ASC"
    );
}


function repaircafe_get_event( $event_id ) {

    global $wpdb;

    $table = $wpdb->prefix . 'rcp_events';

    return $wpdb->get_row(
        $wpdb->prepare(
            "SELECT * FROM {$table} WHERE id = %d",
            $event_id
        )
    );
}


function repaircafe_create_event( $data ) {

    global $wpdb;

    $table = $wpdb->prefix . 'rcp_events';

    $defaults = array(
        'title'       => '',
        'event_date'  => '',
        'start_time'  => '',
        'end_time'    => '',
        'location'    => '',
        'description' => '',
    );

    $data = wp_parse_args( $data, $defaults );

    $inserted = $wpdb->insert(
        $table,
        array(
            'title'       => sanitize_text_field( $data['title'] ),
            'event_date'  => sanitize_text_field( $data['event_date'] ),
            'start_time'  => sanitize_text_field( $data['start_time'] ),
            'end_time'    => sanitize_text_field( $data['end_time'] ),
            'location'    => sanitize_text_field( $data['location'] ),
            'description' => sanitize_textarea_field( $data['description'] ),
        ),
        array(
            '%s',
            '%s',
            '%s',
            '%s',
            '%s',
            '%s',
        )
    );

    if ( false === $inserted ) {
        return false;
    }

    return $wpdb->insert_id;
}


function repaircafe_delete_event( $event_id ) {

    global $wpdb;

    $table = $wpdb->prefix . 'rcp_events';

    return $wpdb->delete(
        $table,
        array( 'id' => absint( $event_id ) ),
        array( '%d' )
    );
}

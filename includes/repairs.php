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

    $wpdb->insert(
        $table,
        array(
            'title'       => sanitize_text_field($data['title']),
            'event_date'  => sanitize_text_field($data['event_date']),
            'start_time'  => sanitize_text_field($data['start_time']),
            'end_time'    => sanitize_text_field($data['end_time']),
            'location'    => sanitize_text_field($data['location']),
            'description' => sanitize_textarea_field($data['description']),
        )
    );

    return $wpdb->insert_id;
}


function repaircafe_delete_event( $event_id ) {

    global $wpdb;

    $table = $wpdb->prefix . 'rcp_events';

    return $wpdb->delete(
        $table,
        array('id' => absint($event_id))
    );
}


/*
EXPERTISES
*/

function repaircafe_get_expertises() {

    global $wpdb;

    $table = $wpdb->prefix . 'rcp_expertises';

    return $wpdb->get_results(
        "SELECT * FROM {$table} ORDER BY name ASC"
    );
}


function repaircafe_add_expertise( $name ) {

    global $wpdb;

    $table = $wpdb->prefix . 'rcp_expertises';

    $wpdb->insert(
        $table,
        array(
            'name' => sanitize_text_field($name)
        )
    );

    return $wpdb->insert_id;
}


function repaircafe_get_user_expertises( $user_id ) {

    global $wpdb;

    $table = $wpdb->prefix . 'rcp_user_expertises';

    return $wpdb->get_col(
        $wpdb->prepare(
            "SELECT expertise_id FROM {$table} WHERE user_id = %d",
            $user_id
        )
    );
}


function repaircafe_set_user_expertises( $user_id, $expertises ) {

    global $wpdb;

    $table = $wpdb->prefix . 'rcp_user_expertises';

    $wpdb->delete(
        $table,
        array('user_id' => $user_id)
    );

    if ( empty($expertises) ) {
        return;
    }

    foreach ( $expertises as $expertise_id ) {

        $wpdb->insert(
            $table,
            array(
                'user_id'      => $user_id,
                'expertise_id' => $expertise_id
            )
        );

    }

}

<?php
if ( ! defined('ABSPATH') ) exit;

/*
EVENTS
Deze functies sluiten nu aan op het bestaande rc_event post type
in plaats van de oude rcp_events tabel.
*/

function repaircafe_get_events() {

    return get_posts(array(
        'post_type'      => 'rc_event',
        'posts_per_page' => -1,
        'post_status'    => array('publish', 'future', 'draft', 'pending'),
        'meta_key'       => '_rc_event_date',
        'orderby'        => 'meta_value',
        'order'          => 'ASC',
    ));
}

function repaircafe_get_event( $event_id ) {

    $post = get_post( absint( $event_id ) );

    if ( ! $post || $post->post_type !== 'rc_event' ) {
        return null;
    }

    return $post;
}

function repaircafe_create_event( $data ) {
    return 0;
}
function repaircafe_delete_event( $event_id ) {

    global $wpdb;

    $event_id = absint( $event_id );

    if ( ! $event_id || get_post_type( $event_id ) !== 'rc_event' ) {
        return false;
    }

    $deleted = wp_delete_post( $event_id, true );

    if ( ! $deleted ) {
        return false;
    }

    $wpdb->delete(
        $wpdb->prefix . 'rcp_event_expertises',
        array( 'event_id' => $event_id ),
        array( '%d' )
    );

    return true;
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
    $name  = trim( sanitize_text_field( wp_unslash( $name ) ) );

    if ( $name === '' ) {
        return 0;
    }

    $exists = $wpdb->get_var(
        $wpdb->prepare(
            "SELECT id FROM {$table} WHERE name = %s LIMIT 1",
            $name
        )
    );

    if ( $exists ) {
        return (int) $exists;
    }

    $inserted = $wpdb->insert(
        $table,
        array(
            'name' => $name,
        ),
        array( '%s' )
    );

    if ( ! $inserted ) {
        return 0;
    }

    return (int) $wpdb->insert_id;
}

function repaircafe_get_user_expertises( $user_id ) {

    global $wpdb;

    $table   = $wpdb->prefix . 'rcp_user_expertises';
    $user_id = absint( $user_id );

    if ( ! $user_id ) {
        return array();
    }

    return $wpdb->get_col(
        $wpdb->prepare(
            "SELECT expertise_id FROM {$table} WHERE user_id = %d",
            $user_id
        )
    );
}

function repaircafe_set_user_expertises( $user_id, $expertises ) {

    global $wpdb;

    $table   = $wpdb->prefix . 'rcp_user_expertises';
    $user_id = absint( $user_id );

    if ( ! $user_id ) {
        return false;
    }

    $wpdb->delete(
        $table,
        array( 'user_id' => $user_id ),
        array( '%d' )
    );

    if ( empty( $expertises ) || ! is_array( $expertises ) ) {
        return true;
    }

    $expertises = array_unique( array_map( 'absint', $expertises ) );
    $expertises = array_filter( $expertises );

    foreach ( $expertises as $expertise_id ) {
        $wpdb->insert(
            $table,
            array(
                'user_id'      => $user_id,
                'expertise_id' => $expertise_id,
            ),
            array( '%d', '%d' )
        );
    }

    return true;
}

function repaircafe_get_user_expertise_names( $user_id ) {

    global $wpdb;

    $user_id    = absint( $user_id );
    $table_user = $wpdb->prefix . 'rcp_user_expertises';
    $table_exp  = $wpdb->prefix . 'rcp_expertises';

    if ( ! $user_id ) {
        return array();
    }

    return $wpdb->get_col(
        $wpdb->prepare(
            "SELECT e.name
             FROM {$table_user} ue
             INNER JOIN {$table_exp} e ON ue.expertise_id = e.id
             WHERE ue.user_id = %d
             ORDER BY e.name ASC",
            $user_id
        )
    );
}

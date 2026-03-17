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

    $title       = isset($data['title']) ? sanitize_text_field($data['title']) : '';
    $event_date  = isset($data['event_date']) ? sanitize_text_field($data['event_date']) : '';
    $start_time  = isset($data['start_time']) ? sanitize_text_field($data['start_time']) : '';
    $end_time    = isset($data['end_time']) ? sanitize_text_field($data['end_time']) : '';
    $location    = isset($data['location']) ? sanitize_text_field($data['location']) : '';
    $description = isset($data['description']) ? sanitize_textarea_field($data['description']) : '';

    $event_id = wp_insert_post(array(
        'post_type'    => 'rc_event',
        'post_status'  => 'publish',
        'post_title'   => $title,
        'post_content' => $description,
    ));

    if ( is_wp_error($event_id) || ! $event_id ) {
        return 0;
    }

    update_post_meta($event_id, '_rc_event_date', $event_date);
    update_post_meta($event_id, '_rc_event_time', $start_time);
    update_post_meta($event_id, '_rc_event_end_time', $end_time);
    update_post_meta($event_id, '_rc_location_name', $location);

    return (int) $event_id;
}


function repaircafe_delete_event( $event_id ) {

    $event_id = absint($event_id);

    if ( ! $event_id || get_post_type($event_id) !== 'rc_event' ) {
        return false;
    }

    return (bool) wp_delete_post($event_id, true);
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
    $name  = trim( sanitize_text_field( $name ) );

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

    $wpdb->insert(
        $table,
        array(
            'name' => $name
        ),
        array('%s')
    );

    return (int) $wpdb->insert_id;
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

    $table   = $wpdb->prefix . 'rcp_user_expertises';
    $user_id = absint($user_id);

    $wpdb->delete(
        $table,
        array('user_id' => $user_id),
        array('%d')
    );

    if ( empty($expertises) || ! is_array($expertises) ) {
        return;
    }

    $expertises = array_unique(array_map('absint', $expertises));

    foreach ( $expertises as $expertise_id ) {

        if ( $expertise_id <= 0 ) {
            continue;
        }

        $wpdb->insert(
            $table,
            array(
                'user_id'      => $user_id,
                'expertise_id' => $expertise_id
            ),
            array('%d', '%d')
        );
    }
}


function repaircafe_get_user_expertise_names( $user_id ) {

    global $wpdb;

    $table_user = $wpdb->prefix . 'rcp_user_expertises';
    $table_exp  = $wpdb->prefix . 'rcp_expertises';

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


/*
USER PROFILE: expertises koppelen aan vrijwilligers
*/

add_action('show_user_profile', 'repaircafe_render_user_expertises_field');
add_action('edit_user_profile', 'repaircafe_render_user_expertises_field');
add_action('personal_options_update', 'repaircafe_save_user_expertises_field');
add_action('edit_user_profile_update', 'repaircafe_save_user_expertises_field');


function repaircafe_render_user_expertises_field( $user ) {

    if ( ! $user || ! isset($user->ID) ) {
        return;
    }

    if ( ! current_user_can('edit_user', $user->ID) ) {
        return;
    }

    $expertises       = repaircafe_get_expertises();
    $user_expertises  = array_map('intval', repaircafe_get_user_expertises($user->ID));

    echo '<h2>Repair Café expertises</h2>';

    if ( empty($expertises) ) {
        echo '<p>Er zijn nog geen expertises aangemaakt. Voeg die eerst toe via Repair Cafés → Expertises.</p>';
        return;
    }

    wp_nonce_field('repaircafe_save_user_expertises', 'repaircafe_user_expertises_nonce');

    echo '<table class="form-table">';
    echo '<tr>';
    echo '<th><label for="repaircafe_expertises">Expertises</label></th>';
    echo '<td>';

    foreach ( $expertises as $expertise ) {
        $checked = in_array((int) $expertise->id, $user_expertises, true) ? 'checked' : '';

        echo '<label style="display:block;margin:0 0 8px 0;">';
        echo '<input type="checkbox" name="repaircafe_expertises[]" value="' . esc_attr($expertise->id) . '" ' . $checked . '> ';
        echo esc_html($expertise->name);
        echo '</label>';
    }

    echo '<p class="description">Selecteer hier welke expertises deze vrijwilliger heeft.</p>';
    echo '</td>';
    echo '</tr>';
    echo '</table>';
}


function repaircafe_save_user_expertises_field( $user_id ) {

    if ( ! current_user_can('edit_user', $user_id) ) {
        return false;
    }

    if (
        ! isset($_POST['repaircafe_user_expertises_nonce']) ||
        ! wp_verify_nonce($_POST['repaircafe_user_expertises_nonce'], 'repaircafe_save_user_expertises')
    ) {
        return false;
    }

    $expertises = isset($_POST['repaircafe_expertises']) ? (array) $_POST['repaircafe_expertises'] : array();

    repaircafe_set_user_expertises($user_id, $expertises);

    return true;
}

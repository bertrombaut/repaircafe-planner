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

function repaircafe_render_calendar() {

    $month = isset($_GET['rc_month']) ? intval($_GET['rc_month']) : date('n');
    $year  = isset($_GET['rc_year']) ? intval($_GET['rc_year']) : date('Y');

    if ($month < 1) { $month = 12; $year--; }
    if ($month > 12) { $month = 1; $year++; }

    $first_day_ts = strtotime("$year-$month-01");
    $days_in_month = date('t', $first_day_ts);
    $start_weekday = date('N', $first_day_ts);

    $events = repaircafe_get_events();
    $events_by_day = [];

    foreach ($events as $event) {
        $date = get_post_meta($event->ID, '_rc_event_date', true);
        if (!$date) continue;

        $event_ts = strtotime($date);
        if (date('n', $event_ts) == $month && date('Y', $event_ts) == $year) {
            $day = intval(date('j', $event_ts));
            $events_by_day[$day][] = $event;
        }
    }

    $prev_month = $month - 1;
    $prev_year  = $year;
    if ($prev_month < 1) { $prev_month = 12; $prev_year--; }

    $next_month = $month + 1;
    $next_year  = $year;
    if ($next_month > 12) { $next_month = 1; $next_year++; }

    $out = "<div class='rc-calendar'>";

    $out .= "<div style='display:flex;justify-content:space-between;margin-bottom:10px;'>";
    $out .= "<a class='rc-btn' href='?rc_month=$prev_month&rc_year=$prev_year'>← vorige maand</a>";
    $out .= "<strong>" . date_i18n('F Y', $first_day_ts) . "</strong>";
    $out .= "<a class='rc-btn' href='?rc_month=$next_month&rc_year=$next_year'>volgende maand →</a>";
    $out .= "</div>";

    $out .= "<div style='display:grid;grid-template-columns:repeat(7,1fr);gap:6px;'>";

    $days = ['ma','di','wo','do','vr','za','zo'];
    foreach ($days as $d) {
        $out .= "<div style='font-weight:600;text-align:center;'>$d</div>";
    }

    for ($i = 1; $i < $start_weekday; $i++) {
        $out .= "<div></div>";
    }

    for ($day = 1; $day <= $days_in_month; $day++) {

        $out .= "<div style='border:1px solid #ddd;min-height:80px;padding:6px;border-radius:8px;background:#fff;'>";
        $out .= "<div style='font-weight:600;margin-bottom:4px;'>$day</div>";

        if (isset($events_by_day[$day])) {
            foreach ($events_by_day[$day] as $event) {
                $link = get_permalink($event->ID);
                $out .= "<a href='$link' style='display:block;background:#f46e16;color:#fff;padding:4px 6px;border-radius:6px;font-size:12px;margin-bottom:4px;text-decoration:none;'>Repair Café</a>";
            }
        }

        $out .= "</div>";
    }

    $out .= "</div>";
    $out .= "</div>";

    return $out;
}
    

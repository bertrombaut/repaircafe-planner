<?php
if ( ! defined('ABSPATH') ) exit;

/*
|--------------------------------------------------------------------------
| EVENTS
|--------------------------------------------------------------------------
| Deze functies werken op het bestaande rc_event post type.
| Er wordt dus niet meer gewerkt met de oude rcp_events tabel.
*/

/**
 * Haalt alle Repair Café events op.
 *
 * Doet:
 * - leest alle rc_event berichten uit WordPress
 * - sorteert op eventdatum oplopend
 *
 * In:
 * - niets
 *
 * Uit:
 * - array met event posts
 *
 * Waarom zo gebouwd:
 * - events staan nu als normaal WordPress post type opgeslagen
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

/**
 * Haalt 1 event op via ID.
 *
 * Doet:
 * - controleert of het bericht bestaat
 * - controleert of het echt een rc_event is
 *
 * In:
 * - $event_id: ID van het event
 *
 * Uit:
 * - post object van het event
 * - null als het geen geldig rc_event is
 */
function repaircafe_get_event( $event_id ) {

    $post = get_post( absint( $event_id ) );

    if ( ! $post || $post->post_type !== 'rc_event' ) {
        return null;
    }

    return $post;
}

/**
 * Placeholder voor event aanmaken.
 *
 * Doet:
 * - nu nog niets
 *
 * In:
 * - $data: gegevens voor nieuw event
 *
 * Uit:
 * - 0
 *
 * Waarom zo gebouwd:
 * - functie bestaat alvast als vaste plek voor later
 */
function repaircafe_create_event( $data ) {
    return 0;
}

/**
 * Verwijdert een event definitief.
 *
 * Doet:
 * - controleert of het ID geldig is
 * - controleert of het om rc_event gaat
 * - verwijdert het event permanent
 * - verwijdert daarna gekoppelde expertise-regels van dat event
 *
 * In:
 * - $event_id: ID van het event
 *
 * Uit:
 * - true bij succes
 * - false bij mislukken
 *
 * Waarom zo gebouwd:
 * - na het verwijderen van het event moeten gekoppelde event-expertises ook weg
 */
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
|--------------------------------------------------------------------------
| EXPERTISES
|--------------------------------------------------------------------------
| Hier staan de functies voor expertises en koppelingen tussen
| gebruikers en expertises.
*/

/**
 * Haalt alle expertises op.
 *
 * Doet:
 * - leest alle rijen uit de expertisetabel
 * - sorteert alfabetisch op naam
 *
 * In:
 * - niets
 *
 * Uit:
 * - array met expertise-objecten
 */
function repaircafe_get_expertises() {

    global $wpdb;

    $table = $wpdb->prefix . 'rcp_expertises';

    return $wpdb->get_results(
        "SELECT * FROM {$table} ORDER BY name ASC"
    );
}

/**
 * Voegt een expertise toe als die nog niet bestaat.
 *
 * Doet:
 * - maakt de naam schoon
 * - stopt als de naam leeg is
 * - zoekt eerst of die expertise al bestaat
 * - voegt anders een nieuwe rij toe
 *
 * In:
 * - $name: naam van de expertise
 *
 * Uit:
 * - ID van bestaande of nieuwe expertise
 * - 0 bij mislukken
 *
 * Waarom zo gebouwd:
 * - dubbele expertises met dezelfde naam worden zo voorkomen
 */
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

/**
 * Haalt alle expertise-ID's van 1 gebruiker op.
 *
 * Doet:
 * - leest alle gekoppelde expertises van een gebruiker
 *
 * In:
 * - $user_id: ID van de gebruiker
 *
 * Uit:
 * - array met expertise-ID's
 */
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

/**
 * Zet de expertises van een gebruiker opnieuw.
 *
 * Doet:
 * - wist eerst alle bestaande koppelingen van die gebruiker
 * - zet daarna de nieuwe koppelingen terug
 *
 * In:
 * - $user_id: ID van de gebruiker
 * - $expertises: array met expertise-ID's
 *
 * Uit:
 * - true of false
 *
 * Waarom zo gebouwd:
 * - eerst leegmaken en daarna opnieuw vullen houdt de koppeling simpel en schoon
 */
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

/**
 * Haalt de namen van expertises van 1 gebruiker op.
 *
 * Doet:
 * - koppelt user_expertises aan expertises
 * - geeft alleen de namen terug
 * - sorteert alfabetisch
 *
 * In:
 * - $user_id: ID van de gebruiker
 *
 * Uit:
 * - array met namen van expertises
 */
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

/*
|--------------------------------------------------------------------------
| KALENDER
|--------------------------------------------------------------------------
| Bouwt de maandkalender op de voorkant van de site.
*/

/**
 * Rendert de maandkalender met events.
 *
 * Doet:
 * - leest maand en jaar uit de URL
 * - berekent vorige en volgende maand
 * - haalt alle events op
 * - zet events van de gekozen maand per dag klaar
 * - bouwt de HTML-kalender
 *
 * In:
 * - rc_month uit de URL
 * - rc_year uit de URL
 *
 * Uit:
 * - HTML-string van de kalender
 *
 * Waarom zo gebouwd:
 * - 1 maand tegelijk houdt het overzichtelijk
 * - events worden eerst per dag gegroepeerd zodat de weergave simpel blijft
 */
function repaircafe_render_calendar() {

    $month = isset($_GET['rc_month']) ? intval($_GET['rc_month']) : date('n');
    $year  = isset($_GET['rc_year']) ? intval($_GET['rc_year']) : date('Y');

    if ($month < 1) { $month = 12; $year--; }
    if ($month > 12) { $month = 1; $year++; }

    $first_day_ts  = strtotime("$year-$month-01");
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

    // Lege vakken voor de eerste dag van de maand zodat de kalender goed uitlijnt.
    for ($i = 1; $i < $start_weekday; $i++) {
        $out .= "<div></div>";
    }

    for ($day = 1; $day <= $days_in_month; $day++) {

        $out .= "<div style='border:1px solid #ddd;min-height:80px;padding:6px;border-radius:8px;background:#fff;'>";
        $out .= "<div style='font-weight:600;margin-bottom:4px;'>$day</div>";

        if (isset($events_by_day[$day])) {
            foreach ($events_by_day[$day] as $event) {
                $link = get_permalink($event->ID);

                // Er is normaal maar 1 event per dag, maar de code ondersteunt er toch meerdere.
                $out .= "<a href='$link' style='display:block;background:#f46e16;color:#fff;padding:4px 6px;border-radius:6px;font-size:12px;margin-bottom:4px;text-decoration:none;'>Repair Café</a>";
            }
        }

        $out .= "</div>";
    }

    $out .= "</div>";
    $out .= "</div>";

    return $out;
}

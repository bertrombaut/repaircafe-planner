<?php
if ( ! defined('ABSPATH') ) exit;

/*
|--------------------------------------------------------------------------
| ADMIN MENUS
|--------------------------------------------------------------------------
| Hier worden extra submenu's onder het Repair Café menu toegevoegd.
| Deze pagina's zijn bedoeld voor beheer van expertises en e-maillijsten.
*/

/**
 * Koppelt de submenu's aan het WordPress adminmenu.
 *
 * Doet:
 * - voegt onder Repair Cafés een submenu "Expertises" toe
 * - voegt onder Repair Cafés een submenu "E-maillijsten" toe
 *
 * In:
 * - niets
 *
 * Uit:
 * - niets
 */
add_action('admin_menu', 'rcp_admin_menu');

/**
 * Registreert de submenu's voor expertises en e-maillijsten.
 *
 * Doet:
 * - maakt de beheerpagina voor expertises bereikbaar
 * - maakt de beheerpagina voor e-maillijsten bereikbaar
 *
 * In:
 * - niets
 *
 * Uit:
 * - niets
 */
function rcp_admin_menu() {

    add_submenu_page(
        'edit.php?post_type=rc_event',
        'Expertises',
        'Expertises',
        'manage_options',
        'repaircafe_expertises',
        'repaircafe_admin_expertises_page'
    );

    add_submenu_page(
        'edit.php?post_type=rc_event',
        'E-maillijsten',
        'E-maillijsten',
        'manage_options',
        'repaircafe_email_lists',
        'repaircafe_admin_email_lists_page'
    );
}

/*
|--------------------------------------------------------------------------
| ADMIN PAGES
|--------------------------------------------------------------------------
| Beheerpagina's voor instellingen, onderhoud en communicatie.
*/

/**
 * Toont de beheerpagina voor expertises.
 *
 * Doet:
 * - controleert adminrechten
 * - verwerkt toevoegen van nieuwe expertise
 * - verwerkt verwijderen van expertise
 * - verwijdert bij verwijderen ook koppelingen met gebruikers en events
 * - toont formulier en overzichtstabel
 *
 * In:
 * - $_POST voor toevoegen
 * - $_GET voor verwijderen
 *
 * Uit:
 * - HTML
 */
function repaircafe_admin_expertises_page() {

    if ( ! current_user_can('manage_options') ) {
        return;
    }

    global $wpdb;
    $table = $wpdb->prefix . 'rcp_expertises';

    if (
        isset($_POST['repaircafe_add_expertise']) &&
        isset($_POST['repaircafe_expertise_nonce']) &&
        wp_verify_nonce(wp_unslash($_POST['repaircafe_expertise_nonce']), 'repaircafe_add_expertise')
    ) {
        $name = isset($_POST['expertise_name']) ? sanitize_text_field(wp_unslash($_POST['expertise_name'])) : '';
        $name = trim($name);

        if ( $name === '' ) {
            echo '<div class="notice notice-error"><p>Vul een expertise naam in.</p></div>';
        } else {
            $exists = $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT id FROM $table WHERE name = %s LIMIT 1",
                    $name
                )
            );

            if ( $exists ) {
                echo '<div class="notice notice-warning"><p>Deze expertise bestaat al.</p></div>';
            } else {
                $inserted = $wpdb->insert(
                    $table,
                    array(
                        'name' => $name,
                    ),
                    array('%s')
                );

                if ( $inserted ) {
                    echo '<div class="notice notice-success"><p>Expertise toegevoegd.</p></div>';
                } else {
                    echo '<div class="notice notice-error"><p>Opslaan mislukt.</p></div>';
                }
            }
        }
    }

    if (
        isset($_GET['delete_expertise']) &&
        isset($_GET['_wpnonce'])
    ) {
        $expertise_id = absint($_GET['delete_expertise']);
        $nonce        = wp_unslash($_GET['_wpnonce']);

        if (
            $expertise_id > 0 &&
            wp_verify_nonce($nonce, 'repaircafe_delete_expertise_' . $expertise_id)
        ) {
            $wpdb->delete($table, array('id' => $expertise_id), array('%d'));
            $wpdb->delete($wpdb->prefix . 'rcp_user_expertises', array('expertise_id' => $expertise_id), array('%d'));
            $wpdb->delete($wpdb->prefix . 'rcp_event_expertises', array('expertise_id' => $expertise_id), array('%d'));

            echo '<div class="notice notice-success"><p>Expertise verwijderd.</p></div>';
        }
    }

    $expertises = $wpdb->get_results("SELECT * FROM $table ORDER BY name ASC");

    echo '<div class="wrap">';
    echo '<h1>Expertises</h1>';

    echo '<form method="post" style="margin-bottom:20px;">';
    wp_nonce_field('repaircafe_add_expertise', 'repaircafe_expertise_nonce');

    echo '<table class="form-table">';
    echo '<tr>';
    echo '<th scope="row"><label for="expertise_name">Nieuwe expertise</label></th>';
    echo '<td>';
    echo '<input type="text" id="expertise_name" name="expertise_name" placeholder="Bijv. Elektronica" class="regular-text" required>';
    echo ' <button type="submit" name="repaircafe_add_expertise" class="button button-primary">Toevoegen</button>';
    echo '</td>';
    echo '</tr>';
    echo '</table>';
    echo '</form>';

    if ( empty($expertises) ) {
        echo '<p>Geen expertises.</p>';
    } else {
        echo '<table class="widefat striped" style="max-width:800px;">';
        echo '<thead>';
        echo '<tr>';
        echo '<th style="width:80px;">ID</th>';
        echo '<th>Naam</th>';
        echo '<th style="width:140px;">Actie</th>';
        echo '</tr>';
        echo '</thead>';
        echo '<tbody>';

        foreach ($expertises as $exp) {
            $delete_url = wp_nonce_url(
                admin_url('edit.php?post_type=rc_event&page=repaircafe_expertises&delete_expertise=' . (int) $exp->id),
                'repaircafe_delete_expertise_' . (int) $exp->id
            );

            echo '<tr>';
            echo '<td>' . esc_html($exp->id) . '</td>';
            echo '<td>' . esc_html($exp->name) . '</td>';
            echo '<td>';
            echo '<a href="' . esc_url($delete_url) . '" class="button button-small" onclick="return confirm(\'Weet je zeker dat je deze expertise wilt verwijderen?\');">Verwijderen</a>';
            echo '</td>';
            echo '</tr>';
        }

        echo '</tbody>';
        echo '</table>';
    }

    echo '</div>';
}

/**
 * Toont de beheerpagina voor e-maillijsten.
 *
 * Doet:
 * - controleert adminrechten
 * - toont filters voor lijsttype, event en expertise
 * - bouwt e-maillijst op basis van gekozen filter
 * - toont losse e-mails voor snel kopiëren
 * - toont tabel met naam, e-mail en expertises
 *
 * Lijsttypes:
 * - alle vrijwilligers
 * - aangemeld voor gekozen event
 * - zelfde expertise en nog niet aangemeld voor gekozen event
 * - zelfde expertise en wel aangemeld voor gekozen event
 *
 * In:
 * - $_GET['email_list_type']
 * - $_GET['event_id']
 * - $_GET['expertise_id']
 *
 * Uit:
 * - HTML
 */
function repaircafe_admin_email_lists_page() {

    if ( ! current_user_can('manage_options') ) {
        return;
    }

    $list_type    = isset($_GET['email_list_type']) ? sanitize_key(wp_unslash($_GET['email_list_type'])) : 'all_volunteers';
    $event_id     = isset($_GET['event_id']) ? absint($_GET['event_id']) : 0;
    $expertise_id = isset($_GET['expertise_id']) ? absint($_GET['expertise_id']) : 0;

    $allowed_types = array(
        'all_volunteers',
        'signed_up_event',
        'expertise_not_signed_up_event',
        'expertise_signed_up_event',
    );

    if ( ! in_array($list_type, $allowed_types, true) ) {
        $list_type = 'all_volunteers';
    }

    $events     = rcp_get_all_events_for_admin();
    $expertises = rcp_get_all_expertises_for_admin();
    $users      = array();
    $title      = '';

    if ( $list_type === 'all_volunteers' ) {
        $users = rcp_get_all_volunteers_for_email_list();
        $title = 'Alle vrijwilligers';
    }

    if ( $list_type === 'signed_up_event' && $event_id > 0 ) {
        $users = rcp_get_signed_up_volunteers_for_event($event_id);
        $title = 'Aangemeld voor event';
    }

    if ( $list_type === 'expertise_not_signed_up_event' && $event_id > 0 && $expertise_id > 0 ) {
        $users = rcp_get_volunteers_by_expertise_not_signed_up_for_event($event_id, $expertise_id);
        $title = 'Zelfde expertise en nog niet aangemeld';
    }

    if ( $list_type === 'expertise_signed_up_event' && $event_id > 0 && $expertise_id > 0 ) {
        $users = rcp_get_volunteers_by_expertise_signed_up_for_event($event_id, $expertise_id);
        $title = 'Zelfde expertise en wel aangemeld';
    }

    $emails = rcp_extract_emails_from_users($users);

    echo '<div class="wrap">';
    echo '<h1>E-maillijsten</h1>';

    echo '<form method="get" style="margin:20px 0;">';
    echo '<input type="hidden" name="post_type" value="rc_event">';
    echo '<input type="hidden" name="page" value="repaircafe_email_lists">';

    echo '<table class="form-table">';
    echo '<tr>';
    echo '<th scope="row"><label for="email_list_type">Type lijst</label></th>';
    echo '<td>';
    echo '<select name="email_list_type" id="email_list_type">';
    echo '<option value="all_volunteers"' . selected($list_type, 'all_volunteers', false) . '>Alle vrijwilligers</option>';
    echo '<option value="signed_up_event"' . selected($list_type, 'signed_up_event', false) . '>Aangemeld voor gekozen event</option>';
    echo '<option value="expertise_not_signed_up_event"' . selected($list_type, 'expertise_not_signed_up_event', false) . '>Zelfde expertise en nog niet aangemeld voor gekozen event</option>';
    echo '<option value="expertise_signed_up_event"' . selected($list_type, 'expertise_signed_up_event', false) . '>Zelfde expertise en wel aangemeld voor gekozen event</option>';
    echo '</select>';
    echo '</td>';
    echo '</tr>';

    echo '<tr>';
    echo '<th scope="row"><label for="event_id">Event</label></th>';
    echo '<td>';
    echo '<select name="event_id" id="event_id">';
    echo '<option value="0">Kies event</option>';

    foreach ($events as $event) {
        $event_label = $event->post_title;

        $event_date = get_post_meta($event->ID, '_rc_event_date', true);
        if ( $event_date ) {
            $event_label .= ' - ' . $event_date;
        }

        echo '<option value="' . esc_attr($event->ID) . '"' . selected($event_id, (int) $event->ID, false) . '>' . esc_html($event_label) . '</option>';
    }

    echo '</select>';
    echo '</td>';
    echo '</tr>';

    echo '<tr>';
    echo '<th scope="row"><label for="expertise_id">Expertise</label></th>';
    echo '<td>';
    echo '<select name="expertise_id" id="expertise_id">';
    echo '<option value="0">Kies expertise</option>';

    foreach ($expertises as $expertise) {
        echo '<option value="' . esc_attr($expertise->id) . '"' . selected($expertise_id, (int) $expertise->id, false) . '>' . esc_html($expertise->name) . '</option>';
    }

    echo '</select>';
    echo '</td>';
    echo '</tr>';
    echo '</table>';

    echo '<p><button type="submit" class="button button-primary">Toon e-maillijst</button></p>';
    echo '</form>';

    if ( $title !== '' ) {
        echo '<h2>' . esc_html($title) . '</h2>';
        echo '<p><strong>Aantal vrijwilligers:</strong> ' . esc_html(count($users)) . '</p>';

                  $mailto_bcc     = rawurlencode(implode(',', $emails));
        $mailto_subject = rawurlencode('Repair Café');
        $mailto_body    = rawurlencode('Hallo,');
        $mailto_link    = 'mailto:?bcc=' . $mailto_bcc . '&subject=' . $mailto_subject . '&body=' . $mailto_body;

        echo '<h3>Snelle lijst (alleen e-mails)</h3>';
        echo '<textarea id="rcp-email-list-compact" readonly style="width:100%;max-width:1000px;height:80px;font-size:13px;">' . esc_textarea(implode(', ', $emails)) . '</textarea>';

        echo '<p style="margin-top:10px;">';
        echo '<button type="button" class="button button-primary" onclick="var f=document.getElementById(\'rcp-email-list-compact\'); f.focus(); f.select(); document.execCommand(\'copy\'); this.innerText=\'Gekopieerd\';">Kopieer snelle lijst</button> ';
        echo '<a href="' . esc_url($mailto_link) . '" class="button button-primary">Open mail</a>';
        echo '</p>';

        echo '<hr style="margin:25px 0;">';

        echo '<h3>E-mailadressen (; gescheiden)</h3>';
        echo '<textarea id="rcp-email-list" readonly style="width:100%;max-width:1000px;height:120px;">' . esc_textarea(implode('; ', $emails)) . '</textarea>';

        echo '<h3 style="margin-top:20px;">E-mailadressen (, gescheiden - BCC)</h3>';
        echo '<textarea id="rcp-email-list-bcc" readonly style="width:100%;max-width:1000px;height:120px;">' . esc_textarea(implode(', ', $emails)) . '</textarea>';

        echo '<p style="margin-top:10px;">';
        echo '<button type="button" class="button button-secondary" onclick="var f=document.getElementById(\'rcp-email-list\'); f.focus(); f.select(); document.execCommand(\'copy\'); this.innerText=\'Gekopieerd\';">Kopieer ; lijst</button> ';
        echo '<button type="button" class="button button-secondary" onclick="var f=document.getElementById(\'rcp-email-list-bcc\'); f.focus(); f.select(); document.execCommand(\'copy\'); this.innerText=\'Gekopieerd\';">Kopieer BCC lijst</button>';
        echo '</p>';
        
        if ( empty($users) ) {
            echo '<p>Geen resultaten gevonden.</p>';
        } else {
            echo '<table class="widefat striped" style="max-width:1000px;margin-top:20px;">';
            echo '<thead>';
            echo '<tr>';
            echo '<th>Naam</th>';
            echo '<th>E-mail</th>';
            echo '<th>Expertises</th>';
            echo '</tr>';
            echo '</thead>';
            echo '<tbody>';

            foreach ($users as $user) {
                echo '<tr>';
                echo '<td>' . esc_html($user->display_name) . '</td>';
                echo '<td>' . esc_html($user->user_email) . '</td>';
                echo '<td>' . esc_html(rcp_get_user_expertise_names_string($user->ID)) . '</td>';
                echo '</tr>';
            }

            echo '</tbody>';
            echo '</table>';
        }
    }

    echo '</div>';
}

/*
|--------------------------------------------------------------------------
| EMAIL HELPERS
|--------------------------------------------------------------------------
| Hulpfuncties voor opbouw van e-maillijsten in de backend.
*/

/**
 * Haalt alle events op voor gebruik in de admin filters.
 *
 * Doet:
 * - haalt alle rc_event posts op
 * - sorteert op eventdatum
 *
 * In:
 * - niets
 *
 * Uit:
 * - array met event posts
 */
function rcp_get_all_events_for_admin() {
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
 * Haalt alle expertises op voor gebruik in de admin filters.
 *
 * Doet:
 * - haalt alle expertises uit de database
 *
 * In:
 * - niets
 *
 * Uit:
 * - array met expertise records
 */
function rcp_get_all_expertises_for_admin() {
    global $wpdb;

    return $wpdb->get_results("SELECT id, name FROM {$wpdb->prefix}rcp_expertises ORDER BY name ASC");
}

/**
 * Haalt alle vrijwilligers op.
 *
 * Doet:
 * - zoekt alle gebruikers die minstens 1 expertise hebben
 * - sorteert op naam
 *
 * In:
 * - niets
 *
 * Uit:
 * - array met WordPress gebruikers
 */
function rcp_get_all_volunteers_for_email_list() {
    global $wpdb;

    $user_ids = $wpdb->get_col("SELECT DISTINCT user_id FROM {$wpdb->prefix}rcp_user_expertises ORDER BY user_id ASC");

    if ( empty($user_ids) ) {
        return array();
    }

    $args = array(
        'include' => array_map('intval', $user_ids),
        'orderby' => 'display_name',
        'order'   => 'ASC',
        'fields'  => array('ID', 'display_name', 'user_email'),
    );

    return get_users($args);
}

/**
 * Haalt vrijwilligers op die zijn aangemeld voor een bepaald event.
 *
 * Doet:
 * - zoekt alle user_id's uit de aanmeldtabel van het gekozen event
 * - zet die om naar WordPress gebruikers
 *
 * In:
 * - $event_id: ID van het event
 *
 * Uit:
 * - array met WordPress gebruikers
 */
function rcp_get_signed_up_volunteers_for_event($event_id) {
    global $wpdb;

    $event_id = (int) $event_id;

    if ( $event_id <= 0 ) {
        return array();
    }

    $user_ids = $wpdb->get_col(
        $wpdb->prepare(
            "SELECT DISTINCT user_id FROM {$wpdb->prefix}rc_signups WHERE event_id = %d ORDER BY user_id ASC",
            $event_id
        )
    );

    if ( empty($user_ids) ) {
        return array();
    }

    $args = array(
        'include' => array_map('intval', $user_ids),
        'orderby' => 'display_name',
        'order'   => 'ASC',
        'fields'  => array('ID', 'display_name', 'user_email'),
    );

    return get_users($args);
}

/**
 * Haalt vrijwilligers op met een gekozen expertise die nog niet zijn aangemeld voor een bepaald event.
 *
 * Doet:
 * - zoekt alle gebruikers met die expertise
 * - sluit gebruikers uit die al aangemeld zijn voor het gekozen event
 *
 * In:
 * - $event_id: ID van het event
 * - $expertise_id: ID van de expertise
 *
 * Uit:
 * - array met WordPress gebruikers
 */
function rcp_get_volunteers_by_expertise_not_signed_up_for_event($event_id, $expertise_id) {
    global $wpdb;

    $event_id     = (int) $event_id;
    $expertise_id = (int) $expertise_id;

    if ( $event_id <= 0 || $expertise_id <= 0 ) {
        return array();
    }

    $user_ids = $wpdb->get_col(
        $wpdb->prepare(
            "
            SELECT DISTINCT ue.user_id
            FROM {$wpdb->prefix}rcp_user_expertises ue
            WHERE ue.expertise_id = %d
            AND ue.user_id NOT IN (
                SELECT s.user_id
                FROM {$wpdb->prefix}rc_signups s
                WHERE s.event_id = %d
            )
            ORDER BY ue.user_id ASC
            ",
            $expertise_id,
            $event_id
        )
    );

    if ( empty($user_ids) ) {
        return array();
    }

    $args = array(
        'include' => array_map('intval', $user_ids),
        'orderby' => 'display_name',
        'order'   => 'ASC',
        'fields'  => array('ID', 'display_name', 'user_email'),
    );

    return get_users($args);
}

/**
 * Haalt vrijwilligers op met een gekozen expertise die wel zijn aangemeld voor een bepaald event.
 *
 * Doet:
 * - zoekt alle gebruikers met die expertise
 * - houdt alleen gebruikers over die aangemeld zijn voor het gekozen event
 *
 * In:
 * - $event_id: ID van het event
 * - $expertise_id: ID van de expertise
 *
 * Uit:
 * - array met WordPress gebruikers
 */
function rcp_get_volunteers_by_expertise_signed_up_for_event($event_id, $expertise_id) {
    global $wpdb;

    $event_id     = (int) $event_id;
    $expertise_id = (int) $expertise_id;

    if ( $event_id <= 0 || $expertise_id <= 0 ) {
        return array();
    }

    $user_ids = $wpdb->get_col(
        $wpdb->prepare(
            "
            SELECT DISTINCT ue.user_id
            FROM {$wpdb->prefix}rcp_user_expertises ue
            INNER JOIN {$wpdb->prefix}rc_signups s ON s.user_id = ue.user_id
            WHERE ue.expertise_id = %d
            AND s.event_id = %d
            ORDER BY ue.user_id ASC
            ",
            $expertise_id,
            $event_id
        )
    );

    if ( empty($user_ids) ) {
        return array();
    }

    $args = array(
        'include' => array_map('intval', $user_ids),
        'orderby' => 'display_name',
        'order'   => 'ASC',
        'fields'  => array('ID', 'display_name', 'user_email'),
    );

    return get_users($args);
}

/**
 * Maakt van een gebruikerslijst een nette e-maillijst.
 *
 * Doet:
 * - haalt e-mailadressen uit gebruikersrecords
 * - verwijdert lege en dubbele waarden
 *
 * In:
 * - $users: array met gebruikers
 *
 * Uit:
 * - array met e-mailadressen
 */
function rcp_extract_emails_from_users($users) {
    $emails = array();

    if ( empty($users) ) {
        return $emails;
    }

    foreach ($users as $user) {
        if ( ! empty($user->user_email) ) {
            $emails[] = $user->user_email;
        }
    }

    $emails = array_unique($emails);
    $emails = array_values($emails);

    return $emails;
}

/**
 * Haalt de expertisenamen van 1 gebruiker op als tekst.
 *
 * Doet:
 * - zoekt alle expertises van 1 gebruiker
 * - zet die om naar 1 komma-gescheiden tekst
 *
 * In:
 * - $user_id: ID van de gebruiker
 *
 * Uit:
 * - string met expertisenamen
 */
function rcp_get_user_expertise_names_string($user_id) {
    global $wpdb;

    $names = $wpdb->get_col(
        $wpdb->prepare(
            "
            SELECT e.name
            FROM {$wpdb->prefix}rcp_expertises e
            INNER JOIN {$wpdb->prefix}rcp_user_expertises ue ON ue.expertise_id = e.id
            WHERE ue.user_id = %d
            ORDER BY e.name ASC
            ",
            $user_id
        )
    );

    if ( empty($names) ) {
        return '';
    }

    return implode(', ', $names);
}

/*
|--------------------------------------------------------------------------
| USER EXPERTISES
|--------------------------------------------------------------------------
| Hier worden expertises gekoppeld aan WordPress gebruikers.
*/

/**
 * Toont het expertise-veld op het gebruikersprofiel.
 *
 * Doet:
 * - haalt alle beschikbare expertises op
 * - haalt de huidige selecties van de gebruiker op
 * - toont checkboxen per expertise
 *
 * In:
 * - $user: WordPress gebruiker
 *
 * Uit:
 * - HTML
 */
add_action('show_user_profile', 'rcp_user_expertises_field');
add_action('edit_user_profile', 'rcp_user_expertises_field');

/**
 * Rendert de expertise-checkboxen op het gebruikersprofiel.
 *
 * Doet:
 * - toont alle expertises
 * - zet bestaande koppelingen alvast aangevinkt
 *
 * In:
 * - $user: WordPress gebruiker
 *
 * Uit:
 * - HTML
 */
function rcp_user_expertises_field($user) {

    global $wpdb;

    $expertises = $wpdb->get_results("SELECT id, name FROM {$wpdb->prefix}rcp_expertises ORDER BY name ASC");
    $selected   = $wpdb->get_col(
        $wpdb->prepare(
            "SELECT expertise_id FROM {$wpdb->prefix}rcp_user_expertises WHERE user_id = %d",
            $user->ID
        )
    );

    echo '<h2>Expertises</h2>';

    if ( empty($expertises) ) {
        echo '<p>Er zijn nog geen expertises aangemaakt.</p>';
        return;
    }

    echo '<table class="form-table">';
    echo '<tr>';
    echo '<th>Kies expertises</th>';
    echo '<td>';

    foreach ($expertises as $exp) {
        $checked = in_array((string) $exp->id, array_map('strval', $selected), true) ? 'checked' : '';
        echo '<label style="display:block;margin-bottom:6px;">';
        echo '<input type="checkbox" name="rcp_user_expertises[]" value="' . esc_attr($exp->id) . '" ' . $checked . '> ';
        echo esc_html($exp->name);
        echo '</label>';
    }

    echo '</td>';
    echo '</tr>';
    echo '</table>';
}

/**
 * Koppelt opslaan van gebruikersexpertises aan profiel-updates.
 *
 * Doet:
 * - slaat expertises op als profiel wordt bewaard
 *
 * In:
 * - niets
 *
 * Uit:
 * - niets
 */
add_action('personal_options_update', 'rcp_save_user_expertises');
add_action('edit_user_profile_update', 'rcp_save_user_expertises');

/**
 * Slaat de expertises van een gebruiker op.
 *
 * Doet:
 * - controleert bewerkrechten
 * - wist eerst bestaande koppelingen
 * - zet daarna de nieuwe koppelingen terug
 *
 * In:
 * - $user_id: ID van de gebruiker
 * - $_POST['rcp_user_expertises']
 *
 * Uit:
 * - niets
 */
function rcp_save_user_expertises($user_id) {

    if ( ! current_user_can('edit_user', $user_id) ) {
        return;
    }

    global $wpdb;
    $table = $wpdb->prefix . 'rcp_user_expertises';

    $wpdb->delete($table, array('user_id' => $user_id), array('%d'));

    if ( empty($_POST['rcp_user_expertises']) || ! is_array($_POST['rcp_user_expertises']) ) {
        return;
    }

    $expertise_ids = array_map('intval', wp_unslash($_POST['rcp_user_expertises']));
    $expertise_ids = array_filter($expertise_ids);

    foreach ($expertise_ids as $exp_id) {
        $wpdb->insert(
            $table,
            array(
                'user_id'      => (int) $user_id,
                'expertise_id' => (int) $exp_id,
            ),
            array('%d', '%d')
        );
    }
}

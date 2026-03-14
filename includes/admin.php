<?php
if ( ! defined('ABSPATH') ) exit;


/*
ADMIN MENUS
*/

add_action('admin_menu', 'rcp_admin_menu');
add_action('admin_menu', 'repaircafe_admin_menu');


function rcp_admin_menu() {

    add_submenu_page(
        'edit.php?post_type=rc_event',
        'Aanmeldingen',
        'Aanmeldingen',
        'manage_options',
        'rc_signups',
        'rcp_admin_signups_page'
    );

    add_submenu_page(
        'edit.php?post_type=rc_event',
        'Instellingen',
        'Instellingen',
        'manage_options',
        'rc_settings',
        'rcp_settings_page'
    );

}


function repaircafe_admin_menu() {

    add_menu_page(
        'Repair Café',
        'Repair Café',
        'read',
        'repaircafe',
        'repaircafe_admin_events_page',
        'dashicons-hammer',
        26
    );

    add_submenu_page(
        'repaircafe',
        'Reparatiedagen',
        'Reparatiedagen',
        'read',
        'repaircafe',
        'repaircafe_admin_events_page'
    );

    add_submenu_page(
        'repaircafe',
        'Expertises',
        'Expertises',
        'manage_options',
        'repaircafe_expertises',
        'repaircafe_admin_expertises_page'
    );

}


/*
ADMIN PAGES
*/

function rcp_settings_page() {

    echo '<div class="wrap">';
    echo '<h1>Repair Café instellingen</h1>';
    echo '<p>Instellingen worden hier later verder uitgewerkt.</p>';
    echo '</div>';

}


function rcp_admin_signups_page() {

    echo '<div class="wrap">';
    echo '<h1>Aanmeldingen</h1>';
    echo '<p>Admin pagina tijdelijk actief.</p>';
    echo '</div>';

}


function repaircafe_admin_events_page() {

    if ( ! is_user_logged_in() ) {
        return;
    }

    if (
        isset($_POST['repaircafe_add_event']) &&
        isset($_POST['repaircafe_event_nonce']) &&
        wp_verify_nonce($_POST['repaircafe_event_nonce'], 'repaircafe_add_event')
    ) {

        $event_id = repaircafe_create_event(array(
            'title'       => isset($_POST['title']) ? $_POST['title'] : '',
            'event_date'  => isset($_POST['event_date']) ? $_POST['event_date'] : '',
            'start_time'  => isset($_POST['start_time']) ? $_POST['start_time'] : '',
            'end_time'    => isset($_POST['end_time']) ? $_POST['end_time'] : '',
            'location'    => isset($_POST['location']) ? $_POST['location'] : '',
            'description' => isset($_POST['description']) ? $_POST['description'] : '',
        ));

        if ( $event_id ) {
            echo '<div class="notice notice-success"><p>Reparatiedag toegevoegd.</p></div>';
        } else {
            echo '<div class="notice notice-error"><p>Opslaan mislukt.</p></div>';
        }
    }

    if (
        isset($_GET['delete_event']) &&
        isset($_GET['_wpnonce']) &&
        wp_verify_nonce($_GET['_wpnonce'], 'repaircafe_delete_event_' . absint($_GET['delete_event']))
    ) {

        repaircafe_delete_event(absint($_GET['delete_event']));
        echo '<div class="notice notice-success"><p>Reparatiedag verwijderd.</p></div>';
    }

    $events = repaircafe_get_events();

    echo '<div class="wrap">';
    echo '<h1>Reparatiedagen</h1>';

    echo '<h2>Nieuwe reparatiedag</h2>';

    echo '<form method="post">';

    wp_nonce_field('repaircafe_add_event', 'repaircafe_event_nonce');

    echo '<table class="form-table">';

    echo '<tr>';
    echo '<th>Titel</th>';
    echo '<td><input type="text" name="title" class="regular-text" required></td>';
    echo '</tr>';

    echo '<tr>';
    echo '<th>Datum</th>';
    echo '<td><input type="date" name="event_date" required></td>';
    echo '</tr>';

    echo '<tr>';
    echo '<th>Starttijd</th>';
    echo '<td><input type="time" name="start_time"></td>';
    echo '</tr>';

    echo '<tr>';
    echo '<th>Eindtijd</th>';
    echo '<td><input type="time" name="end_time"></td>';
    echo '</tr>';

    echo '<tr>';
    echo '<th>Locatie</th>';
    echo '<td><input type="text" name="location" class="regular-text"></td>';
    echo '</tr>';

    echo '<tr>';
    echo '<th>Beschrijving</th>';
    echo '<td><textarea name="description" rows="4" class="large-text"></textarea></td>';
    echo '</tr>';

    echo '</table>';

    echo '<p><button type="submit" name="repaircafe_add_event" class="button button-primary">Opslaan</button></p>';

    echo '</form>';

    echo '<hr>';

    echo '<h2>Bestaande reparatiedagen</h2>';

    if ( empty($events) ) {

        echo '<p>Nog geen reparatiedagen.</p>';

    } else {

        echo '<table class="widefat striped">';
        echo '<thead>';
        echo '<tr>';
        echo '<th>Titel</th>';
        echo '<th>Datum</th>';
        echo '<th>Tijd</th>';
        echo '<th>Locatie</th>';
        echo '<th></th>';
        echo '</tr>';
        echo '</thead>';
        echo '<tbody>';

        foreach ( $events as $event ) {

            $delete_url = wp_nonce_url(
                admin_url('admin.php?page=repaircafe&delete_event=' . $event->id),
                'repaircafe_delete_event_' . $event->id
            );

            echo '<tr>';
            echo '<td>' . esc_html($event->title) . '</td>';
            echo '<td>' . esc_html($event->event_date) . '</td>';
            echo '<td>' . esc_html(trim($event->start_time . ' - ' . $event->end_time, ' -')) . '</td>';
            echo '<td>' . esc_html($event->location) . '</td>';
            echo '<td><a href="' . esc_url($delete_url) . '" onclick="return confirm(\'Verwijderen?\')">Verwijderen</a></td>';
            echo '</tr>';

        }

        echo '</tbody>';
        echo '</table>';

    }

    echo '</div>';
}


function repaircafe_admin_expertises_page() {

    if ( ! current_user_can('manage_options') ) {
        return;
    }

    global $wpdb;
    $table = $wpdb->prefix . 'rcp_expertises';

    if (
        isset($_POST['repaircafe_add_expertise']) &&
        isset($_POST['repaircafe_expertise_nonce']) &&
        wp_verify_nonce($_POST['repaircafe_expertise_nonce'], 'repaircafe_add_expertise')
    ) {
        $name = isset($_POST['expertise_name']) ? sanitize_text_field($_POST['expertise_name']) : '';
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
        isset($_GET['_wpnonce']) &&
        wp_verify_nonce($_GET['_wpnonce'], 'repaircafe_delete_expertise_' . absint($_GET['delete_expertise']))
    ) {
        $expertise_id = absint($_GET['delete_expertise']);

        $wpdb->delete(
            $table,
            array('id' => $expertise_id),
            array('%d')
        );

        $wpdb->delete(
            $wpdb->prefix . 'rcp_user_expertises',
            array('expertise_id' => $expertise_id),
            array('%d')
        );

        $wpdb->delete(
            $wpdb->prefix . 'rcp_event_expertises',
            array('expertise_id' => $expertise_id),
            array('%d')
        );

        echo '<div class="notice notice-success"><p>Expertise verwijderd.</p></div>';
    }

    $expertises = $wpdb->get_results("SELECT * FROM $table ORDER BY name ASC");

    echo '<div class="wrap">';
    echo '<h1>Expertises</h1>';
    echo '<p>Hier beheer je de vaste lijst met expertises voor alle komende evenementen.</p>';

    echo '<h2>Nieuwe expertise</h2>';
    echo '<form method="post">';
    wp_nonce_field('repaircafe_add_expertise', 'repaircafe_expertise_nonce');

    echo '<table class="form-table">';
    echo '<tr>';
    echo '<th>Naam</th>';
    echo '<td><input type="text" name="expertise_name" class="regular-text" required placeholder="Bijv. Elektra"></td>';
    echo '</tr>';
    echo '</table>';

    echo '<p><button type="submit" name="repaircafe_add_expertise" class="button button-primary">Toevoegen</button></p>';
    echo '</form>';

    echo '<hr>';

    echo '<h2>Bestaande expertises</h2>';

    if ( empty($expertises) ) {
        echo '<p>Nog geen expertises toegevoegd.</p>';
    } else {
        echo '<table class="widefat striped">';
        echo '<thead>';
        echo '<tr>';
        echo '<th>Naam</th>';
        echo '<th style="width:140px;">Actie</th>';
        echo '</tr>';
        echo '</thead>';
        echo '<tbody>';

        foreach ( $expertises as $expertise ) {
            $delete_url = wp_nonce_url(
                admin_url('admin.php?page=repaircafe_expertises&delete_expertise=' . $expertise->id),
                'repaircafe_delete_expertise_' . $expertise->id
            );

            echo '<tr>';
            echo '<td>' . esc_html($expertise->name) . '</td>';
            echo '<td><a href="' . esc_url($delete_url) . '" onclick="return confirm(\'Expertise verwijderen?\')">Verwijderen</a></td>';
            echo '</tr>';
        }

        echo '</tbody>';
        echo '</table>';
    }

    echo '</div>';
}

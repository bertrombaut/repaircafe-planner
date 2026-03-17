<?php
if ( ! defined('ABSPATH') ) exit;

/*
ADMIN MENUS
*/

add_action('admin_menu', 'rcp_admin_menu');

function rcp_admin_menu() {

    add_submenu_page(
        'edit.php?post_type=rc_event',
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
                admin_url('edit.php?post_type=rc_event&page=repaircafe_expertises&delete_expertise=' . $expertise->id),
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


/*
EVENT METABOX: EXPERTISES PER EVENEMENT
*/

add_action('add_meta_boxes', 'rcp_add_event_expertises_metabox');

function rcp_add_event_expertises_metabox() {
    add_meta_box(
        'rcp_event_expertises_metabox',
        'Expertises voor dit evenement',
        'rcp_render_event_expertises_metabox',
        'rc_event',
        'side',
        'default'
    );
}

function rcp_render_event_expertises_metabox($post) {
    wp_nonce_field('rcp_save_event_expertises', 'rcp_event_expertises_nonce');

    global $wpdb;

    $expertises_table = $wpdb->prefix . 'rcp_expertises';
    $event_expertises_table = $wpdb->prefix . 'rcp_event_expertises';

    $expertises = $wpdb->get_results("SELECT * FROM $expertises_table ORDER BY name ASC");

    $selected_ids = $wpdb->get_col(
        $wpdb->prepare(
            "SELECT expertise_id FROM $event_expertises_table WHERE event_id = %d",
            $post->ID
        )
    );

    if ( empty($expertises) ) {
        echo '<p>Er zijn nog geen expertises aangemaakt.</p>';
        echo '<p>Voeg die eerst toe via het menu <strong>Expertises</strong>.</p>';
        return;
    }

    echo '<p>Vink aan welke expertises nodig of beschikbaar zijn voor dit evenement.</p>';

    foreach ( $expertises as $expertise ) {
        echo '<p style="margin:0 0 8px;">';
        echo '<label>';
        echo '<input type="checkbox" name="rcp_event_expertises[]" value="' . esc_attr($expertise->id) . '" ' . checked(in_array($expertise->id, $selected_ids), true, false) . '> ';
        echo esc_html($expertise->name);
        echo '</label>';
        echo '</p>';
    }
}


/*
SAVE EVENT EXPERTISES
*/

add_action('save_post', 'rcp_save_event_expertises');

function rcp_save_event_expertises($post_id) {

    if ( get_post_type($post_id) !== 'rc_event' ) {
        return;
    }

    if ( ! isset($_POST['rcp_event_expertises_nonce']) ) {
        return;
    }

    if ( ! wp_verify_nonce($_POST['rcp_event_expertises_nonce'], 'rcp_save_event_expertises') ) {
        return;
    }

    if ( defined('DOING_AUTOSAVE') && DOING_AUTOSAVE ) {
        return;
    }

    if ( wp_is_post_revision($post_id) ) {
        return;
    }

    if ( ! current_user_can('edit_post', $post_id) ) {
        return;
    }

    global $wpdb;

    $event_expertises_table = $wpdb->prefix . 'rcp_event_expertises';

    $wpdb->delete(
        $event_expertises_table,
        array('event_id' => $post_id),
        array('%d')
    );

    if ( isset($_POST['rcp_event_expertises']) && is_array($_POST['rcp_event_expertises']) ) {
        $expertise_ids = array_map('absint', $_POST['rcp_event_expertises']);
        $expertise_ids = array_unique($expertise_ids);

        foreach ( $expertise_ids as $expertise_id ) {
            if ( $expertise_id > 0 ) {
                $wpdb->insert(
                    $event_expertises_table,
                    array(
                        'event_id'     => $post_id,
                        'expertise_id' => $expertise_id,
                    ),
                    array('%d', '%d')
                );
            }
        }
    }
}

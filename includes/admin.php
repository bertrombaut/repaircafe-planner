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


/*
USER EXPERTISES
*/

add_action('show_user_profile', 'rcp_user_expertises_field');
add_action('edit_user_profile', 'rcp_user_expertises_field');

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

add_action('personal_options_update', 'rcp_save_user_expertises');
add_action('edit_user_profile_update', 'rcp_save_user_expertises');

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

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
                    array('name' => $name),
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

        $wpdb->delete($table, ['id' => $expertise_id], ['%d']);
        $wpdb->delete($wpdb->prefix . 'rcp_user_expertises', ['expertise_id' => $expertise_id], ['%d']);
        $wpdb->delete($wpdb->prefix . 'rcp_event_expertises', ['expertise_id' => $expertise_id], ['%d']);

        echo '<div class="notice notice-success"><p>Expertise verwijderd.</p></div>';
    }

    $expertises = $wpdb->get_results("SELECT * FROM $table ORDER BY name ASC");

    echo '<div class="wrap">';
    echo '<h1>Expertises</h1>';

    echo '<form method="post">';
    wp_nonce_field('repaircafe_add_expertise', 'repaircafe_expertise_nonce');

    echo '<input type="text" name="expertise_name" placeholder="Nieuwe expertise" required>';
    echo '<button type="submit" name="repaircafe_add_expertise" class="button button-primary">Toevoegen</button>';
    echo '</form>';

    echo '<hr>';

    if ( empty($expertises) ) {
        echo '<p>Geen expertises.</p>';
    } else {
        foreach ($expertises as $exp) {
            echo '<p>' . esc_html($exp->name) . '</p>';
        }
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
    $selected = $wpdb->get_col($wpdb->prepare(
        "SELECT expertise_id FROM {$wpdb->prefix}rcp_user_expertises WHERE user_id = %d",
        $user->ID
    ));

    echo '<h2>Expertises</h2>';

    foreach ($expertises as $exp) {
        $checked = in_array($exp->id, $selected) ? 'checked' : '';
        echo '<label><input type="checkbox" name="rcp_user_expertises[]" value="' . esc_attr($exp->id) . '" ' . $checked . '> ' . esc_html($exp->name) . '</label><br>';
    }
}

add_action('personal_options_update', 'rcp_save_user_expertises');
add_action('edit_user_profile_update', 'rcp_save_user_expertises');

function rcp_save_user_expertises($user_id) {

    if ( ! current_user_can('edit_user', $user_id) ) return;

    global $wpdb;
    $table = $wpdb->prefix . 'rcp_user_expertises';

    $wpdb->delete($table, ['user_id' => $user_id], ['%d']);

    if (!empty($_POST['rcp_user_expertises'])) {
        foreach ($_POST['rcp_user_expertises'] as $exp_id) {
            $wpdb->insert($table, [
                'user_id' => $user_id,
                'expertise_id' => (int)$exp_id
            ]);
        }
    }
}

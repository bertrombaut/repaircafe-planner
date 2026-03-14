<?php
if ( ! defined('ABSPATH') ) exit;

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
        'repaircafe',
        'repaircafe_admin_events_page'
    );

}


function rcp_settings_page() {

    echo '<div class="wrap"><h1>Repair Café instellingen</h1>';
    echo '<p>Instellingen worden hier later verder uitgewerkt.</p>';
    echo '</div>';

}


function rcp_admin_signups_page() {

    echo '<div class="wrap"><h1>Aanmeldingen</h1>';
    echo '<p>Admin pagina tijdelijk actief.</p>';
    echo '</div>';

}


function repaircafe_admin_events_page() {

    echo '<div class="wrap">';
    echo '<h1>Reparatiedagen</h1>';
    echo '<p>Events worden hier straks beheerd.</p>';
    echo '</div>';

}

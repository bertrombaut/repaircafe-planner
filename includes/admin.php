<?php
if ( ! defined('ABSPATH') ) exit;

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

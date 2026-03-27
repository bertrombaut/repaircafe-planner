<?php
if (!defined('ABSPATH')) exit;

/**
 * Maakt of werkt alle extra plugin-tabellen bij.
 *
 * Doet:
 * - laadt dbDelta
 * - maakt tabellen voor:
 *   - globale expertises
 *   - koppeling gebruiker ↔ expertise
 *   - koppeling event ↔ expertise met maximum vrijwilligers
 * - voert elke CREATE TABLE query uit
 *
 * In:
 * - niets
 *
 * Uit:
 * - niets
 *
 * Waarom zo gebouwd:
 * - alle extra tabellen staan centraal in 1 functie
 * - dbDelta kan veilig aanmaken en bijwerken
 */
function repaircafe_create_tables() {
    global $wpdb;

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';

    $charset_collate      = $wpdb->get_charset_collate();
    $expertises_table     = $wpdb->prefix . 'rcp_expertises';
    $user_expertises_tbl  = $wpdb->prefix . 'rcp_user_expertises';
    $event_expertises_tbl = $wpdb->prefix . 'rcp_event_expertises';

    $sql = [];

    // Tabel met alle beschikbare expertises die globaal in de plugin gebruikt worden.
    $sql[] = "CREATE TABLE $expertises_table (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        name VARCHAR(100) NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY name (name)
    ) $charset_collate;";

    // Koppelt gebruikers aan 1 of meer expertises.
    // UNIQUE KEY voorkomt dat dezelfde expertise dubbel aan dezelfde gebruiker hangt.
    $sql[] = "CREATE TABLE $user_expertises_tbl (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        user_id BIGINT UNSIGNED NOT NULL,
        expertise_id BIGINT UNSIGNED NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY user_expertise (user_id, expertise_id),
        KEY user_id (user_id),
        KEY expertise_id (expertise_id)
    ) $charset_collate;";

    // Koppelt expertises aan een specifiek event.
    // Hier wordt ook per expertise het maximum aantal vrijwilligers opgeslagen.
    $sql[] = "CREATE TABLE $event_expertises_tbl (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        event_id BIGINT UNSIGNED NOT NULL,
        expertise_id BIGINT UNSIGNED NOT NULL,
        max_volunteers INT UNSIGNED NOT NULL DEFAULT 1,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY event_expertise (event_id, expertise_id),
        KEY event_id (event_id),
        KEY expertise_id (expertise_id)
    ) $charset_collate;";

    foreach ($sql as $query) {
        dbDelta($query);
    }
}

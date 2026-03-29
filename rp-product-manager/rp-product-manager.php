<?php
/**
 * Plugin Name:  RP Product Manager
 * Plugin URI:   https://resellpiacenza.it
 * Description:  WooCommerce CRUD layer + Admin UI per ResellPiacenza. Gestione prodotti, varianti e ricerca.
 * Version:      1.0.0
 * Author:       ResellPiacenza
 * License:      Private
 * Requires PHP: 8.0
 * Requires at least: 6.0
 */

defined( 'ABSPATH' ) || exit;

define( 'RP_PM_VERSION', '1.0.0' );
define( 'RP_PM_DIR',     plugin_dir_path( __FILE__ ) );

// Carica i moduli nell'ordine corretto
require_once RP_PM_DIR . 'includes/crud.php';        // funzioni rp_* base
require_once RP_PM_DIR . 'includes/variations.php';  // varianti + ricerca
require_once RP_PM_DIR . 'includes/ajax.php';        // tutti gli AJAX handler
require_once RP_PM_DIR . 'includes/admin-page.php';  // UI admin

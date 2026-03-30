<?php
/**
 * Plugin Name:  RP Media Manager
 * Plugin URI:   https://resellpiacenza.it
 * Description:  Gestione media WordPress: browse, mapping prodotto-immagine, orphan scanner, whitelist e pulizia.
 * Version:      1.0.0
 * Author:       ResellPiacenza
 * License:      Private
 * Requires PHP: 8.0
 * Requires at least: 6.0
 */

defined( 'ABSPATH' ) || exit;

define( 'RP_MM_VERSION', '1.0.0' );
define( 'RP_MM_DIR',     plugin_dir_path( __FILE__ ) );

require_once RP_MM_DIR . 'includes/scanner.php';
require_once RP_MM_DIR . 'includes/library.php';
require_once RP_MM_DIR . 'includes/whitelist.php';
require_once RP_MM_DIR . 'includes/cleaner.php';
require_once RP_MM_DIR . 'includes/ajax.php';
require_once RP_MM_DIR . 'includes/admin-page.php';

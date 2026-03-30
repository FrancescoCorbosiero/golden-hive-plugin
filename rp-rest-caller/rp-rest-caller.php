<?php
/**
 * Plugin Name:  RP REST Caller
 * Plugin URI:   https://resellpiacenza.it
 * Description:  Client HTTP per feed esterni e importazione prodotti. Supporto feed Golden Sneakers con trasformazione automatica.
 * Version:      1.0.0
 * Author:       ResellPiacenza
 * License:      Private
 * Requires PHP: 8.0
 * Requires at least: 6.0
 */

defined( 'ABSPATH' ) || exit;

define( 'RP_RC_VERSION', '1.0.0' );
define( 'RP_RC_DIR',     plugin_dir_path( __FILE__ ) );

require_once RP_RC_DIR . 'includes/http-client.php';
require_once RP_RC_DIR . 'includes/response-parser.php';
require_once RP_RC_DIR . 'includes/saved-endpoints.php';
require_once RP_RC_DIR . 'includes/feed-goldensneakers.php';
require_once RP_RC_DIR . 'includes/ajax.php';
require_once RP_RC_DIR . 'includes/admin-page.php';

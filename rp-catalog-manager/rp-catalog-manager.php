<?php
/**
 * Plugin Name:  RP Catalog Manager
 * Plugin URI:   https://resellpiacenza.it
 * Description:  Catalogo strutturato WooCommerce con export/import JSON roundtrip e vista aggregata.
 * Version:      1.0.0
 * Author:       ResellPiacenza
 * License:      Private
 * Requires PHP: 8.0
 * Requires at least: 6.0
 */

defined( 'ABSPATH' ) || exit;

define( 'RP_CM_VERSION', '1.0.0' );
define( 'RP_CM_DIR',     plugin_dir_path( __FILE__ ) );

require_once RP_CM_DIR . 'includes/reader.php';
require_once RP_CM_DIR . 'includes/aggregator.php';
require_once RP_CM_DIR . 'includes/tree-builder.php';
require_once RP_CM_DIR . 'includes/exporter.php';
require_once RP_CM_DIR . 'includes/importer.php';
require_once RP_CM_DIR . 'includes/bulk-creator.php';
require_once RP_CM_DIR . 'includes/ajax.php';
require_once RP_CM_DIR . 'includes/admin-page.php';

<?php
/**
 * Plugin Name:  Golden Hive
 * Plugin URI:   https://resellpiacenza.it
 * Description:  Suite completa per ResellPiacenza: catalogo, tassonomia, media, import/export, feed esterni.
 * Version:      1.0.0
 * Author:       ResellPiacenza
 * License:      Private
 * Requires PHP: 8.0
 * Requires at least: 6.0
 */

defined( 'ABSPATH' ) || exit;

define( 'GH_VERSION', '1.0.0' );
define( 'GH_DIR',     plugin_dir_path( __FILE__ ) );

// Product — CRUD + variations (merged from rp-product-manager)
require_once GH_DIR . 'includes/product/crud.php';
require_once GH_DIR . 'includes/product/variations.php';

// Core — shared product creation
require_once GH_DIR . 'includes/core/product-factory.php';

// Catalog — overview, taxonomy, export/import
require_once GH_DIR . 'includes/catalog/reader.php';
require_once GH_DIR . 'includes/catalog/aggregator.php';
require_once GH_DIR . 'includes/catalog/tree-builder.php';
require_once GH_DIR . 'includes/catalog/exporter.php';
require_once GH_DIR . 'includes/catalog/importer.php';
require_once GH_DIR . 'includes/catalog/taxonomy-manager.php';
require_once GH_DIR . 'includes/catalog/bulk-creator.php';
require_once GH_DIR . 'includes/catalog/ajax.php';

// Media — browse, mapping, orphans, whitelist
require_once GH_DIR . 'includes/media/scanner.php';
require_once GH_DIR . 'includes/media/library.php';
require_once GH_DIR . 'includes/media/whitelist.php';
require_once GH_DIR . 'includes/media/cleaner.php';
require_once GH_DIR . 'includes/media/ajax.php';

// Feeds — HTTP client, GS feed, SF feed, config engine, CSV feed
require_once GH_DIR . 'includes/feeds/http-client.php';
require_once GH_DIR . 'includes/feeds/response-parser.php';
require_once GH_DIR . 'includes/feeds/saved-endpoints.php';
require_once GH_DIR . 'includes/feeds/feed-goldensneakers.php';
require_once GH_DIR . 'includes/feeds/feed-stockfirmati.php';
require_once GH_DIR . 'includes/feeds/csv-presets.php';
require_once GH_DIR . 'includes/feeds/feed-csv.php';
require_once GH_DIR . 'includes/feeds/feed-config-engine.php';
require_once GH_DIR . 'includes/feeds/ajax.php';

// Filter — composable query engine
require_once GH_DIR . 'includes/filter/conditions.php';
require_once GH_DIR . 'includes/filter/query-engine.php';
require_once GH_DIR . 'includes/filter/ajax.php';

// Bulk — actions + programmatic sorting
require_once GH_DIR . 'includes/bulk/actions.php';
require_once GH_DIR . 'includes/bulk/sorter.php';
require_once GH_DIR . 'includes/bulk/ajax.php';

// Mapper — visual UI field mapper
require_once GH_DIR . 'includes/mapper/engine.php';
require_once GH_DIR . 'includes/mapper/storage.php';
require_once GH_DIR . 'includes/mapper/ajax.php';

// Email — contacts, mailer, campaigns
require_once GH_DIR . 'includes/email/contacts.php';
require_once GH_DIR . 'includes/email/mailer.php';
require_once GH_DIR . 'includes/email/campaigns.php';
require_once GH_DIR . 'includes/email/ajax.php';

// Admin UI
require_once GH_DIR . 'includes/admin-page.php';

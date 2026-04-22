<?php
/**
 * Plugin Name:  Golden Hive
 * Plugin URI:   https://github.com/FrancescoCorbosiero/golden-hive-plugin
 * Description:  WooCommerce suite: catalogo, tassonomia, media, import/export, feed esterni.
 * Version:      1.0.0
 * Author:       Golden Hive
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
require_once GH_DIR . 'includes/product/ajax.php';

// Core — shared product creation
require_once GH_DIR . 'includes/core/product-factory.php';

// Catalog — overview, taxonomy, export/import
require_once GH_DIR . 'includes/catalog/reader.php';
require_once GH_DIR . 'includes/catalog/aggregator.php';
require_once GH_DIR . 'includes/catalog/tree-builder.php';
require_once GH_DIR . 'includes/catalog/exporter.php';
require_once GH_DIR . 'includes/catalog/importer.php';
require_once GH_DIR . 'includes/catalog/taxonomy-manager.php';
require_once GH_DIR . 'includes/catalog/taxonomy-query.php';
require_once GH_DIR . 'includes/catalog/smart-taxonomy.php';
require_once GH_DIR . 'includes/catalog/bulk-creator.php';
require_once GH_DIR . 'includes/catalog/ajax.php';

// Navigation — WP nav menus (read/write, auto-populate from taxonomy query)
require_once GH_DIR . 'includes/navigation/manager.php';
require_once GH_DIR . 'includes/navigation/ajax.php';

// Media — browse, mapping, orphans, whitelist
require_once GH_DIR . 'includes/media/scanner.php';
require_once GH_DIR . 'includes/media/library.php';
require_once GH_DIR . 'includes/media/whitelist.php';
require_once GH_DIR . 'includes/media/cleaner.php';
require_once GH_DIR . 'includes/media/browser.php';
require_once GH_DIR . 'includes/media/ajax.php';

// Feeds — HTTP client, GS feed, SF feed, config engine, CSV feed, media pre-import
require_once GH_DIR . 'includes/feeds/media-preimport.php';
require_once GH_DIR . 'includes/feeds/http-client.php';
require_once GH_DIR . 'includes/feeds/response-parser.php';
require_once GH_DIR . 'includes/feeds/saved-endpoints.php';
require_once GH_DIR . 'includes/feeds/feed-goldensneakers.php';
require_once GH_DIR . 'includes/feeds/feed-stockfirmati.php';
require_once GH_DIR . 'includes/feeds/csv-presets.php';
require_once GH_DIR . 'includes/feeds/feed-csv.php';
require_once GH_DIR . 'includes/feeds/feed-config-engine.php';
require_once GH_DIR . 'includes/feeds/scheduler.php';
require_once GH_DIR . 'includes/feeds/reimport.php';
require_once GH_DIR . 'includes/feeds/ajax.php';

// Jobs — unified scheduler (cron-expression based, chunked, pluggable)
require_once GH_DIR . 'includes/jobs/cron-expr.php';
require_once GH_DIR . 'includes/jobs/registry.php';
require_once GH_DIR . 'includes/jobs/storage.php';
require_once GH_DIR . 'includes/jobs/log.php';
require_once GH_DIR . 'includes/jobs/runner.php';
require_once GH_DIR . 'includes/jobs/handlers-feeds.php';
require_once GH_DIR . 'includes/jobs/handlers-ops.php';
require_once GH_DIR . 'includes/jobs/ajax.php';
require_once GH_DIR . 'includes/jobs/migrate.php';

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

// Email — multi-layer template system + transactional (event-driven).
// Ordine di load: placeholders → brand → templates → renderer → validator →
//                 campaigns → contacts → mailer → log → order-resolver →
//                 transactional → order-meta-box → seeder → ajax.
// Dipendenze: renderer legge brand+templates, validator legge tutto, campaigns
// usa renderer+validator, transactional usa order-resolver+templates+mailer,
// order-meta-box usa transactional, seeder usa brand+templates+campaigns,
// ajax+transactional-ajax usano tutto.
// templates.php carica order_shipped binding al primo admin_init: deve caricarsi
// DOPO transactional.php (che definisce rp_em_save_transactional_binding).
require_once GH_DIR . 'includes/email/placeholders.php';
require_once GH_DIR . 'includes/email/brand.php';
require_once GH_DIR . 'includes/email/renderer.php';
require_once GH_DIR . 'includes/email/validator.php';
require_once GH_DIR . 'includes/email/campaigns.php';
require_once GH_DIR . 'includes/email/contacts.php';
require_once GH_DIR . 'includes/email/mailer.php';
require_once GH_DIR . 'includes/email/log.php';
require_once GH_DIR . 'includes/email/order-resolver.php';
require_once GH_DIR . 'includes/email/transactional.php';
require_once GH_DIR . 'includes/email/templates.php';
require_once GH_DIR . 'includes/email/demo-render.php';
require_once GH_DIR . 'includes/email/order-meta-box.php';
require_once GH_DIR . 'includes/email/_seed/seeder.php';
require_once GH_DIR . 'includes/email/ajax.php';
require_once GH_DIR . 'includes/email/transactional-ajax.php';

// Tools
require_once GH_DIR . 'includes/tools/nuclear-cleanup.php';
require_once GH_DIR . 'includes/tools/ajax.php';

// Admin UI
require_once GH_DIR . 'includes/admin-page.php';

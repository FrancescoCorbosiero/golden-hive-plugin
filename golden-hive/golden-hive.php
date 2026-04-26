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

// Core — foundation helpers (option store, AJAX guard, UI snippets).
// Caricati per primi: ogni modulo puo dipenderne.
require_once GH_DIR . 'includes/core/option-store.php';
require_once GH_DIR . 'includes/core/ajax-helpers.php';
require_once GH_DIR . 'includes/core/ui-helpers.php';

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
require_once GH_DIR . 'includes/catalog/snapshot.php';
require_once GH_DIR . 'includes/catalog/diff.php';
require_once GH_DIR . 'includes/catalog/snapshot-ajax.php';
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

// Conflict — multi-source provenance + conflict resolution engine.
// Caricato PRIMA dei feed per esporre gh_conflict_record_source() ai feed
// esistenti (GS, SF, CSV) che lo chiamano post-create/update.
require_once GH_DIR . 'includes/conflict/provenance.php';
require_once GH_DIR . 'includes/conflict/storage.php';
require_once GH_DIR . 'includes/conflict/engine.php';
require_once GH_DIR . 'includes/conflict/migrate.php';
require_once GH_DIR . 'includes/conflict/ajax.php';

// Feeds — HTTP client, GS feed, SF feed, config engine, CSV feed, media pre-import
require_once GH_DIR . 'includes/feeds/media-preimport.php';
require_once GH_DIR . 'includes/feeds/http-client.php';
require_once GH_DIR . 'includes/feeds/response-parser.php';
require_once GH_DIR . 'includes/feeds/saved-endpoints.php';
// Centralized credentials storage (whitelisted schema + redaction). Caricato
// PRIMA di feeds/ajax.php perche gli handler save/load lo invocano.
require_once GH_DIR . 'includes/feeds/feed-credentials.php';
// Unified settings IO contract (per-field status, verify-after-write).
// Sostituisce le coppie di handler save/load ad-hoc; adottato da KicksDB,
// GS, SF e da qualunque servizio futuro. Caricato PRIMA di feeds/ajax.php
// e di kicksdb/ajax.php cosi gli handler legacy possono coesistere.
require_once GH_DIR . 'includes/feeds/settings-store.php';
require_once GH_DIR . 'includes/feeds/settings-ajax.php';
require_once GH_DIR . 'includes/feeds/feed-goldensneakers.php';
require_once GH_DIR . 'includes/feeds/feed-stockfirmati.php';
require_once GH_DIR . 'includes/feeds/csv-presets.php';
require_once GH_DIR . 'includes/feeds/feed-csv.php';
require_once GH_DIR . 'includes/feeds/feed-config-engine.php';
require_once GH_DIR . 'includes/feeds/scheduler.php';
require_once GH_DIR . 'includes/feeds/reimport.php';
require_once GH_DIR . 'includes/feeds/ajax.php';

// KicksDB — lookup/enrichment service + search discovery + batch pricing refresh.
// NON e un feed push: i SKU vengono da (a) input manuale, (b) search, (c)
// refresh su catalog esistente. Ordine: settings → client → cache → pricing →
// normalizer → feed orchestrator → ajax.
require_once GH_DIR . 'includes/feeds/kicksdb/settings.php';
require_once GH_DIR . 'includes/feeds/kicksdb/client.php';
require_once GH_DIR . 'includes/feeds/kicksdb/cache.php';
require_once GH_DIR . 'includes/feeds/kicksdb/pricing.php';
require_once GH_DIR . 'includes/feeds/kicksdb/profiles.php';
require_once GH_DIR . 'includes/feeds/kicksdb/normalizer.php';
require_once GH_DIR . 'includes/feeds/kicksdb/feed.php';
require_once GH_DIR . 'includes/feeds/kicksdb/ajax.php';

// Jobs — unified scheduler (cron-expression based, chunked, pluggable)
require_once GH_DIR . 'includes/jobs/cron-expr.php';
require_once GH_DIR . 'includes/jobs/registry.php';
require_once GH_DIR . 'includes/jobs/storage.php';
require_once GH_DIR . 'includes/jobs/log.php';
require_once GH_DIR . 'includes/jobs/runner.php';
require_once GH_DIR . 'includes/jobs/handlers-feeds.php';
require_once GH_DIR . 'includes/jobs/handlers-ops.php';
require_once GH_DIR . 'includes/jobs/handlers-snapshot.php';
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

// Email Lite — dev-first standalone campaign tool. Zero dipendenze dal
// sistema email multi-layer qui sopra. Se la UI complessa si rompe, questa
// pagina sotto Tools → Campaign Email continua a funzionare.
require_once GH_DIR . 'includes/email-lite/campaign-tool.php';

// Tools
require_once GH_DIR . 'includes/tools/nuclear-cleanup.php';
require_once GH_DIR . 'includes/tools/ajax.php';

// Admin UI
require_once GH_DIR . 'includes/admin-page.php';

/**
 * Activation: installa le default conflict rules e avvia la prima passata di
 * backfill provenance. Idempotente: re-attivare il plugin non rompe niente,
 * le rule esistenti sono preservate e la migration skippa i prodotti gia
 * taggati.
 */
register_activation_hook( __FILE__, function () {
    if ( function_exists( 'gh_conflict_on_activate' ) ) {
        gh_conflict_on_activate();
    }
    if ( function_exists( 'gh_history_install_tables' ) ) {
        gh_history_install_tables();
    }
    if ( function_exists( 'gh_history_install_default_job' ) ) {
        gh_history_install_default_job();
    }
} );

// Idempotent table install for live-deploy upgrades (no plugin re-activation).
add_action( 'admin_init', function () {
    if ( function_exists( 'gh_history_install_tables' ) ) {
        gh_history_install_tables();
    }
} );

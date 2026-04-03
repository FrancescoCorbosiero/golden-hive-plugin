<?php
/**
 * Plugin Name:  RP Email Marketing
 * Plugin URI:   https://resellpiacenza.it
 * Description:  Email marketing suite per ResellPiacenza: test email, campagne, liste Hustle, CSV import, scheduling.
 * Version:      1.0.0
 * Author:       ResellPiacenza
 * License:      Private
 * Requires PHP: 8.0
 * Requires at least: 6.0
 */

defined( 'ABSPATH' ) || exit;

define( 'RP_EM_VERSION', '1.0.0' );
define( 'RP_EM_DIR',     plugin_dir_path( __FILE__ ) );

// Contacts — Hustle subscribers + CSV + merge/dedupe
require_once RP_EM_DIR . 'includes/contacts.php';

// Mailer — wp_mail wrapper, test email, personalization
require_once RP_EM_DIR . 'includes/mailer.php';

// Campaigns — CRUD + WP-Cron scheduling
require_once RP_EM_DIR . 'includes/campaigns.php';

// AJAX bridge
require_once RP_EM_DIR . 'includes/ajax.php';

// Admin UI
require_once RP_EM_DIR . 'includes/admin-page.php';

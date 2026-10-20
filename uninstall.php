<?php
/**
 * Remove SEO Pro Analytics's settings and caches on uninstall (every site on
 * multisite). Add every option, post meta, user meta, transient, cron hook
 * and file a feature stores (STANDARDS.md → Structure).
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 * Additional terms (GPL-3.0 section 7(b)): ATTRIBUTION.txt
 * Provenance: wpas-c8662019b99fb07b. Copies, even of part, keep these lines.
 *
 * @package SEOProAnalytics
 */

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

require_once __DIR__ . '/includes/stats/class-seoproanalytics-schema.php';
require_once __DIR__ . '/includes/stats/class-seoproanalytics-collection.php';
require_once __DIR__ . '/includes/stats/class-seoproanalytics-demo.php';
require_once __DIR__ . '/includes/stats/class-seoproanalytics-goals.php';
require_once __DIR__ . '/includes/stats/class-seoproanalytics-purchases.php';
require_once __DIR__ . '/includes/stats/class-seoproanalytics-connections.php';
require_once __DIR__ . '/includes/stats/class-seoproanalytics-search-import.php';
require_once __DIR__ . '/includes/stats/class-seoproanalytics-audit.php';
require_once __DIR__ . '/includes/stats/class-seoproanalytics-indexation.php';
require_once __DIR__ . '/includes/stats/class-seoproanalytics-targets.php';
require_once __DIR__ . '/includes/stats/class-seoproanalytics-backlinks.php';
require_once __DIR__ . '/includes/stats/class-seoproanalytics-inspections.php';
require_once __DIR__ . '/includes/stats/class-seoproanalytics-indexnow.php';
require_once __DIR__ . '/includes/stats/class-seoproanalytics-bing-site-data.php';
require_once __DIR__ . '/includes/stats/class-seoproanalytics-crawl.php';
require_once __DIR__ . '/includes/stats/class-seoproanalytics-crawl-import.php';

/**
 * Delete the plugin's tables (live and demo), collector folder, cron job,
 * options and transients for the current site.
 */
function seoproanalytics_uninstall_site() {
    global $wpdb;

    SEOProAnalytics_Demo::remove();
    // An imported crawl's job, staged parts, cron event and counts (its tables go with the others).
    SEOProAnalytics_Crawl_Import::reset();
    SEOProAnalytics_Schema::drop();
    SEOProAnalytics_Goals::forget();
    SEOProAnalytics_Audit::reset();
    SEOProAnalytics_Indexation::reset();
    SEOProAnalytics_Targets::reset();
    SEOProAnalytics_Backlinks::reset();
    SEOProAnalytics_Inspections::reset();
    SEOProAnalytics_Bing_Site_Data::reset();
    SEOProAnalytics_IndexNow::forget();
    SEOProAnalytics_Purchases::forget();
    SEOProAnalytics_Search_Import::forget();
    SEOProAnalytics_Connections::forget();
    // Every run lock left behind, ours and the Connect add-on's (whose data lives in our tables and connections).
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- one-off cleanup of our locks.
    $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like('seoproanalytics_') . '%' . $wpdb->esc_like('_lock') . '%'));
    SEOProAnalytics_Collection::remove();
    delete_option('seoproanalytics_options');
    delete_option('seoproanalytics_options_lock');
    // A table upgrade's lock (SEOProAnalytics_Schema::maybe_upgrade()), live and demo, should a request have died holding it.
    delete_option('seoproanalytics_schema_lock');
    delete_option('seoproanalytics_schema_lock_demo');
    delete_option('seoproanalytics_db_version');
    delete_option('seoproanalytics_network_seen');
    delete_option('seoproanalytics_network_lock');
    delete_option('seoproanalytics_page_cache');
    delete_option('seoproanalytics_shares');
    delete_option('seoproanalytics_mail_log');
    delete_option('seoproanalytics_alerts');
    delete_option('seoproanalytics_alerts_checked');
    // The owners' version (SEOProAnalytics_Owners::OPTION), live and demo; the owners table goes with the others.
    delete_option('seoproanalytics_owners');
    delete_option('seoproanalytics_owners_demo');
    wp_clear_scheduled_hook('seoproanalytics_page_cache_purge');
    // Imports from other statistics plugins (SEOProAnalytics_Migrate::forget();
    // the found list is a transient, removed below). Their plugins' own
    // data is never touched here.
    wp_clear_scheduled_hook(SEOProAnalytics_Collection::MIGRATE_HOOK);
    wp_clear_scheduled_hook(SEOProAnalytics_Collection::MIGRATE_SCAN_HOOK);
    delete_option(SEOProAnalytics_Collection::MIGRATE_NOTICES);
    delete_option('seoproanalytics_migrate');
    delete_option('seoproanalytics_migrate_lock');
    // Our copy of Jetpack Stats' daily counts (SEOProAnalytics_Migrate_Jetpack::SERIES_OPTION).
    delete_option('seoproanalytics_migrate_jetpack');
    delete_option('seoproanalytics_imported');
    delete_option('seoproanalytics_imported_demo');
    // The move from the plugin's old name: its notes and lock (SEOProAnalytics_Rename).
    delete_option('seoproanalytics_rename_report');
    delete_option('seoproanalytics_rename_lock');

    $patterns = array('_transient_seoproanalytics_', '_transient_timeout_seoproanalytics_');
    foreach ($patterns as $pattern) {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- one-off cleanup of our transients.
        $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like($pattern) . '%'));
    }
}

if (is_multisite()) {
    foreach (get_sites(array('fields' => 'ids', 'number' => 0)) as $seoproanalytics_site_id) {
        switch_to_blog($seoproanalytics_site_id);
        seoproanalytics_uninstall_site();
        restore_current_blog();
    }
    delete_site_option('seoproanalytics_network');
} else {
    seoproanalytics_uninstall_site();
}

// Hidden "SEO Pro Analytics can do the job of these plugins" lines.
delete_metadata('user', 0, 'seoproanalytics_replaced_plugins_hidden', '', true);

// Hidden "Moving to SEO Pro Analytics from other statistics plugins" steps.
delete_metadata('user', 0, 'seoproanalytics_migrate_notices_hidden', '', true);

// Who chose to see the demo data.
delete_metadata('user', 0, 'seoproanalytics_data', '', true);

// Per-user tracker diagnostics preference.
delete_metadata('user', 0, 'seoproanalytics_test_mode', '', true);

// The shared theme removes our preference and keeps other plugins' choices.
require_once __DIR__ . '/admin/includes/class-admin-theme.php';
SEOProAnalytics_Admin_Theme::uninstall();

// Each person's last named dashboard period and comparison.
delete_metadata('user', 0, 'seoproanalytics_period', '', true);

// Latest GitHub releases (the shared GitHub updater). Only a cache: another
// plugin's copy asks again.
delete_site_transient('wpallstars_github_releases');

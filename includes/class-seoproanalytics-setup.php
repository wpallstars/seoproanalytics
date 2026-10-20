<?php
/**
 * What makes this plugin SEO Pro Analytics: its features, settings tabs,
 * links, settings history and the helpers only it needs.
 *
 * The other files in includes/ and admin/ that the starter plugin also has
 * (the feature registry, settings store, base feature and admin screen)
 * read this class and differ from the starter's only in names. Keep
 * anything that only this plugin needs here, in features or in its own
 * files loaded from here.
 *
 * The statistics' settings are one feature, SEOProAnalytics_Statistics, on
 * the Tracking, Privacy and Data tabs. Add a feature: STANDARDS.md →
 * Structure and README.md → Developers.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 * Additional terms (GPL-3.0 section 7(b)): ATTRIBUTION.txt
 * Provenance: wpas-c8662019b99fb07b. Copies, even of part, keep these lines.
 *
 * @package SEOProAnalytics
 * @since 1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

final class SEOProAnalytics_Setup {

    /**
     * Built-in features, in the order their cards appear within each tab.
     * Each lives in includes/features/class-{lowercase-hyphenated-name}.php,
     * for example SEOProAnalytics_Example in class-seoproanalytics-example.php.
     */
    const FEATURES = array(
        'SEOProAnalytics_Statistics',
    );

    /**
     * Features that some builds leave out, loaded only when their file is
     * present (for example one listed in .distignore-wporg).
     */
    const OPTIONAL_FEATURES = array();

    /**
     * Settings version. SEOProAnalytics_Settings::maybe_migrate() runs
     * migrate() below and every feature's migrate() once per version.
     * After a release, bump it for a new or changed import and add a line.
     *
     * v1: first version.
     */
    const DB_VERSION = 1;

    /**
     * Renamed tab slugs, old => new. Settings that still use an old slug
     * land on the new tab, and old admin links open the new tab.
     */
    const RENAMED_TABS = array(
        'general' => 'tracking',
    );

    /**
     * Slug of the plugin's own top-level menu (add_menu_page()), when it
     * has one: the settings screen is then Settings, the last item in that
     * menu, and not in WordPress's Settings menu. Empty: Settings → SEO Pro Analytics.
     *
     * The SEO Pro Analytics menu (includes/admin/class-seoproanalytics-dashboard.php).
     */
    const MENU_PARENT = 'seoproanalytics-dashboard';

    /**
     * Links in the settings screen header; leave one out for no button.
     *
     * - source:  the plugin's code (its GitHub repository)
     * - support: where people report problems (the plugin's GitHub issues)
     * - donate:  where people can support the maker
     *
     * @return array<string,string>
     */
    public static function header_links() {
        return array(
            'source'  => 'https://github.com/wpallstars/seoproanalytics',
            'support' => 'https://github.com/wpallstars/seoproanalytics/issues',
            'donate'  => 'https://buymeacoffee.com/marcusquinn',
        );
    }

    /**
     * Load the plugin's own helpers, before the features.
     */
    public static function load() {
        require_once SEOPROANALYTICS_DIR . 'includes/stats/class-seoproanalytics-network-snapshot.php';
        SEOProAnalytics_Network_Snapshot::init();
        require_once SEOPROANALYTICS_DIR . 'includes/stats/class-seoproanalytics-schema.php';
        require_once SEOPROANALYTICS_DIR . 'includes/stats/class-seoproanalytics-collection.php';
        require_once SEOPROANALYTICS_DIR . 'includes/stats/class-seoproanalytics-api.php';
        require_once SEOPROANALYTICS_DIR . 'includes/stats/class-seoproanalytics-purchases.php';
        require_once SEOPROANALYTICS_DIR . 'includes/stats/class-seoproanalytics-forms.php';
        require_once SEOPROANALYTICS_DIR . 'includes/stats/class-seoproanalytics-changes.php';
        require_once SEOPROANALYTICS_DIR . 'includes/stats/class-seoproanalytics-abilities.php';
    }

    /**
     * Register the helpers' hooks, after the settings store's.
     */
    public static function init() {
        // Tables are made and upgraded on admin requests, and made on a site's first cron or
        // WP-CLI request (SEOProAnalytics_Collection::set_up_outside_admin()); never on visitor pages.
        add_action('admin_init', static function () {
            SEOProAnalytics_Schema::maybe_upgrade();
        });
        SEOProAnalytics_Collection::init();
        SEOProAnalytics_API::init();
        require_once SEOPROANALYTICS_DIR . 'includes/stats/class-seoproanalytics-test-mode.php';
        SEOProAnalytics_Test_Mode::init();
        require_once SEOPROANALYTICS_DIR . 'includes/stats/class-seoproanalytics-share-page.php';
        SEOProAnalytics_Share_Page::init();
        // Shops' order hooks and the ThriveCart webhook route (hooks only).
        SEOProAnalytics_Purchases::init();
        SEOProAnalytics_Forms::init();
        // The change log: hooks on saving posts, products, plugins and settings only.
        SEOProAnalytics_Changes::init();
        // Business profiles added, changed or removed, on the timeline; loads only when settings change.
        add_action('seoproanalytics_settings_changed', static function ($old, $new) {
            require_once SEOPROANALYTICS_DIR . 'includes/stats/class-seoproanalytics-business-profiles.php';
            SEOProAnalytics_Business_Profiles::settings_changed($old, $new);
        }, 10, 2);
        require_once SEOPROANALYTICS_DIR . 'includes/stats/class-seoproanalytics-indexnow.php';
        SEOProAnalytics_IndexNow::init();
        // Abilities for AI agents (WordPress 6.9+; hooks only).
        SEOProAnalytics_Abilities::init();
        // A/B test blocks (they render on the site from their attributes
        // alone) and their registry, read when a post is saved.
        require_once SEOPROANALYTICS_DIR . 'includes/stats/class-seoproanalytics-ab-tests.php';
        SEOProAnalytics_AB_Tests::init();
        if (!is_admin()) {
            // Prints the tracker on front-end pages.
            require_once SEOPROANALYTICS_DIR . 'includes/stats/class-seoproanalytics-tracker.php';
            SEOProAnalytics_Tracker::init();
        }

        if (wp_doing_cron()) {
            self::page_cache();
        }

        if (defined('WP_CLI') && WP_CLI) {
            self::page_cache();
            SEOProAnalytics_API::load();
            // Registers `wp seoproanalytics`.
            require_once SEOPROANALYTICS_DIR . 'includes/stats/class-seoproanalytics-cli.php';
        }
    }

    /**
     * Imports that belong to no feature, run once per DB_VERSION before
     * the features' own (for example settings from the plugin's earlier
     * names). Never write or delete other plugins' options.
     *
     * @param array $options      Stored settings (raw, without defaults).
     * @param int   $from_version Stored settings version before this upgrade.
     * @return array
     */
    public static function migrate(array $options, $from_version) {
        unset($from_version);
        return $options;
    }

    /**
     * Settings tabs, in navigation order. A tab shows only when a setting
     * uses it; with no settings at all, the first one shows, empty.
     *
     * @return array<string,array{label:string,description:string}>
     */
    public static function settings_tabs() {
        return array(
            'alerts' => array(
                'label' => __('Alerts', 'seoproanalytics'),
                'description' => __('Email when statistics are at risk. Open alerts also appear on SEO Pro Analytics screens.', 'seoproanalytics'),
            ),
            'shared-reports' => array(
                'label' => __('Shared reports', 'seoproanalytics'),
                'description' => __('Default branding for new private reports. Each report can override it.', 'seoproanalytics'),
            ),
            'tracking' => array(
                'label'       => __('Tracking', 'seoproanalytics'),
                'description' => __('What is collected, from which pages and domains.', 'seoproanalytics'),
            ),
            'privacy'  => array(
                'label'       => __('Privacy', 'seoproanalytics'),
                'description' => __('Who and what is left out. Visitors are never identified across days, and no cookies or IP addresses are stored.', 'seoproanalytics'),
            ),
            'data'     => array(
                'label'       => __('Data', 'seoproanalytics'),
                'description' => __('How long visits and search data are kept, search engine updates on the charts, and who can see the statistics.', 'seoproanalytics'),
            ),
            'backlinks' => array(
                'label'       => __('Backlinks', 'seoproanalytics'),
                'description' => __('Sites and pages whose links have a class in Search → Backlinks: spam, social media, profile pages and the rest.', 'seoproanalytics'),
            ),
        );
    }

    /**
     * Admin requests: load and start the plugin's own admin parts (other
     * tabs with the seoproanalytics_admin_tabs filter, scripts with the
     * seoproanalytics_admin_enqueue action).
     */
    public static function admin() {
        require_once SEOPROANALYTICS_DIR . 'includes/admin/class-seoproanalytics-dashboard.php';
        SEOProAnalytics_Dashboard::init();
        // Search queries in the post editor (hooks only).
        require_once SEOPROANALYTICS_DIR . 'includes/admin/class-seoproanalytics-editor.php';
        SEOProAnalytics_Editor::init();
        // Settings → Connections (outside data sources; hooks only).
        require_once SEOPROANALYTICS_DIR . 'includes/admin/class-seoproanalytics-connections-tab.php';
        SEOProAnalytics_Connections_Tab::init();
        // Settings → Import (other statistics plugins' history; hooks only).
        require_once SEOPROANALYTICS_DIR . 'includes/admin/class-seoproanalytics-import-tab.php';
        SEOProAnalytics_Import_Tab::init();
        // The next step in moving from another statistics plugin, on Plugins and our screens (hooks only).
        require_once SEOPROANALYTICS_DIR . 'includes/admin/class-seoproanalytics-migrate-notices.php';
        SEOProAnalytics_Migrate_Notices::init();
        // WP-Cron off and no server cron job running the jobs: how to add one, on our screens (hooks only).
        require_once SEOPROANALYTICS_DIR . 'includes/admin/class-seoproanalytics-schedule-notice.php';
        SEOProAnalytics_Schedule_Notice::init();
        require_once SEOPROANALYTICS_DIR . 'includes/admin/class-seoproanalytics-alerts-notice.php';
        SEOProAnalytics_Alerts_Notice::init();
        // Settings → Shared reports: the accent colour picker (hooks only).
        require_once SEOPROANALYTICS_DIR . 'includes/admin/class-seoproanalytics-share-settings.php';
        SEOProAnalytics_Share_Settings::init();
        // Settings → Data → Owners: the roles that can own, at the top of the card (hooks only).
        require_once SEOPROANALYTICS_DIR . 'includes/stats/class-seoproanalytics-owners.php';
        SEOProAnalytics_Owners::admin_init();
        // Settings → Data → Business profiles: each profile's tagged link (hooks only).
        require_once SEOPROANALYTICS_DIR . 'includes/stats/class-seoproanalytics-business-profiles.php';
        SEOProAnalytics_Business_Profiles::admin_init();
        // Settings → Backlinks → Classes: the classes in use, hidden and the site's own (hooks only).
        require_once SEOPROANALYTICS_DIR . 'includes/stats/class-seoproanalytics-backlinks-triage.php';
        require_once SEOPROANALYTICS_DIR . 'includes/stats/class-seoproanalytics-backlink-lists.php';
        SEOProAnalytics_Backlinks_Triage::admin_init();
        // The shared theme owns the header; this adapter keeps the dashboard's palette.
        require_once SEOPROANALYTICS_DIR . 'includes/admin/class-seoproanalytics-dashboard-theme.php';
        SEOProAnalytics_Dashboard_Theme::init();
        // Last named dashboard period, per person (hooks only).
        require_once SEOPROANALYTICS_DIR . 'includes/admin/class-seoproanalytics-admin-period.php';
        SEOProAnalytics_Admin_Period::init();
        self::page_cache();
    }

    /**
     * Admin, WP-Cron and WP-CLI requests: purge page caches when the
     * tracker or its settings change (never on visitor pages). Loads once.
     */
    private static function page_cache() {
        if (class_exists('SEOProAnalytics_Page_Cache', false)) {
            return;
        }
        require_once SEOPROANALYTICS_DIR . 'includes/stats/class-seoproanalytics-page-cache.php';
        SEOProAnalytics_Page_Cache::init();
    }
}

<?php
/**
 * The SEO Pro Analytics screen and the Dashboard widget.
 *
 * A top-level menu at position 3, where site statistics usually sit, opens
 * one screen holding the dashboard app (packages/wp-admin, built into
 * assets/build/). The settings screen (page=seoproanalytics) is the menu's last
 * item, Settings (SEOProAnalytics_Setup::MENU_PARENT names this menu). Views
 * live in the URL hash (#/overview?range=30d), so each can be bookmarked.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 * Additional terms (GPL-3.0 section 7(b)): ATTRIBUTION.txt
 * Provenance: wpas-c8662019b99fb07b. Copies, even of part, keep these lines.
 *
 * @package SEOProAnalytics
 * @since 0.2.0
 */

if (!defined('ABSPATH')) {
    exit;
}

final class SEOProAnalytics_Dashboard {

    /** Screen slug: admin.php?page=seoproanalytics-dashboard. */
    const SLUG = SEOProAnalytics_Setup::MENU_PARENT;

    /** Menu position: where site statistics usually sit, under Dashboard. */
    const POSITION = 3;

    /** Hook suffix of the screen. */
    const HOOK = 'toplevel_page_seoproanalytics-dashboard';

    /** Dashboard widget id. */
    const WIDGET = 'seoproanalytics_widget';

    /** Admin page address prefix. */
    private const PAGE_URL = 'admin.php?page=';

    /** Built entries, relative to the plugin directory. */
    private const BUILD_DIR = 'assets/build/';

    /**
     * Register hooks (admin requests only).
     */
    public static function init() {
        add_action('admin_menu', array(__CLASS__, 'register_menu'));
        add_action('admin_enqueue_scripts', array(__CLASS__, 'enqueue'));
        add_action('wp_dashboard_setup', array(__CLASS__, 'register_widget'));
        add_filter('seoprostack_admin_menu_catalog', array(__CLASS__, 'menu_catalog'));
        add_filter('seoprostack_dashboard_layout', array(__CLASS__, 'dashboard_layout'));
    }

    /**
     * When SEO Pro Stack lays out the Dashboard ("Tidy the dashboard"), put
     * the widget at the top of its visitors and SEO column. A place already
     * given in its rules wins.
     *
     * @param mixed $rules SEO Pro Stack's Dashboard layout rules.
     * @return mixed
     */
    public static function dashboard_layout($rules) {
        if (!is_array($rules)) {
            return $rules;
        }
        $columns = isset($rules['columns']) && is_array($rules['columns']) ? $rules['columns'] : array();
        foreach ($columns as $ids) {
            if (in_array(self::WIDGET, (array) $ids, true)) {
                return $rules;
            }
        }
        $columns['column3'] = array_merge(array(self::WIDGET), isset($columns['column3']) ? (array) $columns['column3'] : array());
        $rules['columns']   = $columns;
        return $rules;
    }

    /**
     * Whether the person's saved Dashboard arrangement (or one a layout
     * plugin gives through the same option) places the widget.
     *
     * @return bool
     */
    private static function widget_arranged() {
        $order = get_user_option('meta-box-order_dashboard');
        if (!is_array($order)) {
            return false;
        }
        foreach ($order as $ids) {
            if (is_string($ids) && in_array(self::WIDGET, explode(',', $ids), true)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Keep the menu at the top, under Dashboard, when SEO Pro Stack
     * organises the admin menu into sections; otherwise it would go under
     * Administrators. A place already given in its catalog wins, and
     * people can still move it with SEO Pro Stack's "Move menu entries".
     *
     * @param mixed $catalog SEO Pro Stack's admin menu catalog.
     * @return mixed
     */
    public static function menu_catalog($catalog) {
        if (is_array($catalog) && !isset($catalog['menus'][self::SLUG])) {
            $catalog['menus'][self::SLUG] = 'top';
        }
        return $catalog;
    }

    /**
     * The screen's address.
     *
     * @param string $view Hash view, e.g. 'overview'.
     * @return string
     */
    public static function url($view = 'overview') {
        return admin_url(self::PAGE_URL . self::SLUG . '#/' . $view);
    }

    /**
     * The screen's sections, in order: hash view => label. Search first,
     * the screen's default (DEFAULT_STATE in packages/core/src/state.ts),
     * then Visitors, whose address stays #/overview so bookmarks and
     * shared reports keep working.
     *
     * @return array<string,string>
     */
    private static function sections() {
        return array(
            'search'     => __('Search', 'seoproanalytics'),
            'overview'   => __('Visitors', 'seoproanalytics'),
            'goals'      => __('Goals', 'seoproanalytics'),
            'funnels'    => __('Funnels', 'seoproanalytics'),
            'properties' => __('Properties', 'seoproanalytics'),
            'clicks'     => __('Clicks', 'seoproanalytics'),
            'ab-tests'   => __('A/B tests', 'seoproanalytics'),
            'changes'    => __('Changes', 'seoproanalytics'),
        );
    }

    /**
     * Register the menu, its first item (the screen itself, which opens on
     * the first section) and links to the other sections of the same
     * screen (the app marks the one shown). Settings is added after these
     * (SEOProAnalytics_Admin_Manager, priority 20).
     *
     * The first item's page title stays the plugin's name: it is the
     * screen's name in the browser title, which the app follows with the
     * section shown ("SEO Pro Analytics: Search", boot()).
     */
    public static function register_menu() {
        $sections = self::sections();
        $first    = (string) key($sections);
        add_menu_page(
            __('SEO Pro Analytics', 'seoproanalytics'),
            __('SEO Pro Analytics', 'seoproanalytics'),
            SEOProAnalytics_API::CAP,
            self::SLUG,
            array(__CLASS__, 'render'),
            self::icon(),
            self::POSITION
        );
        add_submenu_page(
            self::SLUG,
            __('SEO Pro Analytics', 'seoproanalytics'),
            $sections[$first],
            SEOProAnalytics_API::CAP,
            self::SLUG,
            array(__CLASS__, 'render')
        );
        unset($sections[$first]);
        foreach ($sections as $view => $title) {
            // A slug that is an address, with no callback, is a plain link.
            add_submenu_page(self::SLUG, $title, $title, SEOProAnalytics_API::CAP, self::PAGE_URL . self::SLUG . '#/' . $view);
        }
        add_submenu_page(self::SLUG, __('Shared reports', 'seoproanalytics'), __('Shared reports', 'seoproanalytics'), 'manage_options', self::PAGE_URL . self::SLUG . '#/shares');
    }

    /**
     * The menu icon: rising bars and a star, in one colour so WordPress
     * paints it to match the admin colour scheme.
     *
     * @return string Data URI.
     */
    public static function icon() {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20"><path fill="black" d="M1 17h18v1.5H1zM2.5 11H6v5H2.5zM8.25 8h3.5v8h-3.5zM14 4h3.5v12H14zM5 1.8l.72 2.21h2.32l-1.88 1.37.72 2.21L5 6.22 3.12 7.59l.72-2.21-1.88-1.37h2.32z"/></svg>';
        // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- add_menu_page() takes an SVG icon as a base64 data URI.
        return 'data:image/svg+xml;base64,' . base64_encode($svg);
    }

    /**
     * The screen: the settings screen's header (name, version, feature
     * search and links), the tab bar, then the app. The bar holds the
     * sections as the settings screen's tabs and, on the right, the app's
     * Live/Demo switch, period, comparison and Share
     * (#seoproan-dashboard-controls); the live count is the Visitors tab's own.
     * The app marks the section shown and
     * keeps the period and filters in the tabs' links
     * (packages/wp-admin/src/App.tsx).
     */
    public static function render() {
        $sections = array();
        foreach (self::sections() as $view => $label) {
            $sections[$view] = array('label' => $label, 'url' => '#/' . $view);
        }
        ?>
        <div class="wrap seoproan-wrap seoproan-app-shell">
            <?php SEOProAnalytics_Admin_Manager::render_header(); ?>
            <hr class="wp-header-end">
            <div class="seoproan-app-nav" id="seoproan-dashboard-nav">
                <div class="seoproan-app-nav__links">
                    <?php SEOProAnalytics_Admin_Page::sections_nav($sections, 'search', __('Sections', 'seoproanalytics')); ?>
                </div>
                <div class="seoproan-app-nav__end" id="seoproan-dashboard-controls"></div>
            </div>
            <div id="seoproan-dashboard" class="seoproan-app-main">
                <p class="seoproan-loading"><?php esc_html_e('Loading statistics…', 'seoproanalytics'); ?></p>
                <noscript><p><?php esc_html_e('The statistics need JavaScript. They are also available through the REST API and WP-CLI (wp seoproanalytics stats).', 'seoproanalytics'); ?></p></noscript>
            </div>
        </div>
        <?php
    }

    /**
     * Register the Dashboard widget for people who can read statistics, at
     * the top of the second column: the right-hand column in WordPress's
     * usual two. Until the person arranges the Dashboard themselves, the
     * widget's script moves it to the top of whichever column is right-most
     * at their screen width (packages/wp-admin/src/placeWidget.ts).
     */
    public static function register_widget() {
        if (!current_user_can(SEOProAnalytics_API::CAP)) {
            return;
        }
        wp_add_dashboard_widget(self::WIDGET, __('SEO Pro Analytics', 'seoproanalytics'), array(__CLASS__, 'render_widget'), null, null, 'side', 'high');
    }

    /**
     * The widget's holder; the app fills it.
     */
    public static function render_widget() {
        echo '<div id="seoproan-widget"><p class="seoproan-loading">' . esc_html__('Loading statistics…', 'seoproanalytics') . '</p></div>';
    }

    /**
     * Load the app on the screen, and the widget's on the Dashboard.
     *
     * @param string $hook Current admin page hook.
     */
    public static function enqueue($hook) {
        if ($hook === self::HOOK) {
            SEOProAnalytics_Admin_Manager::enqueue_header();
            // Share → logos: WordPress's Media Library, for those who share.
            if (current_user_can('manage_options')) {
                wp_enqueue_media();
            }
            self::enqueue_entry('dashboard');
        } elseif ($hook === 'index.php' && current_user_can(SEOProAnalytics_API::CAP)) {
            self::enqueue_entry('widget');
        }
    }

    /**
     * Enqueue a built entry (assets/build/{name}.js and .css) with the
     * dependencies and version its .asset.php file lists.
     *
     * @param string     $name  Entry name.
     * @param array|null $boot  Public boot data, or null for the admin.
     * @param string[]   $extra Scripts it reads from the page besides those (the editor's).
     * @return bool Whether it was enqueued.
     */
    public static function enqueue_entry($name, $boot = null, array $extra = array()) {
        $asset_file = SEOPROANALYTICS_DIR . self::BUILD_DIR . $name . '.asset.php';
        if (!is_readable($asset_file)) {
            return false;
        }
        $asset   = require $asset_file; // NOSONAR: the file returns the build's asset array; require_once returns true if it was loaded before.
        $deps    = array_values(array_unique(array_merge(isset($asset['dependencies']) ? (array) $asset['dependencies'] : array(), $extra)));
        $version = isset($asset['version']) ? (string) $asset['version'] : SEOPROANALYTICS_VERSION;
        $handle  = 'seoproanalytics-' . $name;

        // WordPress before 6.6 has no react-jsx-runtime script: stand in for it.
        $shim = !wp_script_is('react-jsx-runtime', 'registered') && in_array('react-jsx-runtime', $deps, true);
        if ($shim) {
            $deps = array_values(array_diff($deps, array('react-jsx-runtime')));
            $deps = array_values(array_unique(array_merge($deps, array('react'))));
        }

        wp_enqueue_script($handle, SEOPROANALYTICS_URL . self::BUILD_DIR . $name . '.js', $deps, $version, true);
        if ($shim) {
            wp_add_inline_script($handle, self::jsx_runtime_shim(), 'before');
        }
        wp_add_inline_script($handle, 'window.seoproanalyticsBoot = ' . wp_json_encode($boot === null ? self::boot($name) : $boot) . ';', 'before');
        wp_set_script_translations($handle, 'seoproanalytics');

        $style = self::BUILD_DIR . $name . (is_rtl() ? '-rtl' : '') . '.css';
        if (is_readable(SEOPROANALYTICS_DIR . $style)) {
            wp_enqueue_style($handle, SEOPROANALYTICS_URL . $style, array('wp-components'), $version);
        }
        return true;
    }

    /**
     * What the app needs to know about the site and the user.
     *
     * @param string $name Entry name: 'dashboard', 'widget' or 'editor'.
     * @return array<string,mixed>
     */
    private static function boot($name) {
        require_once SEOPROANALYTICS_DIR . 'includes/stats/class-seoproanalytics-demo.php';
        $boot = array(
            'locale'       => get_user_locale(),
            'timezone'     => wp_timezone_string(),
            // This week starts here (Settings → General), as in SEOProAnalytics_Query::range().
            'startOfWeek'  => (int) get_option('start_of_week', 1),
            'dashboardUrl' => self::url(),
            'settingsUrl'  => SEOProAnalytics_Admin_Manager::page_url(),
            'siteHost'     => (string) wp_parse_url(home_url('/'), PHP_URL_HOST),
            'canManage'    => current_user_can('manage_options'),
            // Read after wp_dashboard_setup, so a layout plugin's order counts.
            'placeWidget'  => $name === 'widget' && !self::widget_arranged(),
            // Live statistics, or the demo data this person switched to.
            'data'         => SEOProAnalytics_Demo::viewing(),
            'demo'         => SEOProAnalytics_Demo::status(),
            // Share → accent: the site's colours, as Settings offers them.
            'sharePalette' => $name === 'dashboard' && current_user_can('manage_options') && class_exists('SEOProAnalytics_Share_Settings')
                ? array_map(static function ($hex, $label) {
                    return array('color' => $hex, 'name' => $label);
                }, array_keys(SEOProAnalytics_Share_Settings::palette()), SEOProAnalytics_Share_Settings::palette())
                : array(),
        );
        if ($name === 'dashboard') {
            require_once SEOPROANALYTICS_DIR . 'includes/stats/class-seoproanalytics-connections.php';
            // Search's setup names the add-on's connections not registered here, with its page.
            $boot['addon'] = array(
                'url'    => SEOProAnalytics_Connections::connect_url(),
                'needed' => array_keys(SEOProAnalytics_Connections::placeholders()),
            );
            $boot['period'] = SEOProAnalytics_Admin_Period::boot();
            // Search → Backlinks: the classes in use (Settings → Backlinks → Classes).
            require_once SEOPROANALYTICS_DIR . 'includes/stats/class-seoproanalytics-backlinks-triage.php';
            require_once SEOPROANALYTICS_DIR . 'includes/stats/class-seoproanalytics-backlink-lists.php';
            $boot['backlinkClasses'] = SEOProAnalytics_Backlinks_Triage::boot();
            $screen = wp_strip_all_tags((string) get_admin_page_title());
            $boot['screenTitle'] = html_entity_decode($screen, ENT_QUOTES, 'UTF-8');
            $boot['viewTitles'] = array();
            $sections = self::sections();
            $sections['shares'] = __('Shared reports', 'seoproanalytics');
            foreach ($sections as $view => $label) {
                $boot['viewTitles'][$view] = html_entity_decode(SEOProAnalytics_Admin_Page::screen_title($screen, $label), ENT_QUOTES, 'UTF-8');
            }
        }
        return $boot;
    }

    /**
     * ReactJSXRuntime (jsx, jsxs, Fragment) on WordPress's React, as the
     * react-jsx-runtime script provides from WordPress 6.6.
     *
     * @return string
     */
    private static function jsx_runtime_shim() {
        return 'window.ReactJSXRuntime = window.ReactJSXRuntime || (function (React) {'
            . 'function jsx(type, props, key) {'
            . 'var p = {}; for (var k in props) { if (Object.prototype.hasOwnProperty.call(props, k)) { p[k] = props[k]; } }'
            . 'if (key !== undefined) { p.key = key; }'
            . 'return React.createElement(type, p);'
            . '}'
            . 'return { jsx: jsx, jsxs: jsx, Fragment: React.Fragment };'
            . '}(window.React));';
    }
}

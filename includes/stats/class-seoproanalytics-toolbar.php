<?php
/**
 * The toolbar's page statistics and the click and scroll heatmap shown
 * over a page (Settings → Data → Page statistics in the toolbar).
 *
 * On the site, people who may see the statistics (SEOProAnalytics_API's
 * can_read()) get an Analytics menu in the toolbar: the current page's
 * last 30 days (visitors, pageviews, time, bounce rate, top sources,
 * clicks, real visitors' page speed and crawler requests), fetched from
 * GET /page-summary only when the menu is first opened, and links to its
 * heatmap and report. Visitors who are not logged in get nothing: the
 * hooks return before any work, and nothing is added to cached pages.
 *
 * The heatmap (?seoproanalytics_heatmap=1 on any page, for the same
 * people) draws GET /heatmap's click cells and scroll depth over the page
 * (admin/js/seoproanalytics-heatmap.js).
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 * Additional terms (GPL-3.0 section 7(b)): ATTRIBUTION.txt
 * Provenance: wpas-c8662019b99fb07b. Copies, even of part, keep these lines.
 *
 * @package SEOProAnalytics
 * @since 1.22.0
 */

if (!defined('ABSPATH')) {
    exit;
}

final class SEOProAnalytics_Toolbar {

    /** Query parameter that shows the heatmap over a page. */
    const HEATMAP_PARAM = 'seoproanalytics_heatmap';

    /** Toolbar node id. */
    const NODE = 'seoproanalytics-page';

    /**
     * Register hooks (front-end requests only).
     */
    public static function init() {
        add_action('admin_bar_menu', array(__CLASS__, 'menu'), 90);
        add_action('wp_enqueue_scripts', array(__CLASS__, 'enqueue'));
    }

    /**
     * Whether this person may see statistics over this page (the heatmap,
     * which the Clicks report links to whether or not the menu is on).
     *
     * @return bool
     */
    private static function readable() {
        return is_user_logged_in() && SEOProAnalytics_API::can_read() && !is_admin() && !is_feed() && !is_embed() && !is_customize_preview();
    }

    /**
     * Whether this person gets the toolbar menu on this page.
     *
     * @return bool
     */
    private static function shown() {
        return SEOProAnalytics_Statistics::toolbar() && self::readable();
    }

    /**
     * Whether the heatmap was asked for, by someone who may see it.
     *
     * @return bool
     */
    private static function heatmap_asked() {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a display switch for people who may read statistics; changes nothing.
        return !empty($_GET[self::HEATMAP_PARAM]) && self::readable();
    }

    /**
     * The current page's path, as pageviews store it (no query).
     *
     * @return string
     */
    private static function path() {
        $uri  = isset($_SERVER['REQUEST_URI']) ? (string) wp_unslash($_SERVER['REQUEST_URI']) : '/'; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sent to our own REST route as a parameter, escaped for the page.
        $path = (string) wp_parse_url($uri, PHP_URL_PATH);
        return $path === '' ? '/' : $path;
    }

    /**
     * The dashboard's address for a view, filtered to one page.
     *
     * @param string $view Hash view.
     * @param string $path Page path.
     * @param string $more More hash parameters (already encoded), or ''.
     * @return string
     */
    public static function report_url($view, $path, $more = '') {
        $filter = 'page:is:' . str_replace(array('\\', ','), array('\\\\', '\\,'), $path);
        return admin_url('admin.php?page=' . SEOProAnalytics_Setup::MENU_PARENT . '#/' . $view . '?range=30d&compare=prev&f=' . rawurlencode($filter) . ($more !== '' ? '&' . $more : ''));
    }

    /**
     * admin_bar_menu: the Analytics menu; its figures load when opened.
     *
     * @param WP_Admin_Bar $bar Toolbar.
     */
    public static function menu($bar) {
        if (!self::shown()) {
            return;
        }
        $path    = self::path();
        $heatmap = add_query_arg(self::HEATMAP_PARAM, '1', remove_query_arg(self::HEATMAP_PARAM));
        $bar->add_node(array(
            'id'    => self::NODE,
            'title' => '<span class="ab-icon dashicons-chart-bar" aria-hidden="true"></span><span class="ab-label">' . esc_html__('Analytics', 'seoproanalytics') . '</span>',
            'href'  => self::report_url('overview', $path),
            'meta'  => array('title' => __('This page\'s statistics, last 30 days', 'seoproanalytics')),
        ));
        $bar->add_node(array(
            'parent' => self::NODE,
            'id'     => self::NODE . '-figures',
            'title'  => '<span class="seoproan-ab-figures" data-seoproan-figures>' . esc_html__('Open the menu to load this page\'s last 30 days.', 'seoproanalytics') . '</span>',
            'meta'   => array('class' => 'seoproan-ab-figures-item'),
        ));
        $bar->add_node(array(
            'parent' => self::NODE,
            'id'     => self::NODE . '-heatmap',
            'title'  => esc_html__('Click and scroll heatmap', 'seoproanalytics'),
            'href'   => $heatmap,
        ));
        $bar->add_node(array(
            'parent' => self::NODE,
            'id'     => self::NODE . '-report',
            'title'  => esc_html__('Visitors report for this page', 'seoproanalytics'),
            'href'   => self::report_url('overview', $path),
        ));
        $bar->add_node(array(
            'parent' => self::NODE,
            'id'     => self::NODE . '-clicks',
            'title'  => esc_html__('Clicks on this page', 'seoproanalytics'),
            'href'   => admin_url('admin.php?page=' . SEOProAnalytics_Setup::MENU_PARENT . '#/clicks?range=30d&compare=prev&page=' . rawurlencode($path)),
        ));
    }

    /**
     * wp_enqueue_scripts: the menu's loader for people who get the menu,
     * and the heatmap when asked for; nothing for anyone else.
     */
    public static function enqueue() {
        $menu    = self::shown() && is_admin_bar_showing();
        $heatmap = self::heatmap_asked();
        if (!$menu && !$heatmap) {
            return;
        }
        $config = array(
            'root'  => esc_url_raw(rest_url(SEOProAnalytics_Collection::REST_NAMESPACE . '/')),
            'nonce' => wp_create_nonce('wp_rest'),
            'page'  => self::path(),
            'node'  => 'wp-admin-bar-' . self::NODE,
            'report' => self::report_url('overview', self::path()),
            'i18n'  => array(
                'loading'   => __('Loading…', 'seoproanalytics'),
                'failed'    => __('The statistics could not be loaded.', 'seoproanalytics'),
                'visitors'  => __('Visitors', 'seoproanalytics'),
                'pageviews' => __('Pageviews', 'seoproanalytics'),
                'time'      => __('Visit duration', 'seoproanalytics'),
                'bounce'    => __('Bounce rate', 'seoproanalytics'),
                'scroll'    => __('Average scroll', 'seoproanalytics'),
                'clicks'    => __('Clicks', 'seoproanalytics'),
                'sources'   => __('Top sources', 'seoproanalytics'),
                'speed'     => __('Page speed (p75)', 'seoproanalytics'),
                'crawlers'  => __('Crawler requests', 'seoproanalytics'),
                'ai'        => __('AI', 'seoproanalytics'),
                'search'    => __('search', 'seoproanalytics'),
                'none'      => __('No visits in the last 30 days.', 'seoproanalytics'),
                'direct'    => __('Direct', 'seoproanalytics'),
                'previous'  => __('vs previous 30 days', 'seoproanalytics'),
            ),
        );
        if ($menu) {
            self::enqueue_menu($config);
        }
        if (!$heatmap) {
            return;
        }
        $config['devices'] = array(
            ''        => __('All devices', 'seoproanalytics'),
            'desktop' => __('Desktop', 'seoproanalytics'),
            'mobile'  => __('Mobile', 'seoproanalytics'),
            'tablet'  => __('Tablet', 'seoproanalytics'),
        );
        $config['ranges'] = array(
            '7d'  => __('Last 7 days', 'seoproanalytics'),
            '30d' => __('Last 30 days', 'seoproanalytics'),
            '90d' => __('Last 90 days', 'seoproanalytics'),
            '12mo' => __('Last 12 months', 'seoproanalytics'),
        );
        $config['close'] = remove_query_arg(self::HEATMAP_PARAM);
        $config['clicksReport'] = admin_url('admin.php?page=' . SEOProAnalytics_Setup::MENU_PARENT . '#/clicks?range=30d&compare=prev&page=' . rawurlencode(self::path()));
        $config['i18n'] += array(
            'title'      => __('Click and scroll heatmap', 'seoproanalytics'),
            /* translators: %s: number of clicks. */
            'clickCount' => __('%s clicks', 'seoproanalytics'),
            /* translators: %s: number of pageviews. */
            'viewCount'  => __('%s pageviews', 'seoproanalytics'),
            /* translators: %s: share of pageviews, such as 60%. */
            'reached'    => __('%s of pageviews reached here', 'seoproanalytics'),
            'noClicks'  => __('No clicks with a place on this page for this period and device yet. Clicks are placed from version 1.22.0 on.', 'seoproanalytics'),
            'hint'      => __('Clicks are placed by the share of the window\'s width and the distance from the top: compare at a window width like the visitors\'.', 'seoproanalytics'),
            'clicksOn'  => __('Clicks', 'seoproanalytics'),
            'scrollOn'  => __('Scroll depth', 'seoproanalytics'),
            'close'     => __('Close', 'seoproanalytics'),
            'report'    => __('Clicks report', 'seoproanalytics'),
            'dead'      => __('dead clicks are outlined', 'seoproanalytics'),
        );
        wp_enqueue_script('seoproanalytics-heatmap', plugins_url('admin/js/seoproanalytics-heatmap.js', SEOPROANALYTICS_FILE), array(), SEOPROANALYTICS_VERSION, array('in_footer' => true, 'strategy' => 'defer'));
        wp_add_inline_script('seoproanalytics-heatmap', 'window.seoproanalyticsHeatmap = ' . wp_json_encode($config) . ';', 'before');
    }

    /**
     * The menu's styles and loader (a small deferred script; it fetches
     * nothing until the menu is opened).
     *
     * @param array<string,mixed> $config The loader's settings.
     */
    private static function enqueue_menu(array $config) {
        wp_register_style('seoproanalytics-toolbar', false, array(), SEOPROANALYTICS_VERSION);
        wp_enqueue_style('seoproanalytics-toolbar');
        wp_add_inline_style('seoproanalytics-toolbar', '#wpadminbar .seoproan-ab-figures-item > .ab-item{height:auto!important;line-height:1.5!important;padding:6px 10px!important;white-space:normal;min-width:260px;max-width:340px}#wpadminbar .seoproan-ab-figures table{border-collapse:collapse;width:100%}#wpadminbar .seoproan-ab-figures td,#wpadminbar .seoproan-ab-figures th{padding:1px 6px 1px 0;color:inherit;font-weight:400;text-align:left;font-size:12px;line-height:1.5}#wpadminbar .seoproan-ab-figures td{text-align:right;font-weight:600}#wpadminbar .seoproan-ab-figures .is-good{color:#68de7c}#wpadminbar .seoproan-ab-figures .is-ni{color:#f2d675}#wpadminbar .seoproan-ab-figures .is-poor{color:#ff8085}#wpadminbar #wp-admin-bar-' . self::NODE . ' .ab-icon:before{content:"\f185";top:2px}');
        wp_enqueue_script('seoproanalytics-toolbar', plugins_url('admin/js/seoproanalytics-toolbar.js', SEOPROANALYTICS_FILE), array(), SEOPROANALYTICS_VERSION, array('in_footer' => true, 'strategy' => 'defer'));
        wp_add_inline_script('seoproanalytics-toolbar', 'window.seoproanalyticsToolbar = ' . wp_json_encode($config) . ';', 'before');
    }

    /**
     * The toolbar's figures for one page over the last 30 days, compared
     * with the 30 days before.
     *
     * @param string $path Page path.
     * @return array<string,mixed>|WP_Error
     */
    public static function summary($path) {
        $path = trim((string) $path);
        if ($path === '' || $path[0] !== '/' || strpos($path, '*') !== false) {
            return new WP_Error('seoproanalytics_page', __('Use one local page path, such as /pricing/.', 'seoproanalytics'), array('status' => 400));
        }
        $filters = array(array('dimension' => 'page', 'op' => 'is', 'values' => array($path)));
        $req     = SEOProAnalytics_Query::request(array('range' => '30d', 'compare' => 'prev', 'filters' => $filters));
        if (is_wp_error($req)) {
            return $req;
        }
        $stats   = SEOProAnalytics_Query::stats($req);
        $sources = SEOProAnalytics_Query::request(array('range' => '30d', 'compare' => 'none', 'filters' => $filters, 'dimension' => 'source', 'limit' => 3));
        $top     = is_wp_error($sources) ? array() : SEOProAnalytics_Query::breakdown($sources);
        $ask     = SEOProAnalytics_Query::request(array('range' => '30d', 'compare' => 'none', 'limit' => 1));
        $clicks  = is_wp_error($ask) ? array('totals' => array('clicks' => 0)) : SEOProAnalytics_Clicks::report($ask, 'elements', $path);
        return array(
            'page'     => $path,
            'metrics'  => $stats['metrics'],
            'change'   => isset($stats['compare']['change']) ? $stats['compare']['change'] : null,
            'scroll'   => self::average_scroll($req),
            'sources'  => is_array($top) && isset($top['rows']) ? array_map(static function ($row) {
                return array('value' => (string) ($row['label'] ?? $row['value'] ?? ''), 'visitors' => (int) ($row['visitors'] ?? 0));
            }, array_slice($top['rows'], 0, 3)) : array(),
            'clicks'   => (int) $clicks['totals']['clicks'],
            'speed'    => SEOProAnalytics_Speed::page_p75($path),
            'ratings'  => SEOProAnalytics_Speed::METRICS,
            'crawlers' => SEOProAnalytics_Crawlers::page_summary($path),
        );
    }

    /**
     * The page's pageviews' average deepest scroll, in percent.
     *
     * @param array<string,mixed> $req From SEOProAnalytics_Query::request(), filtered to the page.
     * @return int
     */
    private static function average_scroll(array $req) {
        global $wpdb;
        $scope = SEOProAnalytics_Clicks::scope(SEOProAnalytics_Query::range($req), $req['filters'], '', 'pageviews');
        if ($scope === null) {
            return 0;
        }
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, PluginCheck.Security.DirectDB.UnescapedDBParameter -- our own tables: pageviews by index `path_ts`, visits by primary key; the scope holds only placeholders.
        return (int) round((float) $wpdb->get_var($wpdb->prepare("SELECT AVG(c.scroll) {$scope['sql']}", $scope['args'])));
    }
}

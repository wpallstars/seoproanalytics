<?php
/**
 * The REST API (namespace seoproanalytics/v1): the reports stats, timeseries,
 * breakdown, realtime, markers, changes, goals, funnels, properties, clicks,
 * search, opportunities and content, each on
 * live data or the demo data (data=demo); goals and funnels also add,
 * change and delete their definitions (administrators); demo (make, carry
 * on, remove) and view (the data set a person sees). Contract:
 * docs/api/openapi.yaml.
 *
 * Reading needs the view_seoproanalytics capability: administrators (anyone
 * with manage_options) and the roles Settings → Data allows. Agents use
 * Application Passwords. The report engine loads
 * only on REST and WP-CLI requests.
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

final class SEOProAnalytics_API {

    /** Capability that reads statistics. */
    const CAP = 'view_seoproanalytics';

    /** SEOProAnalytics_Migrate's file, loaded only by the import routes. */
    const MIGRATE_FILE = 'includes/stats/class-seoproanalytics-migrate.php';

    /**
     * Register hooks.
     */
    public static function init() {
        require_once SEOPROANALYTICS_DIR . 'includes/stats/class-seoproanalytics-shares.php';
        add_filter('map_meta_cap', array(__CLASS__, 'map_meta_cap'), 10, 3);
        add_action('rest_api_init', array(__CLASS__, 'register_routes'));
    }

    /**
     * view_seoproanalytics is granted to whoever can manage options, and to
     * people with a role Settings → Data allows.
     *
     * @param string[] $caps    Primitive capabilities required.
     * @param string   $cap     Capability checked.
     * @param int      $user_id User checked.
     * @return string[]
     */
    public static function map_meta_cap($caps, $cap, $user_id = 0) {
        if ($cap !== self::CAP) {
            return $caps;
        }
        $roles = SEOProAnalytics_Statistics::viewer_roles();
        if ($roles && $user_id) {
            $user = get_userdata((int) $user_id);
            if ($user && array_intersect((array) $user->roles, $roles)) {
                return array('read');
            }
        }
        /**
         * Filters the capabilities a user needs to read SEO Pro Analytics.
         *
         * @param string[] $caps Default: manage_options.
         */
        $needed = apply_filters('seoproanalytics_view_caps', array('manage_options'));
        return is_array($needed) && $needed ? array_map('strval', $needed) : array('manage_options');
    }

    /**
     * Load the report engine.
     */
    public static function load() {
        require_once SEOPROANALYTICS_DIR . 'includes/stats/class-seoproanalytics-dict.php';
        require_once SEOPROANALYTICS_DIR . 'includes/stats/class-seoproanalytics-query.php';
        require_once SEOPROANALYTICS_DIR . 'includes/stats/class-seoproanalytics-rollup.php';
        require_once SEOPROANALYTICS_DIR . 'includes/stats/class-seoproanalytics-demo.php';
        require_once SEOPROANALYTICS_DIR . 'includes/stats/class-seoproanalytics-goals.php';
        require_once SEOPROANALYTICS_DIR . 'includes/stats/class-seoproanalytics-conversions.php';
        require_once SEOPROANALYTICS_DIR . 'includes/stats/class-seoproanalytics-business-profiles.php';
        require_once SEOPROANALYTICS_DIR . 'includes/stats/class-seoproanalytics-clicks.php';
        require_once SEOPROANALYTICS_DIR . 'includes/stats/class-seoproanalytics-search.php';
        require_once SEOPROANALYTICS_DIR . 'includes/stats/class-seoproanalytics-opportunities.php';
        require_once SEOPROANALYTICS_DIR . 'includes/stats/class-seoproanalytics-coverage.php';
        require_once SEOPROANALYTICS_DIR . 'includes/stats/class-seoproanalytics-content.php';
        require_once SEOPROANALYTICS_DIR . 'includes/stats/class-seoproanalytics-changes.php';
        require_once SEOPROANALYTICS_DIR . 'includes/stats/class-seoproanalytics-experiments.php';
        require_once SEOPROANALYTICS_DIR . 'includes/stats/class-seoproanalytics-ab-report.php';
        require_once SEOPROANALYTICS_DIR . 'includes/stats/class-seoproanalytics-queue.php';
        require_once SEOPROANALYTICS_DIR . 'includes/stats/class-seoproanalytics-audit.php';
        require_once SEOPROANALYTICS_DIR . 'includes/stats/class-seoproanalytics-links.php';
        require_once SEOPROANALYTICS_DIR . 'includes/stats/class-seoproanalytics-indexation.php';
        require_once SEOPROANALYTICS_DIR . 'includes/stats/class-seoproanalytics-backlinks.php';
        require_once SEOPROANALYTICS_DIR . 'includes/stats/class-seoproanalytics-inspections.php';
        require_once SEOPROANALYTICS_DIR . 'includes/stats/class-seoproanalytics-bing-site-data.php';
        require_once SEOPROANALYTICS_DIR . 'includes/stats/class-seoproanalytics-crawl.php';
        require_once SEOPROANALYTICS_DIR . 'includes/stats/class-seoproanalytics-crawl-import.php';
        require_once SEOPROANALYTICS_DIR . 'includes/stats/class-seoproanalytics-vitals.php';
        require_once SEOPROANALYTICS_DIR . 'includes/stats/class-seoproanalytics-targets.php';
        require_once SEOPROANALYTICS_DIR . 'includes/stats/class-seoproanalytics-target-sources.php';
        require_once SEOPROANALYTICS_DIR . 'includes/stats/class-seoproanalytics-loop.php';
    }

    /**
     * Run a report on the data set asked for: live, or demo when it is
     * made (topped up to now first).
     *
     * @param string   $data live or demo.
     * @param callable $work Makes the answer.
     * @return mixed|WP_Error The answer.
     */
    public static function on_data($data, callable $work) {
        if ($data !== 'demo') {
            return $work();
        }
        if (!SEOProAnalytics_Demo::ready()) {
            return new WP_Error('seoproanalytics_no_demo', __('There is no demo data yet. An administrator can make it: switch on Demo data in SEO Pro Analytics, or run wp seoproanalytics demo make.', 'seoproanalytics'), array('status' => 404));
        }
        SEOProAnalytics_Demo::refresh();
        return SEOProAnalytics_Demo::run($work);
    }

    /**
     * GET /alerts: stored collection-health state, without running checks.
     *
     * @return WP_REST_Response
     */
    public static function alerts() {
        require_once __DIR__ . '/class-seoproanalytics-alerts.php';
        $response = new WP_REST_Response(array('alerts' => SEOProAnalytics_Alerts::open()));
        $response->header('Cache-Control', 'private, no-store');
        return $response;
    }

    /** Use PHP's shortest decimal encoding for report responses. */
    public static function short_floats() {
        if ((string) ini_get('serialize_precision') !== '-1') {
            ini_set('serialize_precision', '-1'); // phpcs:ignore WordPress.PHP.IniSet.Risky, Squiz.PHP.DiscouragedFunctions -- only for our own report answers, which are encoded next.
        }
    }

    /**
     * Register the read routes.
     */
    public static function register_routes() {
        self::load();
        $ns   = SEOProAnalytics_Collection::REST_NAMESPACE;
        $base = self::args(false);
        self::share_routes($ns);
        $read = array(
            'methods'             => WP_REST_Server::READABLE,
            'permission_callback' => array(__CLASS__, 'can_read'),
        );

        register_rest_route($ns, '/alerts', array(
            'methods' => WP_REST_Server::READABLE,
            'permission_callback' => array(__CLASS__, 'can_manage'),
            'callback' => array(__CLASS__, 'alerts'),
        ));
        register_rest_route($ns, '/indexnow', array(
            'methods'             => WP_REST_Server::READABLE,
            'permission_callback' => array(__CLASS__, 'can_change'),
            'callback'            => array(__CLASS__, 'indexnow'),
        ));
        register_rest_route($ns, '/stats', $read + array(
            'callback' => array(__CLASS__, 'stats'),
            'args'     => $base,
        ));
        register_rest_route($ns, '/vitals', $read + array(
            'callback' => array(__CLASS__, 'vitals'),
            'args' => array('page' => array('type' => 'string', 'default' => ''), 'data' => array('type' => 'string', 'enum' => SEOProAnalytics_Schema::SETS, 'default' => 'live')),
        ));
        register_rest_route($ns, '/timeseries', $read + array(
            'callback' => array(__CLASS__, 'timeseries'),
            'args'     => $base + array(
                'grain' => array(
                    'description' => __('Points per hour, day or month; auto picks by the range length.', 'seoproanalytics'),
                    'type'        => 'string',
                    'enum'        => SEOProAnalytics_Query::GRAINS,
                    'default'     => 'auto',
                ),
            ),
        ));
        register_rest_route($ns, '/breakdown', $read + array(
            'callback' => array(__CLASS__, 'breakdown'),
            'args'     => self::args(true),
        ));
        register_rest_route($ns, '/realtime', $read + array(
            'callback' => array(__CLASS__, 'realtime'),
            'args'     => array('data' => $base['data']),
        ));
        $changes = array(
            'page'  => array(
                'description' => __('Only changes to this page (a path such as /pricing/; * for any text), and the site-wide ones (plugins, themes, settings).', 'seoproanalytics'),
                'type'        => 'string',
                'default'     => '',
            ),
            'kinds' => array(
                'description' => __('Only these kinds or groups of change (content, seo, product, site, search, note), comma-separated.', 'seoproanalytics'),
                'type'        => 'string',
                'default'     => '',
            ),
        );
        register_rest_route($ns, '/markers', $read + array(
            'callback' => array(__CLASS__, 'markers'),
            'args'     => $base + $changes,
        ));
        register_rest_route($ns, '/changes', $read + array(
            'callback' => array(__CLASS__, 'changes'),
            'args'     => $base + $changes + array(
                'limit'  => array('maximum' => SEOProAnalytics_Changes::MAX_LIMIT, 'default' => 50) + self::args(true)['limit'],
                'offset' => self::args(true)['offset'],
            ),
        ));

        $manage = array(__CLASS__, 'can_manage');
        $data   = array('data' => $base['data']);

        // Notes on the timeline (administrators), on the data set asked for.
        register_rest_route($ns, '/annotations', array(
            'methods'             => WP_REST_Server::CREATABLE,
            'permission_callback' => $manage,
            'callback'            => array(__CLASS__, 'annotate'),
            'args'                => $data + array(
                'note' => array(
                    'description' => __('What happened, such as "Newsletter sent" (up to 190 characters).', 'seoproanalytics'),
                    'type'        => 'string',
                    'required'    => true,
                ),
                'page' => array(
                    'description' => __('The page it is about (a path such as /pricing/); without it, the whole site.', 'seoproanalytics'),
                    'type'        => 'string',
                    'default'     => '',
                ),
                'time' => array(
                    'description' => __('When, in the site time zone (2026-10-05 or 2026-10-05 14:30), or Unix time; without it, now.', 'seoproanalytics'),
                    'type'        => 'string',
                    'default'     => '',
                ),
            ),
        ));
        register_rest_route($ns, '/annotations/(?P<id>\d+)', array(
            'methods'             => WP_REST_Server::DELETABLE,
            'permission_callback' => $manage,
            'callback'            => array(__CLASS__, 'delete_annotation'),
            'args'                => $data,
        ));

        // Goals and funnels: the report, and (administrators) their definitions.
        foreach (array('goals', 'funnels') as $type) {
            $fields = $type === 'goals' ? self::goal_args() : self::funnel_args();
            register_rest_route($ns, '/' . $type, array(
                $read + array(
                    'callback' => array(__CLASS__, $type),
                    'args'     => $base,
                ),
                array(
                    'methods'             => WP_REST_Server::CREATABLE,
                    'permission_callback' => $manage,
                    'callback'            => array(__CLASS__, 'save_' . $type),
                    'args'                => $data + $fields,
                ),
            ));
            register_rest_route($ns, '/' . $type . '/(?P<id>[a-z0-9]+)', array(
                array(
                    'methods'             => WP_REST_Server::EDITABLE,
                    'permission_callback' => $manage,
                    'callback'            => array(__CLASS__, 'save_' . $type),
                    'args'                => $data + $fields,
                ),
                array(
                    'methods'             => WP_REST_Server::DELETABLE,
                    'permission_callback' => $manage,
                    'callback'            => array(__CLASS__, 'delete_' . $type),
                    'args'                => $data,
                ),
            ));
        }
        // Business profiles (Settings → Data): visits from their tagged links.
        register_rest_route($ns, '/business-profiles', $read + array(
            'callback' => array(__CLASS__, 'business_profiles'),
            'args'     => $base,
        ));
        register_rest_route($ns, '/properties', $read + array(
            'callback' => array(__CLASS__, 'properties'),
            'args'     => $base + array(
                'key'    => array(
                    'description' => __('Property whose values to list; without it, the property keys are listed.', 'seoproanalytics'),
                    'type'        => 'string',
                    'default'     => '',
                ),
                'event'  => array(
                    'description' => __('Only properties sent with this event (without it: events and pages).', 'seoproanalytics'),
                    'type'        => 'string',
                    'default'     => '',
                ),
                'limit'  => self::args(true)['limit'],
                'offset' => self::args(true)['offset'],
            ),
        ));
        register_rest_route($ns, '/clicks', $read + array(
            'callback' => array(__CLASS__, 'clicks'),
            'args'     => $base + array(
                'kind'   => array(
                    'description' => __('Rows: clicked elements, dead clicks only, link destinations, file links, forms sent or pages.', 'seoproanalytics'),
                    'type'        => 'string',
                    'enum'        => SEOProAnalytics_Clicks::KINDS,
                    'default'     => 'elements',
                ),
                'page'   => array(
                    'description' => __('Only clicks on this page (a path such as /pricing/; * for any text).', 'seoproanalytics'),
                    'type'        => 'string',
                    'default'     => '',
                ),
                'limit'  => self::args(true)['limit'],
                'offset' => self::args(true)['offset'],
            ),
        ));
        $engine = array(
            'description' => __('Search engine: google (Search Console) or bing (Bing Webmaster Tools).', 'seoproanalytics'),
            'type'        => 'string',
            'enum'        => array_keys(SEOProAnalytics_Search::ENGINES),
            'default'     => 'google',
        );
        // Rankings, Opportunities and Content can also add up every engine with data.
        $combined = array(
            'description' => __('Search engine: google (Search Console), bing (Bing Webmaster Tools) or all (Combined: every engine with data added up; as the one engine while only one has data).', 'seoproanalytics'),
            'enum'        => array_merge(array_keys(SEOProAnalytics_Search::ENGINES), array(SEOProAnalytics_Search::ALL)),
        ) + $engine;
        // Sorted tables (Rankings, Content, Audit): the column's natural order unless asked otherwise.
        $order = array(
            'description' => __('Order of the sort: desc (most first) or asc (least first). Left out: the sort\'s natural order, lowest first for position (a lower position is better) and most first for the rest.', 'seoproanalytics'),
            'type'        => 'string',
            'enum'        => array('', 'asc', 'desc'),
            'default'     => '',
        );
        register_rest_route($ns, '/search', $read + array(
            'callback' => array(__CLASS__, 'search'),
            'args'     => $base + array(
                'engine' => $combined,
                'kind'   => array(
                    'description' => __('Rows: search queries, pages, countries, devices, appearance (countries, devices and appearance for the whole site, Google only) or days (the chart\'s points by day, week or month, newest first).', 'seoproanalytics'),
                    'type'        => 'string',
                    'enum'        => SEOProAnalytics_Search::KINDS,
                    'default'     => 'queries',
                ),
                'sort'   => array(
                    'description' => __('Order of the rows: impressions (the default), clicks, ctr or position; days also by day (their default, newest first). Ties: most impressions, then most clicks, first.', 'seoproanalytics'),
                    'type'        => 'string',
                    'enum'        => array_merge(array(''), SEOProAnalytics_Search::SORTS, array('day')),
                    'default'     => '',
                ),
                'order'  => $order,
                'page'   => array(
                    'description' => __('Only searches that showed this page (a path such as /pricing/; * for any text).', 'seoproanalytics'),
                    'type'        => 'string',
                    'default'     => '',
                ),
                'query'  => array(
                    'description' => __('Only this search query (* for any text).', 'seoproanalytics'),
                    'type'        => 'string',
                    'default'     => '',
                ),
                'limit'  => self::args(true)['limit'],
                'offset' => self::args(true)['offset'],
                'grain'  => array(
                    'description' => __('Points per day or month; auto (the default) picks days up to 120 days, else months. A weekly engine\'s page or query stays by week.', 'seoproanalytics'),
                    'type'        => 'string',
                    'enum'        => array('auto', 'day', 'month'),
                    'default'     => 'auto',
                ),
            ),
        ));
        register_rest_route($ns, '/opportunities', $read + array(
            'callback' => array(__CLASS__, 'opportunities'),
            'args'     => $base + array(
                'engine' => $combined,
                'kind'   => array(
                    'description' => __('Opportunities: striking (queries at position 4–20 that could reach the top three), ctr (top-10 queries with a CTR well under the site\'s own at that position), decay (pages losing clicks, with the likely cause), missing (top-20 queries whose words the page does not have) or overlap (queries shared by two or more pages, each with at least 10% of the impressions: candidates to review).', 'seoproanalytics'),
                    'type'        => 'string',
                    'enum'        => SEOProAnalytics_Opportunities::KINDS,
                    'default'     => 'striking',
                ),
                'limit'  => self::args(true)['limit'],
                'offset' => self::args(true)['offset'],
            ),
        ));
        register_rest_route($ns, '/audit', $read + array(
            'callback' => array(__CLASS__, 'audit'),
            'args'     => $base + array(
                'engine'  => $engine,
                'finding' => array(
                    'description' => __('Only pages with this finding; all findings when left out.', 'seoproanalytics'),
                    'type'        => 'string',
                    'enum'        => array_merge(array(''), SEOProAnalytics_Audit::FINDINGS),
                    'default'     => '',
                ),
                'sort'    => array(
                    'description' => __('Order of the pages by their search figures: impressions (the default), clicks, ctr or position.', 'seoproanalytics'),
                    'type'        => 'string',
                    'enum'        => array_merge(array(''), SEOProAnalytics_Search::SORTS),
                    'default'     => '',
                ),
                'order'   => $order,
                'limit'   => array('maximum' => SEOProAnalytics_Audit::MAX_LIMIT, 'default' => SEOProAnalytics_Audit::LIMIT) + self::args(true)['limit'],
                'offset'  => self::args(true)['offset'],
            ),
        ));
        register_rest_route($ns, '/links', $read + array(
            'callback' => array(__CLASS__, 'links'),
            'args'     => $base + array(
                'engine' => $engine,
                'kind'   => array(
                    'description' => __('Internal links: orphans (published pages no other page links to), converting (pages that convert from search with few pages linking to them) or missing (a page showing for a search does not link to the page that gets its clicks).', 'seoproanalytics'),
                    'type'        => 'string',
                    'enum'        => SEOProAnalytics_Links::KINDS,
                    'default'     => 'orphans',
                ),
                'goal'   => array(
                    'description' => __('ID of the goal whose conversions are counted; the first goal when left out.', 'seoproanalytics'),
                    'type'        => 'string',
                    'default'     => '',
                ),
                'limit'  => array('maximum' => SEOProAnalytics_Links::MAX_LIMIT, 'default' => SEOProAnalytics_Links::LIMIT) + self::args(true)['limit'],
                'offset' => self::args(true)['offset'],
            ),
        ));
        register_rest_route($ns, '/indexation', $read + array(
            'callback' => array(__CLASS__, 'indexation'),
            'args'     => $base + array(
                'engine' => $engine,
                'kind'   => array(
                    'description' => __('Indexation: pages (published pages with no search impressions in the engine\'s newest days, published before them) or sitemap (other addresses in the site\'s sitemaps, such as category and author archives, with none).', 'seoproanalytics'),
                    'type'        => 'string',
                    'enum'        => SEOProAnalytics_Indexation::KINDS,
                    'default'     => 'pages',
                ),
                'days'   => array(
                    'description' => __('Days without search impressions, and since publishing or first listing.', 'seoproanalytics'),
                    'type'        => 'integer',
                    'minimum'     => SEOProAnalytics_Indexation::MIN_DAYS,
                    'maximum'     => SEOProAnalytics_Indexation::MAX_DAYS,
                    'default'     => SEOProAnalytics_Indexation::DAYS,
                ),
                'limit'  => array('maximum' => SEOProAnalytics_Indexation::MAX_LIMIT, 'default' => SEOProAnalytics_Indexation::LIMIT) + self::args(true)['limit'],
                'offset' => self::args(true)['offset'],
            ),
        ));
        register_rest_route($ns, '/backlinks', $read + array(
            'callback' => array(__CLASS__, 'backlinks'),
            'args'     => $base + array(
                'kind'   => array(
                    'description' => __('Backlinks: links (pages of other sites linking to the site\'s pages now, newest first, each with value: 0–100, an estimate from rank, rel and classes), domains (the sites linking, with their visits in the period), pages (the site\'s pages they link to, most useful referring domains first, with followed and useful domains, the linking sites\' median DR and their class mix), lost (links lost in the period), reported (the referring pages link exports named, and their check), needs (pages with queries at position 4–20 with fewer useful referring domains, followed and not ignore, spam, paid_link, private_blog, profile_page, social_media or url_unlinked, than the median of the site\'s pages in the top three; need = clicks within reach × gap, highest first) or impact (pages that gained useful referring domains in the period, with their search clicks per day in the 28 days before the first new link and from then, against the whole site, and change_id, its backlink_new change to start an experiment from: associated with the links, not proof they caused the change). New and lost are counted in the period.', 'seoproanalytics'),
                    'type'        => 'string',
                    'enum'        => SEOProAnalytics_Backlinks::KINDS,
                    'default'     => 'links',
                ),
                'engine' => array('description' => __('needs and impact: the search engine whose data they read: google, bing or all (Combined).', 'seoproanalytics')) + $combined,
                'source' => array('type' => 'string', 'enum' => array_merge(array(''), array_keys(SEOProAnalytics_Backlinks::FOUND)), 'default' => ''),
                'limit'  => array('maximum' => SEOProAnalytics_Backlinks::MAX_LIMIT, 'default' => SEOProAnalytics_Backlinks::LIMIT) + self::args(true)['limit'],
                'offset' => self::args(true)['offset'],
            ) + self::backlink_triage_args(),
        ));
        require_once __DIR__ . '/class-seoproanalytics-backlinks-triage.php';
        register_rest_route($ns, '/backlinks/classify', array(
            'methods'             => WP_REST_Server::CREATABLE,
            'permission_callback' => $manage,
            'callback'            => array(__CLASS__, 'backlinks_classify'),
            'args'                => $data + array(
                'ids'     => array(
                    'description' => __('Link ids (the id of each row of the links and lost lists), comma-separated or a list; at most 500. Give one of ids, pages or sites.', 'seoproanalytics'),
                    'type'        => array('string', 'array'),
                ),
                'pages'   => array(
                    'description' => __('Referring page ids (the id of each row of the reported list), comma-separated or a list; at most 500. Every link on the page has its classes, found now or later.', 'seoproanalytics'),
                    'type'        => array('string', 'array'),
                ),
                'sites'   => array(
                    'description' => __('Sites (example.com, with their subdomains) or pages (example.com/page/), comma- or line-separated or a list; at most 500. They go on (or come off) the class lists of Settings → Backlinks, so every link from them has the classes, found now or later. Only lines equal to a site or page come off.', 'seoproanalytics'),
                    'type'        => array('string', 'array'),
                ),
                'classes' => array(
                    /* translators: %s: list of class names */
                    'description' => sprintf(__('Class names, comma-separated or a list: %s. Empty with set clears them.', 'seoproanalytics'), implode(', ', array_keys(SEOProAnalytics_Backlinks_Triage::classes()))),
                    'type'        => array('string', 'array'),
                    'default'     => '',
                ),
                'mode'    => array(
                    'description' => __('set (exactly these classes), add or remove.', 'seoproanalytics'),
                    'type'        => 'string',
                    'enum'        => array('set', 'add', 'remove'),
                    'default'     => 'set',
                ),
            ),
        ));
        register_rest_route($ns, '/backlinks/lists', array(
            'methods'             => WP_REST_Server::READABLE,
            'permission_callback' => $manage,
            'callback'            => array(__CLASS__, 'backlinks_lists'),
        ));
        register_rest_route($ns, '/inspections', $read + array(
            'callback' => array(__CLASS__, 'inspections'),
            'args'     => $base + array(
                'verdict'  => array(
                    'description' => __('Only pages with this verdict of Google\'s URL Inspection (the version in Google\'s index).', 'seoproanalytics'),
                    'type'        => 'string',
                    'enum'        => array_merge(array(''), array_values(SEOProAnalytics_Inspections::VERDICTS)),
                    'default'     => '',
                ),
                'coverage' => array(
                    'description' => __('Only pages with this coverage state, in Google\'s words (for example "Crawled - currently not indexed").', 'seoproanalytics'),
                    'type'        => 'string',
                    'default'     => '',
                ),
                'finding'  => array(
                    'description' => __('Only pages with this finding: robots_blocked, not_indexed, google_canonical (Google chose another canonical) or rich_errors.', 'seoproanalytics'),
                    'type'        => 'string',
                    'enum'        => array_merge(array(''), array_keys(SEOProAnalytics_Inspections::FLAGS)),
                    'default'     => '',
                ),
                'limit'    => array('maximum' => SEOProAnalytics_Inspections::MAX_LIMIT, 'default' => SEOProAnalytics_Inspections::LIMIT) + self::args(true)['limit'],
                'offset'   => self::args(true)['offset'],
            ),
        ));
        register_rest_route($ns, '/crawl-issues', $read + array(
            'callback' => array(__CLASS__, 'crawl_issues'),
            'args'     => $base + array(
                'issue'  => array(
                    'description' => __('Only addresses with this crawl issue of Bing\'s, or errors for every issue but redirects.', 'seoproanalytics'),
                    'type'        => 'string',
                    'enum'        => array_merge(array('', 'errors'), array_keys(SEOProAnalytics_Bing_Site_Data::ISSUES)),
                    'default'     => '',
                ),
                'limit'  => array('maximum' => SEOProAnalytics_Bing_Site_Data::MAX_LIMIT, 'default' => SEOProAnalytics_Bing_Site_Data::LIMIT) + self::args(true)['limit'],
                'offset' => self::args(true)['offset'],
            ),
        ));
        register_rest_route($ns, '/crawl', $read + array(
            'callback' => array(__CLASS__, 'crawl'),
            'args'     => $base + array(
                'kind'    => array(
                    'description' => __('The list: pages (with a problem the imported crawl saw), links (that fail or redirect) or clicked (outbound and affiliate destinations clicked in the range whose crawl answer failed).', 'seoproanalytics'),
                    'type'        => 'string',
                    'enum'        => array_merge(array(''), SEOProAnalytics_Crawl::KINDS),
                    'default'     => '',
                ),
                'status'  => array(
                    'description' => __('Only rows with this answer: errors, redirects; for pages also noindex, canonical (elsewhere) or title_missing as served.', 'seoproanalytics'),
                    'type'        => 'string',
                    'enum'        => array_merge(array(''), SEOProAnalytics_Crawl::STATUSES),
                    'default'     => '',
                ),
                'finding' => array(
                    'description' => __('Only pages with this crawl finding, including served_title and served_description (served text differs from WordPress).', 'seoproanalytics'),
                    'type'        => 'string',
                    'enum'        => array_merge(array(''), array_keys(SEOProAnalytics_Crawl::FINDINGS)),
                    'default'     => '',
                ),
                'limit'   => array('maximum' => SEOProAnalytics_Crawl::MAX_LIMIT, 'default' => SEOProAnalytics_Crawl::LIMIT) + self::args(true)['limit'],
                'offset'  => self::args(true)['offset'],
            ),
        ));
        register_rest_route($ns, '/coverage', $read + array(
            'callback' => array(__CLASS__, 'coverage'),
            'args'     => $base + array(
                'page' => array(
                    'description' => __('The page (a path such as /pricing/); or give post.', 'seoproanalytics'),
                    'type'        => 'string',
                    'default'     => '',
                ),
                'post' => array(
                    'description' => __('The post whose address is the page; or give page.', 'seoproanalytics'),
                    'type'        => 'integer',
                    'minimum'     => 0,
                    'default'     => 0,
                ),
            ),
        ));
        register_rest_route($ns, '/content', $read + array(
            'callback' => array(__CLASS__, 'content'),
            'args'     => $base + array(
                'engine' => $combined,
                'sort'   => array(
                    'description' => __('Order of the pages: impressions (the default), clicks, ctr, position, visits (from search), bounce_rate, visit_duration, or conversions and conversion_rate of the goal (with a goal).', 'seoproanalytics'),
                    'type'        => 'string',
                    'enum'        => array_merge(array(''), SEOProAnalytics_Content::SORTS),
                    'default'     => '',
                ),
                'order'  => $order,
                'goal'   => array(
                    'description' => __('ID of the goal whose conversions are counted; the first goal when left out.', 'seoproanalytics'),
                    'type'        => 'string',
                    'default'     => '',
                ),
                'owner'  => self::owner_arg(),
                'limit'  => self::args(true)['limit'],
                'offset' => self::args(true)['offset'],
            ),
        ));
        self::experiment_routes($read, $manage, $data);
        self::ab_test_routes($read, $data + array('filters' => $base['filters']));
        self::queue_routes($read, $manage, $base, $engine);
        self::target_routes($read, $manage, $base, $engine);
        self::owner_routes($base, $engine);
        // Outside data sources (administrators who may change the settings).
        $settings = array(__CLASS__, 'can_change');
        require_once __DIR__ . '/class-seoproanalytics-backlink-review.php';
        register_rest_route($ns, '/backlinks/review', array(
            'methods' => WP_REST_Server::READABLE,
            'permission_callback' => $settings,
            'callback' => array(__CLASS__, 'backlink_review'),
            'args' => $base + array('limit' => array('type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 25), 'offset' => self::args(true)['offset']),
        ));
        register_rest_route($ns, '/backlinks/review', array(
            'methods' => WP_REST_Server::CREATABLE,
            'permission_callback' => $settings,
            'callback' => array(__CLASS__, 'backlink_review'),
            'args' => $data + array(
                'scope' => array('type' => 'string', 'enum' => array('domain', 'url'), 'required' => true),
                'target' => array('type' => 'string', 'required' => true),
                'decision' => array('type' => 'string', 'enum' => SEOProAnalytics_Backlink_Review::DECISIONS, 'required' => true),
            ),
        ));
        register_rest_route($ns, '/backlinks/disavow.txt', array(
            'methods' => WP_REST_Server::READABLE,
            'permission_callback' => $settings,
            'callback' => array(__CLASS__, 'backlink_disavow'),
            'args' => $data,
        ));
        register_rest_route($ns, '/backlinks/disavow/merge', array(
            'methods' => WP_REST_Server::CREATABLE,
            'permission_callback' => $settings,
            'callback' => array(__CLASS__, 'backlink_merge'),
            'args' => $data + array('text' => array('type' => 'string')),
        ));
        add_filter('rest_pre_serve_request', array(__CLASS__, 'serve_disavow'), 10, 4);
        register_rest_route($ns, '/backlinks/import', array(
            array(
                'methods' => WP_REST_Server::CREATABLE,
                'permission_callback' => $settings,
                'callback' => array(__CLASS__, 'backlinks_import'),
            ),
            array(
                'methods' => WP_REST_Server::READABLE,
                'permission_callback' => $settings,
                'callback' => array(__CLASS__, 'backlinks_import_status'),
            ),
        ));
        register_rest_route($ns, '/backlinks/imports', array(
            'methods' => WP_REST_Server::READABLE,
            'permission_callback' => $settings,
            'callback' => array(__CLASS__, 'backlinks_imports'),
        ));
        register_rest_route($ns, '/backlinks/imports/(?P<id>\d+)/file', array(
            'methods' => WP_REST_Server::READABLE,
            'permission_callback' => $settings,
            'callback' => array(__CLASS__, 'backlinks_import_file'),
            'args' => array('id' => array('type' => 'integer', 'minimum' => 1, 'required' => true)),
        ));
        add_filter('rest_pre_serve_request', array(__CLASS__, 'serve_import_file'), 10, 4);
        register_rest_route($ns, '/crawl/readiness', array(
            'methods' => WP_REST_Server::CREATABLE, 'permission_callback' => $settings,
            'callback' => array(__CLASS__, 'crawl_readiness'),
            'args' => array('external' => array('type' => 'boolean', 'default' => false)),
        ));
        register_rest_route($ns, '/crawl/run', array(
            array('methods' => WP_REST_Server::READABLE, 'permission_callback' => $settings, 'callback' => array(__CLASS__, 'crawl_run')),
            array('methods' => WP_REST_Server::CREATABLE, 'permission_callback' => $settings, 'callback' => array(__CLASS__, 'crawl_run')),
            array('methods' => WP_REST_Server::DELETABLE, 'permission_callback' => $settings, 'callback' => array(__CLASS__, 'crawl_run')),
        ));
        register_rest_route($ns, '/crawl/import', array(
            array(
                'methods'             => WP_REST_Server::CREATABLE,
                'permission_callback' => $settings,
                'callback'            => array(__CLASS__, 'crawl_import'),
                'args'                => array(
                    'record' => array('type' => 'object', 'description' => __('Optional LibreCrawl crawl record with status, urls_crawled and config_snapshot.max_urls, used to establish completeness instead of owner confirmation.', 'seoproanalytics')),
                    'complete' => array('type' => 'boolean', 'default' => false, 'description' => __('The owner confirms this upload covered the whole site, without reaching its page limit.', 'seoproanalytics')),
                    'partial' => array('type' => 'boolean', 'default' => false, 'description' => __('Replace only covered pages (at most 100); keep other crawl findings.', 'seoproanalytics')),
                    'format' => array(
                        'description' => __('The crawler that made the files: librecrawl, aidevops or csv (any other CSV or JSON); told by the columns when left out.', 'seoproanalytics'),
                        'type'        => 'string',
                        'enum'        => array_merge(array(''), SEOProAnalytics_Crawl_Import::FORMATS),
                        'default'     => '',
                    ),
                ),
            ),
            array(
                'methods'             => WP_REST_Server::READABLE,
                'permission_callback' => $settings,
                'callback'            => array(__CLASS__, 'crawl_import_status'),
            ),
        ));
        register_rest_route($ns, '/crawl/imports', array(
            'methods'             => WP_REST_Server::READABLE,
            'permission_callback' => $settings,
            'callback'            => array(__CLASS__, 'crawl_imports'),
        ));
        register_rest_route($ns, '/crawl/imports/(?P<id>\d+)', array(
            'methods'             => WP_REST_Server::DELETABLE,
            'permission_callback' => $settings,
            'callback'            => array(__CLASS__, 'crawl_undo'),
            'args'                => array('id' => array('type' => 'integer', 'minimum' => 1, 'required' => true)),
        ));
        register_rest_route($ns, '/backlinks/check', array(
            'methods' => WP_REST_Server::CREATABLE,
            'permission_callback' => $settings,
            'callback' => array(__CLASS__, 'backlinks_check'),
        ));
        $source   = '/connections/(?P<source>[a-z0-9-]+)';
        register_rest_route($ns, '/connections', array(
            'methods'             => WP_REST_Server::READABLE,
            'permission_callback' => $settings,
            'callback'            => array(__CLASS__, 'connections'),
        ));
        register_rest_route($ns, $source, array(
            array(
                'methods'             => WP_REST_Server::READABLE,
                'permission_callback' => $settings,
                'callback'            => array(__CLASS__, 'connection'),
            ),
            array(
                'methods'             => WP_REST_Server::CREATABLE,
                'permission_callback' => $settings,
                'callback'            => array(__CLASS__, 'connect'),
                // An add-on's source takes its own fields too (connect()).
                'args'                => array(
                    'key'      => array(
                        'description' => __('The API key. Without it, the saved key is kept (to change the site).', 'seoproanalytics'),
                        'type'        => 'string',
                        'default'     => '',
                    ),
                    'property' => array(
                        'description' => __('Bing: the site (https://example.com/). Without it, the one for this site.', 'seoproanalytics'),
                        'type'        => 'string',
                        'default'     => '',
                    ),
                ),
            ),
            array(
                'methods'             => WP_REST_Server::DELETABLE,
                'permission_callback' => $settings,
                'callback'            => array(__CLASS__, 'disconnect'),
                'args'                => array(
                    'delete_data' => array(
                        'description' => __('Also delete the data imported from it.', 'seoproanalytics'),
                        'type'        => 'boolean',
                        'default'     => false,
                    ),
                ),
            ),
        ));
        register_rest_route($ns, $source . '/import', array(
            'methods'             => WP_REST_Server::CREATABLE,
            'permission_callback' => $settings,
            'callback'            => array(__CLASS__, 'import_now'),
        ));
        register_rest_route($ns, '/imports/(?P<id>\d+)', array(
            'methods'             => WP_REST_Server::DELETABLE,
            'permission_callback' => $settings,
            'callback'            => array(__CLASS__, 'undo_import'),
        ));
        self::migrate_routes($settings);

        register_rest_route($ns, '/demo', array(
            array(
                'methods'             => WP_REST_Server::READABLE,
                'permission_callback' => array(__CLASS__, 'can_read'),
                'callback'            => array(__CLASS__, 'demo_status'),
            ),
            array(
                'methods'             => WP_REST_Server::CREATABLE,
                'permission_callback' => $manage,
                'callback'            => array(__CLASS__, 'demo_make'),
                'args'                => array(
                    'days'    => array(
                        'description' => __('Days of demo visits back from today.', 'seoproanalytics'),
                        'type'        => 'integer',
                        'minimum'     => 1,
                        'maximum'     => SEOProAnalytics_Demo::MAX_DAYS,
                        'default'     => SEOProAnalytics_Demo::DAYS,
                    ),
                    'restart' => array(
                        'description' => __('Remove the demo data and make it again.', 'seoproanalytics'),
                        'type'        => 'boolean',
                        'default'     => false,
                    ),
                ),
            ),
            array(
                'methods'             => WP_REST_Server::DELETABLE,
                'permission_callback' => $manage,
                'callback'            => array(__CLASS__, 'demo_remove'),
            ),
        ));
        register_rest_route($ns, '/view', array(
            'methods'             => WP_REST_Server::EDITABLE,
            'permission_callback' => array(__CLASS__, 'can_read'),
            'callback'            => array(__CLASS__, 'view'),
            'args'                => array('data' => array('required' => true) + array_diff_key($base['data'], array('default' => true))),
        ));
    }

    /**
     * Register the A/B test routes: reports read with view_seoproanalytics;
     * tests are started and ended in the block editor, which records a
     * winner picked there for people who may edit the test's post.
     *
     * @param array<string,mixed> $read Read route base.
     * @param array<string,mixed> $args The data and filters arguments.
     */
    private static function ab_test_routes(array $read, array $args) {
        register_rest_route(SEOProAnalytics_Collection::REST_NAMESPACE, '/ab-tests/(?P<id>[a-z0-9]{6,32})/winner', array(
            'methods'             => 'POST',
            'callback'            => array(__CLASS__, 'ab_test_winner'),
            // The test's own post is checked in SEOProAnalytics_AB_Tests::pick_winner().
            'permission_callback' => static function () {
                return current_user_can('edit_posts');
            },
            'args'                => array(
                'variant' => array(
                    'description' => __('The winning variant\'s slug.', 'seoproanalytics'),
                    'type'        => 'string',
                    'pattern'     => '^[a-z0-9-]{1,64}$',
                    'required'    => true,
                ),
            ),
        ));
        $ns = SEOProAnalytics_Collection::REST_NAMESPACE;
        register_rest_route($ns, '/ab-tests', $read + array(
            'callback' => array(__CLASS__, 'ab_tests'),
            'args'     => $args + array(
                'status' => array(
                    'description' => __('Only tests in this state: draft, running, paused or ended.', 'seoproanalytics'),
                    'type'        => 'string',
                    'enum'        => array_merge(array(''), array_keys(SEOProAnalytics_AB_Tests::STATUSES)),
                    'default'     => '',
                ),
            ),
        ));
        register_rest_route($ns, '/ab-tests/(?P<id>[a-z0-9]{6,32})', $read + array(
            'callback' => array(__CLASS__, 'ab_test'),
            'args'     => $args,
        ));
    }

    /**
     * Register the experiment routes: read with view_seoproanalytics; add,
     * decide and delete with manage_options.
     *
     * @param array<string,mixed>   $read   Read route base.
     * @param callable              $manage Write permission callback.
     * @param array<string,mixed>   $data   The data argument.
     */
    private static function experiment_routes(array $read, $manage, array $data) {
        self::change_set_routes($read, $manage);
        $ns = SEOProAnalytics_Collection::REST_NAMESPACE;
        register_rest_route($ns, '/experiments', array(
            $read + array(
                'callback' => array(__CLASS__, 'experiments'),
                'args'     => $data + array(
                    'status' => array(
                        'description' => __('Only experiments in this state: running, due (running, with data through the review day), decided or cancelled.', 'seoproanalytics'),
                        'type'        => 'string',
                        'enum'        => array('', 'running', 'due', 'decided', 'cancelled'),
                        'default'     => '',
                    ),
                    'page'   => array(
                        'description' => __('Only experiments on this page (a path such as /pricing/).', 'seoproanalytics'),
                        'type'        => 'string',
                        'default'     => '',
                    ),
                    'owner'  => self::owner_arg(),
                ),
            ),
            array(
                'methods'             => WP_REST_Server::CREATABLE,
                'permission_callback' => $manage,
                'callback'            => array(__CLASS__, 'experiment_add'),
                'args'                => $data + self::experiment_args(),
            ),
        ));
        register_rest_route($ns, '/experiments/(?P<id>\d+)', array(
            $read + array(
                'callback' => array(__CLASS__, 'experiment'),
                'args'     => $data,
            ),
            array(
                'methods'             => WP_REST_Server::CREATABLE,
                'permission_callback' => $manage,
                'callback'            => array(__CLASS__, 'experiment_update'),
                'args'                => $data + array(
                    'action' => array(
                        'description' => __('decide (with result), note (change the note) or cancel.', 'seoproanalytics'),
                        'type'        => 'string',
                        'enum'        => array('decide', 'note', 'cancel'),
                        'required'    => true,
                    ),
                    'restore' => array('type' => 'boolean', 'default' => false),
                    'result' => array(
                        'description' => __('For decide: keep, revise, undo or inconclusive.', 'seoproanalytics'),
                        'type'        => 'string',
                        'enum'        => array_merge(array(''), array_values(SEOProAnalytics_Experiments::RESULTS)),
                        'default'     => '',
                    ),
                    'note'   => array(
                        'description' => __('Why, or what was learnt (up to 2,000 characters).', 'seoproanalytics'),
                        'type'        => 'string',
                        'default'     => '',
                    ),
                ),
            ),
            array(
                'methods'             => WP_REST_Server::DELETABLE,
                'permission_callback' => $manage,
                'callback'            => array(__CLASS__, 'experiment_delete'),
                'args'                => $data,
            ),
        ));
    }

    /**
     * Change sets are live site facts, even while viewing demo reports.
     * @param array<string,mixed> $read Read route base.
     * @param callable $manage Write permission.
     */
    private static function change_set_routes(array $read, $manage) {
        $ns = SEOProAnalytics_Collection::REST_NAMESPACE;
        register_rest_route($ns, '/change-sets', array(
            $read + array('callback' => array(__CLASS__, 'change_sets'), 'args' => array('before' => array('type' => 'integer', 'minimum' => 0, 'default' => 0))),
            array(
                'methods' => WP_REST_Server::CREATABLE,
                'permission_callback' => $manage,
                'callback' => array(__CLASS__, 'change_set_apply'),
                'args' => array(
                    'plan' => array('type' => 'object', 'required' => true),
                    'dry_run' => array('type' => 'boolean', 'default' => false),
                    'experiment' => array('type' => 'string', 'default' => ''),
                ) + array_diff_key(self::experiment_args(), array('name' => true, 'change' => true, 'start' => true, 'page' => true)),
            ),
        ));
        register_rest_route($ns, '/change-sets/(?P<id>\d+)', $read + array('callback' => array(__CLASS__, 'change_set')));
        register_rest_route($ns, '/change-sets/(?P<id>\d+)/undo', array(
            'methods' => WP_REST_Server::CREATABLE,
            'permission_callback' => $manage,
            'callback' => array(__CLASS__, 'change_set_undo'),
        ));
    }

    /** @param WP_REST_Request $request Request. @return WP_REST_Response */
    public static function change_sets($request) {
        require_once __DIR__ . '/class-seoproanalytics-change-sets.php';
        return rest_ensure_response(SEOProAnalytics_Change_Sets::list_sets((int) $request->get_param('before')));
    }

    /** @param WP_REST_Request $request Request. @return WP_REST_Response|WP_Error */
    public static function change_set($request) {
        require_once __DIR__ . '/class-seoproanalytics-change-sets.php';
        return rest_ensure_response(SEOProAnalytics_Change_Sets::get((int) $request->get_param('id')));
    }

    /** @param WP_REST_Request $request Request. @return WP_REST_Response|WP_Error */
    public static function change_set_apply($request) {
        require_once __DIR__ . '/class-seoproanalytics-change-sets.php';
        return rest_ensure_response(SEOProAnalytics_Change_Sets::apply((array) $request->get_param('plan'), $request->get_params()));
    }

    /** @param WP_REST_Request $request Request. @return WP_REST_Response|WP_Error */
    public static function change_set_undo($request) {
        require_once __DIR__ . '/class-seoproanalytics-change-sets.php';
        return rest_ensure_response(SEOProAnalytics_Change_Sets::undo((int) $request->get_param('id')));
    }

    /**
     * Register the decision queue routes: read with view_seoproanalytics; act
     * on an item with manage_options. Both take the period the list is
     * made from (range, from, to, page filters), the engine and the goal,
     * as does the loop export (GET /loop), which carries the queue.
     *
     * @param array<string,mixed> $read   Read route base.
     * @param callable            $manage Write permission callback.
     * @param array<string,mixed> $base   Report arguments.
     * @param array<string,mixed> $engine The engine argument.
     */
    private static function queue_routes(array $read, $manage, array $base, array $engine) {
        $ns   = SEOProAnalytics_Collection::REST_NAMESPACE;
        $list = array_diff_key($base, array('compare' => true)) + array(
            'engine' => $engine,
            'goal'   => array(
                'description' => __('ID of the goal whose conversions give a page its value; the first goal when left out.', 'seoproanalytics'),
                'type'        => 'string',
                'default'     => '',
            ),
        );
        $list['range'] = array('default' => '90d') + $list['range'];
        register_rest_route($ns, '/queue', $read + array(
            'callback' => array(__CLASS__, 'queue'),
            'args'     => $list + array(
                'status' => array(
                    'description' => __('Items in this state: open (new and accepted), new, accepted, done, dismissed (in the last 90 days) or all.', 'seoproanalytics'),
                    'type'        => 'string',
                    'enum'        => SEOProAnalytics_Queue::FILTERS,
                    'default'     => 'open',
                ),
                'kind'   => array(
                    'description' => __('Only items of this kind, e.g. refresh for the refresh planner\'s proposals; every kind when left out.', 'seoproanalytics'),
                    'type'        => 'string',
                    'enum'        => array_merge(array(''), array_values(SEOProAnalytics_Queue::KINDS)),
                    'default'     => '',
                ),
                'owner'  => self::owner_arg(),
                'limit'  => array('maximum' => SEOProAnalytics_Queue::MAX_LIMIT, 'default' => SEOProAnalytics_Queue::LIMIT) + self::args(true)['limit'],
                'offset' => self::args(true)['offset'],
            ),
        ));
        register_rest_route($ns, '/queue/(?P<key>[0-9a-fA-F]{16})', array(
            'methods'             => WP_REST_Server::CREATABLE,
            'permission_callback' => $manage,
            'callback'            => array(__CLASS__, 'queue_update'),
            'args'                => $list + array(
                'action'    => array(
                    'description' => __('accept, done (opens an experiment on the page with the kind\'s measure), dismiss (hidden for 90 days), restore (forget what was done with it), effort or note.', 'seoproanalytics'),
                    'type'        => 'string',
                    'enum'        => SEOProAnalytics_Queue::ACTIONS,
                    'required'    => true,
                ),
                'effort'    => array(
                    'description' => __('For effort: 1 (least) to 5.', 'seoproanalytics'),
                    'type'        => 'integer',
                    'minimum'     => 1,
                    'maximum'     => SEOProAnalytics_Queue::MAX_EFFORT,
                ),
                'note'      => array(
                    'description' => __('A note (up to 190 characters).', 'seoproanalytics'),
                    'type'        => 'string',
                    'default'     => '',
                ),
                'name'      => array(
                    'description' => __('For done: the experiment\'s name; without it, one made from the item.', 'seoproanalytics'),
                    'type'        => 'string',
                    'default'     => '',
                ),
                'days'      => array(
                    'description' => __('For done: days in each window of the experiment.', 'seoproanalytics'),
                    'type'        => 'integer',
                    'enum'        => SEOProAnalytics_Experiments::WINDOWS,
                    'default'     => SEOProAnalytics_Experiments::DAYS,
                ),
                'threshold' => array(
                    'description' => __('For done: the smallest change that counts: percent (default 10), or places for position (default 1).', 'seoproanalytics'),
                    'type'        => 'number',
                    'minimum'     => 0,
                ),
            ),
        ));
        $loop          = $list;
        $loop['range'] = array('default' => SEOProAnalytics_Loop::RANGE) + $list['range'];
        register_rest_route($ns, '/loop', $read + array(
            'callback' => array(__CLASS__, 'loop'),
            'args'     => $loop + array(
                'limit' => array(
                    'description' => __('Most queue items (open: new and accepted), best first.', 'seoproanalytics'),
                    'maximum'     => SEOProAnalytics_Loop::MAX_ITEMS,
                    'default'     => SEOProAnalytics_Loop::ITEMS,
                ) + self::args(true)['limit'],
                'rows'  => array(
                    'description' => __('Most export rows (a search and a page each), most impressions first.', 'seoproanalytics'),
                    'type'        => 'integer',
                    'minimum'     => 1,
                    'maximum'     => SEOProAnalytics_Loop::MAX_ROWS,
                    'default'     => SEOProAnalytics_Loop::ROWS,
                ),
            ),
        ));
    }

    /**
     * Register the search target routes: the report with view_seoproanalytics;
     * import and delete with manage_options.
     *
     * @param array<string,mixed> $read   Read route base.
     * @param callable            $manage Write permission callback.
     * @param array<string,mixed> $base   Report arguments.
     * @param array<string,mixed> $engine The engine argument.
     */
    private static function target_routes(array $read, $manage, array $base, array $engine) {
        $ns   = SEOProAnalytics_Collection::REST_NAMESPACE;
        $list = $base + array('engine' => $engine);
        $list['range'] = array('default' => '30d') + $list['range'];
        register_rest_route($ns, '/targets', array(
            $read + array(
                'callback' => array(__CLASS__, 'targets'),
                'args'     => $list + array(
                    'status' => array(
                        'description' => __('Targets in this status: all, open (candidate, targeted and live), candidate, targeted, live, won or retired.', 'seoproanalytics'),
                        'type'        => 'string',
                        'enum'        => SEOProAnalytics_Targets::FILTERS,
                        'default'     => 'all',
                    ),
                    'owner'  => self::owner_arg(),
                    'limit'  => array('maximum' => SEOProAnalytics_Targets::MAX_LIMIT, 'default' => SEOProAnalytics_Targets::LIMIT) + self::args(true)['limit'],
                    'offset' => self::args(true)['offset'],
                ),
            ),
            array(
                'methods'             => WP_REST_Server::CREATABLE,
                'permission_callback' => $manage,
                'callback'            => array(__CLASS__, 'targets_import'),
                'args'                => array('data' => $base['data']) + array(
                    'targets' => array(
                        'description' => __('Targets as a list of objects: query (or phrase), page (a path such as /pricing/ or an address on this site; or target_url), priority (0–100, or high, medium, low; 50 when left out) and status (candidate, targeted, live, won or retired; targeted when left out). Or give text.', 'seoproanalytics'),
                        'type'        => 'array',
                        'items'       => array('type' => 'object'),
                    ),
                    'text'    => array(
                        'description' => __('Targets as text: CSV or tab-separated (a header row naming query, page, priority and status, or those columns in that order), JSON, or the aidevops search targets table (TOON). Or give targets.', 'seoproanalytics'),
                        'type'        => 'string',
                        'default'     => '',
                    ),
                    'replace' => array(
                        'description' => __('Delete the targets that are not in this import.', 'seoproanalytics'),
                        'type'        => 'boolean',
                        'default'     => false,
                    ),
                    'only_new' => array(
                        'description' => __('Add new searches only: searches already listed are skipped (exists) and left as they are. replace is ignored.', 'seoproanalytics'),
                        'type'        => 'boolean',
                        'default'     => false,
                    ),
                    'source'  => array(
                        'description' => __('Where the targets come from: list (the default; the aidevops table when the text is TOON) or search (rows of the search reports).', 'seoproanalytics'),
                        'type'        => 'string',
                        'enum'        => array('list', 'search'),
                        'default'     => 'list',
                    ),
                ),
            ),
            array(
                'methods'             => WP_REST_Server::DELETABLE,
                'permission_callback' => $manage,
                'callback'            => array(__CLASS__, 'targets_delete'),
                'args'                => array('data' => $base['data']) + array(
                    'queries' => array(
                        'description' => __('The searches whose targets to delete.', 'seoproanalytics'),
                        'type'        => 'array',
                        'items'       => array('type' => 'string'),
                        'default'     => array(),
                    ),
                    'all'     => array(
                        'description' => __('Delete every target.', 'seoproanalytics'),
                        'type'        => 'boolean',
                        'default'     => false,
                    ),
                ),
            ),
        ));
        register_rest_route($ns, '/targets/queries', array(
            $read + array(
                'callback' => array(__CLASS__, 'targets_queries'),
                'args'     => array('data' => $base['data']),
            ),
        ));
        $keywords = array(
            'description' => __('Every focus keyword of a page, not only its main one.', 'seoproanalytics'),
            'type'        => 'boolean',
            'default'     => false,
        );
        register_rest_route($ns, '/targets/suggestions', array(
            array(
                'methods'             => WP_REST_Server::READABLE,
                'permission_callback' => $manage,
                'callback'            => array(__CLASS__, 'targets_suggestions'),
                'args'                => array('data' => $base['data'], 'all_keywords' => $keywords),
            ),
            array(
                'methods'             => WP_REST_Server::CREATABLE,
                'permission_callback' => $manage,
                'callback'            => array(__CLASS__, 'targets_suggestions_import'),
                'args'                => array('data' => $base['data'], 'all_keywords' => $keywords) + array(
                    'queries' => array(
                        'description' => __('The suggested searches to import; left out for every new one.', 'seoproanalytics'),
                        'type'        => 'array',
                        'items'       => array('type' => 'string'),
                        'default'     => array(),
                    ),
                ),
            ),
        ));
    }

    /**
     * Register the owner routes: the summary (who owns what) for people
     * who can read the statistics and edit posts; setting owners for
     * them too, as SEOProAnalytics_Owners::assign() allows (administrators
     * give anyone work; people who can own take it and give it back).
     *
     * @param array<string,mixed> $base   Report arguments.
     * @param array<string,mixed> $engine The engine argument.
     */
    private static function owner_routes(array $base, array $engine) {
        require_once __DIR__ . '/class-seoproanalytics-owners.php';
        $ns    = SEOProAnalytics_Collection::REST_NAMESPACE;
        $owner = array(__CLASS__, 'can_see_owners');
        $list  = array_diff_key($base, array('compare' => true)) + array(
            'engine' => $engine,
            'goal'   => array(
                'description' => __('ID of the goal whose conversions give a page its value; the first goal when left out.', 'seoproanalytics'),
                'type'        => 'string',
                'default'     => '',
            ),
        );
        $list['range'] = array('default' => '90d') + $list['range'];
        register_rest_route($ns, '/owners', array(
            array(
                'methods'             => WP_REST_Server::READABLE,
                'permission_callback' => $owner,
                'callback'            => array(__CLASS__, 'owners'),
                'args'                => $list,
            ),
            array(
                'methods'             => WP_REST_Server::CREATABLE,
                'permission_callback' => $owner,
                'callback'            => array(__CLASS__, 'owners_assign'),
                'args'                => array('data' => $base['data']) + array(
                    'kind' => array(
                        'description' => __('What to set the owner of: page, target, item (a Plan item) or experiment.', 'seoproanalytics'),
                        'type'        => 'string',
                        'enum'        => array_values(SEOProAnalytics_Owners::KINDS),
                        'required'    => true,
                    ),
                    'ids'  => array(
                        /* translators: %d: most objects */
                        'description' => sprintf(__('Up to %d of them: pages (paths such as /pricing/ or addresses on this site), target searches, Plan item keys or experiment ids.', 'seoproanalytics'), SEOProAnalytics_Owners::MAX_IDS),
                        'type'        => 'array',
                        'items'       => array('type' => 'string'),
                        'required'    => true,
                    ),
                    'user' => array(
                        'description' => __('The owner: a user id, me, none (nobody, on purpose) or default (forget the owner set, so the default applies again).', 'seoproanalytics'),
                        'type'        => 'string',
                        'required'    => true,
                    ),
                ),
            ),
        ));
    }

    /**
     * The owner filter of a list.
     *
     * @return array<string,mixed>
     */
    private static function owner_arg() {
        return array(
            'description' => __('Only those of this owner: me, none (owned by nobody) or a user id; everyone\'s when left out. For people who can edit posts.', 'seoproanalytics'),
            'type'        => 'string',
            'default'     => '',
        );
    }

    /**
     * Whether the current user may see and set owners: they read the
     * statistics and edit posts.
     *
     * @return bool
     */
    public static function can_see_owners() {
        return self::can_read() && current_user_can('edit_posts');
    }

    /**
     * Fields of a new experiment.
     *
     * @return array<string,array<string,mixed>>
     */
    private static function experiment_args() {
        return array(
            'name'       => array(
                'description' => __('The change and what it should do, in one line (up to 190 characters).', 'seoproanalytics'),
                'type'        => 'string',
                'required'    => true,
            ),
            'change'     => array(
                'description' => __('The change it measures (its id in /changes): its time is the start, its page the page unless page is given.', 'seoproanalytics'),
                'type'        => 'integer',
                'minimum'     => 0,
                'default'     => 0,
            ),
            'start'      => array(
                'description' => __('Without change: when the change was made, in the site time zone (2026-10-05 or 2026-10-05 14:30) or Unix time; without it, now.', 'seoproanalytics'),
                'type'        => 'string',
                'default'     => '',
            ),
            'page'       => array(
                'description' => __('Its page (a path such as /pricing/), or several, comma-separated (up to 50).', 'seoproanalytics'),
                'type'        => 'string',
                'default'     => '',
            ),
            'days'       => array(
                'description' => __('Days in each window, before and after.', 'seoproanalytics'),
                'type'        => 'integer',
                'enum'        => SEOProAnalytics_Experiments::WINDOWS,
                'default'     => SEOProAnalytics_Experiments::DAYS,
            ),
            'engine'     => array(
                'description' => __('Search engine whose data measures it: google or bing.', 'seoproanalytics'),
                'type'        => 'string',
                'enum'        => array_keys(SEOProAnalytics_Search::ENGINES),
                'default'     => 'google',
            ),
            'metric'     => array(
                'description' => __('What it should change: clicks, impressions, ctr, position, visits (from search) or conversions (of goal).', 'seoproanalytics'),
                'type'        => 'string',
                'enum'        => array_values(SEOProAnalytics_Experiments::METRICS),
                'default'     => 'clicks',
            ),
            'direction'  => array(
                'description' => __('Expected direction: up or down (for position, up means a better place).', 'seoproanalytics'),
                'type'        => 'string',
                'enum'        => array_values(SEOProAnalytics_Experiments::DIRECTIONS),
                'default'     => 'up',
            ),
            'threshold'  => array(
                'description' => __('The smallest change that counts: percent for counts and CTR (default 10), places for position (default 1).', 'seoproanalytics'),
                'type'        => 'number',
                'minimum'     => 0,
            ),
            'goal'       => array(
                'description' => __('For conversions: the goal\'s id.', 'seoproanalytics'),
                'type'        => 'string',
                'default'     => '',
            ),
            'hypothesis' => array(
                'description' => __('The reasoning, in more words (up to 2,000 characters).', 'seoproanalytics'),
                'type'        => 'string',
                'default'     => '',
            ),
            'note'       => array(
                'description' => __('A note (up to 2,000 characters).', 'seoproanalytics'),
                'type'        => 'string',
                'default'     => '',
            ),
        );
    }

    /**
     * Whether the current user may make and remove demo data.
     *
     * @return bool
     */
    public static function can_manage() {
        return current_user_can('manage_options');
    }

    /**
     * Whether the current user may change the settings (connections).
     *
     * @return bool
     */
    public static function can_change() {
        return SEOProAnalytics_Settings::can_change();
    }

    /** @return WP_REST_Response Stored IndexNow status; never sends a request. */
    public static function indexnow() {
        require_once __DIR__ . '/class-seoproanalytics-indexnow.php';
        return new WP_REST_Response(SEOProAnalytics_IndexNow::status());
    }

    /**
     * Load the connection classes.
     */
    private static function load_connections() {
        require_once SEOPROANALYTICS_DIR . 'includes/stats/class-seoproanalytics-connections.php';
        require_once SEOPROANALYTICS_DIR . 'includes/stats/class-seoproanalytics-search-import.php';
    }

    /**
     * The source of a /connections/{source} request, or an error.
     *
     * @param WP_REST_Request $request Request.
     * @return string|WP_Error
     */
    private static function source($request) {
        self::load_connections();
        $source = (string) $request->get_param('source');
        if (!SEOProAnalytics_Connections::source_class($source)) {
            // The add-on's connections say so, with where to get it.
            return SEOProAnalytics_Connections::needs_addon($source);
        }
        return $source;
    }

    /**
     * GET /connections: every registered source's status, and the add-on's
     * connections not registered here with the add-on's page. Never
     * credentials.
     *
     * @return WP_REST_Response
     */
    public static function connections() {
        self::load_connections();
        return rest_ensure_response(array(
            'sources' => SEOProAnalytics_Connections::statuses(),
            'addon'   => array('url' => SEOProAnalytics_Connections::connect_url(), 'needed' => SEOProAnalytics_Connections::placeholders()),
        ));
    }

    /**
     * GET /connections/{source}: its status.
     *
     * @param WP_REST_Request $request Request.
     * @return WP_REST_Response|WP_Error
     */
    public static function connection($request) {
        $source = self::source($request);
        return is_wp_error($source) ? $source : rest_ensure_response(SEOProAnalytics_Connections::status($source));
    }

    /**
     * POST /connections/{source}: connect it, or change its property. The
     * import starts in cron a few seconds later. The adapter's connect()
     * (or configure()) gets every field sent, and checks its own: key and
     * property are '' when not sent. An adapter whose ADMIN_CONNECT is
     * true takes its credentials only in wp-admin or WP-CLI.
     *
     * @param WP_REST_Request $request Request.
     * @return WP_REST_Response|WP_Error
     */
    public static function connect($request) {
        $source = self::source($request);
        if (is_wp_error($source)) {
            return $source;
        }
        $class = (string) SEOProAnalytics_Connections::source_class($source);
        if (defined($class . '::ADMIN_CONNECT') && constant($class . '::ADMIN_CONNECT')) {
            return new WP_Error('seoproanalytics_connect_admin', __('Set this connection\'s credentials only in wp-admin or WP-CLI.', 'seoproanalytics'), array('status' => 403));
        }
        $input = array_diff_key((array) $request->get_params(), array('source' => true, '_locale' => true));
        $input['key']      = (string) $request->get_param('key');
        $input['property'] = (string) $request->get_param('property');
        $status = SEOProAnalytics_Connections::connect($source, $input);
        return self::with_status($status, 400);
    }

    /**
     * DELETE /connections/{source}: forget its credentials, and its data
     * when asked.
     *
     * @param WP_REST_Request $request Request.
     * @return WP_REST_Response|WP_Error
     */
    public static function disconnect($request) {
        self::load_connections();
        // Also an add-on connection saved here while the add-on is not registered (its key is forgotten).
        $source = (string) $request->get_param('source');
        return self::with_status(SEOProAnalytics_Connections::disconnect($source, (bool) $request->get_param('delete_data')), 400);
    }

    /**
     * GET /vitals: local field data only.
     *
     * @param WP_REST_Request $request Request.
     * @return WP_REST_Response|WP_Error
     */
    public static function vitals($request) {
        $page = (string) $request->get_param('page');
        if ($page !== '' && SEOProAnalytics_Vitals::local_url($page) === '') {
            return new WP_Error('seoproanalytics_vitals_page', __('Use a local page path.', 'seoproanalytics'), array('status' => 400));
        }
        return rest_ensure_response(self::on_data((string) $request->get_param('data'), static function () use ($page) {
            return SEOProAnalytics_Vitals::report($page);
        }));
    }

    /**
     * POST /connections/{source}/import: import now, for up to
     * SEOProAnalytics_Search_Import::BUDGET seconds; the job carries on.
     *
     * @param WP_REST_Request $request Request.
     * @return WP_REST_Response|WP_Error
     */
    public static function import_now($request) {
        $source = self::source($request);
        if (is_wp_error($source)) {
            return $source;
        }
        // A source with its own connection and job runs it (its adapter's import_now()).
        $class = (string) SEOProAnalytics_Connections::source_class($source);
        if (method_exists($class, 'import_now')) {
            return self::with_status($class::import_now(), 502);
        }
        if (!SEOProAnalytics_Connections::get($source)) {
            return new WP_Error('seoproanalytics_not_connected', __('This source is not connected.', 'seoproanalytics'), array('status' => 400));
        }
        $result = SEOProAnalytics_Search_Import::run($source, SEOProAnalytics_Search_Import::BUDGET, true);
        if (is_wp_error($result)) {
            return self::with_status($result, $result->get_error_code() === 'seoproanalytics_import_busy' ? 409 : 502);
        }
        if (!$result['done']) {
            wp_schedule_single_event(time() + MINUTE_IN_SECONDS, SEOProAnalytics_Search_Import::HOOK, array('more'));
        }
        return rest_ensure_response(array('run' => $result) + SEOProAnalytics_Connections::status($source));
    }

    /**
     * DELETE /imports/{id}: undo an import (delete the rows it wrote).
     *
     * @param WP_REST_Request $request Request.
     * @return WP_REST_Response|WP_Error
     */
    public static function undo_import($request) {
        global $wpdb;
        self::load_connections();
        if (!SEOProAnalytics_Schema::is_current()) {
            return new WP_Error('seoproanalytics_tables', __('The statistics tables are being updated. Try again after visiting wp-admin.', 'seoproanalytics'), array('status' => 503));
        }
        $id = (int) $request->get_param('id');
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- our own table, by primary key.
        $source = (string) $wpdb->get_var($wpdb->prepare('SELECT source FROM %i WHERE id = %d', SEOProAnalytics_Schema::table('imports'), $id));
        require_once SEOPROANALYTICS_DIR . self::MIGRATE_FILE;
        // Imports from other statistics plugins undo through SEOProAnalytics_Migrate.
        $deleted = SEOProAnalytics_Migrate::owns($source) ? SEOProAnalytics_Migrate::undo($id) : SEOProAnalytics_Search_Import::undo($id);
        if (is_wp_error($deleted)) {
            return self::with_status($deleted, 404);
        }
        return rest_ensure_response(array('id' => $id, 'deleted' => $deleted));
    }

    /**
     * Routes of imports from other statistics plugins (SEOProAnalytics_Migrate),
     * for administrators who may change the settings.
     *
     * @param callable $settings Permission callback.
     */
    private static function migrate_routes($settings) {
        $ns     = SEOProAnalytics_Collection::REST_NAMESPACE;
        $source = '/migrate/(?P<source>[a-z0-9-]+)';
        register_rest_route($ns, '/migrate', array(
            'methods'             => WP_REST_Server::READABLE,
            'permission_callback' => $settings,
            'callback'            => array(__CLASS__, 'migrate_status'),
            'args'                => array(
                'fresh' => array(
                    'description' => __('Look for statistics plugins again instead of the list kept for ten minutes.', 'seoproanalytics'),
                    'type'        => 'boolean',
                    'default'     => false,
                ),
            ),
        ));
        register_rest_route($ns, $source, array(
            'methods'             => WP_REST_Server::CREATABLE,
            'permission_callback' => $settings,
            'callback'            => array(__CLASS__, 'migrate_run'),
            'args'                => array(
                'dry_run' => array(
                    'description' => __('Only say what the import would do: days, rows, overlap with other plugins and SEO Pro Analytics\'s own days, and settings. Writes nothing.', 'seoproanalytics'),
                    'type'        => 'boolean',
                    'default'     => false,
                ),
                'prefer'  => array(
                    'description' => __('When another plugin not imported yet has statistics on the same days: the one whose counts fill those days. It imports first.', 'seoproanalytics'),
                    'type'        => 'string',
                    'default'     => '',
                ),
                'from'    => array(
                    'description' => __('First day to import (YYYY-MM-DD); default: its first day.', 'seoproanalytics'),
                    'type'        => 'string',
                    'default'     => '',
                ),
                'to'      => array(
                    'description' => __('Last day to import (YYYY-MM-DD); default: its last day.', 'seoproanalytics'),
                    'type'        => 'string',
                    'default'     => '',
                ),
                'settings' => array(
                    'description' => __('The settings to carry over, by key (the dry run lists them); an empty list carries none. Default: every one the dry run would change.', 'seoproanalytics'),
                    'type'        => 'array',
                    'items'       => array('type' => 'string'),
                ),
            ),
        ));
        register_rest_route($ns, $source . '/cleanup', array(
            'methods'             => WP_REST_Server::CREATABLE,
            'permission_callback' => $settings,
            'callback'            => array(__CLASS__, 'migrate_cleanup'),
            'args'                => array(
                'dry_run' => array(
                    'description' => __('Only list what it left behind (default). false deletes exactly that list; it cannot be undone.', 'seoproanalytics'),
                    'type'        => 'boolean',
                    'default'     => true,
                ),
            ),
        ));
    }

    /**
     * GET /migrate: statistics plugins found, the import job and the
     * imports so far. While an import runs, each read moves it on for a
     * few seconds (the Import tab polls), as well as cron.
     *
     * @param WP_REST_Request $request Request.
     * @return WP_REST_Response
     */
    public static function migrate_status($request) {
        require_once SEOPROANALYTICS_DIR . self::MIGRATE_FILE;
        SEOProAnalytics_Migrate::nudge();
        return rest_ensure_response(SEOProAnalytics_Migrate::status((bool) $request->get_param('fresh')));
    }

    /**
     * POST /migrate/{source}: the dry run, or start an import (cron and
     * GET /migrate carry it on).
     *
     * @param WP_REST_Request $request Request.
     * @return WP_REST_Response|WP_Error
     */
    public static function migrate_run($request) {
        require_once SEOPROANALYTICS_DIR . self::MIGRATE_FILE;
        $source = (string) $request->get_param('source');
        $args   = array(
            'from'   => (string) $request->get_param('from'),
            'to'     => (string) $request->get_param('to'),
            'prefer' => (string) $request->get_param('prefer'),
        );
        if ($request->has_param('settings') && is_array($request->get_param('settings'))) {
            $args['settings'] = array_map('strval', $request->get_param('settings'));
        }
        if ($request->get_param('dry_run')) {
            return self::with_status(SEOProAnalytics_Migrate::plan($source, $args), 400);
        }
        $job = SEOProAnalytics_Migrate::start($source, $args);
        return is_wp_error($job) ? self::with_status($job, 400) : rest_ensure_response(array('job' => $job));
    }

    /**
     * POST /migrate/{source}/cleanup: list, or remove, what a plugin left
     * behind; refused while it is active. People only: no ability does it.
     *
     * @param WP_REST_Request $request Request.
     * @return WP_REST_Response|WP_Error
     */
    public static function migrate_cleanup($request) {
        require_once SEOPROANALYTICS_DIR . self::MIGRATE_FILE;
        return self::with_status(SEOProAnalytics_Migrate::cleanup((string) $request->get_param('source'), (bool) $request->get_param('dry_run')), 400);
    }

    /**
     * An answer, or its error with an HTTP status.
     *
     * @param mixed    $answer Answer or WP_Error.
     * @param int      $status Status for an error without one.
     * @return WP_REST_Response|WP_Error
     */
    private static function with_status($answer, $status) {
        if (!is_wp_error($answer)) {
            return rest_ensure_response($answer);
        }
        $data = $answer->get_error_data();
        $data = is_array($data) ? $data : array();
        // Google's own status (401, 403) is not this request's.
        if (empty($data['status']) || strpos((string) $answer->get_error_code(), 'seoproanalytics_google_') === 0) {
            $data['status'] = $status;
            // Only when changed: add_data() keeps the old data in additional_data.
            $answer->add_data($data);
        }
        return $answer;
    }

    /**
     * GET /demo: whether demo data is made.
     *
     * @return WP_REST_Response
     */
    public static function demo_status() {
        return rest_ensure_response(SEOProAnalytics_Demo::status() + array('viewing' => SEOProAnalytics_Demo::viewing()));
    }

    /**
     * POST /demo: start making demo data, or carry on; one call does up to
     * SEOProAnalytics_Demo::BUDGET seconds of work. Call again until ready.
     *
     * @param WP_REST_Request $request Request.
     * @return WP_REST_Response|WP_Error
     */
    public static function demo_make($request) {
        $status = SEOProAnalytics_Demo::status();
        if (($status['status'] === 'none' || $request->get_param('restart')) && !SEOProAnalytics_Demo::start((int) $request->get_param('days'))) {
            return new WP_Error('seoproanalytics_demo_tables', __('The demo tables could not be made.', 'seoproanalytics'), array('status' => 500));
        }
        return rest_ensure_response(SEOProAnalytics_Demo::step() + array('viewing' => SEOProAnalytics_Demo::viewing()));
    }

    /**
     * DELETE /demo: remove the demo tables. Live data is untouched.
     *
     * @return WP_REST_Response
     */
    public static function demo_remove() {
        SEOProAnalytics_Demo::remove();
        return rest_ensure_response(SEOProAnalytics_Demo::status() + array('viewing' => SEOProAnalytics_Demo::viewing()));
    }

    /**
     * POST /view: the data set the current user sees on the screens.
     *
     * @param WP_REST_Request $request Request.
     * @return WP_REST_Response
     */
    public static function view($request) {
        $data = $request->get_param('data') === 'demo' ? 'demo' : 'live';
        if ($data === 'demo') {
            update_user_meta(get_current_user_id(), SEOProAnalytics_Demo::USER_META, 'demo');
        } else {
            delete_user_meta(get_current_user_id(), SEOProAnalytics_Demo::USER_META);
        }
        return rest_ensure_response(array('data' => $data));
    }

    /**
     * Whether the current user may read statistics.
     *
     * @return bool
     */
    public static function can_read() {
        return current_user_can(self::CAP);
    }

    /**
     * Separate public routes: share credentials never authorize normal routes.
     *
     * @param non-falsy-string $ns REST namespace.
     */
    private static function share_routes($ns) {
        $manage = array(__CLASS__, 'can_manage');
        register_rest_route($ns, '/email/test', array(
            'methods' => 'POST', 'permission_callback' => $manage,
            'args' => array('to' => array('type' => 'string', 'format' => 'email')),
            'callback' => static function ($request) {
                require_once SEOPROANALYTICS_DIR . 'includes/stats/class-seoproanalytics-mail.php';
                $result = SEOProAnalytics_Mail::test($request['to'] ?? '');
                return is_wp_error($result) ? $result : array('sent' => true);
            },
        ));
        register_rest_route($ns, '/email/log', array(
            'methods' => 'GET', 'permission_callback' => $manage,
            'callback' => static function () {
                require_once SEOPROANALYTICS_DIR . 'includes/stats/class-seoproanalytics-mail.php';
                return new WP_REST_Response(SEOProAnalytics_Mail::log_entries(), 200, array('Cache-Control' => 'private, no-store'));
            },
        ));
        register_rest_route($ns, '/shares', array(
            array('methods' => 'GET', 'permission_callback' => $manage, 'callback' => static function () {
                $shares   = array_values(array_map(array('SEOProAnalytics_Shares', 'summary'), SEOProAnalytics_Shares::all()));
                $defaults = SEOProAnalytics_Shares::branding(array());
                // Logo previews for the editor: id => image address.
                $logos = array();
                foreach (array_merge(array($defaults), array_column($shares, 'branding')) as $brand) {
                    foreach (array('logo', 'agency_logo') as $key) {
                        $id = (int) ($brand[$key] ?? 0);
                        if ($id && !isset($logos[$id])) {
                            $logos[$id] = SEOProAnalytics_Shares::local_logo($id);
                        }
                    }
                }
                require_once __DIR__ . '/class-seoproanalytics-email-reports.php';
                $intros = array();
                foreach (array_keys(SEOProAnalytics_Email_Reports::DAYS) as $interval) {
                    $period = SEOProAnalytics_Email_Reports::period($interval, new DateTimeImmutable('now', wp_timezone()));
                    if ($period) {
                        $intros[$interval] = SEOProAnalytics_Email_Reports::intro($interval, $period);
                    }
                }
                // Search sections: one for each engine with data. A new report starts with the sections that have data.
                return array(
                    'shares'   => $shares,
                    'defaults' => $defaults,
                    'logos'    => (object) array_filter($logos),
                    'engines'  => SEOProAnalytics_Search::engines(),
                    'sections' => SEOProAnalytics_Shares::sections_with_data(),
                    'email_intros' => $intros,
                );
            }),
            array('methods' => 'POST', 'permission_callback' => $manage, 'callback' => static function ($request) {
                return SEOProAnalytics_Shares::save((array) $request->get_json_params());
            }),
        ));
        register_rest_route($ns, '/shares/(?P<id>[a-zA-Z0-9]+)', array(
            array('methods' => 'POST', 'permission_callback' => $manage, 'callback' => static function ($request) {
                return SEOProAnalytics_Shares::save((array) $request->get_json_params(), $request['id']);
            }),
            array('methods' => 'DELETE', 'permission_callback' => $manage, 'callback' => static function ($request) {
                return SEOProAnalytics_Shares::revoke($request['id']);
            }),
        ));
        register_rest_route($ns, '/shares/(?P<id>[a-zA-Z0-9]+)/renew', array(
            'methods' => 'POST', 'permission_callback' => $manage, 'callback' => static function ($request) {
                return SEOProAnalytics_Shares::revoke($request['id'], true);
            },
        ));
        register_rest_route($ns, '/shares/(?P<id>[a-zA-Z0-9]+)/link', array(
            'methods' => 'GET', 'permission_callback' => $manage, 'callback' => static function ($request) {
                return SEOProAnalytics_Shares::link($request['id']);
            },
        ));
        register_rest_route($ns, '/shares/(?P<id>[a-zA-Z0-9]+)/email/test', array(
            'methods' => 'POST', 'permission_callback' => $manage, 'callback' => static function ($request) {
                require_once __DIR__ . '/class-seoproanalytics-email-reports.php';
                $result = SEOProAnalytics_Email_Reports::send($request['id'], true);
                return is_wp_error($result) ? $result : array('sent' => true);
            },
        ));
        register_rest_route($ns, '/share/(?P<token>[a-f0-9]{32})', array(
            'methods' => 'POST', 'permission_callback' => '__return_true', 'callback' => array(__CLASS__, 'share_open'),
            'args' => array('password' => array('type' => 'string', 'maxLength' => 256, 'default' => '')),
        ));
        // Each read's own arguments are checked against its normal route (share_report()).
        register_rest_route($ns, '/share/(?P<token>[a-f0-9]{32})/(?P<section>' . implode('|', SEOProAnalytics_Shares::SECTIONS) . ')/(?P<report>' . implode('|', array_unique(array_merge(...array_values(SEOProAnalytics_Shares::REPORTS)))) . ')', array(
            'methods' => 'GET', 'permission_callback' => '__return_true', 'callback' => array(__CLASS__, 'share_report'),
            'args' => self::args(false) + array(
                'page' => array('type' => 'string', 'maxLength' => 2048),
                'limit' => array('type' => 'integer', 'minimum' => 1, 'maximum' => SEOProAnalytics_Query::MAX_LIMIT),
                'offset' => array('type' => 'integer', 'minimum' => 0, 'maximum' => 1000),
            ),
        ));
        add_filter('rest_post_dispatch', array(__CLASS__, 'share_headers'), 10, 3);
    }

    /**
     * Sensitive answers and errors must never enter a public cache.
     *
     * @param WP_REST_Response $response Response.
     * @param WP_REST_Server   $server   Server.
     * @param WP_REST_Request  $request  Request.
     * @return WP_REST_Response
     */
    public static function share_headers($response, $server, $request) {
        unset($server);
        if (strpos($request->get_route(), '/seoproanalytics/v1/share') === 0) {
            $response->header('Cache-Control', 'private, no-store');
            $response->header('X-Robots-Tag', 'noindex, nofollow');
            $response->header('Referrer-Policy', 'no-referrer');
        }
        return $response;
    }

    /**
     * Unlock and open; uniform failures for token, expiry and password.
     *
     * @param WP_REST_Request $request Request.
     * @return array|WP_Error
     */
    public static function share_open($request) {
        $share = SEOProAnalytics_Shares::find((string) $request['token']);
        if (is_wp_error($share)) {
            return $share;
        }
        if (!SEOProAnalytics_Shares::quota($share, 'passwords', 5)) {
            return SEOProAnalytics_Shares::denied();
        }
        $grant = (string) $request->get_header('X-Seoproanalytics-Unlock');
        if (!SEOProAnalytics_Shares::unlocked($share, $grant) && !wp_check_password((string) $request->get_param('password'), $share['password_hash'])) {
            return SEOProAnalytics_Shares::denied();
        }
        $share = SEOProAnalytics_Shares::opened($share);
        if (is_wp_error($share)) {
            return $share;
        }
        $answer = SEOProAnalytics_Shares::summary($share);
        unset($answer['id'], $answer['created'], $answer['opens'], $answer['last_opened'], $answer['revoked'], $answer['email']);
        $answer['unlock'] = SEOProAnalytics_Shares::grant($share, time() + HOUR_IN_SECONDS);
        $answer['home'] = home_url('/');
        $answer['site_name'] = get_bloginfo('name');
        $logo = $share['branding']['logo'] ?: (int) get_theme_mod('custom_logo');
        $answer['logo_url'] = SEOProAnalytics_Shares::local_logo($logo) ?: SEOProAnalytics_Shares::local_logo((int) get_option('site_icon'));
        $answer['agency_logo_url'] = SEOProAnalytics_Shares::local_logo($share['branding']['agency_logo']);
        // Breakdowns this report leaves out: the reader shows no tab for them, rather than an empty one.
        $answer['hidden_dimensions'] = $share['hide_sensitive'] ? SEOProAnalytics_Shares::SENSITIVE_DIMENSIONS : array();
        return $answer;
    }

    /**
     * Public reports are rebuilt from safe inputs, never an admin request.
     * Filters are ANDed, comparison periods obey the same date boundary.
     *
     * @param WP_REST_Request $request Request.
     * @return WP_REST_Response|WP_Error
     */
    public static function share_report($request) {
        $share = SEOProAnalytics_Shares::find((string) $request['token']);
        if (is_wp_error($share) || !SEOProAnalytics_Shares::unlocked($share, (string) $request->get_header('X-Seoproanalytics-Unlock'))) {
            return SEOProAnalytics_Shares::denied();
        }
        $section = (string) $request['section'];
        $report  = (string) $request['report'];
        $read    = self::share_read($share, $section, $report);
        if (!$read) {
            return SEOProAnalytics_Shares::denied();
        }
        return self::share_result($share, $section, $report, $request->get_query_params());
    }

    /**
     * Server-side shared read, without unlocks, open counts or reader quota.
     * Callers must already be trusted owner tools or the scheduled job.
     *
     * @param array  $share Stored share.
     * @param string $section Section.
     * @param string $report Report.
     * @param array  $params Report parameters.
     * @return array|WP_Error
     */
    public static function share_snapshot(array $share, $section, $report, array $params) {
        $answer = self::share_result($share, $section, $report, $params);
        return is_wp_error($answer) ? $answer : $answer->get_data();
    }

    /**
     * Owner-only email projection, independent of the chosen report sections.
     * Public Changes reads deliberately keep their existing note exclusion.
     *
     * @param array $share Stored share.
     * @param array $params Period parameters.
     * @param bool  $notes Include ordinary team notes, never experiments.
     * @return array|null|WP_Error Null when visit-level locks withhold changes.
     */
    public static function share_period_changes(array $share, array $params, bool $notes) {
        if (!SEOProAnalytics_Shares::page_locks_only($share['locked_filters'])) {
            return null;
        }
        $req = SEOProAnalytics_Query::request(array_merge($params, array('compare' => 'none', 'data' => 'live', 'filters' => $share['locked_filters'])));
        if (is_wp_error($req)) {
            return $req;
        }
        if ($share['revoked'] || ($share['expires'] && $share['expires'] <= time()) || !self::share_in_window($share, $req)) {
            return SEOProAnalytics_Shares::denied();
        }
        $changes = SEOProAnalytics_Changes::list_changes($req, array('limit' => SEOProAnalytics_Changes::MAX_LIMIT, 'order' => 'desc'));
        if (is_wp_error($changes)) {
            return $changes;
        }
        $rows = array();
        foreach ($changes['changes'] as $change) {
            $note = $change['kind'] === 'note' && $change['group'] === 'note';
            if ($change['kind'] === 'experiment' || ($change['group'] === 'note' && (!$notes || !$note)) || !self::share_change_allowed($change, $share['locked_filters'])) {
                continue;
            }
            $row = self::share_change_row($change);
            if ($note) {
                $row['title'] = (string) $change['new'];
            }
            $rows[] = $row;
        }
        return $rows;
    }

    /**
     * The common public and server-side read pipeline.
     *
     * @param array  $share Stored share.
     * @param string $section Section.
     * @param string $report Report.
     * @param array  $params Report parameters.
     * @return WP_REST_Response|WP_Error
     */
    private static function share_result(array $share, $section, $report, array $params) {
        if ($share['revoked'] || ($share['expires'] && $share['expires'] <= time()) || !in_array($section, array_column($share['views'], 'view'), true) || !in_array($report, SEOProAnalytics_Shares::REPORTS[$section] ?? array(), true)) {
            return SEOProAnalytics_Shares::denied();
        }
        if (in_array($section, SEOProAnalytics_Shares::PAGE_ONLY, true) && !SEOProAnalytics_Shares::page_locks_only($share['locked_filters'])) {
            return SEOProAnalytics_Shares::denied();
        }
        $read = array(__CLASS__, $report);
        // Who owns what is for the site's own people, never a shared report, whoever opens it.
        require_once __DIR__ . '/class-seoproanalytics-owners.php';
        SEOProAnalytics_Owners::hide();
        $args = self::share_args($params, $share, $section, $report);
        if (is_wp_error($args)) {
            return $args;
        }
        $safe = self::share_request($report, $args);
        if (is_wp_error($safe)) {
            return $safe;
        }
        if (!self::share_engine($share, $section, $report, $safe)) {
            return SEOProAnalytics_Shares::denied();
        }
        $req = SEOProAnalytics_Query::request(array_merge($args, array('limit' => (int) ($safe->get_param('limit') ?: 10))));
        if (is_wp_error($req)) {
            return $req;
        }
        if (!self::share_in_window($share, $req)) {
            return SEOProAnalytics_Shares::denied();
        }
        $withheld = self::share_withheld($share, $report, $req);
        if ($withheld) {
            return $withheld;
        }
        if ($report === 'markers' || $report === 'changes') {
            return self::share_changes($req, $share, $report, (string) $safe->get_param('page'), (string) $safe->get_param('kinds'), (int) $safe->get_param('limit'), (int) $safe->get_param('offset'));
        }
        return self::share_answer($share, $report, $read, $safe);
    }

    /**
     * The method that answers a shared read (the one named for its report)
     * when the share shows this section and report, within its read quota
     * (counted only for a section and report it shows), and with only page
     * locks for a section that needs them; null otherwise.
     *
     * @param array  $share   Share.
     * @param string $section Section.
     * @param string $report  Report.
     * @return callable|null
     */
    private static function share_read(array $share, $section, $report) {
        if (!in_array($section, array_column($share['views'], 'view'), true) || !in_array($report, SEOProAnalytics_Shares::REPORTS[$section], true) || !SEOProAnalytics_Shares::quota($share, 'reads', 120)) {
            return null;
        }
        if (in_array($section, SEOProAnalytics_Shares::PAGE_ONLY, true) && !SEOProAnalytics_Shares::page_locks_only($share['locked_filters'])) {
            return null;
        }
        return array(__CLASS__, $report);
    }

    /**
     * A shared read's arguments: the known ones asked for, its filters
     * with the share's locked filters, live data, and the limit and offset
     * in bounds.
     *
     * @param array  $params Request parameters.
     * @param array  $share Share.
     * @param string $section Section.
     * @param string $report Report.
     * @return array<string,mixed>|WP_Error
     */
    private static function share_args(array $params, array $share, $section, $report) {
        $args = array_intersect_key($params, array_flip(array('range', 'from', 'to', 'compare', 'grain', 'filters', 'dimension', 'limit', 'offset', 'page', 'kind', 'engine', 'query', 'key', 'event', 'kinds', 'sort', 'order', 'goal', 'finding', 'days')));
        $filters = SEOProAnalytics_Shares::filters($args['filters'] ?? array());
        if (is_wp_error($filters)) {
            return $filters;
        }
        $args['filters'] = array_merge($filters, $share['locked_filters']);
        $args['data'] = 'live';
        if (isset($args['limit'])) {
            // Days are the chart's points: printing needs the whole period, not one page.
            $maximum = $section === 'search' && $report === 'search' && ($args['kind'] ?? '') === 'days' ? SEOProAnalytics_Query::MAX_LIMIT : 100;
            $checked = rest_validate_value_from_schema($args['limit'], array('type' => 'integer', 'minimum' => 1, 'maximum' => $maximum), 'limit');
            if (is_wp_error($checked)) {
                $checked->add_data(array('status' => 400));
                return $checked;
            }
            $args['limit'] = min($maximum, max(1, (int) $args['limit']));
        }
        $args['offset'] = min(1000, max(0, (int) ($args['offset'] ?? 0)));
        return $args;
    }

    /**
     * The read's request on its own route, with that route's defaults,
     * its arguments checked and sanitized as when an administrator asks.
     *
     * @param string              $report Report.
     * @param array<string,mixed> $args   share_args()'s arguments.
     * @return WP_REST_Request|WP_Error
     */
    private static function share_request($report, array $args) {
        // The read's own route checks its arguments, as when an administrator asks.
        $routes  = rest_get_server()->get_routes('seoproanalytics/v1');
        $handler = $routes['/seoproanalytics/v1/' . $report][0] ?? null;
        if (!$handler) {
            return SEOProAnalytics_Shares::denied();
        }
        $safe = new WP_REST_Request('GET', '/seoproanalytics/v1/' . $report);
        $safe->set_query_params($args);
        $safe->set_attributes(array('args' => $handler['args']));
        $defaults = array();
        foreach ($handler['args'] as $name => $spec) {
            if (is_array($spec) && array_key_exists('default', $spec)) {
                $defaults[$name] = $spec['default'];
            }
        }
        $safe->set_default_params($defaults);
        $checked = $safe->has_valid_params();
        if (is_wp_error($checked)) {
            return $checked;
        }
        $checked = $safe->sanitize_params();
        return is_wp_error($checked) ? $checked : $safe;
    }

    /**
     * Whether a Search read asks for the engine of one of the share's
     * Search sections (a Search section is one engine's); others pass.
     *
     * @param array           $share   Share.
     * @param string          $section Section.
     * @param string          $report  Report.
     * @param WP_REST_Request $safe    share_request()'s request.
     * @return bool
     */
    private static function share_engine(array $share, $section, $report, $safe) {
        if ($section !== 'search' || $report === 'markers') {
            return true;
        }
        $engines = array();
        foreach ($share['views'] as $view) {
            if ($view['view'] === 'search') {
                $engines[] = $view['engine'] ?? 'google';
            }
        }
        return in_array((string) ($safe->get_param('engine') ?: 'google'), $engines, true);
    }

    /**
     * Whether the read's period, and its comparison period, start within
     * the share's newest days (always when it has no such limit).
     *
     * @param array               $share Share.
     * @param array<string,mixed> $req   Checked report request.
     * @return bool
     */
    private static function share_in_window(array $share, array $req) {
        if (!$share['max_days']) {
            return true;
        }
        $floor = (new DateTimeImmutable('today', wp_timezone()))->modify('-' . ($share['max_days'] - 1) . ' days')->getTimestamp();
        $range = SEOProAnalytics_Query::range($req);
        if ($req['compare'] === 'typical') {
            foreach (SEOProAnalytics_Query::typical_periods($range) as $period) {
                if ($period['from'] < $floor) {
                    return false;
                }
            }
        }
        // Decay reports require the previous period when typical is unsupported.
        $other = SEOProAnalytics_Query::compare_range($range, $req['compare'] === 'typical' ? 'prev' : $req['compare']);
        return !($range['from'] < $floor || ($other && $other['from'] < $floor));
    }

    /**
     * The empty answer of a read the share withholds: live visitors when
     * hidden or locked, and the A/B variant (or a sensitive) breakdown;
     * null for any other read.
     *
     * @param array               $share  Share.
     * @param string              $report Report.
     * @param array<string,mixed> $req    Checked report request.
     * @return WP_REST_Response|null
     */
    private static function share_withheld(array $share, $report, array $req) {
        if ($report === 'realtime' && ($share['hide_realtime'] || $share['locked_filters'])) {
            return rest_ensure_response(array('visitors' => 0));
        }
        // A/B variants (the owner's tests in progress) are never in shared reports.
        if ($report === 'breakdown' && ($req['dimension'] === 'variant' || ($share['hide_sensitive'] && in_array($req['dimension'], SEOProAnalytics_Shares::SENSITIVE_DIMENSIONS, true)))) {
            return rest_ensure_response(array('dimension' => $req['dimension'], 'rows' => array(), 'total' => 0, 'range' => SEOProAnalytics_Query::range_out(SEOProAnalytics_Query::range($req))));
        }
        return null;
    }

    /**
     * The read's answer, without editor links, and without live sources
     * when the share hides sensitive data.
     *
     * @param array           $share  Share.
     * @param string          $report Report.
     * @param callable        $read   share_read()'s method.
     * @param WP_REST_Request $safe   share_request()'s request.
     * @return WP_REST_Response|WP_Error
     */
    private static function share_answer(array $share, $report, $read, $safe) {
        $answer = call_user_func($read, $safe);
        if (is_wp_error($answer)) {
            return $answer;
        }
        $data = self::share_redact($answer->get_data());
        if ($report === 'realtime' && $share['hide_sensitive']) {
            $data['sources'] = array();
        }
        $answer->set_data($data);
        return $answer;
    }

    /**
     * The timeline (markers, oldest first) or the change log (changes,
     * newest first, a page of it) as a minimal projection: never users,
     * notes, settings, plugin or theme names, or before and after values.
     * Visit-level locks cannot scope site changes, so then there are none.
     *
     * @param array  $req    Checked report request.
     * @param array  $share  Share.
     * @param string $report markers or changes.
     * @param string $page   Only this page's (and site-wide) changes.
     * @param string $kinds  Change log: only these groups (comma-separated).
     * @param int    $limit  Change log: most rows.
     * @param int    $offset Change log: rows to skip.
     * @return WP_REST_Response|WP_Error
     */
    private static function share_changes(array $req, array $share, $report, $page, $kinds, $limit, $offset) {
        $list   = $report === 'markers' ? 'markers' : 'changes';
        $answer = array('range' => SEOProAnalytics_Query::range_out(SEOProAnalytics_Query::range($req)), $list => array(), 'total' => 0);
        if ($list === 'changes') {
            $answer += array('limit' => $limit, 'offset' => $offset);
        }
        if (!SEOProAnalytics_Shares::page_locks_only($share['locked_filters'])) {
            return rest_ensure_response($answer);
        }
        $changes = SEOProAnalytics_Changes::list_changes($req, array('page' => $page, 'kinds' => $kinds, 'limit' => SEOProAnalytics_Changes::MAX_LIMIT, 'order' => $list === 'markers' ? 'asc' : 'desc', 'running' => $list === 'markers'));
        if (is_wp_error($changes)) {
            return $changes;
        }
        $rows = array();
        foreach ($changes['changes'] as $change) {
            if ($change['group'] !== 'note' && self::share_change_allowed($change, $share['locked_filters'])) {
                $rows[] = self::share_change_row($change);
            }
        }
        $answer['total'] = count($rows);
        $answer[$list] = $list === 'markers' ? $rows : array_slice($rows, $offset, $limit);
        return rest_ensure_response($answer);
    }

    /**
     * Whether a change is within every locked filter: its page matches
     * each (or, for is_not, does not); a change without a page only when
     * it is a search engine update and there are locks.
     *
     * @param array<string,mixed>            $change A change from list_changes().
     * @param array<int,array<string,mixed>> $locked The share's locked filters.
     * @return bool
     */
    private static function share_change_allowed(array $change, array $locked) {
        $allowed = true;
        foreach ($locked as $filter) {
            if (!$change['path']) {
                $allowed = $change['group'] === 'search';
                continue;
            }
            $matches = self::share_path_matches($filter, $change['path']);
            $allowed = $allowed && ($filter['op'] === 'is_not' ? !$matches : $matches);
        }
        return $allowed;
    }

    /**
     * Whether a page matches one of a filter's values: by pattern (*
     * for any text), by containing it, or exactly.
     *
     * @param array<string,mixed> $filter A locked filter.
     * @param string              $path   The change's page.
     * @return bool
     */
    private static function share_path_matches(array $filter, $path) {
        $matches = false;
        foreach ($filter['values'] as $value) {
            if ($filter['op'] === 'matches') {
                $pattern = '/^' . str_replace('\\*', '.*', preg_quote($value, '/')) . '$/D';
                $matches = $matches || (bool) preg_match($pattern, $path);
            } elseif ($filter['op'] === 'contains') {
                $matches = $matches || strpos($path, $value) !== false;
            } else {
                $matches = $matches || $path === $value;
            }
        }
        return $matches;
    }

    /**
     * A change as a shared report shows it: never users, notes, settings,
     * plugin or theme names, or before and after values.
     *
     * @param array<string,mixed> $change A change from list_changes().
     * @return array<string,mixed>
     */
    private static function share_change_row(array $change) {
        $safe = array_intersect_key($change, array_flip(array('id', 't', 'kind', 'group', 'path', 'source')));
        $safe['label'] = ucfirst(str_replace('_', ' ', $change['kind']));
        // A published page's or product's name, or a search engine update's: public already.
        $safe['title'] = ($change['path'] && $change['group'] !== 'site') || $change['group'] === 'search' ? (string) ($change['title'] ?? '') : '';
        $safe['old'] = '';
        $safe['new'] = '';
        $safe['object'] = array('type' => $change['group'] === 'search' ? (string) ($change['object']['type'] ?? '') : '', 'id' => 0);
        $safe['user'] = null;
        // A search engine update's name, engine, announcement and end (it rolls out over a span).
        $safe['meta'] = $change['group'] === 'search' ? array_intersect_key((array) ($change['meta'] ?? array()), array_flip(array('name', 'engine', 'url', 'ended'))) : array();
        return $safe;
    }

    /**
     * Strip editor links and owners even when opened by a signed-in
     * administrator.
     *
     * @param mixed $value Answer.
     * @return mixed
     */
    private static function share_redact($value) {
        if (!is_array($value)) {
            return $value;
        }
        foreach ($value as $key => &$item) {
            $item = $key === 'edit_url' || $key === 'owner' ? null : self::share_redact($item);
        }
        return $value;
    }

    /**
     * GET /stats.
     *
     * @param WP_REST_Request $request Request.
     * @return WP_REST_Response|WP_Error
     */
    public static function stats($request) {
        return self::answer($request, 'stats');
    }

    /**
     * GET /timeseries.
     *
     * @param WP_REST_Request $request Request.
     * @return WP_REST_Response|WP_Error
     */
    public static function timeseries($request) {
        return self::answer($request, 'timeseries');
    }

    /**
     * GET /breakdown.
     *
     * @param WP_REST_Request $request Request.
     * @return WP_REST_Response|WP_Error
     */
    public static function breakdown($request) {
        return self::answer($request, 'breakdown');
    }

    /**
     * GET /realtime.
     *
     * @param WP_REST_Request $request Request.
     * @return WP_REST_Response|WP_Error
     */
    public static function realtime($request) {
        $answer = self::on_data((string) $request->get_param('data'), array('SEOProAnalytics_Query', 'realtime'));
        if (is_wp_error($answer)) {
            return $answer;
        }
        self::short_floats();
        return rest_ensure_response($answer);
    }

    /**
     * GET /markers: the changes in the range, oldest first, for the
     * timeline (at most SEOProAnalytics_Changes::MAX_LIMIT), with search
     * engine updates that started earlier and were still rolling out.
     *
     * @param WP_REST_Request $request Request.
     * @return WP_REST_Response|WP_Error
     */
    public static function markers($request) {
        $args = array(
            'page'    => (string) $request->get_param('page'),
            'kinds'   => (string) $request->get_param('kinds'),
            'limit'   => SEOProAnalytics_Changes::MAX_LIMIT,
            'order'   => 'asc',
            'running' => true,
        );
        return self::report($request, static function ($req) use ($args) {
            $answer = SEOProAnalytics_Changes::list_changes($req, $args);
            if (is_wp_error($answer)) {
                return $answer;
            }
            return array(
                'range'   => $answer['range'],
                'markers' => $answer['changes'],
                'total'   => $answer['total'],
            );
        });
    }

    /**
     * GET /changes: the change log for the range, newest first, with paging.
     *
     * @param WP_REST_Request $request Request.
     * @return WP_REST_Response|WP_Error
     */
    public static function changes($request) {
        $args = array(
            'page'   => (string) $request->get_param('page'),
            'kinds'  => (string) $request->get_param('kinds'),
            'limit'  => (int) $request->get_param('limit'),
            'offset' => (int) $request->get_param('offset'),
        );
        return self::report($request, static function ($req) use ($args) {
            $answer = SEOProAnalytics_Changes::list_changes($req, $args);
            if (is_wp_error($answer)) {
                return $answer;
            }
            return $answer + array('limit' => $args['limit'], 'offset' => $args['offset']);
        });
    }

    /**
     * GET /goals: every goal's conversions and revenue.
     *
     * @param WP_REST_Request $request Request.
     * @return WP_REST_Response|WP_Error
     */
    public static function goals($request) {
        return self::report($request, static function ($req) {
            $answer = SEOProAnalytics_Conversions::goals($req);
            $answer['renewals'] = SEOProAnalytics_Purchases::renewals($req);
            return $answer;
        });
    }

    /**
     * GET /funnels: every funnel's visits per step.
     *
     * @param WP_REST_Request $request Request.
     * @return WP_REST_Response|WP_Error
     */
    public static function funnels($request) {
        return self::report($request, array('SEOProAnalytics_Conversions', 'funnels'));
    }

    /**
     * GET /business-profiles: the Business profiles channel and each listed
     * profile's visits, conversions and revenue from its tagged link.
     *
     * @param WP_REST_Request $request Request.
     * @return WP_REST_Response|WP_Error
     */
    public static function business_profiles($request) {
        return self::report($request, array('SEOProAnalytics_Business_Profiles', 'report'));
    }

    /**
     * GET /properties: property keys, or one key's values.
     *
     * @param WP_REST_Request $request Request.
     * @return WP_REST_Response|WP_Error
     */
    public static function properties($request) {
        $key   = (string) $request->get_param('key');
        $event = (string) $request->get_param('event');
        return self::report($request, static function ($req) use ($key, $event) {
            return SEOProAnalytics_Conversions::properties($req, $key, $event);
        });
    }

    /**
     * GET /clicks: clicked elements, dead clicks, links, files or forms.
     *
     * @param WP_REST_Request $request Request.
     * @return WP_REST_Response|WP_Error
     */
    public static function clicks($request) {
        $kind = (string) $request->get_param('kind');
        $page = (string) $request->get_param('page');
        return self::report($request, static function ($req) use ($kind, $page) {
            return SEOProAnalytics_Clicks::report($req, $kind, $page);
        });
    }

    /**
     * GET /search: search clicks, impressions, CTR and position, with
     * queries, pages, countries or devices; optionally of a page or query.
     *
     * @param WP_REST_Request $request Request.
     * @return WP_REST_Response|WP_Error
     */
    public static function search($request) {
        $kind   = (string) $request->get_param('kind');
        $page   = (string) $request->get_param('page');
        $query  = (string) $request->get_param('query');
        $engine = (string) $request->get_param('engine');
        $sort   = (string) $request->get_param('sort');
        $order  = (string) $request->get_param('order');
        return self::report($request, static function ($req) use ($kind, $page, $query, $engine, $sort, $order) {
            return SEOProAnalytics_Search::report($req, $kind, $page, $query, $engine, $sort, $order);
        });
    }

    /**
     * GET /opportunities: striking-distance queries, low-CTR queries,
     * pages losing clicks with the likely cause, queries missing from their
     * page, or queries shared by several pages.
     *
     * @param WP_REST_Request $request Request.
     * @return WP_REST_Response|WP_Error
     */
    public static function opportunities($request) {
        $kind   = (string) $request->get_param('kind');
        $engine = (string) $request->get_param('engine');
        return self::report($request, static function ($req) use ($kind, $engine) {
            return SEOProAnalytics_Opportunities::report($req, $kind, $engine);
        });
    }

    /**
     * GET /audit: published pages with findings from their WordPress
     * content and SEO fields, most search impressions first unless sorted
     * otherwise.
     *
     * @param WP_REST_Request $request Request.
     * @return WP_REST_Response|WP_Error
     */
    public static function audit($request) {
        $engine  = (string) $request->get_param('engine');
        $finding = (string) $request->get_param('finding');
        $sort    = (string) $request->get_param('sort');
        $order   = (string) $request->get_param('order');
        return self::report($request, static function ($req) use ($engine, $finding, $sort, $order) {
            return SEOProAnalytics_Audit::report($req, $engine, $finding, $sort, $order);
        });
    }

    /**
     * GET /links: orphan pages, converting pages with few links in, or
     * links missing between pages that share a search.
     *
     * @param WP_REST_Request $request Request.
     * @return WP_REST_Response|WP_Error
     */
    public static function links($request) {
        $engine = (string) $request->get_param('engine');
        $kind   = (string) $request->get_param('kind');
        $goal   = (string) $request->get_param('goal');
        return self::report($request, static function ($req) use ($engine, $kind, $goal) {
            return SEOProAnalytics_Links::report($req, $engine, $kind, $goal);
        });
    }

    /**
     * GET /indexation: published pages and sitemap addresses search has
     * not shown in its newest days.
     *
     * @param WP_REST_Request $request Request.
     * @return WP_REST_Response|WP_Error
     */
    public static function indexation($request) {
        $engine = (string) $request->get_param('engine');
        $kind   = (string) $request->get_param('kind');
        $days   = (int) $request->get_param('days');
        return self::report($request, static function ($req) use ($engine, $kind, $days) {
            return SEOProAnalytics_Indexation::report($req, $engine, $kind, $days);
        });
    }

    /**
     * GET /backlinks: pages of other sites linking to the site's pages,
     * found by checking the pages that sent visits.
     *
     * @param WP_REST_Request $request Request.
     * @return WP_REST_Response|WP_Error
     */
    public static function backlinks($request) {
        $kind   = (string) $request->get_param('kind');
        $source = (string) $request->get_param('source');
        $engine = (string) $request->get_param('engine');
        $triage = array_intersect_key((array) $request->get_params(), self::backlink_triage_args());
        return self::report($request, static function ($req) use ($kind, $source, $engine, $triage) {
            $req['source'] = $source;
            $req['engine'] = $engine;
            $req['triage'] = $triage;
            return SEOProAnalytics_Backlinks::report($req, $kind);
        });
    }

    /**
     * POST /backlinks/classify: the owner's classes of links.
     *
     * @param WP_REST_Request $request Request.
     * @return WP_REST_Response|WP_Error
     */
    public static function backlinks_classify($request) {
        require_once __DIR__ . '/class-seoproanalytics-backlinks-triage.php';
        $params = (array) $request->get_params();
        return rest_ensure_response(self::on_data((string) $request->get_param('data'), static function () use ($params) {
            return SEOProAnalytics_Backlinks_Triage::classify($params);
        }));
    }

    /**
     * GET /backlinks/lists: the class lists of Settings → Backlinks, and
     * whether they apply. Change them with POST /backlinks/classify (sites)
     * or the settings.
     *
     * @return WP_REST_Response
     */
    public static function backlinks_lists() {
        require_once __DIR__ . '/class-seoproanalytics-backlinks-triage.php';
        require_once __DIR__ . '/class-seoproanalytics-backlink-lists.php';
        return rest_ensure_response(SEOProAnalytics_Backlink_Lists::all());
    }

    /**
     * Filters, search and sort of the links and lost lists (GET /backlinks,
     * the backlinks ability).
     *
     * @return array<string,array<string,mixed>>
     */
    public static function backlink_triage_args() {
        require_once __DIR__ . '/class-seoproanalytics-backlinks-triage.php';
        $list   = static function ($description) {
            return array('description' => $description, 'type' => 'string');
        };
        $bound  = static function ($description) {
            return array('description' => $description, 'type' => 'integer', 'minimum' => 0);
        };
        $args = array(
            'classes'   => $list(__('links, lost, domains and reported: only rows whose every class is among these (comma-separated class names; none: rows without a class). Left out: every row.', 'seoproanalytics')),
            'rel'       => $list(__('links and lost: only links with one of these rel values (comma-separated: followed, nofollow, sponsored, ugc).', 'seoproanalytics')),
            'search'    => array('description' => __('links and lost: only links with this text (any case) in the linking page, the page linked to or the link text.', 'seoproanalytics'), 'type' => 'string', 'maxLength' => 200),
            'search_in' => $list(__('Where search looks (comma-separated: source, page, anchor); all three when left out.', 'seoproanalytics')),
            'date'      => array('description' => __('The date date_from and date_to apply to: first_seen, last_seen or lost.', 'seoproanalytics'), 'type' => 'string', 'enum' => array('', 'first_seen', 'last_seen', 'lost'), 'default' => ''),
            'date_from' => array('description' => __('links and lost: only links whose date is on or after this day (YYYY-MM-DD).', 'seoproanalytics'), 'type' => 'string', 'pattern' => '^(\d{4}-\d{2}-\d{2})?$'),
            'date_to'   => array('description' => __('links and lost: only links whose date is on or before this day (YYYY-MM-DD).', 'seoproanalytics'), 'type' => 'string', 'pattern' => '^(\d{4}-\d{2}-\d{2})?$'),
            'sort'      => array('description' => __('links and lost: order by source, page, anchor, first_seen, last_seen, lost, site_dr, site_ld, page_dr, page_ld or value (the link value, 0-100). Links without the figure come last.', 'seoproanalytics'), 'type' => 'string', 'enum' => array_merge(array(''), SEOProAnalytics_Backlinks_Triage::SORTS), 'default' => ''),
            'order'     => array('description' => __('asc or desc; left out: A to Z for text, most first for the rest.', 'seoproanalytics'), 'type' => 'string', 'enum' => array('', 'asc', 'desc'), 'default' => ''),
        );
        $names = array(
            'site_dr' => __('the linking site\'s rank (DR, 0-100)', 'seoproanalytics'),
            'site_ld' => __('the linking site\'s referring domains (LD)', 'seoproanalytics'),
            'page_dr' => __('the linking page\'s rank (DR, 0-100)', 'seoproanalytics'),
            'page_ld' => __('the linking page\'s referring domains (LD)', 'seoproanalytics'),
        );
        foreach ($names as $metric => $name) {
            /* translators: %s: a metric, such as the linking site's rank */
            $args[$metric . '_min'] = $bound(sprintf(__('links and lost: only links where %s is at least this (links without it are left out).', 'seoproanalytics'), $name));
            /* translators: %s: a metric, such as the linking site's rank */
            $args[$metric . '_max'] = $bound(sprintf(__('links and lost: only links where %s is at most this (links without it are left out).', 'seoproanalytics'), $name));
        }
        return $args;
    }

    /** Local review read/write. @param WP_REST_Request $request Request. @return WP_REST_Response|WP_Error */
    public static function backlink_review($request) {
        require_once __DIR__ . '/class-seoproanalytics-backlink-review.php';
        if ($request->get_method() === 'GET') {
            return self::report($request, array('SEOProAnalytics_Backlink_Review', 'report'));
        }
        return rest_ensure_response(self::on_data((string) $request->get_param('data'), static function () use ($request) {
            return SEOProAnalytics_Backlink_Review::decide($request->get_params());
        }));
    }

    /** Generate text; errors still use the normal JSON REST response. @param WP_REST_Request $request Request. @return WP_REST_Response|WP_Error */
    public static function backlink_disavow($request) {
        require_once __DIR__ . '/class-seoproanalytics-backlink-review.php';
        $text = self::on_data((string) $request->get_param('data'), array('SEOProAnalytics_Backlink_Review', 'export'));
        return is_wp_error($text) ? $text : new WP_REST_Response($text, 200, array('Content-Type' => 'text/plain; charset=UTF-8', 'Content-Disposition' => 'attachment; filename="disavow.txt"', 'Cache-Control' => 'no-store'));
    }

    /** Raw successful export only, never JSON-encoded or escaped text. @param bool $served Served. @param WP_HTTP_Response $result Response. @param WP_REST_Request $request Request. @param WP_REST_Server $server Server. @return bool */
    public static function serve_disavow($served, $result, $request, $server) {
        if (!$served && $request->get_route() === '/' . SEOProAnalytics_Collection::REST_NAMESPACE . '/backlinks/disavow.txt' && $result->get_status() === 200 && is_string($result->get_data())) {
            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- validated UTF-8 plain-text attachment, not HTML.
            echo $result->get_data();
            return true;
        }
        return $served;
    }

    /** Retain pasted/uploaded prior entries locally. @param WP_REST_Request $request Request. @return WP_REST_Response|WP_Error */
    public static function backlink_merge($request) {
        require_once __DIR__ . '/class-seoproanalytics-backlink-review.php';
        $text = (string) $request->get_param('text');
        $files = $request->get_file_params();
        if (isset($files['file'])) {
            $file = $files['file'];
            if ($file['error'] !== UPLOAD_ERR_OK || $file['size'] > SEOProAnalytics_Backlink_Review::MAX_BYTES || !is_uploaded_file($file['tmp_name'])) {
                return new WP_Error('seoproanalytics_disavow_upload', __('Upload a text list of at most 2 MB.', 'seoproanalytics'), array('status' => 400));
            }
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- an authenticated local upload, bounded above, never a remote URL.
            $got = file_get_contents($file['tmp_name']);
            if ($got === false) {
                return new WP_Error('seoproanalytics_disavow_upload', __('The list could not be read.', 'seoproanalytics'), array('status' => 400));
            }
            $text = $got;
        }
        return rest_ensure_response(self::on_data((string) $request->get_param('data'), static function () use ($text) {
            return SEOProAnalytics_Backlink_Review::merge($text);
        }));
    }

    /** Start a links export import. @param WP_REST_Request $request Request. @return WP_REST_Response|WP_Error */
    public static function backlinks_import($request) {
        require_once __DIR__ . '/class-seoproanalytics-backlinks-import.php';
        $files = $request->get_file_params();
        $source = $request->get_param('source');
        $source = is_string($source) ? $source : '';
        if (isset($files['file'])) {
            $file = $files['file'];
            if ($file['error'] !== UPLOAD_ERR_OK || $file['size'] > SEOProAnalytics_Backlinks_Import::MAX_BYTES || !is_uploaded_file($file['tmp_name'])) {
                return new WP_Error('seoproanalytics_links_upload', __('Upload a CSV of at most 50 MB.', 'seoproanalytics'), array('status' => 400));
            }
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- read PHP's authenticated temporary upload, never a caller-supplied path.
            $stream = fopen($file['tmp_name'], 'r');
            if (!$stream) {
                return new WP_Error('seoproanalytics_links_upload', __('The upload could not be read.', 'seoproanalytics'), array('status' => 400));
            }
            try {
                $job = SEOProAnalytics_Backlinks_Import::start($stream, $source);
            } finally {
                // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- the temporary upload stream.
                fclose($stream);
            }
            $kept = array((string) $file['tmp_name'], isset($file['name']) ? (string) $file['name'] : '', 'upload');
        } else {
            $rows = $request->get_param('rows');
            if (!is_array($rows) || count($rows) > SEOProAnalytics_Backlinks_Import::MAX_ROWS || strlen($request->get_body()) > SEOProAnalytics_Backlinks_Import::MAX_BYTES) {
                return new WP_Error('seoproanalytics_links_rows', __('Supply up to 100,000 JSON rows or a CSV file.', 'seoproanalytics'), array('status' => 400));
            }
            $job  = SEOProAnalytics_Backlinks_Import::start(array_values($rows), $source);
            $kept = array('', 'rows.json', 'rest');
        }
        if (is_wp_error($job)) {
            return $job;
        }
        require_once __DIR__ . '/class-seoproanalytics-backlinks-history.php';
        $entry = SEOProAnalytics_Backlinks_History::add($job, $kept[0], $kept[1], $kept[2]);
        return new WP_REST_Response($job + array('history' => (int) $entry['id']), 202);
    }

    /**
     * Import progress. Cron does the work; while an administrator watches,
     * each poll also moves it on for a few seconds, so it never waits on a
     * cron run that a busy or replaced site lost (run() schedules the next).
     *
     * @param WP_REST_Request $request Request.
     * @return WP_REST_Response
     */
    public static function backlinks_import_status($request) {
        require_once __DIR__ . '/class-seoproanalytics-backlinks-import.php';
        if (SEOProAnalytics_Backlinks_Import::status()['status'] === 'running') {
            return new WP_REST_Response(SEOProAnalytics_Backlinks_Import::run(SEOProAnalytics_Backlinks_Import::POLL_BUDGET));
        }
        return new WP_REST_Response(SEOProAnalytics_Backlinks_Import::status());
    }

    /** The link export imports, newest first. @param WP_REST_Request $request Request. @return WP_REST_Response */
    public static function backlinks_imports($request) {
        require_once __DIR__ . '/class-seoproanalytics-backlinks-history.php';
        return new WP_REST_Response(array('imports' => SEOProAnalytics_Backlinks_History::out()));
    }

    /** The file served by serve_import_file() (never in a response body). @var string */
    private static $import_file = '';

    /** An import's uploaded file, as it was uploaded. @param WP_REST_Request $request Request. @return WP_REST_Response|WP_Error */
    public static function backlinks_import_file($request) {
        self::$import_file = '';
        require_once __DIR__ . '/class-seoproanalytics-backlinks-history.php';
        $file = SEOProAnalytics_Backlinks_History::file((int) $request->get_param('id'));
        if ($file === null) {
            return new WP_Error('seoproanalytics_links_file', __('That import\'s file is no longer kept.', 'seoproanalytics'), array('status' => 404));
        }
        // Retention can remove a file after the history lookup.
        $size = @filesize($file['path']);
        if ($size === false) {
            return new WP_Error('seoproanalytics_links_file', __('That import\'s file is no longer kept.', 'seoproanalytics'), array('status' => 404));
        }
        self::$import_file = $file['path'];
        $name = str_replace(array('"', '\\'), '', $file['name']);
        return new WP_REST_Response(null, 200, array('Content-Type' => 'text/csv; charset=UTF-8', 'Content-Disposition' => 'attachment; filename="' . $name . '"', 'Content-Length' => (string) $size, 'Cache-Control' => 'no-store', 'X-Content-Type-Options' => 'nosniff'));
    }

    /** Send the kept file as it is, not JSON. @param bool $served Served. @param WP_HTTP_Response $result Response. @param WP_REST_Request $request Request. @param WP_REST_Server $server Server. @return bool */
    public static function serve_import_file($served, $result, $request, $server) {
        if ($served || self::$import_file === '' || $result->get_status() !== 200 || strpos($request->get_route(), '/' . SEOProAnalytics_Collection::REST_NAMESPACE . '/backlinks/imports/') !== 0) {
            return $served;
        }
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile -- the owner's own upload, kept in our folder, sent as the attachment.
        readfile(self::$import_file);
        return true;
    }

    /**
     * POST /crawl/import: stage a crawl's exports (multipart file or
     * files[], or JSON files: [{name, rows}] or rows) and start writing
     * them in cron. The plugin never crawls and never fetches an export.
     *
     * @param WP_REST_Request $request Request.
     * @return WP_REST_Response|WP_Error
     */
    public static function crawl_import($request) {
        $sources = self::crawl_uploads($request->get_file_params());
        if (is_wp_error($sources)) {
            return $sources;
        }
        $streams = array_filter(array_column($sources, 'stream'));
        if (!$sources) {
            if (strlen($request->get_body()) > SEOProAnalytics_Crawl_Import::MAX_BYTES) {
                /* translators: %d: most megabytes */
                return new WP_Error('seoproanalytics_crawl_size', sprintf(__('Send at most %d MB.', 'seoproanalytics'), SEOProAnalytics_Crawl_Import::MAX_BYTES / MB_IN_BYTES), array('status' => 400));
            }
            $files = $request->get_param('files');
            $rows  = $request->get_param('rows');
            if (is_array($files)) {
                foreach (array_slice($files, 0, SEOProAnalytics_Crawl_Import::MAX_FILES + 1) as $one) {
                    if (is_array($one) && isset($one['rows']) && is_array($one['rows'])) {
                        $sources[] = array('name' => isset($one['name']) ? (string) $one['name'] : 'rows.json', 'rows' => $one['rows']);
                    }
                }
            } elseif (is_array($rows)) {
                $sources[] = array('name' => 'rows.json', 'rows' => $rows);
            }
        }
        try {
            $record = $request->get_param('record');
            $job = SEOProAnalytics_Crawl_Import::start($sources, (string) $request->get_param('format'), filter_var($request->get_param('complete'), FILTER_VALIDATE_BOOLEAN), is_array($record) ? $record : array(), 'upload', filter_var($request->get_param('partial'), FILTER_VALIDATE_BOOLEAN));
        } finally {
            foreach ($streams as $stream) {
                // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- the temporary upload streams.
                fclose($stream);
            }
        }
        return is_wp_error($job) ? $job : new WP_REST_Response($job, 202);
    }

    /**
     * Uploaded crawl files as import sources: file, or files[].
     *
     * @param array<string,mixed> $files PHP's uploads.
     * @return array<int,array<string,mixed>>|WP_Error
     */
    private static function crawl_uploads(array $files) {
        $list = array();
        foreach (array('file', 'files') as $field) {
            if (!isset($files[$field]['tmp_name'])) {
                continue;
            }
            $one = $files[$field];
            foreach ((array) $one['tmp_name'] as $i => $tmp) {
                $list[] = array(
                    'tmp'   => (string) $tmp,
                    'name'  => is_array($one['name']) ? (string) $one['name'][$i] : (string) $one['name'],
                    'error' => is_array($one['error']) ? (int) $one['error'][$i] : (int) $one['error'],
                    'size'  => is_array($one['size']) ? (int) $one['size'][$i] : (int) $one['size'],
                );
            }
        }
        if (count($list) > SEOProAnalytics_Crawl_Import::MAX_FILES) {
            /* translators: %d: most files */
            return new WP_Error('seoproanalytics_crawl_files', sprintf(__('Import from 1 to %d files of one crawl together.', 'seoproanalytics'), SEOProAnalytics_Crawl_Import::MAX_FILES), array('status' => 400));
        }
        $sources = array();
        foreach ($list as $file) {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- read PHP's authenticated temporary upload, never a caller-supplied path.
            $stream = $file['error'] === UPLOAD_ERR_OK && $file['size'] <= SEOProAnalytics_Crawl_Import::MAX_BYTES && is_uploaded_file($file['tmp']) ? fopen($file['tmp'], 'r') : false;
            if (!$stream) {
                foreach ($sources as $open) {
                    // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- the temporary upload streams.
                    fclose($open['stream']);
                }
                /* translators: %d: most megabytes */
                return new WP_Error('seoproanalytics_crawl_upload', sprintf(__('Upload CSV or JSON files of at most %d MB each.', 'seoproanalytics'), SEOProAnalytics_Crawl_Import::MAX_BYTES / MB_IN_BYTES), array('status' => 400));
            }
            $sources[] = array('name' => $file['name'], 'stream' => $stream);
        }
        return $sources;
    }

    /**
     * GET /crawl/import: the crawl import's progress; while it runs, each
     * poll moves it on for a few seconds too.
     *
     * @param WP_REST_Request $request Request.
     * @return WP_REST_Response
     */
    public static function crawl_import_status($request) {
        if (SEOProAnalytics_Crawl_Import::status()['status'] === 'running') {
            return new WP_REST_Response(SEOProAnalytics_Crawl_Import::run(SEOProAnalytics_Crawl_Import::POLL_BUDGET));
        }
        return new WP_REST_Response(SEOProAnalytics_Crawl_Import::status());
    }

    /** POST /crawl/readiness: explicit probes. @param WP_REST_Request $request Request. @return WP_REST_Response */
    public static function crawl_readiness($request) {
        require_once __DIR__ . '/class-seoproanalytics-site-crawler.php';
        return new WP_REST_Response(SEOProAnalytics_Site_Crawler::readiness(true, (bool) $request->get_param('external')));
    }

    /** GET/POST/DELETE /crawl/run: status/start/stop. @param WP_REST_Request $request Request. @return WP_REST_Response|WP_Error */
    public static function crawl_run($request) {
        require_once __DIR__ . '/class-seoproanalytics-site-crawler.php';
        if ($request->get_method() === 'POST') {
            $answer = SEOProAnalytics_Site_Crawler::start();
        } elseif ($request->get_method() === 'DELETE') {
            $answer = SEOProAnalytics_Site_Crawler::stop();
        } else {
            $answer = SEOProAnalytics_Site_Crawler::status();
        }
        return is_wp_error($answer) ? $answer : new WP_REST_Response($answer, $request->get_method() === 'POST' ? 202 : 200);
    }

    /** GET /crawl/imports: crawl imports, newest first. @param WP_REST_Request $request Request. @return WP_REST_Response */
    public static function crawl_imports($request) {
        return new WP_REST_Response(array('imports' => SEOProAnalytics_Crawl_Import::imports(), 'job' => SEOProAnalytics_Crawl_Import::status()));
    }

    /** DELETE /crawl/imports/{id}: undo a crawl import. @param WP_REST_Request $request Request. @return WP_REST_Response|WP_Error */
    public static function crawl_undo($request) {
        $deleted = SEOProAnalytics_Crawl_Import::undo((int) $request->get_param('id'));
        return is_wp_error($deleted) ? $deleted : new WP_REST_Response(array('deleted' => $deleted, 'imports' => SEOProAnalytics_Crawl_Import::imports()));
    }

    /**
     * GET /crawl: what an imported crawl saw: pages with a problem, links
     * that fail or redirect, or clicked destinations that fail.
     *
     * @param WP_REST_Request $request Request.
     * @return WP_REST_Response|WP_Error
     */
    public static function crawl($request) {
        $kind    = (string) $request->get_param('kind');
        $status  = (string) $request->get_param('status');
        $finding = (string) $request->get_param('finding');
        return self::report($request, static function ($req) use ($kind, $status, $finding, $request) {
            return SEOProAnalytics_Crawl::report($req, $kind, $status, $finding, (string) $request->get_param('goal'), (string) ($request->get_param('engine') ?: 'google'));
        });
    }

    /** Check now: open the referring pages due for up to 20 seconds, then carry on in cron while an export's pages wait. @param WP_REST_Request $request Request. @return WP_REST_Response|WP_Error */
    public static function backlinks_check($request) {
        if (SEOProAnalytics_Schema::set() !== 'live' || !SEOProAnalytics_Schema::maybe_upgrade()) {
            return new WP_Error('seoproanalytics_backlinks_check', __('Check the live data only.', 'seoproanalytics'), array('status' => 409));
        }
        $done = SEOProAnalytics_Backlinks::run_locked(SEOProAnalytics_Backlinks::BUDGET, true);
        if ($done === null) {
            return new WP_Error('seoproanalytics_backlinks_busy', __('A check is running already; it carries on by itself.', 'seoproanalytics'), array('status' => 409));
        }
        return new WP_REST_Response($done + array('waiting' => SEOProAnalytics_Backlinks::waiting()));
    }

    /**
     * GET /inspections: Google's URL Inspection of the site's pages (the
     * version in Google's index), newest first, with Search Console's
     * sitemaps and the run's progress.
     *
     * @param WP_REST_Request $request Request.
     * @return WP_REST_Response|WP_Error
     */
    public static function inspections($request) {
        $verdict  = (string) $request->get_param('verdict');
        $coverage = (string) $request->get_param('coverage');
        $finding  = (string) $request->get_param('finding');
        return self::report($request, static function ($req) use ($verdict, $coverage, $finding) {
            return SEOProAnalytics_Inspections::report($req, $verdict, $coverage, $finding);
        });
    }

    /**
     * GET /crawl-issues: Bing's crawl issues of the site's addresses, errors
     * first, with its crawl stats, sitemaps, URL submission quota and the
     * weekly links read.
     *
     * @param WP_REST_Request $request Request.
     * @return WP_REST_Response|WP_Error
     */
    public static function crawl_issues($request) {
        $issue = (string) $request->get_param('issue');
        return self::report($request, static function ($req) use ($issue) {
            return SEOProAnalytics_Bing_Site_Data::report($req, $issue);
        });
    }

    /**
     * GET /coverage: a page's search queries, each with how far the page's
     * own words cover it, questions and the SEO plugin's focus keywords.
     *
     * @param WP_REST_Request $request Request.
     * @return WP_REST_Response|WP_Error
     */
    public static function coverage($request) {
        $page = (string) $request->get_param('page');
        $post = (int) $request->get_param('post');
        return self::report($request, static function ($req) use ($page, $post) {
            return SEOProAnalytics_Coverage::report($req, $page, $post);
        });
    }

    /**
     * GET /content: per page, search clicks and position with the visits
     * from search that landed on it and their conversions of a goal.
     *
     * @param WP_REST_Request $request Request.
     * @return WP_REST_Response|WP_Error
     */
    public static function content($request) {
        $sort   = (string) $request->get_param('sort');
        $goal   = (string) $request->get_param('goal');
        $engine = (string) $request->get_param('engine');
        $order  = (string) $request->get_param('order');
        $owner  = self::owner_filter($request);
        if (is_wp_error($owner)) {
            return $owner;
        }
        return self::report($request, static function ($req) use ($sort, $goal, $engine, $order, $owner) {
            return SEOProAnalytics_Content::report($req, $sort, $goal, $engine, $order, $owner);
        });
    }

    /**
     * A request's owner filter (SEOProAnalytics_Owners::filter()).
     *
     * @param WP_REST_Request $request Request.
     * @return int|null|WP_Error
     */
    private static function owner_filter($request) {
        require_once __DIR__ . '/class-seoproanalytics-owners.php';
        return SEOProAnalytics_Owners::filter($request->get_param('owner'));
    }

    /**
     * GET /owners: each person who can own, with what they own (pages,
     * open Plan items and their potential clicks, open targets, running
     * and due experiments), and what nobody owns.
     *
     * @param WP_REST_Request $request Request.
     * @return WP_REST_Response|WP_Error
     */
    public static function owners($request) {
        $engine = (string) $request->get_param('engine');
        $goal   = (string) $request->get_param('goal');
        return self::report($request, static function ($req) use ($engine, $goal) {
            return SEOProAnalytics_Owners::summary($req, $engine, $goal);
        });
    }

    /**
     * POST /owners: set the owner of pages, targets, Plan items or
     * experiments, with those skipped and why.
     *
     * @param WP_REST_Request $request Request.
     * @return WP_REST_Response|WP_Error
     */
    public static function owners_assign($request) {
        $kind = (string) $request->get_param('kind');
        $ids  = (array) $request->get_param('ids');
        $user = (string) $request->get_param('user');
        return self::define($request, static function () use ($kind, $ids, $user) {
            return SEOProAnalytics_Owners::assign($kind, $ids, $user);
        });
    }

    /**
     * POST /annotations: add a note to the timeline.
     *
     * @param WP_REST_Request $request Request.
     * @return WP_REST_Response|WP_Error
     */
    public static function annotate($request) {
        return self::define($request, static function () use ($request) {
            return SEOProAnalytics_Changes::annotate((string) $request->get_param('note'), (string) $request->get_param('page'), (string) $request->get_param('time'));
        });
    }

    /**
     * DELETE /annotations/{id}: delete a note (only notes).
     *
     * @param WP_REST_Request $request Request.
     * @return WP_REST_Response|WP_Error
     */
    public static function delete_annotation($request) {
        $id = (int) $request->get_param('id');
        return self::define($request, static function () use ($id) {
            if (!SEOProAnalytics_Changes::delete_note($id)) {
                return new WP_Error('seoproanalytics_not_found', __('There is no such note.', 'seoproanalytics'), array('status' => 404));
            }
            return array('deleted' => true, 'id' => $id);
        });
    }

    /**
     * GET /experiments: the newest experiments, due ones first.
     *
     * @param WP_REST_Request $request Request.
     * @return WP_REST_Response|WP_Error
     */
    public static function experiments($request) {
        $owner = self::owner_filter($request);
        if (is_wp_error($owner)) {
            return $owner;
        }
        $args = array('status' => (string) $request->get_param('status'), 'page' => (string) $request->get_param('page'), 'owner' => $owner);
        return self::experiment_answer($request, static function () use ($args) {
            return SEOProAnalytics_Experiments::list_experiments($args);
        });
    }

    /**
     * GET /experiments/{id}: one experiment with its measurement.
     *
     * @param WP_REST_Request $request Request.
     * @return WP_REST_Response|WP_Error
     */
    public static function experiment($request) {
        $id = (int) $request->get_param('id');
        return self::experiment_answer($request, static function () use ($id) {
            return SEOProAnalytics_Experiments::get($id);
        });
    }

    /**
     * POST /experiments: record an experiment.
     *
     * @param WP_REST_Request $request Request.
     * @return WP_REST_Response|WP_Error
     */
    public static function experiment_add($request) {
        $input = (array) $request->get_params();
        return self::experiment_answer($request, static function () use ($input) {
            return SEOProAnalytics_Experiments::add($input);
        });
    }

    /**
     * POST /experiments/{id}: decide, note or cancel.
     *
     * @param WP_REST_Request $request Request.
     * @return WP_REST_Response|WP_Error
     */
    public static function experiment_update($request) {
        $id    = (int) $request->get_param('id');
        $input = (array) $request->get_params();
        return self::experiment_answer($request, static function () use ($id, $input) {
            return SEOProAnalytics_Experiments::update($id, $input);
        });
    }

    /**
     * DELETE /experiments/{id}: delete an experiment and its timeline marker.
     *
     * @param WP_REST_Request $request Request.
     * @return WP_REST_Response|WP_Error
     */
    public static function experiment_delete($request) {
        $id = (int) $request->get_param('id');
        return self::define($request, static function () use ($id) {
            if (!SEOProAnalytics_Experiments::delete($id)) {
                return new WP_Error('seoproanalytics_not_found', __('There is no such experiment.', 'seoproanalytics'), array('status' => 404));
            }
            return array('deleted' => true, 'id' => $id);
        });
    }

    /**
     * GET /ab-tests: every A/B test with its visits per variant, leader
     * and verdict; running tests first.
     *
     * @param WP_REST_Request $request Request.
     * @return WP_REST_Response|WP_Error
     */
    public static function ab_tests($request) {
        $args = array('status' => (string) $request->get_param('status'), 'filters' => $request->get_param('filters'));
        return self::experiment_answer($request, static function () use ($args) {
            return SEOProAnalytics_AB_Report::list_tests($args);
        });
    }

    /**
     * GET /ab-tests/{id}: one A/B test's variants side by side.
     *
     * @param WP_REST_Request $request Request.
     * @return WP_REST_Response|WP_Error
     */
    public static function ab_test($request) {
        $id   = (string) $request->get_param('id');
        $args = array('filters' => $request->get_param('filters'));
        return self::experiment_answer($request, static function () use ($id, $args) {
            return SEOProAnalytics_AB_Report::get($id, $args);
        });
    }

    /**
     * POST /ab-tests/{id}/winner: record the winner picked in the editor,
     * once the post no longer holds the test (live data only).
     *
     * @param WP_REST_Request $request Request.
     * @return WP_REST_Response|WP_Error
     */
    public static function ab_test_winner($request) {
        $done = SEOProAnalytics_AB_Tests::pick_winner((string) $request->get_param('id'), (string) $request->get_param('variant'));
        return is_wp_error($done) ? $done : rest_ensure_response($done);
    }

    /**
     * GET /queue: the decision queue, best first, with each item's why and
     * score parts.
     *
     * @param WP_REST_Request $request Request.
     * @return WP_REST_Response|WP_Error
     */
    public static function queue($request) {
        $engine = (string) $request->get_param('engine');
        $status = (string) $request->get_param('status');
        $goal   = (string) $request->get_param('goal');
        $kind   = (string) $request->get_param('kind');
        $owner  = self::owner_filter($request);
        if (is_wp_error($owner)) {
            return $owner;
        }
        return self::report($request, static function ($req) use ($engine, $status, $goal, $kind, $owner) {
            return SEOProAnalytics_Queue::report($req, $engine, $status, $goal, $kind, $owner);
        });
    }

    /**
     * POST /queue/{key}: accept, done, dismiss, restore, effort or note.
     *
     * @param WP_REST_Request $request Request.
     * @return WP_REST_Response|WP_Error
     */
    public static function queue_update($request) {
        $key    = (string) $request->get_param('key');
        $engine = (string) $request->get_param('engine');
        $goal   = (string) $request->get_param('goal');
        $input  = (array) $request->get_params();
        return self::report($request, static function ($req) use ($key, $input, $engine, $goal) {
            return SEOProAnalytics_Queue::update($key, $input, $req, $engine, $goal);
        });
    }

    /**
     * GET /loop: one answer per cycle for an agent: the open queue items,
     * the experiments due, running and recently decided, and the period's
     * search figures per query and page in the aidevops export layout.
     *
     * @param WP_REST_Request $request Request.
     * @return WP_REST_Response|WP_Error
     */
    public static function loop($request) {
        $engine = (string) $request->get_param('engine');
        $goal   = (string) $request->get_param('goal');
        $rows   = (int) $request->get_param('rows');
        return self::report($request, static function ($req) use ($engine, $goal, $rows) {
            return SEOProAnalytics_Loop::report($req, $engine, $goal, $rows);
        });
    }

    /**
     * GET /targets: the site's search targets, each with how search
     * treats it now: position, clicks and the page that ranks, plus nullable
     * research counts, independent measurement dates and the KGR heuristic.
     *
     * @param WP_REST_Request $request Request.
     * @return WP_REST_Response|WP_Error
     */
    public static function targets($request) {
        $engine = (string) $request->get_param('engine');
        $status = (string) $request->get_param('status');
        $owner  = self::owner_filter($request);
        if (is_wp_error($owner)) {
            return $owner;
        }
        return self::report($request, static function ($req) use ($engine, $status, $owner) {
            return SEOProAnalytics_Targets::report($req, $engine, $status, $owner);
        });
    }

    /**
     * POST /targets: import targets (added or updated by query), with the
     * rows skipped and why.
     *
     * @param WP_REST_Request $request Request.
     * @return WP_REST_Response|WP_Error
     */
    public static function targets_import($request) {
        $rows     = $request->get_param('targets');
        $text     = (string) $request->get_param('text');
        $replace  = (bool) $request->get_param('replace');
        $only_new = (bool) $request->get_param('only_new');
        $from     = (string) $request->get_param('source') === 'search' ? 'search' : 'list';
        return self::define($request, static function () use ($rows, $text, $replace, $only_new, $from) {
            $format = 'list';
            if (!is_array($rows) || !$rows) {
                if (trim($text) === '') {
                    return new WP_Error('seoproanalytics_targets_empty', __('Give the targets as a list or as text.', 'seoproanalytics'), array('status' => 400));
                }
                $parsed = SEOProAnalytics_Targets::parse($text);
                if (is_wp_error($parsed)) {
                    return $parsed;
                }
                $rows   = $parsed['rows'];
                $format = $parsed['format'];
            }
            $source = $format === 'toon' ? 'aidevops' : $from;
            $done   = SEOProAnalytics_Targets::import($rows, $source, $replace, $only_new);
            return is_wp_error($done) ? $done : array('format' => $format) + $done;
        });
    }

    /**
     * GET /targets/queries: every target's search with its page, status
     * and priority, without search figures, so reports can mark searches
     * that are targets.
     *
     * @param WP_REST_Request $request Request.
     * @return WP_REST_Response|WP_Error
     */
    public static function targets_queries($request) {
        return self::define($request, static function () {
            return array('targets' => array_values(SEOProAnalytics_Targets::listed()));
        });
    }

    /**
     * GET /targets/suggestions: the SEO plugin's focus keywords, each
     * with its page and whether it is new, a target already, or the focus
     * keyword of more than one page.
     *
     * @param WP_REST_Request $request Request.
     * @return WP_REST_Response|WP_Error
     */
    public static function targets_suggestions($request) {
        $all = (bool) $request->get_param('all_keywords');
        return self::define($request, static function () use ($all) {
            return SEOProAnalytics_Target_Sources::suggestions($all);
        });
    }

    /**
     * POST /targets/suggestions: import the new suggestions (all, or the
     * searches given) as targeted, leaving existing targets as they are.
     *
     * @param WP_REST_Request $request Request.
     * @return WP_REST_Response|WP_Error
     */
    public static function targets_suggestions_import($request) {
        $all     = (bool) $request->get_param('all_keywords');
        $queries = array_map('strval', (array) $request->get_param('queries'));
        return self::define($request, static function () use ($queries, $all) {
            return SEOProAnalytics_Target_Sources::import($queries, $all);
        });
    }

    /**
     * DELETE /targets: delete targets by their searches, or every target.
     *
     * @param WP_REST_Request $request Request.
     * @return WP_REST_Response|WP_Error
     */
    public static function targets_delete($request) {
        $queries = array_map('strval', (array) $request->get_param('queries'));
        $all     = (bool) $request->get_param('all');
        return self::define($request, static function () use ($queries, $all) {
            return SEOProAnalytics_Targets::delete($queries, $all);
        });
    }

    /**
     * Run experiment work on the data set asked for, with short decimals.
     *
     * @param WP_REST_Request $request Request.
     * @param callable        $work    Makes the answer or an error.
     * @return WP_REST_Response|WP_Error
     */
    private static function experiment_answer($request, callable $work) {
        self::short_floats();
        return self::define($request, $work);
    }

    /**
     * POST /goals, PUT /goals/{id}: add or change a goal.
     *
     * @param WP_REST_Request $request Request.
     * @return WP_REST_Response|WP_Error
     */
    public static function save_goals($request) {
        return self::define($request, static function () use ($request) {
            return SEOProAnalytics_Goals::save_goal((array) $request->get_params(), (string) $request->get_param('id'));
        });
    }

    /**
     * POST /funnels, PUT /funnels/{id}: add or change a funnel.
     *
     * @param WP_REST_Request $request Request.
     * @return WP_REST_Response|WP_Error
     */
    public static function save_funnels($request) {
        return self::define($request, static function () use ($request) {
            return SEOProAnalytics_Goals::save_funnel((array) $request->get_params(), (string) $request->get_param('id'));
        });
    }

    /**
     * DELETE /goals/{id}.
     *
     * @param WP_REST_Request $request Request.
     * @return WP_REST_Response|WP_Error
     */
    public static function delete_goals($request) {
        return self::remove_definition($request, 'goals');
    }

    /**
     * DELETE /funnels/{id}.
     *
     * @param WP_REST_Request $request Request.
     * @return WP_REST_Response|WP_Error
     */
    public static function delete_funnels($request) {
        return self::remove_definition($request, 'funnels');
    }

    /**
     * Delete a goal or funnel on the data set asked for.
     *
     * @param WP_REST_Request $request Request.
     * @param string          $type    goals or funnels.
     * @return WP_REST_Response|WP_Error
     */
    private static function remove_definition($request, $type) {
        $id = (string) $request->get_param('id');
        return self::define($request, static function () use ($type, $id) {
            if (!SEOProAnalytics_Goals::delete($type, $id)) {
                return new WP_Error('seoproanalytics_not_found', __('There is no such goal or funnel.', 'seoproanalytics'), array('status' => 404));
            }
            return array('deleted' => true, 'id' => $id);
        });
    }

    /**
     * Change definitions on the data set asked for (the demo data has its
     * own goals and funnels).
     *
     * @param WP_REST_Request $request Request.
     * @param callable        $work    Makes the change; returns the answer or an error.
     * @return WP_REST_Response|WP_Error
     */
    private static function define($request, callable $work) {
        $answer = self::on_data((string) $request->get_param('data'), $work);
        return is_wp_error($answer) ? $answer : rest_ensure_response($answer);
    }

    /**
     * Run a report for a REST request.
     *
     * @param WP_REST_Request $request Request.
     * @param string          $report  stats, timeseries or breakdown.
     * @return WP_REST_Response|WP_Error
     */
    private static function answer($request, $report) {
        return self::report($request, static function ($req) use ($report) {
            if ($report === 'stats') {
                return SEOProAnalytics_Query::stats($req);
            }
            if ($report === 'timeseries') {
                return SEOProAnalytics_Query::timeseries($req);
            }
            return SEOProAnalytics_Query::breakdown($req);
        });
    }

    /**
     * Run a report on the request's range, filters and data set.
     *
     * @param WP_REST_Request $request Request.
     * @param callable        $work    Takes the checked request; makes the answer.
     * @return WP_REST_Response|WP_Error
     */
    private static function report($request, callable $work) {
        $req = SEOProAnalytics_Query::request((array) $request->get_params());
        if (is_wp_error($req)) {
            return $req;
        }
        $answer = self::on_data((string) $request->get_param('data'), static function () use ($req, $work) {
            return $work($req);
        });
        if (is_wp_error($answer)) {
            return $answer;
        }
        // WordPress sends no-cache headers to signed-in users; the engine
        // caches answers on the server instead.
        self::short_floats();
        return rest_ensure_response($answer);
    }

    /**
     * Fields of a goal (also of each funnel step).
     *
     * @return array<string,array<string,mixed>>
     */
    private static function goal_args() {
        return array(
            'name'  => array(
                'description' => __('Name shown in reports.', 'seoproanalytics'),
                'type'        => 'string',
            ),
            'kind'  => array(
                'description' => __('What counts: a page viewed or an event sent.', 'seoproanalytics'),
                'type'        => 'string',
                'enum'        => SEOProAnalytics_Goals::KINDS,
            ),
            'match' => array(
                'description' => __('The page path (such as /pricing/; * for any text) or the event name (such as Purchase).', 'seoproanalytics'),
                'type'        => 'string',
            ),
            'prop'  => array(
                'description' => __('Optional event property key. Leave both property fields empty to remove the condition.', 'seoproanalytics'),
                'type'        => 'string',
                'maxLength'   => SEOProAnalytics_Goals::MAX_MATCH,
            ),
            'value' => array(
                'description' => __('Property value; * matches any text. Requires a property key and an event goal or step.', 'seoproanalytics'),
                'type'        => 'string',
                'maxLength'   => SEOProAnalytics_Goals::MAX_MATCH,
            ),
        );
    }

    /**
     * Fields of a funnel.
     *
     * @return array<string,array<string,mixed>>
     */
    private static function funnel_args() {
        $step = self::goal_args();
        return array(
            'name'  => $step['name'],
            'steps' => array(
                'description' => sprintf(
                    /* translators: 1: fewest steps, 2: most steps */
                    __('%1$d to %2$d steps, reached in this order within one visit; each a name, kind and match as for goals.', 'seoproanalytics'),
                    SEOProAnalytics_Goals::MIN_STEPS,
                    SEOProAnalytics_Goals::MAX_STEPS
                ),
                'type'        => 'array',
                'items'       => array(
                    'type'       => 'object',
                    'properties' => $step,
                ),
            ),
        );
    }

    /**
     * Arguments shared by the report routes.
     *
     * @param bool $breakdown Whether to add dimension, limit and offset.
     * @return array<string,array<string,mixed>>
     */
    private static function args($breakdown) {
        $args = array(
            'range'   => array(
                'description' => __('Period, in the site time zone.', 'seoproanalytics'),
                'type'        => 'string',
                'enum'        => SEOProAnalytics_Query::RANGES,
                'default'     => '7d',
            ),
            'from'    => array(
                'description' => __('First day of a custom range (YYYY-MM-DD).', 'seoproanalytics'),
                'type'        => 'string',
                'format'      => 'date',
            ),
            'to'      => array(
                'description' => __('Last day of a custom range (YYYY-MM-DD).', 'seoproanalytics'),
                'type'        => 'string',
                'format'      => 'date',
            ),
            'compare' => array(
                'description' => __('Compare with the period before (prev), four earlier same-weekday periods averaged (typical; stats and timeseries only, whole-day ranges up to 28 days), a week earlier (week), a month earlier by weekday or date (month_weekday, month), or a year earlier by weekday or date (year_weekday, year).', 'seoproanalytics'),
                'type'        => 'string',
                'enum'        => SEOProAnalytics_Query::COMPARE,
                'default'     => 'none',
            ),
            // No type: WordPress would split a string on commas, and comma
            // means "any of" inside one filter. The engine checks it.
            'filters' => array(
                'description' => __('Filters: dimension:operator:value strings (comma means any of), or a JSON list of {dimension, op, values}.', 'seoproanalytics'),
            ),
            'data'    => array(
                'description' => __('Live statistics, or the demo data (made-up visits for training, screenshots and testing).', 'seoproanalytics'),
                'type'        => 'string',
                'enum'        => SEOProAnalytics_Schema::SETS,
                'default'     => 'live',
            ),
        );
        if ($breakdown) {
            $args['dimension'] = array(
                'description' => __('What to break the visits down by.', 'seoproanalytics'),
                'type'        => 'string',
                'enum'        => array_keys(SEOProAnalytics_Query::DIMENSIONS),
                'required'    => true,
            );
            $args['limit']     = array(
                'description' => __('Most rows.', 'seoproanalytics'),
                'type'        => 'integer',
                'minimum'     => 1,
                'maximum'     => SEOProAnalytics_Query::MAX_LIMIT,
                'default'     => 10,
            );
            $args['offset']    = array(
                'description' => __('Rows to skip.', 'seoproanalytics'),
                'type'        => 'integer',
                'minimum'     => 0,
                'default'     => 0,
            );
        }
        return $args;
    }
}

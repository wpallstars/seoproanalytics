<?php
/**
 * Abilities (WordPress 6.9 and later): what SEO Pro Analytics can do for AI
 * agents and other tools, through WordPress's Abilities API (and its REST
 * routes under wp-abilities/v1). The same engine as the REST API and
 * WP-CLI answers them.
 *
 * - seoproanalytics/markers: the changes in a range, oldest first (read).
 * - seoproanalytics/annotate: add a note to the timeline (administrators).
 * - seoproanalytics/search: Search Console clicks, impressions, CTR and
 *   position, with top queries, pages, countries or devices (read).
 * - seoproanalytics/opportunities: striking-distance and low-CTR queries,
 *   pages losing clicks with the likely cause, queries missing from
 *   their page, and queries shared by several pages (read).
 * - seoproanalytics/audit: published pages with findings from their content
 *   and SEO plugin fields, weighed by search impressions (read).
 * - seoproanalytics/links: orphan pages, converting pages with few links in,
 *   and links missing between pages that share a search (read).
 * - seoproanalytics/indexation: published pages and sitemap addresses
 *   search has not shown in its newest days (read).
 * - seoproanalytics/coverage: one page's queries, each checked against the
 *   page's words, questions and SEO plugin focus keywords (read).
 * - seoproanalytics/content: per page, search clicks and position with the
 *   visits from search that landed on it and their conversions (read).
 * - seoproanalytics/experiments: changes and what they were meant to do,
 *   measured against unchanged pages, with a suggested result (read).
 * - seoproanalytics/experiment-record: record, decide, note or cancel an
 *   experiment (administrators).
 * - seoproanalytics/ab-tests: A/B tests of blocks, their variants side by
 *   side with uplift, interval, probability to beat the control and a
 *   verdict (read).
 * - seoproanalytics/queue: the decision queue, a ranked list of search work
 *   with each item's why and score parts (read).
 * - seoproanalytics/queue-update: accept, do (opens an experiment), dismiss
 *   or restore an item, or set its effort or note (administrators).
 * - seoproanalytics/targets: the site's search targets, each with its
 *   position, clicks and the page that ranks (read).
 * - seoproanalytics/targets-import: import or delete search targets, or
 *   import the SEO plugin's focus keywords (administrators).
 * - seoproanalytics/targets-suggest: the SEO plugin's focus keywords as
 *   target suggestions, each new, a target already, or the focus keyword
 *   of more than one page (administrators; read).
 * - seoproanalytics/business-profiles: the business profiles listed in
 *   Settings → Data, with their tagged links and the visits,
 *   conversions and revenue from each (read).
 * - seoproanalytics/migrate: other statistics plugins whose history is on
 *   the site, an import's dry run and what a plugin leaves behind
 *   (administrators; read).
 * - seoproanalytics/migrate-import: import a plugin's history
 *   (administrators). Removing its leftovers is for people only.
 *
 * On older WordPress the hooks never run.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 * Additional terms (GPL-3.0 section 7(b)): ATTRIBUTION.txt
 * Provenance: wpas-c8662019b99fb07b. Copies, even of part, keep these lines.
 *
 * @package SEOProAnalytics
 * @since 0.7.0
 */

if (!defined('ABSPATH')) {
    exit;
}

final class SEOProAnalytics_Abilities {

    /** The abilities' category. */
    const CATEGORY = 'site-statistics';

    /**
     * Register hooks.
     */
    public static function init() {
        add_action('wp_abilities_api_categories_init', array(__CLASS__, 'register_category'));
        add_action('wp_abilities_api_init', array(__CLASS__, 'register'));
    }

    /**
     * Read stored collection health without running checks or sending mail.
     *
     * @return array
     */
    public static function alerts() {
        require_once __DIR__ . '/class-seoproanalytics-alerts.php';
        return array('alerts' => SEOProAnalytics_Alerts::open());
    }

    /** Register the category only when supported and not already registered. */
    public static function register_category() {
        // The Abilities API is WordPress 6.9 and later; these hooks fire only there.
        if (!function_exists('wp_register_ability_category')) {
            return;
        }
        if (function_exists('wp_has_ability_category') && wp_has_ability_category(self::CATEGORY)) {
            return;
        }
        wp_register_ability_category(self::CATEGORY, array(
            'label'       => __('Site statistics', 'seoproanalytics'),
            'description' => __('Visits, sources, conversions and the changes that moved them.', 'seoproanalytics'),
        ));
    }

    /**
     * The abilities.
     */
    public static function register() {
        if (!function_exists('wp_register_ability')) {
            return;
        }
        SEOProAnalytics_API::load();
        require_once __DIR__ . '/class-seoproanalytics-backlinks-triage.php';
        wp_register_ability('seoproanalytics/alerts', array(
            'label' => __('Statistics alerts', 'seoproanalytics'),
            'description' => __('Read open and recently resolved collection-health alerts. Does not run checks or send email.', 'seoproanalytics'),
            'category' => self::CATEGORY,
            'input_schema' => array('type' => 'object', 'additionalProperties' => false, 'properties' => array()),
            'output_schema' => array('type' => 'object'),
            'permission_callback' => array('SEOProAnalytics_API', 'can_manage'),
            'execute_callback' => array(__CLASS__, 'alerts'),
            'meta' => array('show_in_rest' => true, 'annotations' => array('readonly' => true)),
        ));
        wp_register_ability('seoproanalytics/vitals', array(
            'label' => __('Page experience', 'seoproanalytics'),
            'description' => __('Read locally stored Chrome UX Report field metrics, origin history and failing pages. Never runs a lab test.', 'seoproanalytics'),
            'category' => self::CATEGORY,
            'input_schema' => array('type' => 'object', 'additionalProperties' => false, 'properties' => array('page' => array('type' => 'string', 'default' => ''), 'data' => array('type' => 'string', 'enum' => SEOProAnalytics_Schema::SETS, 'default' => 'live'))),
            'output_schema' => array('type' => 'object'),
            'permission_callback' => array('SEOProAnalytics_API', 'can_read'),
            'execute_callback' => array(__CLASS__, 'vitals'),
            'meta' => array('show_in_rest' => true),
        ));
        $data = array(
            'type'        => 'string',
            'enum'        => SEOProAnalytics_Schema::SETS,
            'default'     => 'live',
            'description' => __('Live statistics, or the demo data.', 'seoproanalytics'),
        );
        wp_register_ability('seoproanalytics/markers', array(
            'label'               => __('Changes on the timeline', 'seoproanalytics'),
            'description'         => __('What changed on the site in a period, oldest first: posts published, edited or moved, SEO fields, prices and stock, plugin, theme and WordPress updates, settings, search engine updates (when switched on) and notes. Set them against traffic and sales to see what moved them.', 'seoproanalytics'),
            'category'            => self::CATEGORY,
            'input_schema'        => array(
                'type'                 => 'object',
                'default'              => array(),
                'additionalProperties' => false,
                'properties'           => array(
                    'range' => array(
                        'type'        => 'string',
                        'enum'        => SEOProAnalytics_Query::RANGES,
                        'default'     => '30d',
                        'description' => __('Period, in the site time zone.', 'seoproanalytics'),
                    ),
                    'from'  => array(
                        'type'        => 'string',
                        'description' => __('First day of a custom range (YYYY-MM-DD).', 'seoproanalytics'),
                    ),
                    'to'    => array(
                        'type'        => 'string',
                        'description' => __('Last day of a custom range (YYYY-MM-DD).', 'seoproanalytics'),
                    ),
                    'page'  => array(
                        'type'        => 'string',
                        'description' => __('Only changes to this page (a path such as /pricing/; * for any text), and the site-wide ones.', 'seoproanalytics'),
                    ),
                    'kinds' => array(
                        'type'        => 'string',
                        'description' => __('Only these kinds or groups of change (content, seo, product, site, search, note), comma-separated.', 'seoproanalytics'),
                    ),
                    'data'  => $data,
                ),
            ),
            'output_schema'       => array(
                'type'       => 'object',
                'properties' => array(
                    'range'   => array('type' => 'object'),
                    'markers' => array(
                        'type'  => 'array',
                        'items' => array('type' => 'object'),
                    ),
                    'total'   => array('type' => 'integer'),
                ),
            ),
            'execute_callback'    => array(__CLASS__, 'markers'),
            'permission_callback' => array('SEOProAnalytics_API', 'can_read'),
            'meta'                => array(
                'show_in_rest' => true,
                'annotations'  => array(
                    'readonly'    => true,
                    'destructive' => false,
                    'idempotent'  => true,
                ),
            ),
        ));
        wp_register_ability('seoproanalytics/annotate', array(
            'label'               => __('Add a note to the timeline', 'seoproanalytics'),
            'description'         => __('Note something the change log cannot see, such as a newsletter sent, a sale or a move to a new host, so the charts show it next to the traffic it affected.', 'seoproanalytics'),
            'category'            => self::CATEGORY,
            'input_schema'        => array(
                'type'                 => 'object',
                'additionalProperties' => false,
                'required'             => array('note'),
                'properties'           => array(
                    'note' => array(
                        'type'        => 'string',
                        'minLength'   => 1,
                        'maxLength'   => SEOProAnalytics_Changes::MAX_VALUE,
                        'description' => __('What happened.', 'seoproanalytics'),
                    ),
                    'page' => array(
                        'type'        => 'string',
                        'description' => __('The page it is about (a path such as /pricing/); without it, the whole site.', 'seoproanalytics'),
                    ),
                    'time' => array(
                        'type'        => 'string',
                        'description' => __('When, in the site time zone (2026-10-05 or 2026-10-05 14:30); without it, now.', 'seoproanalytics'),
                    ),
                    'data' => $data,
                ),
            ),
            'output_schema'       => array('type' => 'object'),
            'execute_callback'    => array(__CLASS__, 'annotate'),
            'permission_callback' => array('SEOProAnalytics_API', 'can_manage'),
            'meta'                => array(
                'show_in_rest' => true,
                'annotations'  => array(
                    'readonly'    => false,
                    'destructive' => false,
                    'idempotent'  => false,
                ),
            ),
        ));
        $engine = array(
            'type'        => 'string',
            'enum'        => array_keys(SEOProAnalytics_Search::ENGINES),
            'default'     => 'google',
            'description' => __('Search engine: google (Search Console) or bing (Bing Webmaster Tools). Bing gives pages and queries by week, stored on each week\'s last day, and no countries or devices.', 'seoproanalytics'),
        );
        // Rankings, Opportunities and Content can also add up every engine with data.
        $combined = array(
            'enum'        => array_merge(array_keys(SEOProAnalytics_Search::ENGINES), array(SEOProAnalytics_Search::ALL)),
            'description' => __('Search engine: google (Search Console), bing (Bing Webmaster Tools) or all (Combined: every engine with data added up, a query or page shared by engines as one row; the period ends at the earliest newest day, in whole weeks with Bing; no countries or devices; as the one engine while only one has data). Bing gives pages and queries by week, stored on each week\'s last day, and no countries or devices.', 'seoproanalytics'),
        ) + $engine;
        $order    = array(
            'type'        => 'string',
            'enum'        => array('', 'asc', 'desc'),
            'default'     => '',
            'description' => __('Order of the sort: desc (most first) or asc (least first). Left out: lowest first for position (a lower position is better), most first for the rest.', 'seoproanalytics'),
        );
        wp_register_ability('seoproanalytics/search', array(
            'label'               => __('Search rankings', 'seoproanalytics'),
            'description'         => __('Search engine clicks, impressions, CTR and average position in a period (Google Search Console, Bing Webmaster Tools with engine bing, or every engine added up with engine all), with the comparison, and the top search queries, pages, countries, devices or search appearances, or the figures by day (week or month); or one page\'s queries, or one query\'s pages. Search data is final only, so the newest day is some days old; the period is cut there. The answer lists the engines with data.', 'seoproanalytics'),
            'category'            => self::CATEGORY,
            'input_schema'        => array(
                'type'                 => 'object',
                'default'              => array(),
                'additionalProperties' => false,
                'properties'           => array(
                    'engine'  => $combined,
                    'range'   => array(
                        'type'        => 'string',
                        'enum'        => SEOProAnalytics_Query::RANGES,
                        'default'     => '30d',
                        'description' => __('Period, in the site time zone.', 'seoproanalytics'),
                    ),
                    'from'    => array(
                        'type'        => 'string',
                        'description' => __('First day of a custom range (YYYY-MM-DD).', 'seoproanalytics'),
                    ),
                    'to'      => array(
                        'type'        => 'string',
                        'description' => __('Last day of a custom range (YYYY-MM-DD).', 'seoproanalytics'),
                    ),
                    'compare' => array(
                        'type'        => 'string',
                        'enum'        => SEOProAnalytics_Query::COMPARE,
                        'default'     => 'none',
                        'description' => __('Compare with the period before (prev; this week, month or year so far meets the same part of the previous one), four earlier same-weekday periods averaged (typical; stats and timeseries only, whole-day ranges up to 28 days), a week earlier (week), a month earlier by weekday or date (month_weekday, month), or a year earlier by weekday, 52 weeks back, or date (year_weekday, year).', 'seoproanalytics'),
                    ),
                    'kind'    => array(
                        'type'        => 'string',
                        'enum'        => SEOProAnalytics_Search::KINDS,
                        'default'     => 'queries',
                        'description' => __('Rows: search queries, pages, countries, devices, appearance (countries, devices and appearance for the whole site, Google only) or days (the chart\'s points by day, week or month, newest first).', 'seoproanalytics'),
                    ),
                    'page'    => array(
                        'type'        => 'string',
                        'description' => __('Only searches that showed this page (a path such as /pricing/; * for any text).', 'seoproanalytics'),
                    ),
                    'query'   => array(
                        'type'        => 'string',
                        'description' => __('Only this search query (* for any text).', 'seoproanalytics'),
                    ),
                    'sort'    => array(
                        'type'        => 'string',
                        'enum'        => array_merge(array(''), SEOProAnalytics_Search::SORTS, array('day')),
                        'default'     => '',
                        'description' => __('Order of the rows: impressions (the default), clicks, ctr or position; days also by day (their default, newest first).', 'seoproanalytics'),
                    ),
                    'order'   => $order,
                    'limit'   => array(
                        'type'    => 'integer',
                        'minimum' => 1,
                        'maximum' => SEOProAnalytics_Query::MAX_LIMIT,
                        'default' => 25,
                    ),
                    'offset'  => array(
                        'type'        => 'integer',
                        'minimum'     => 0,
                        'default'     => 0,
                        'description' => __('Rows skipped (for the next rows; the answer says whether there are more).', 'seoproanalytics'),
                    ),
                    'data'    => $data,
                ),
            ),
            'output_schema'       => array(
                'type'       => 'object',
                'properties' => array(
                    'engine'    => array('type' => 'string'),
                    'engines'   => array(
                        'type'  => 'array',
                        'items' => array('type' => 'string'),
                    ),
                    'combined'  => array('type' => 'boolean'),
                    'range'     => array('type' => 'object'),
                    'through'   => array('type' => 'string'),
                    'connected' => array('type' => 'boolean'),
                    'grain'     => array('type' => 'string'),
                    'totals'    => array('type' => 'object'),
                    'points'    => array(
                        'type'  => 'array',
                        'items' => array('type' => 'object'),
                    ),
                    'rows'      => array(
                        'type'  => 'array',
                        'items' => array('type' => 'object'),
                    ),
                ),
            ),
            'execute_callback'    => array(__CLASS__, 'search'),
            'permission_callback' => array('SEOProAnalytics_API', 'can_read'),
            'meta'                => array(
                'show_in_rest' => true,
                'annotations'  => array(
                    'readonly'    => true,
                    'destructive' => false,
                    'idempotent'  => true,
                ),
            ),
        ));
        wp_register_ability('seoproanalytics/opportunities', array(
            'label'               => __('Search opportunities', 'seoproanalytics'),
            'description'         => __('Where search work pays, from Google Search Console (or Bing Webmaster Tools with engine bing, or every engine added up with engine all): striking (a page\'s query at position 4–20, with the clicks it could gain in the top three), ctr (a top-10 query whose CTR is well under the site\'s own at that position: improve its title and description), decay (pages losing clicks against the previous period, each with the likely cause, position, demand, ctr or gone, the queries that lost most, and the changes made to the page), missing (a top-20 query whose words its page does not have, or has only some of: the words missing, and whether it is a question to answer) or overlap (a query for which two or more pages each get at least 10% of the impressions, with each page\'s clicks, share and position and whether the page with most impressions changed between the halves of the period: a candidate to review, as two pages can both be right). Expected CTR is the site\'s own. Final days only; at most the newest 366 days of the period are read.', 'seoproanalytics'),
            'category'            => self::CATEGORY,
            'input_schema'        => array(
                'type'                 => 'object',
                'default'              => array(),
                'additionalProperties' => false,
                'properties'           => array(
                    'kind'    => array(
                        'type'        => 'string',
                        'enum'        => SEOProAnalytics_Opportunities::KINDS,
                        'default'     => 'striking',
                        'description' => __('striking, ctr, decay, missing or overlap.', 'seoproanalytics'),
                    ),
                    'engine'  => $combined,
                    'range'   => array(
                        'type'        => 'string',
                        'enum'        => SEOProAnalytics_Query::RANGES,
                        'default'     => '30d',
                        'description' => __('Period, in the site time zone.', 'seoproanalytics'),
                    ),
                    'from'    => array(
                        'type'        => 'string',
                        'description' => __('First day of a custom range (YYYY-MM-DD).', 'seoproanalytics'),
                    ),
                    'to'      => array(
                        'type'        => 'string',
                        'description' => __('Last day of a custom range (YYYY-MM-DD).', 'seoproanalytics'),
                    ),
                    'compare' => array(
                        'type'        => 'string',
                        'enum'        => array('prev', 'year', 'year_weekday'),
                        'default'     => 'prev',
                        'description' => __('For decay: against the period before (prev), or a year earlier by date (year) or by weekday, 52 weeks back (year_weekday).', 'seoproanalytics'),
                    ),
                    'limit'   => array(
                        'type'    => 'integer',
                        'minimum' => 1,
                        'maximum' => SEOProAnalytics_Query::MAX_LIMIT,
                        'default' => 25,
                    ),
                    'data'    => $data,
                ),
            ),
            'output_schema'       => array(
                'type'       => 'object',
                'properties' => array(
                    'engine'    => array('type' => 'string'),
                    'kind'      => array('type' => 'string'),
                    'range'     => array('type' => 'object'),
                    'through'   => array('type' => 'string'),
                    'connected' => array('type' => 'boolean'),
                    'rules'     => array('type' => 'object'),
                    'rows'      => array(
                        'type'  => 'array',
                        'items' => array('type' => 'object'),
                    ),
                    'total'     => array('type' => 'integer'),
                ),
            ),
            'execute_callback'    => array(__CLASS__, 'opportunities'),
            'permission_callback' => array('SEOProAnalytics_API', 'can_read'),
            'meta'                => array(
                'show_in_rest' => true,
                'annotations'  => array(
                    'readonly'    => true,
                    'destructive' => false,
                    'idempotent'  => true,
                ),
            ),
        ));
        wp_register_ability('seoproanalytics/audit', array(
            'label'               => __('Content audit', 'seoproanalytics'),
            'description'         => __('Published pages with findings from their WordPress content and SEO plugin fields, most search impressions first: title or description missing, too long or the same as another page\'s (with those pages), no H1 or several, images without alt text, a thin page (few words, with impressions but no clicks), and noindex or a canonical address elsewhere on a page with search impressions; with Search Console, Google\'s URL Inspection findings (google on the row); with Bing Webmaster Tools, bing_crawl: Bing\'s crawler had an error on the page (bing on the row: http, issues; see seoproanalytics/crawl-issues); with an imported crawl (seoproanalytics/crawl), whatever the page\'s impressions: crawl_error (the page answered an error or nothing), served_noindex and served_canonical (served with noindex or a canonical elsewhere that WordPress does not set: a theme, plugin, cache or the host adds it), broken_links and redirect_links (it links to addresses that fail or redirect; crawl on the row). Each page has its search figures and its facts (lengths, words, H1s, images, robots, when it was read); counts give the pages per finding. Facts are read when a post is saved and by a daily batch.', 'seoproanalytics'),
            'category'            => self::CATEGORY,
            'input_schema'        => array(
                'type'                 => 'object',
                'default'              => array(),
                'additionalProperties' => false,
                'properties'           => array(
                    'finding' => array(
                        'type'        => 'string',
                        'enum'        => array_merge(array(''), SEOProAnalytics_Audit::FINDINGS),
                        'default'     => '',
                        'description' => __('Only pages with this finding; all when empty.', 'seoproanalytics'),
                    ),
                    'sort'    => array(
                        'type'        => 'string',
                        'enum'        => array_merge(array(''), SEOProAnalytics_Search::SORTS),
                        'default'     => '',
                        'description' => __('Order of the pages by their search figures: impressions (the default), clicks, ctr or position.', 'seoproanalytics'),
                    ),
                    'order'   => $order,
                    'engine'  => $engine,
                    'range'   => array(
                        'type'        => 'string',
                        'enum'        => SEOProAnalytics_Query::RANGES,
                        'default'     => '30d',
                        'description' => __('Period of the search figures, in the site time zone.', 'seoproanalytics'),
                    ),
                    'from'    => array(
                        'type'        => 'string',
                        'description' => __('First day of a custom range (YYYY-MM-DD).', 'seoproanalytics'),
                    ),
                    'to'      => array(
                        'type'        => 'string',
                        'description' => __('Last day of a custom range (YYYY-MM-DD).', 'seoproanalytics'),
                    ),
                    'limit'   => array(
                        'type'    => 'integer',
                        'minimum' => 1,
                        'maximum' => SEOProAnalytics_Audit::MAX_LIMIT,
                        'default' => 25,
                    ),
                    'offset'  => array(
                        'type'    => 'integer',
                        'minimum' => 0,
                        'default' => 0,
                    ),
                    'data'    => $data,
                ),
            ),
            'output_schema'       => array(
                'type'       => 'object',
                'properties' => array(
                    'engine'    => array('type' => 'string'),
                    'range'     => array('type' => 'object'),
                    'connected' => array('type' => 'boolean'),
                    'rules'     => array('type' => 'object'),
                    'checked'   => array('type' => 'object'),
                    'counts'    => array('type' => 'object'),
                    'pages'     => array('type' => 'integer'),
                    'rows'      => array(
                        'type'  => 'array',
                        'items' => array('type' => 'object'),
                    ),
                    'total'     => array('type' => 'integer'),
                ),
            ),
            'execute_callback'    => array(__CLASS__, 'audit'),
            'permission_callback' => array('SEOProAnalytics_API', 'can_read'),
            'meta'                => array(
                'show_in_rest' => true,
                'annotations'  => array(
                    'readonly'    => true,
                    'destructive' => false,
                    'idempotent'  => true,
                ),
            ),
        ));
        wp_register_ability('seoproanalytics/links', array(
            'label'               => __('Internal links', 'seoproanalytics'),
            'description'         => __('Links between the site\'s own pages, read from the text of published pages, in three lists. orphans: pages no other page links to, most search impressions first. converting: pages whose visits from search reach the goal 3 times or more with 2 or fewer pages linking to them, most conversions first: link to them from related pages. The front page is in neither list. missing: a page shows for a search but does not link to the page that gets most of its clicks, with the searches and both pages\' figures, most impressions first: add the link. Rows name the pages linking in (up to 5); counts give each list\'s size and read how many pages\' links were read so far. Links are read with the content audit when a post is saved and by a daily batch.', 'seoproanalytics'),
            'category'            => self::CATEGORY,
            'input_schema'        => array(
                'type'                 => 'object',
                'default'              => array(),
                'additionalProperties' => false,
                'properties'           => array(
                    'kind'   => array(
                        'type'        => 'string',
                        'enum'        => SEOProAnalytics_Links::KINDS,
                        'default'     => 'orphans',
                        'description' => __('Which list.', 'seoproanalytics'),
                    ),
                    'goal'   => array(
                        'type'        => 'string',
                        'default'     => '',
                        'description' => __('ID of the goal whose conversions are counted; the first goal when empty.', 'seoproanalytics'),
                    ),
                    'engine' => $engine,
                    'range'  => array(
                        'type'        => 'string',
                        'enum'        => SEOProAnalytics_Query::RANGES,
                        'default'     => '30d',
                        'description' => __('Period of the search figures and conversions, in the site time zone.', 'seoproanalytics'),
                    ),
                    'from'   => array(
                        'type'        => 'string',
                        'description' => __('First day of a custom range (YYYY-MM-DD).', 'seoproanalytics'),
                    ),
                    'to'     => array(
                        'type'        => 'string',
                        'description' => __('Last day of a custom range (YYYY-MM-DD).', 'seoproanalytics'),
                    ),
                    'limit'  => array(
                        'type'    => 'integer',
                        'minimum' => 1,
                        'maximum' => SEOProAnalytics_Links::MAX_LIMIT,
                        'default' => 25,
                    ),
                    'offset' => array(
                        'type'    => 'integer',
                        'minimum' => 0,
                        'default' => 0,
                    ),
                    'data'   => $data,
                ),
            ),
            'output_schema'       => array(
                'type'       => 'object',
                'properties' => array(
                    'engine'    => array('type' => 'string'),
                    'range'     => array('type' => 'object'),
                    'connected' => array('type' => 'boolean'),
                    'kind'      => array('type' => 'string'),
                    'goal'      => array('type' => array('object', 'null')),
                    'rules'     => array('type' => 'object'),
                    'read'      => array('type' => 'object'),
                    'counts'    => array('type' => 'object'),
                    'rows'      => array(
                        'type'  => 'array',
                        'items' => array('type' => 'object'),
                    ),
                    'total'     => array('type' => 'integer'),
                ),
            ),
            'execute_callback'    => array(__CLASS__, 'links'),
            'permission_callback' => array('SEOProAnalytics_API', 'can_read'),
            'meta'                => array(
                'show_in_rest' => true,
                'annotations'  => array(
                    'readonly'    => true,
                    'destructive' => false,
                    'idempotent'  => true,
                ),
            ),
        ));
        wp_register_ability('seoproanalytics/business-profiles', array(
            'label'               => __('Business profiles', 'seoproanalytics'),
            'description'         => __('The site\'s business profiles (Google Business Profile, Bing Places, Apple Business Connect, other directories) listed in Settings → Data, each with its tagged website link (utm_medium=business_profile, utm_campaign the profile\'s campaign) and the visitors, visits, goal conversions and revenue by currency of the visits from that link. channel has the same for the whole Business profiles channel, which also counts map apps\' visits without the link. Empty profiles: none listed or the setting is off.', 'seoproanalytics'),
            'category'            => self::CATEGORY,
            'input_schema'        => array(
                'type'                 => 'object',
                'default'              => array(),
                'additionalProperties' => false,
                'properties'           => array(
                    'range'   => array(
                        'type'        => 'string',
                        'enum'        => SEOProAnalytics_Query::RANGES,
                        'default'     => '30d',
                        'description' => __('Period, in the site time zone.', 'seoproanalytics'),
                    ),
                    'from'    => array(
                        'type'        => 'string',
                        'description' => __('First day of a custom range (YYYY-MM-DD).', 'seoproanalytics'),
                    ),
                    'to'      => array(
                        'type'        => 'string',
                        'description' => __('Last day of a custom range (YYYY-MM-DD).', 'seoproanalytics'),
                    ),
                    'filters' => array(
                        'type'        => 'array',
                        'items'       => array('type' => 'string'),
                        'description' => __('Only these visits: dimension:operator:value strings, as for the statistics (such as device:is:mobile).', 'seoproanalytics'),
                    ),
                    'data'    => $data,
                ),
            ),
            'output_schema'       => array(
                'type'       => 'object',
                'properties' => array(
                    'range'    => array('type' => 'object'),
                    'channel'  => array('type' => 'object'),
                    'profiles' => array(
                        'type'  => 'array',
                        'items' => array('type' => 'object'),
                    ),
                ),
            ),
            'execute_callback'    => array(__CLASS__, 'business_profiles'),
            'permission_callback' => array('SEOProAnalytics_API', 'can_read'),
            'meta'                => array(
                'show_in_rest' => true,
                'annotations'  => array(
                    'readonly'    => true,
                    'destructive' => false,
                    'idempotent'  => true,
                ),
            ),
        ));
        wp_register_ability('seoproanalytics/indexation', array(
            'label'               => __('Indexation', 'seoproanalytics'),
            'description'         => __('Pages search engines do not seem to show, from the site\'s own search data, in two lists. pages: published pages with no search impressions in the engine\'s newest days (28 by default), published before them. sitemap: other addresses in the site\'s own sitemaps (category, tag and author archives, other plugins\'), listed that long, with none. Each row says whether search never showed it (state never) or showed it until last_impression (state lost), with its age in days; pages add words and links_in (pages linking to it). Never shown first, then the newest. Pages that ask not to be indexed or name another page as canonical are left out (skipped counts them). typical is a page\'s clicks per 28 days here when search shows it. Fix: check the page may be indexed and is linked and in the sitemap, ask the engine to crawl it, or improve or merge it. For Google, google on each row is its URL Inspection (verdict, coverage, last_crawl; null until inspected), sitemaps the property\'s submitted sitemaps and inspections the inspection run (seoproanalytics/inspections has the details).', 'seoproanalytics'),
            'category'            => self::CATEGORY,
            'input_schema'        => array(
                'type'                 => 'object',
                'default'              => array(),
                'additionalProperties' => false,
                'properties'           => array(
                    'kind'   => array(
                        'type'        => 'string',
                        'enum'        => SEOProAnalytics_Indexation::KINDS,
                        'default'     => 'pages',
                        'description' => __('Which list.', 'seoproanalytics'),
                    ),
                    'days'   => array(
                        'type'        => 'integer',
                        'minimum'     => SEOProAnalytics_Indexation::MIN_DAYS,
                        'maximum'     => SEOProAnalytics_Indexation::MAX_DAYS,
                        'default'     => SEOProAnalytics_Indexation::DAYS,
                        'description' => __('Days without search impressions, and since publishing or first listing.', 'seoproanalytics'),
                    ),
                    'engine' => $engine,
                    'limit'  => array(
                        'type'    => 'integer',
                        'minimum' => 1,
                        'maximum' => SEOProAnalytics_Indexation::MAX_LIMIT,
                        'default' => 25,
                    ),
                    'offset' => array(
                        'type'    => 'integer',
                        'minimum' => 0,
                        'default' => 0,
                    ),
                    'data'   => $data,
                ),
            ),
            'output_schema'       => array(
                'type'       => 'object',
                'properties' => array(
                    'engine'    => array('type' => 'string'),
                    'range'     => array('type' => 'object'),
                    'through'   => array('type' => 'string'),
                    'connected' => array('type' => 'boolean'),
                    'kind'      => array('type' => 'string'),
                    'days'      => array('type' => 'integer'),
                    'rules'     => array('type' => 'object'),
                    'read'      => array('type' => 'object'),
                    'typical'   => array('type' => 'number'),
                    'skipped'   => array('type' => 'object'),
                    'counts'    => array('type' => 'object'),
                    'rows'      => array(
                        'type'  => 'array',
                        'items' => array('type' => 'object'),
                    ),
                    'total'     => array('type' => 'integer'),
                ),
            ),
            'execute_callback'    => array(__CLASS__, 'indexation'),
            'permission_callback' => array('SEOProAnalytics_API', 'can_read'),
            'meta'                => array(
                'show_in_rest' => true,
                'annotations'  => array(
                    'readonly'    => true,
                    'destructive' => false,
                    'idempotent'  => true,
                ),
            ),
        ));
        wp_register_ability('seoproanalytics/backlinks', array(
            'label'               => __('Backlinks', 'seoproanalytics'),
            'description'         => __('Pages of other sites that link to the site\'s pages, found without an outside service: once a day the site opens the pages that sent visits (Referral channel; the other site\'s home page when the browser gave only its address) and reads their links to the site, with anchor text and rel (nofollow, sponsored, ugc). Each page is checked again weekly; a link missing on two checks in a row, or on a page that is gone, is lost. Seven lists. links: live links, newest first, each with source (the page linking), host, page (the site\'s page it links to), anchor, rel, first_seen, last_seen, new (first found in the period) and value (0–100: an estimate to rank links by, not a search engine\'s figure; the mean of the linking site\'s and page\'s rank where read, else the export\'s authority, else 10; × 0.3 nofollow or ugc, × 0.1 sponsored; by class × 0 spam, ignore or url_unlinked, × 0.1 private_blog, × 0.25 paid_link, × 0.5 cross_site, link_swap, niche_edit, press_release, profile_page, site_footer or social_media, × 0.75 forum, nap_citation, redirect or wiki, × 1.25 topical, a link with several classes taking its lowest factor below 1 and highest above 1; value_from says rank, authority or unknown; sort=value). needs: pages with search queries at position 4–20 (Search Console or Bing, engine) with fewer useful referring domains (followed, and not ignore, spam, paid_link, private_blog, profile_page, social_media or url_unlinked) than the median of the site\'s own pages in the top three (search.top: pages and median), highest need first: potential (clicks it could gain in the top three), its best queries, domains, followed, useful, top_median, gap (top_median − useful) and need = potential × gap; research link prospects in a browser (resource pages, sites that take articles, list posts, statistics roundups, mentions). impact: pages that gained useful referring domains in the period (newest 200), with new_domains, hosts (the new sites, first found first), the page\'s search clicks, impressions and position per day in the 28 days before the first new link was found and the 28 days from then (before, after), change (% clicks per day), site_change (the whole site\'s, same days), relative (change − site_change, points) and change_id (the first new site\'s backlink_new change: record an experiment from it with seoproanalytics/experiment-record, change: change_id, to test the links against pages left alone); status measured, measuring (fewer than 28 days after so far) or no_data. A change associated with the links, not proof they caused it: content changes, seasons and updates move pages too, and a link can be older than the day it was first found. domains: the sites linking, with links, followed (without nofollow, sponsored or ugc), pages, new and lost in the period, and their visits in the period, most visits first. pages: the site\'s pages linked to, with domains, followed, useful (sites with a useful link), median_dr (the linking sites\' median DR, null when none read), mix (sites with a link of each class: topical, general, press_release, profile_page, social_media, spam) and links, most useful domains first. lost: links lost in the period, with lost. reported: the referring pages link exports named (Settings → Import → Links; Search Console names the linking page, not the page linked to), with state (unchecked, links, none, error or gone), links (live links found on it), reported (the export\'s date) and checked; after an import the check catches up every minute until each was opened. totals counts each (reported, reported_domains, reported_checked); read says how many referring pages were checked, the last run, next (the catch-up\'s next run) and whether the check is on. source keeps only what one source found (gsc, ahrefs, referrer, verified and so on). Links an export did not name, from sites that never sent a visit, are not found. New links (not those an export reported first) and lost links are also changes on the timeline (seoproanalytics/markers).', 'seoproanalytics'),
            'category'            => self::CATEGORY,
            'input_schema'        => array(
                'type'                 => 'object',
                'default'              => array(),
                'additionalProperties' => false,
                'properties'           => array(
                    'kind'   => array(
                        'type'        => 'string',
                        'enum'        => SEOProAnalytics_Backlinks::KINDS,
                        'default'     => 'links',
                        'description' => __('Which list.', 'seoproanalytics'),
                    ),
                    'engine' => array(
                        'type'        => 'string',
                        'enum'        => array_merge(array_keys(SEOProAnalytics_Search::ENGINES), array(SEOProAnalytics_Search::ALL)),
                        'default'     => 'google',
                        'description' => __('needs and impact: the search engine whose data they read (all: Combined).', 'seoproanalytics'),
                    ),
                    'source' => array(
                        'type'        => 'string',
                        'enum'        => array_merge(array(''), array_keys(SEOProAnalytics_Backlinks::FOUND)),
                        'default'     => '',
                        'description' => __('Only what this source found ("" for all).', 'seoproanalytics'),
                    ),
                    'range'  => array(
                        'type'        => 'string',
                        'enum'        => SEOProAnalytics_Query::RANGES,
                        'default'     => '30d',
                        'description' => __('Period for new and lost links and the sites\' visits, in the site time zone.', 'seoproanalytics'),
                    ),
                    'from'   => array(
                        'type'        => 'string',
                        'description' => __('First day of a custom range (YYYY-MM-DD).', 'seoproanalytics'),
                    ),
                    'to'     => array(
                        'type'        => 'string',
                        'description' => __('Last day of a custom range (YYYY-MM-DD).', 'seoproanalytics'),
                    ),
                    'limit'  => array(
                        'type'    => 'integer',
                        'minimum' => 1,
                        'maximum' => SEOProAnalytics_Backlinks::MAX_LIMIT,
                        'default' => 25,
                    ),
                    'offset' => array(
                        'type'    => 'integer',
                        'minimum' => 0,
                        'default' => 0,
                    ),
                    'data'   => $data,
                ) + SEOProAnalytics_API::backlink_triage_args(),
            ),
            'output_schema'       => array(
                'type'       => 'object',
                'properties' => array(
                    'range'  => array('type' => 'object'),
                    'totals' => array('type' => 'object'),
                    'read'   => array('type' => 'object'),
                    'rules'  => array('type' => 'object'),
                    'facets' => array('type' => 'object'),
                    'kind'   => array('type' => 'string'),
                    'rows'   => array(
                        'type'  => 'array',
                        'items' => array('type' => 'object'),
                    ),
                    'total'  => array('type' => 'integer'),
                    'more'   => array('type' => 'boolean'),
                ),
            ),
            'execute_callback'    => array(__CLASS__, 'backlinks'),
            'permission_callback' => array('SEOProAnalytics_API', 'can_read'),
            'meta'                => array(
                'show_in_rest' => true,
                'annotations'  => array(
                    'readonly'    => true,
                    'destructive' => false,
                    'idempotent'  => true,
                ),
            ),
        ));
        wp_register_ability('seoproanalytics/backlink-classify', array(
            'label'               => __('Classify backlinks', 'seoproanalytics'),
            /* translators: %s: list of class names */
            'description'         => sprintf(__('Set, add or remove the owner\'s classes, at most 500 at a time, by one of: ids (rows of seoproanalytics/backlinks kind links or lost), pages (rows of kind reported: every link on the page has its classes, found now or later) or sites (example.com or example.com/page/: put on or taken off the class lists of Settings → Backlinks, so every link from them has the classes, found now or later; rows of kind domains have their host). Classes: %s. Then filter the lists with classes (none: rows without a class); rows say in inherited which classes come from their page or a list. The dashboard hides the classes hidden until ticked (Settings → Backlinks → Classes; ignore and spam to start) until asked. Nothing leaves the site.', 'seoproanalytics'), implode(', ', array_keys(SEOProAnalytics_Backlinks_Triage::classes()))),
            'category'            => self::CATEGORY,
            'input_schema'        => array(
                'type'                 => 'object',
                'additionalProperties' => false,
                'properties'           => array(
                    'ids'     => array('type' => 'array', 'items' => array('type' => 'integer', 'minimum' => 1), 'minItems' => 1, 'maxItems' => 500),
                    'pages'   => array('type' => 'array', 'items' => array('type' => 'integer', 'minimum' => 1), 'minItems' => 1, 'maxItems' => 500),
                    'sites'   => array('type' => 'array', 'items' => array('type' => 'string', 'minLength' => 3, 'maxLength' => 2000), 'minItems' => 1, 'maxItems' => 500),
                    'classes' => array('type' => 'array', 'items' => array('type' => 'string', 'enum' => array_keys(SEOProAnalytics_Backlinks_Triage::classes())), 'default' => array()),
                    'mode'    => array('type' => 'string', 'enum' => array('set', 'add', 'remove'), 'default' => 'set'),
                    'data'    => $data,
                ),
            ),
            'output_schema'       => array('type' => 'object'),
            'execute_callback'    => array(__CLASS__, 'backlink_classify'),
            'permission_callback' => array('SEOProAnalytics_API', 'can_manage'),
            'meta'                => array('show_in_rest' => true, 'annotations' => array('readonly' => false, 'destructive' => false, 'idempotent' => true)),
        ));
        wp_register_ability('seoproanalytics/backlink-review', array(
            'label' => __('Backlink review', 'seoproanalytics'),
            'description' => __('Review explained spam signals, save local keep/disavow/undecided decisions, merge a prior list or generate Google disavow text. Nothing is submitted; most sites never need disavow. Use a URL-prefix property and remember an upload replaces the old list.', 'seoproanalytics'),
            'category' => self::CATEGORY,
            'input_schema' => array(
                'type' => 'object', 'default' => array(), 'additionalProperties' => false,
                'properties' => array(
                    'action' => array('type' => 'string', 'enum' => array('list', 'decide', 'merge', 'export'), 'default' => 'list'),
                    'scope' => array('type' => 'string', 'enum' => array('domain', 'url')),
                    'target' => array('type' => 'string'),
                    'decision' => array('type' => 'string', 'enum' => array('keep', 'disavow', 'undecided')),
                    'text' => array('type' => 'string', 'maxLength' => 2097152),
                    'limit' => array('type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 25),
                    'offset' => array('type' => 'integer', 'minimum' => 0, 'default' => 0),
                    'data' => $data,
                ),
            ),
            'output_schema' => array('type' => 'object'),
            'execute_callback' => array(__CLASS__, 'backlink_review'),
            'permission_callback' => array('SEOProAnalytics_API', 'can_change'),
            'meta' => array('show_in_rest' => true, 'annotations' => array('readonly' => false, 'destructive' => false, 'idempotent' => false)),
        ));
        wp_register_ability('seoproanalytics/inspections', array(
            'label'               => __('Google URL Inspection', 'seoproanalytics'),
            'description'         => __('How Google indexed the site\'s pages, from Search Console\'s URL Inspection (the version in Google\'s index, not a live test), newest first. The SEO Pro Analytics Connect add-on\'s Search Console connection reads them; the demo data has them too. Each row: page, verdict (PASS, PARTIAL, FAIL, NEUTRAL), coverage (Google\'s reason in its words, such as "Crawled - currently not indexed"), indexing, robots, page_fetch, crawled_as, last_crawl, google_canonical and user_canonical, rich_verdict and rich (rich result types with their errors, warnings and issues), sitemaps and referring (addresses Google knows it from), link (the report in Search Console) and findings: robots_blocked, not_indexed, google_canonical (Google chose another canonical than the page\'s own), rich_errors. counts gives pages per verdict; progress the daily cap, today\'s use and the last error; sitemaps the property\'s submitted sitemaps with errors, warnings, last downloaded and problems (errors, stale, warnings), and submitted (false when the site\'s own sitemap index is not submitted). A verdict change is also a change on the timeline (index_status, seoproanalytics/markers).', 'seoproanalytics'),
            'category'            => self::CATEGORY,
            'input_schema'        => array(
                'type'                 => 'object',
                'default'              => array(),
                'additionalProperties' => false,
                'properties'           => array(
                    'page'     => array(
                        'type'        => 'string',
                        'description' => __('Only this page (a path such as /pricing/).', 'seoproanalytics'),
                    ),
                    'verdict'  => array(
                        'type'        => 'string',
                        'enum'        => array_merge(array(''), array_values(SEOProAnalytics_Inspections::VERDICTS)),
                        'default'     => '',
                        'description' => __('Only pages with this verdict.', 'seoproanalytics'),
                    ),
                    'coverage' => array(
                        'type'        => 'string',
                        'default'     => '',
                        'description' => __('Only pages with this coverage state, in Google\'s words.', 'seoproanalytics'),
                    ),
                    'finding'  => array(
                        'type'        => 'string',
                        'enum'        => array_merge(array(''), array_keys(SEOProAnalytics_Inspections::FLAGS)),
                        'default'     => '',
                        'description' => __('Only pages with this finding.', 'seoproanalytics'),
                    ),
                    'limit'    => array(
                        'type'    => 'integer',
                        'minimum' => 1,
                        'maximum' => SEOProAnalytics_Inspections::MAX_LIMIT,
                        'default' => 25,
                    ),
                    'offset'   => array(
                        'type'    => 'integer',
                        'minimum' => 0,
                        'default' => 0,
                    ),
                    'data'     => $data,
                ),
            ),
            'output_schema'       => array(
                'type'       => 'object',
                'properties' => array(
                    'connected' => array('type' => 'boolean'),
                    'progress'  => array('type' => 'object'),
                    'rules'     => array('type' => 'object'),
                    'sitemaps'  => array('type' => 'object'),
                    'inspected' => array('type' => 'integer'),
                    'counts'    => array('type' => 'object'),
                    'rows'      => array(
                        'type'  => 'array',
                        'items' => array('type' => 'object'),
                    ),
                    'total'     => array('type' => 'integer'),
                    'more'      => array('type' => 'boolean'),
                ),
            ),
            'execute_callback'    => array(__CLASS__, 'inspections'),
            'permission_callback' => array('SEOProAnalytics_API', 'can_read'),
            'meta'                => array(
                'show_in_rest' => true,
                'annotations'  => array(
                    'readonly'    => true,
                    'destructive' => false,
                    'idempotent'  => true,
                ),
            ),
        ));
        wp_register_ability('seoproanalytics/crawl-issues', array(
            'label'               => __('Bing crawl issues', 'seoproanalytics'),
            'description'         => __('How Bing crawls the site, from Bing Webmaster Tools, read once a day while it is connected. rows: addresses with a crawl issue, errors first then most inbound links, each with url, path (on the site, else empty), http (Bing\'s status code; 0 for robots.txt or DNS), issues and error (true unless the only issues are redirects). Issues: redirect_301, redirect_302, client_error (4xx), server_error (5xx), robots_blocked, malware, important_blocked (an important address blocked by robots.txt), dns, timeout. counts gives addresses per issue and errors. Errors on published pages are also the content audit\'s bing_crawl finding (seoproanalytics/audit) and so Plan items. stats: Bing\'s crawl by day (crawled, errors, in_index, inlinks and status code counts). sitemaps: the sitemaps Bing has, with status, last crawled and addresses. quota: the URL submission quota (a day and a month). links: the weekly read of Bing\'s inbound links into Backlinks (seoproanalytics/backlinks with source bing). Fix a client or server error by making the address answer (or redirecting it), and robots_blocked by allowing Bingbot, then ask Bing to crawl it again.', 'seoproanalytics'),
            'category'            => self::CATEGORY,
            'input_schema'        => array(
                'type'                 => 'object',
                'default'              => array(),
                'additionalProperties' => false,
                'properties'           => array(
                    'issue'  => array(
                        'type'        => 'string',
                        'enum'        => array_merge(array('', 'errors'), array_keys(SEOProAnalytics_Bing_Site_Data::ISSUES)),
                        'default'     => '',
                        'description' => __('Only addresses with this issue, or errors for every issue but redirects.', 'seoproanalytics'),
                    ),
                    'limit'  => array(
                        'type'    => 'integer',
                        'minimum' => 1,
                        'maximum' => SEOProAnalytics_Bing_Site_Data::MAX_LIMIT,
                        'default' => 25,
                    ),
                    'offset' => array(
                        'type'    => 'integer',
                        'minimum' => 0,
                        'default' => 0,
                    ),
                    'data'   => $data,
                ),
            ),
            'output_schema'       => array(
                'type'       => 'object',
                'properties' => array(
                    'connected' => array('type' => 'boolean'),
                    'read'      => array('type' => array('string', 'null')),
                    'error'     => array('type' => array('string', 'null')),
                    'counts'    => array('type' => 'object'),
                    'rows'      => array(
                        'type'  => 'array',
                        'items' => array('type' => 'object'),
                    ),
                    'total'     => array('type' => 'integer'),
                    'more'      => array('type' => 'boolean'),
                    'stats'     => array('type' => 'array'),
                    'sitemaps'  => array('type' => 'array'),
                    'quota'     => array('type' => array('object', 'null')),
                    'links'     => array('type' => 'object'),
                ),
            ),
            'execute_callback'    => array(__CLASS__, 'crawl_issues'),
            'permission_callback' => array('SEOProAnalytics_API', 'can_read'),
            'meta'                => array(
                'show_in_rest' => true,
                'annotations'  => array(
                    'readonly'    => true,
                    'destructive' => false,
                    'idempotent'  => true,
                ),
            ),
        ));
        wp_register_ability('seoproanalytics/crawl', array(
            'label'               => __('Imported crawl', 'seoproanalytics'),
            'description'         => __('What a crawl of the site made elsewhere saw (imported with seoproanalytics/crawl-import, the REST route /crawl/import or wp seoproanalytics crawl import; the plugin never crawls): what each page served once the theme, plugins, caching and the host had their say. kind pages (the default): pages with a problem, errors first then most failing links, each with path, status (0 when there was no answer), answer (ok, redirect, error, none), prev_status (the import before), hops and redirect, flags as served (noindex, nofollow, canonical: elsewhere, title_missing), findings (crawl_error, served_noindex and served_canonical when WordPress does not set them, broken_links, redirect_links), broken and redirects (its links that fail or redirect), depth, response_ms and bytes. kind links: links that fail or redirect, with from, target, internal, status, hops, placement (navigation, footer, body, image, sidebar, other) and anchor. kind clicked: outbound and affiliate destinations clicked in the range whose crawl answer failed, most clicks first, with the pages they were clicked on and the pages that link to them: lost clicks and revenue to fix first. counts and findings give pages and links per answer and finding. Findings are also content audit findings (seoproanalytics/audit) and Plan items weighted by traffic and conversions (seoproanalytics/queue); a page whose answer changed between imports is a served change on the timeline (seoproanalytics/markers).', 'seoproanalytics'),
            'category'            => self::CATEGORY,
            'input_schema'        => array(
                'type'                 => 'object',
                'default'              => array(),
                'additionalProperties' => false,
                'properties'           => array(
                    'kind'    => array(
                        'type'    => 'string',
                        'enum'    => array_merge(array(''), SEOProAnalytics_Crawl::KINDS),
                        'default' => '',
                    ),
                    'status'  => array(
                        'type'        => 'string',
                        'enum'        => array_merge(array(''), SEOProAnalytics_Crawl::STATUSES),
                        'default'     => '',
                        'description' => __('errors or redirects; links also blocked: external 401, 403, 429 or 999 means the linked site refused the crawler; check by hand. Blocked links are excluded from broken_links, clicked failures and Plan items. Pages also accept noindex, canonical or title_missing as served; internal errors still fail.', 'seoproanalytics'),
                    ),
                    'finding' => array(
                        'type'    => 'string',
                        'enum'    => array_merge(array(''), array_keys(SEOProAnalytics_Crawl::FINDINGS)),
                        'default' => '',
                        'description' => __('Includes slow: pages with visits, confirmed crawl timing or large HTML, with fresh field TTFB/LCP taking precedence. Sorted by conversions with a goal, otherwise visits. Includes served_title and served_description: observed text differs from WordPress.', 'seoproanalytics'),
                    ),
                    'goal' => array('type' => 'string', 'default' => ''),
                    'engine' => array('type' => 'string', 'default' => 'google'),
                    'range'   => array(
                        'type'        => 'string',
                        'default'     => '30d',
                        'description' => __('clicked: the clicks\' range.', 'seoproanalytics'),
                    ),
                    'limit'   => array(
                        'type'    => 'integer',
                        'minimum' => 1,
                        'maximum' => SEOProAnalytics_Crawl::MAX_LIMIT,
                        'default' => 25,
                    ),
                    'offset'  => array(
                        'type'    => 'integer',
                        'minimum' => 0,
                        'default' => 0,
                    ),
                    'data'    => $data,
                ),
            ),
            'output_schema'       => array(
                'type'       => 'object',
                'properties' => array(
                    'kind'     => array('type' => 'string'),
                    'import'   => array('type' => array('integer', 'null')),
                    'format'   => array('type' => array('string', 'null')),
                    'read'     => array('type' => array('string', 'null')),
                    'counts'   => array('type' => 'object'),
                    'findings' => array('type' => 'object'),
                    'rows'     => array(
                        'type'  => 'array',
                        'items' => array('type' => 'object'),
                    ),
                    'total'    => array('type' => 'integer'),
                    'more'     => array('type' => 'boolean'),
                ),
            ),
            'execute_callback'    => array(__CLASS__, 'crawl'),
            'permission_callback' => array('SEOProAnalytics_API', 'can_read'),
            'meta'                => array(
                'show_in_rest' => true,
                'annotations'  => array(
                    'readonly'    => true,
                    'destructive' => false,
                    'idempotent'  => true,
                ),
            ),
        ));
        wp_register_ability('seoproanalytics/crawl-import', array(
            'label'               => __('Import or undo a crawl', 'seoproanalytics'),
            'description'         => __('Import one crawl of the site made elsewhere: files, a list of {name, rows} (up to 10: a pages export and a links export, as LibreCrawl, the aidevops site crawler or any crawler writes them; pages need an address and status, links a source and target), or text (one CSV or JSON export). format is librecrawl, aidevops or csv, told by the columns when left out. The rows are staged and written in the background; the answer is the job (status running, done or error; done, total, pages, links, skipped, changes). Call again with no input for the job\'s progress, which moves it on a few seconds too. The import replaces the earlier crawl\'s rows; undo (an import ID from imports) deletes an import\'s rows. Live data only; settings permission.', 'seoproanalytics'),
            'category'            => self::CATEGORY,
            'input_schema'        => array(
                'type'                 => 'object',
                'default'              => array(),
                'additionalProperties' => false,
                'properties'           => array(
                    'files'   => array(
                        'type'     => 'array',
                        'maxItems' => SEOProAnalytics_Crawl_Import::MAX_FILES,
                        'items'    => array(
                            'type'       => 'object',
                            'properties' => array(
                                'name' => array('type' => 'string'),
                                'rows' => array('type' => 'array', 'items' => array('type' => 'object')),
                            ),
                        ),
                    ),
                    'text'    => array(
                        'type'      => 'string',
                        'maxLength' => SEOProAnalytics_Crawl_Import::MAX_BYTES,
                    ),
                    'name'    => array(
                        'type'        => 'string',
                        'description' => __('With text: the file name kept in the import history. Pages and links are told by the columns.', 'seoproanalytics'),
                    ),
                    'format'  => array(
                        'type'    => 'string',
                        'enum'    => array_merge(array(''), SEOProAnalytics_Crawl_Import::FORMATS),
                        'default' => '',
                    ),
                    'imports' => array(
                        'type'        => 'boolean',
                        'default'     => false,
                        'description' => __('List the crawl imports instead.', 'seoproanalytics'),
                    ),
                    'undo'    => array(
                        'type'        => 'integer',
                        'minimum'     => 1,
                        'description' => __('Delete this import\'s rows instead.', 'seoproanalytics'),
                    ),
                    'complete' => array('type' => 'boolean', 'default' => false, 'description' => __('The owner confirms the uploaded crawl covered the whole site without reaching its page limit.', 'seoproanalytics')),
                    'partial' => array('type' => 'boolean', 'default' => false, 'description' => __('Replace only covered pages (at most 100); keep other crawl findings.', 'seoproanalytics')),
                    'record' => array('type' => 'object', 'description' => __('Optional LibreCrawl record: status, urls_crawled and config_snapshot.max_urls establish completeness instead of confirmation.', 'seoproanalytics')),
                ),
            ),
            'output_schema'       => array('type' => 'object'),
            'execute_callback'    => array(__CLASS__, 'crawl_import'),
            'permission_callback' => array('SEOProAnalytics_API', 'can_change'),
            'meta'                => array(
                'show_in_rest' => true,
                'annotations'  => array(
                    'readonly'    => false,
                    'destructive' => true,
                    'idempotent'  => false,
                ),
            ),
        ));
        wp_register_ability('seoproanalytics/coverage', array(
            'label'               => __('Query coverage of a page', 'seoproanalytics'),
            'description'         => __('The Google Search Console queries one page shows for (most impressions first, up to 200), each with how far the page\'s own words cover it: title (every word in the title or SEO title), heading, text, partial or none, the words missing, and whether it is a question. Also the focus keywords of Rank Math, Yoast SEO, SEOPress or All in One SEO when one is active, with their search figures. Queries the page does not cover are cheap wins: add the words, or answer the question in a heading. Works without any SEO plugin. Final days only; at most the newest 366 days of the period are read.', 'seoproanalytics'),
            'category'            => self::CATEGORY,
            'input_schema'        => array(
                'type'                 => 'object',
                'additionalProperties' => false,
                'properties'           => array(
                    'page'  => array(
                        'type'        => 'string',
                        'description' => __('The page (a path such as /pricing/); or give post.', 'seoproanalytics'),
                    ),
                    'post'  => array(
                        'type'        => 'integer',
                        'minimum'     => 1,
                        'description' => __('The post whose address is the page; or give page.', 'seoproanalytics'),
                    ),
                    'range' => array(
                        'type'        => 'string',
                        'enum'        => SEOProAnalytics_Query::RANGES,
                        'default'     => '90d',
                        'description' => __('Period, in the site time zone.', 'seoproanalytics'),
                    ),
                    'from'  => array(
                        'type'        => 'string',
                        'description' => __('First day of a custom range (YYYY-MM-DD).', 'seoproanalytics'),
                    ),
                    'to'    => array(
                        'type'        => 'string',
                        'description' => __('Last day of a custom range (YYYY-MM-DD).', 'seoproanalytics'),
                    ),
                    'data'  => $data,
                ),
            ),
            'output_schema'       => array(
                'type'       => 'object',
                'properties' => array(
                    'page'      => array('type' => 'string'),
                    'range'     => array('type' => 'object'),
                    'through'   => array('type' => 'string'),
                    'connected' => array('type' => 'boolean'),
                    'text'      => array('type' => array('object', 'null')),
                    'focus'     => array(
                        'type'  => 'array',
                        'items' => array('type' => 'object'),
                    ),
                    'totals'    => array('type' => 'object'),
                    'rows'      => array(
                        'type'  => 'array',
                        'items' => array('type' => 'object'),
                    ),
                ),
            ),
            'execute_callback'    => array(__CLASS__, 'coverage'),
            'permission_callback' => array('SEOProAnalytics_API', 'can_read'),
            'meta'                => array(
                'show_in_rest' => true,
                'annotations'  => array(
                    'readonly'    => true,
                    'destructive' => false,
                    'idempotent'  => true,
                ),
            ),
        ));
        wp_register_ability('seoproanalytics/content', array(
            'label'               => __('Content performance', 'seoproanalytics'),
            'description'         => __('Which pages earn their search traffic: per page, Google Search Console (or Bing Webmaster Tools with engine bing, or every engine added up with engine all) clicks, impressions, CTR and position, with the visits from search that landed on the page (bounce rate, views per visit, visit duration in seconds) and how many reached a goal (conversions, conversion rate; the first goal unless one is named). A page that ranks but whose visits leave or never convert needs better content or a clearer next step; one that converts but gets few clicks is worth ranking higher. Final search days only.', 'seoproanalytics'),
            'category'            => self::CATEGORY,
            'input_schema'        => array(
                'type'                 => 'object',
                'default'              => array(),
                'additionalProperties' => false,
                'properties'           => array(
                    'sort'    => array(
                        'type'        => 'string',
                        'enum'        => array_merge(array(''), SEOProAnalytics_Content::SORTS),
                        'default'     => '',
                        'description' => __('Order of the pages: impressions (the default), clicks, ctr, position, visits (from search), bounce_rate, visit_duration, or conversions and conversion_rate (with a goal).', 'seoproanalytics'),
                    ),
                    'order'   => $order,
                    'goal'    => array(
                        'type'        => 'string',
                        'description' => __('ID of the goal counted (the answer lists the goals); the first when left out.', 'seoproanalytics'),
                    ),
                    'engine'  => $combined,
                    'range'   => array(
                        'type'        => 'string',
                        'enum'        => SEOProAnalytics_Query::RANGES,
                        'default'     => '30d',
                        'description' => __('Period, in the site time zone.', 'seoproanalytics'),
                    ),
                    'from'    => array(
                        'type'        => 'string',
                        'description' => __('First day of a custom range (YYYY-MM-DD).', 'seoproanalytics'),
                    ),
                    'to'      => array(
                        'type'        => 'string',
                        'description' => __('Last day of a custom range (YYYY-MM-DD).', 'seoproanalytics'),
                    ),
                    'compare' => array(
                        'type'        => 'string',
                        'enum'        => SEOProAnalytics_Query::COMPARE,
                        'default'     => 'none',
                        'description' => __('Also an earlier period (as for stats: prev, week, month_weekday, month, year_weekday or year), with the change.', 'seoproanalytics'),
                    ),
                    'limit'   => array(
                        'type'    => 'integer',
                        'minimum' => 1,
                        'maximum' => SEOProAnalytics_Query::MAX_LIMIT,
                        'default' => 25,
                    ),
                    'owner'   => self::owner_property(),
                    'data'    => $data,
                ),
            ),
            'output_schema'       => array(
                'type'       => 'object',
                'properties' => array(
                    'engine'        => array('type' => 'string'),
                    'range'         => array('type' => 'object'),
                    'through'       => array('type' => 'string'),
                    'connected'     => array('type' => 'boolean'),
                    'goal'          => array('type' => array('object', 'null')),
                    'goals'         => array(
                        'type'  => 'array',
                        'items' => array('type' => 'object'),
                    ),
                    'partial'       => array('type' => 'boolean'),
                    'landings_from' => array('type' => 'string'),
                    'totals'        => array('type' => 'object'),
                    'rows'          => array(
                        'type'  => 'array',
                        'items' => array('type' => 'object'),
                    ),
                    'total'         => array('type' => 'integer'),
                ),
            ),
            'execute_callback'    => array(__CLASS__, 'content'),
            'permission_callback' => array('SEOProAnalytics_API', 'can_read'),
            'meta'                => array(
                'show_in_rest' => true,
                'annotations'  => array(
                    'readonly'    => true,
                    'destructive' => false,
                    'idempotent'  => true,
                ),
            ),
        ));
        self::register_experiments($data, $engine);
        self::register_queue($data, $engine);
        self::register_targets($data, $engine);
        self::register_owners($data, $engine);
        self::register_loop($data, $engine);
    }

    /**
     * The loop export ability.
     *
     * @param array<string,mixed> $data   The data property.
     * @param array<string,mixed> $engine The engine property.
     */
    private static function register_loop(array $data, array $engine) {
        require_once SEOPROANALYTICS_DIR . 'includes/stats/class-seoproanalytics-loop.php';
        wp_register_ability('seoproanalytics/loop', array(
            'label'               => __('SEO loop export', 'seoproanalytics'),
            'description'         => __('One answer per cycle of the SEO decision loop: summary (counts of open queue items, experiments due, running and recently decided, export rows); queue (the open items of seoproanalytics/queue, best first, each with its key, why, figures and score parts); experiments (due: running with search data through the review day, decide them with seoproanalytics/experiment-record; running; decided: in the last 90 days with their results, null and negative ones included, to weigh the next plan); export (the period\'s search figures per query and page in the aidevops export layout: query, page, clicks, impressions, ctr, position, most impressions first, with domain, source, start_date and end_date). params gives the period, engine and goal used: pass the same to seoproanalytics/queue-update. Read-only; nothing changes content.', 'seoproanalytics'),
            'category'            => self::CATEGORY,
            'input_schema'        => array(
                'type'                 => 'object',
                'default'              => array(),
                'additionalProperties' => false,
                'properties'           => array(
                    'engine' => $engine,
                    'range'  => array(
                        'type'        => 'string',
                        'enum'        => SEOProAnalytics_Query::RANGES,
                        'default'     => SEOProAnalytics_Loop::RANGE,
                        'description' => __('The period, in the site time zone (at most its newest 366 days are read).', 'seoproanalytics'),
                    ),
                    'from'   => array(
                        'type'        => 'string',
                        'description' => __('First day of a custom range (YYYY-MM-DD).', 'seoproanalytics'),
                    ),
                    'to'     => array(
                        'type'        => 'string',
                        'description' => __('Last day of a custom range (YYYY-MM-DD).', 'seoproanalytics'),
                    ),
                    'goal'   => array(
                        'type'        => 'string',
                        'description' => __('The goal whose conversions give a page its value in the queue; the first goal when left out.', 'seoproanalytics'),
                    ),
                    'limit'  => array(
                        'type'        => 'integer',
                        'minimum'     => 1,
                        'maximum'     => SEOProAnalytics_Loop::MAX_ITEMS,
                        'default'     => SEOProAnalytics_Loop::ITEMS,
                        'description' => __('Most queue items.', 'seoproanalytics'),
                    ),
                    'rows'   => array(
                        'type'        => 'integer',
                        'minimum'     => 1,
                        'maximum'     => SEOProAnalytics_Loop::MAX_ROWS,
                        'default'     => SEOProAnalytics_Loop::ROWS,
                        'description' => __('Most export rows.', 'seoproanalytics'),
                    ),
                    'data'   => $data,
                ),
            ),
            'output_schema'       => array(
                'type'       => 'object',
                'properties' => array(
                    'loop'        => array('type' => 'integer'),
                    'engine'      => array('type' => 'string'),
                    'range'       => array('type' => 'object'),
                    'through'     => array('type' => 'string'),
                    'connected'   => array('type' => 'boolean'),
                    'params'      => array('type' => 'object'),
                    'summary'     => array('type' => 'object'),
                    'queue'       => array('type' => 'object'),
                    'experiments' => array('type' => 'object'),
                    'export'      => array('type' => 'object'),
                ),
            ),
            'execute_callback'    => array(__CLASS__, 'loop'),
            'permission_callback' => array('SEOProAnalytics_API', 'can_read'),
            'meta'                => array(
                'show_in_rest' => true,
                'annotations'  => array(
                    'readonly'    => true,
                    'destructive' => false,
                    'idempotent'  => true,
                ),
            ),
        ));
    }

    /**
     * seoproanalytics/loop.
     *
     * @param array<string,mixed>|null $input Input.
     * @return array<string,mixed>|WP_Error
     */
    public static function loop($input = null) {
        $input = is_array($input) ? $input : array();
        $req   = SEOProAnalytics_Query::request(array_diff_key($input, array('rows' => true)) + array('range' => SEOProAnalytics_Loop::RANGE, 'limit' => SEOProAnalytics_Loop::ITEMS));
        if (is_wp_error($req)) {
            return $req;
        }
        $engine = isset($input['engine']) ? (string) $input['engine'] : 'google';
        $goal   = isset($input['goal']) ? (string) $input['goal'] : '';
        $rows   = isset($input['rows']) ? (int) $input['rows'] : SEOProAnalytics_Loop::ROWS;
        return SEOProAnalytics_API::on_data(self::data($input), static function () use ($req, $engine, $goal, $rows) {
            return SEOProAnalytics_Loop::report((array) $req, $engine, $goal, $rows);
        });
    }

    /**
     * The search target abilities.
     *
     * @param array<string,mixed> $data   The data property.
     * @param array<string,mixed> $engine The engine property.
     */
    private static function register_targets(array $data, array $engine) {
        wp_register_ability('seoproanalytics/targets', array(
            'label'               => __('Search targets', 'seoproanalytics'),
            'description'         => __('The searches the site chose to win and the page meant for each (imported with seoproanalytics/targets-import), with how search treats them now: the query\'s clicks, impressions, CTR and position on any page, the page search shows most for it (shown) and the page meant for it (page) with its own figures, as a state: ranking (the page meant for it is the one shown most), wrong_page (another page is), no_page (none chosen yet) or not_shown (no impressions in the period). band is top (positions 1–3), striking (4–20) or beyond. Highest priority first. The decision queue lists open targets shown with the wrong page, and high-priority targets in striking distance (kind target). Final days only; at most the newest 366 days of the period are read.', 'seoproanalytics'),
            'category'            => self::CATEGORY,
            'input_schema'        => array(
                'type'                 => 'object',
                'default'              => array(),
                'additionalProperties' => false,
                'properties'           => array(
                    'status'  => array(
                        'type'        => 'string',
                        'enum'        => SEOProAnalytics_Targets::FILTERS,
                        'default'     => 'all',
                        'description' => __('all, open (candidate, targeted and live), candidate, targeted, live, won or retired.', 'seoproanalytics'),
                    ),
                    'range'   => array(
                        'type'        => 'string',
                        'enum'        => SEOProAnalytics_Query::RANGES,
                        'default'     => '30d',
                        'description' => __('Period, in the site time zone.', 'seoproanalytics'),
                    ),
                    'from'    => array(
                        'type'        => 'string',
                        'description' => __('First day of a custom range (YYYY-MM-DD).', 'seoproanalytics'),
                    ),
                    'to'      => array(
                        'type'        => 'string',
                        'description' => __('Last day of a custom range (YYYY-MM-DD).', 'seoproanalytics'),
                    ),
                    'compare' => array(
                        'type'        => 'string',
                        'enum'        => SEOProAnalytics_Query::COMPARE,
                        'default'     => 'none',
                        'description' => __('Any comparison but none (as for stats) adds each target\'s position and clicks then.', 'seoproanalytics'),
                    ),
                    'engine'  => $engine,
                    'limit'   => array(
                        'type'    => 'integer',
                        'minimum' => 1,
                        'maximum' => SEOProAnalytics_Targets::MAX_LIMIT,
                        'default' => SEOProAnalytics_Targets::LIMIT,
                    ),
                    'offset'  => array(
                        'type'    => 'integer',
                        'minimum' => 0,
                        'default' => 0,
                    ),
                    'owner'   => self::owner_property(),
                    'data'    => $data,
                ),
            ),
            'output_schema'       => array(
                'type'       => 'object',
                'properties' => array(
                    'engine'    => array('type' => 'string'),
                    'range'     => array('type' => 'object'),
                    'through'   => array('type' => 'string'),
                    'connected' => array('type' => 'boolean'),
                    'rules'     => array('type' => 'object'),
                    'statuses'  => array('type' => 'object'),
                    'counts'    => array('type' => 'object'),
                    'rows'      => array(
                        'type'  => 'array',
                        'items' => array('type' => 'object'),
                    ),
                    'total'     => array('type' => 'integer'),
                ),
            ),
            'execute_callback'    => array(__CLASS__, 'targets'),
            'permission_callback' => array('SEOProAnalytics_API', 'can_read'),
            'meta'                => array(
                'show_in_rest' => true,
                'annotations'  => array(
                    'readonly'    => true,
                    'destructive' => false,
                    'idempotent'  => true,
                ),
            ),
        ));
        wp_register_ability('seoproanalytics/targets-import', array(
            'label'               => __('Import or delete search targets', 'seoproanalytics'),
            'description'         => __('Add or update search targets by query, from targets (a list of objects: query or phrase; page or target_url, a path such as /pricing/ or an address on this site, left out when no page is chosen yet; priority 0–100 or high, medium, low, 50 when left out; status candidate, targeted, live, won or retired, targeted when left out) or text (CSV or tab-separated with a header row, JSON, or the aidevops search targets table in TOON). Rows without search text, with an address that is not on this site, or with a priority or status that cannot be read are skipped and listed with the reason, never guessed. replace deletes targets not in the import; only_new adds new searches only and leaves listed ones as they are; source search marks targets added from search report rows. from seo-plugin imports the SEO plugin\'s new focus keywords instead (see seoproanalytics/targets-suggest; queries picks some, all_keywords adds each page\'s other focus keywords), as targeted with the page, leaving existing targets and keywords of more than one page alone. delete removes the searches given (all: every target) instead of importing.', 'seoproanalytics'),
            'category'            => self::CATEGORY,
            'input_schema'        => array(
                'type'                 => 'object',
                'additionalProperties' => false,
                'properties'           => array(
                    'targets' => array(
                        'type'     => 'array',
                        'maxItems' => SEOProAnalytics_Targets::MAX_ROWS,
                        'items'    => array('type' => 'object'),
                    ),
                    'text'    => array(
                        'type'      => 'string',
                        'maxLength' => SEOProAnalytics_Targets::MAX_BYTES,
                    ),
                    'replace' => array(
                        'type'    => 'boolean',
                        'default' => false,
                    ),
                    'only_new' => array(
                        'type'    => 'boolean',
                        'default' => false,
                    ),
                    'source'  => array(
                        'type'    => 'string',
                        'enum'    => array('list', 'search'),
                        'default' => 'list',
                    ),
                    'from'    => array(
                        'type'        => 'string',
                        'enum'        => array('seo-plugin'),
                        'description' => __('seo-plugin: import the SEO plugin\'s new focus keywords instead of targets or text.', 'seoproanalytics'),
                    ),
                    'queries' => array(
                        'type'        => 'array',
                        'items'       => array('type' => 'string'),
                        'description' => __('With from: the suggested searches to import; left out for every new one.', 'seoproanalytics'),
                    ),
                    'all_keywords' => array(
                        'type'        => 'boolean',
                        'default'     => false,
                        'description' => __('With from: every focus keyword of a page, not only its main one.', 'seoproanalytics'),
                    ),
                    'delete'  => array(
                        'type'        => 'array',
                        'items'       => array('type' => 'string'),
                        'description' => __('The searches whose targets to delete (instead of importing).', 'seoproanalytics'),
                    ),
                    'all'     => array(
                        'type'        => 'boolean',
                        'default'     => false,
                        'description' => __('With delete: delete every target.', 'seoproanalytics'),
                    ),
                    'data'    => $data,
                ),
            ),
            'output_schema'       => array('type' => 'object'),
            'execute_callback'    => array(__CLASS__, 'targets_import'),
            'permission_callback' => array('SEOProAnalytics_API', 'can_manage'),
            'meta'                => array(
                'show_in_rest' => true,
                'annotations'  => array(
                    'readonly'    => false,
                    'destructive' => true,
                    'idempotent'  => true,
                ),
            ),
        ));
        wp_register_ability('seoproanalytics/targets-suggest', array(
            'label'               => __('Suggest search targets from the SEO plugin', 'seoproanalytics'),
            'description'         => __('The focus keywords Rank Math, Yoast SEO, SEOPress or All in One SEO keeps for published posts (the active one\'s; any when none is active), one row per search with its page or pages and a state: new (the focus keyword of one page, not a target yet), clash (the focus keyword of more than one page: choose its page and import it as a list) or targeted (already a target, with its page and status). The main keyword of each page; all_keywords adds its other focus keywords. At most max_posts posts are read (more says there were more). Import the new ones with seoproanalytics/targets-import, from seo-plugin.', 'seoproanalytics'),
            'category'            => self::CATEGORY,
            'input_schema'        => array(
                'type'                 => 'object',
                'additionalProperties' => false,
                'default'              => array(),
                'properties'           => array(
                    'all_keywords' => array(
                        'type'    => 'boolean',
                        'default' => false,
                    ),
                    'data'         => $data,
                ),
            ),
            'output_schema'       => array(
                'type'       => 'object',
                'properties' => array(
                    'plugin'       => array('type' => 'string'),
                    'all_keywords' => array('type' => 'boolean'),
                    'pages'        => array('type' => 'integer'),
                    'more'         => array('type' => 'boolean'),
                    'max_posts'    => array('type' => 'integer'),
                    'counts'       => array('type' => 'object'),
                    'rows'         => array(
                        'type'  => 'array',
                        'items' => array('type' => 'object'),
                    ),
                ),
            ),
            'execute_callback'    => array(__CLASS__, 'targets_suggest'),
            'permission_callback' => array('SEOProAnalytics_API', 'can_manage'),
            'meta'                => array(
                'show_in_rest' => true,
                'annotations'  => array(
                    'readonly'    => true,
                    'destructive' => false,
                    'idempotent'  => true,
                ),
            ),
        ));
        self::register_migrate();
    }

    /**
     * Abilities to move from another statistics plugin. Removing its
     * leftovers is for people only (the Import tab or WP-CLI).
     */
    private static function register_migrate() {
        $source = array(
            'type'        => 'string',
            'description' => __('The plugin\'s key, such as burst-statistics (as listed in sources).', 'seoproanalytics'),
        );
        $days   = array(
            'source' => $source,
            'from'   => array('type' => 'string', 'description' => __('First day to import (YYYY-MM-DD); default: its first day.', 'seoproanalytics')),
            'to'     => array('type' => 'string', 'description' => __('Last day to import (YYYY-MM-DD); default: its last day.', 'seoproanalytics')),
            'prefer' => array('type' => 'string', 'description' => __('When another plugin not imported yet has statistics on the same days: the one whose counts fill them (it imports first). Default: this one.', 'seoproanalytics')),
        );
        wp_register_ability('seoproanalytics/migrate', array(
            'label'               => __('Statistics plugins to move from', 'seoproanalytics'),
            'description'         => __('Other statistics plugins whose history is on this site (whether active, inactive or deleted with its tables left): their days, the import job and past imports, each with its own counts beside the imported ones (check). With source: the dry run of importing it — the days it would fill (only before SEO Pro Analytics\'s own first day, own_from, and not filled by another import), rows per dimension (estimated), plugins sharing those days (overlap, with a suggested prefer: the one with more pageviews), the settings it would carry over (only ours still at their default) — and leftovers, exactly what the plugin leaves on the site. Writes nothing.', 'seoproanalytics'),
            'category'            => self::CATEGORY,
            'input_schema'        => array(
                'type'                 => 'object',
                'default'              => array(),
                'additionalProperties' => false,
                'properties'           => $days,
            ),
            'output_schema'       => array('type' => 'object'),
            'execute_callback'    => array(__CLASS__, 'migrate'),
            'permission_callback' => array('SEOProAnalytics_API', 'can_change'),
            'meta'                => array(
                'show_in_rest' => true,
                'annotations'  => array(
                    'readonly'    => true,
                    'destructive' => false,
                    'idempotent'  => true,
                ),
            ),
        ));
        wp_register_ability('seoproanalytics/migrate-import', array(
            'label'               => __('Import another statistics plugin\'s history', 'seoproanalytics'),
            'description'         => __('Start importing a plugin\'s history (see seoproanalytics/migrate for its dry run). It runs in the background in batches of days; seoproanalytics/migrate shows its progress, then the import with its check. Each day is filled once, so running it again adds nothing; undo is DELETE /seoproanalytics/v1/imports/{id}.', 'seoproanalytics'),
            'category'            => self::CATEGORY,
            'input_schema'        => array(
                'type'                 => 'object',
                'additionalProperties' => false,
                'required'             => array('source'),
                'properties'           => $days + array(
                    'settings' => array(
                        'type'        => 'array',
                        'items'       => array('type' => 'string'),
                        'description' => __('The settings to carry over, by key (the dry run\'s plan.settings); an empty list carries none. Default: every one the dry run would change.', 'seoproanalytics'),
                    ),
                ),
            ),
            'output_schema'       => array('type' => 'object'),
            'execute_callback'    => array(__CLASS__, 'migrate_import'),
            'permission_callback' => array('SEOProAnalytics_API', 'can_change'),
            'meta'                => array(
                'show_in_rest' => true,
                'annotations'  => array(
                    'readonly'    => false,
                    'destructive' => false,
                    'idempotent'  => true,
                ),
            ),
        ));
    }

    /**
     * seoproanalytics/migrate.
     *
     * @param array<string,mixed>|null $input Input.
     * @return array<string,mixed>|WP_Error
     */
    public static function migrate($input = null) {
        require_once SEOPROANALYTICS_DIR . 'includes/stats/class-seoproanalytics-migrate.php';
        $input  = is_array($input) ? $input : array();
        $status = SEOProAnalytics_Migrate::status();
        $key    = isset($input['source']) ? (string) $input['source'] : '';
        if ($key === '') {
            return $status;
        }
        $plan = SEOProAnalytics_Migrate::plan($key, self::migrate_args($input));
        if (is_wp_error($plan)) {
            return $plan;
        }
        $source = SEOProAnalytics_Migrate::source($key);
        return $status + array('plan' => $plan, 'leftovers' => $source ? $source->leftovers() : array());
    }

    /**
     * seoproanalytics/migrate-import.
     *
     * @param array<string,mixed>|null $input Input.
     * @return array<string,mixed>|WP_Error
     */
    public static function migrate_import($input = null) {
        require_once SEOPROANALYTICS_DIR . 'includes/stats/class-seoproanalytics-migrate.php';
        $input = is_array($input) ? $input : array();
        $args  = self::migrate_args($input);
        if (isset($input['settings']) && is_array($input['settings'])) {
            $args['settings'] = array_map('strval', $input['settings']);
        }
        $job = SEOProAnalytics_Migrate::start(isset($input['source']) ? (string) $input['source'] : '', $args);
        return is_wp_error($job) ? $job : array('job' => $job);
    }

    /**
     * The days and preference of a migrate ability's input.
     *
     * @param array<string,mixed> $input Input.
     * @return array<string,string>
     */
    private static function migrate_args(array $input) {
        $out = array();
        foreach (array('from', 'to', 'prefer') as $key) {
            $out[$key] = isset($input[$key]) ? (string) $input[$key] : '';
        }
        return $out;
    }

    /**
     * seoproanalytics/targets.
     *
     * @param array<string,mixed>|null $input Input.
     * @return array<string,mixed>|WP_Error
     */
    public static function targets($input = null) {
        $input = is_array($input) ? $input : array();
        $req   = SEOProAnalytics_Query::request(array_diff_key($input, array('owner' => true)) + array('range' => '30d', 'limit' => SEOProAnalytics_Targets::LIMIT));
        if (is_wp_error($req)) {
            return $req;
        }
        $owner = self::owner_filter($input);
        if (is_wp_error($owner)) {
            return $owner;
        }
        $engine = isset($input['engine']) ? (string) $input['engine'] : 'google';
        $status = isset($input['status']) ? (string) $input['status'] : 'all';
        return SEOProAnalytics_API::on_data(self::data($input), static function () use ($req, $engine, $status, $owner) {
            return SEOProAnalytics_Targets::report((array) $req, $engine, $status, $owner);
        });
    }

    /**
     * seoproanalytics/targets-import.
     *
     * @param array<string,mixed>|null $input Input.
     * @return array<string,mixed>|WP_Error
     */
    public static function targets_import($input = null) {
        $input = is_array($input) ? $input : array();
        return SEOProAnalytics_API::on_data(self::data($input), static function () use ($input) {
            return self::import_targets($input);
        });
    }

    /**
     * seoproanalytics/targets-import on its data set: delete targets, import
     * the SEO plugin's focus keywords, or import a list.
     *
     * @param array<string,mixed> $input Input.
     * @return array<string,mixed>|WP_Error
     */
    private static function import_targets(array $input) {
        if (isset($input['delete']) || !empty($input['all'])) {
            return SEOProAnalytics_Targets::delete(array_map('strval', isset($input['delete']) ? (array) $input['delete'] : array()), !empty($input['all']));
        }
        if (isset($input['from']) && $input['from'] === 'seo-plugin') {
            return SEOProAnalytics_Target_Sources::import(array_map('strval', isset($input['queries']) ? (array) $input['queries'] : array()), !empty($input['all_keywords']));
        }
        return self::import_target_list($input);
    }

    /**
     * Import the targets given, or the list text parsed (list or TOON).
     *
     * @param array<string,mixed> $input Input.
     * @return array<string,mixed>|WP_Error
     */
    private static function import_target_list(array $input) {
        $format = 'list';
        $rows   = isset($input['targets']) ? (array) $input['targets'] : array();
        if (!$rows) {
            $parsed = SEOProAnalytics_Targets::parse(isset($input['text']) ? (string) $input['text'] : '');
            if (is_wp_error($parsed)) {
                return $parsed;
            }
            $rows   = $parsed['rows'];
            $format = $parsed['format'];
        }
        $source = isset($input['source']) && $input['source'] === 'search' ? 'search' : 'list';
        $done   = SEOProAnalytics_Targets::import($rows, $format === 'toon' ? 'aidevops' : $source, !empty($input['replace']), !empty($input['only_new']));
        return is_wp_error($done) ? $done : array('format' => $format) + $done;
    }

    /**
     * seoproanalytics/targets-suggest.
     *
     * @param array<string,mixed>|null $input Input.
     * @return array<string,mixed>|WP_Error
     */
    public static function targets_suggest($input = null) {
        $input = is_array($input) ? $input : array();
        return SEOProAnalytics_API::on_data(self::data($input), static function () use ($input) {
            return SEOProAnalytics_Target_Sources::suggestions(!empty($input['all_keywords']));
        });
    }

    /**
     * The decision queue abilities.
     *
     * @param array<string,mixed> $data   The data property.
     * @param array<string,mixed> $engine The engine property.
     */
    private static function register_queue(array $data, array $engine) {
        $period = array(
            'engine' => $engine,
            'range'  => array(
                'type'        => 'string',
                'enum'        => SEOProAnalytics_Query::RANGES,
                'default'     => '90d',
                'description' => __('The period the list is made from, in the site time zone (at most its newest 366 days are read).', 'seoproanalytics'),
            ),
            'from'   => array(
                'type'        => 'string',
                'description' => __('First day of a custom range (YYYY-MM-DD).', 'seoproanalytics'),
            ),
            'to'     => array(
                'type'        => 'string',
                'description' => __('Last day of a custom range (YYYY-MM-DD).', 'seoproanalytics'),
            ),
            'goal'   => array(
                'type'        => 'string',
                'description' => __('The goal whose conversions give a page its value; the first goal when left out.', 'seoproanalytics'),
            ),
            'data'   => $data,
        );
        wp_register_ability('seoproanalytics/queue', array(
            'label'               => __('Decision queue', 'seoproanalytics'),
            'description'         => __('One ranked list of search work made from the opportunities (ctr: rewrite a title and description; missing: answer a search the page lacks; striking: improve a page ranking 4–20; decay: find why a page lost clicks, then update it; overlap: review a search several pages share, and make one the clear answer if they serve the same need; audit: fix a content audit finding on a page with search impressions, one item per page and finding; links: internal links to add; index: a page search does not show; refresh: the refresh planner\'s proposal for a page losing clicks, in place of its decay item: update it, leave it (fewer people search), protect it (it converts: change it carefully) or merge it (another page of the site overtook it for a search it lost), with the reason and the numbers in why and figures; it never changes content; target: a search target (seoproanalytics/targets) shown with another page than the one meant for it (finding wrong_page), or a high-priority target in striking distance (finding striking, in place of its striking item)). Each item has a key, its page and query (or finding), why it is listed, its figures and its score with the parts: potential clicks per 28 days × value (how well the page\'s visits from search convert against the site, at least 1) × confidence (the kind\'s, weighed by impressions) ÷ effort, so you can rank by your own rule. Pages with a running experiment are left out of new items; done items show their experiment\'s result.', 'seoproanalytics'),
            'category'            => self::CATEGORY,
            'input_schema'        => array(
                'type'                 => 'object',
                'default'              => array(),
                'additionalProperties' => false,
                'properties'           => $period + array(
                    'status' => array(
                        'type'        => 'string',
                        'enum'        => SEOProAnalytics_Queue::FILTERS,
                        'default'     => 'open',
                        'description' => __('open (new and accepted), new, accepted, done, dismissed (in the last 90 days) or all.', 'seoproanalytics'),
                    ),
                    'kind'   => array(
                        'type'        => 'string',
                        'enum'        => array_values(SEOProAnalytics_Queue::KINDS),
                        'description' => __('Only items of this kind, e.g. refresh; every kind when left out.', 'seoproanalytics'),
                    ),
                    'limit'  => array(
                        'type'    => 'integer',
                        'minimum' => 1,
                        'maximum' => SEOProAnalytics_Queue::MAX_LIMIT,
                        'default' => 25,
                    ),
                    'owner'  => self::owner_property(),
                ),
            ),
            'output_schema'       => array(
                'type'       => 'object',
                'properties' => array(
                    'engine'    => array('type' => 'string'),
                    'range'     => array('type' => 'object'),
                    'days'      => array('type' => 'integer'),
                    'connected' => array('type' => 'boolean'),
                    'rules'     => array('type' => 'object'),
                    'counts'    => array('type' => 'object'),
                    'items'     => array(
                        'type'  => 'array',
                        'items' => array('type' => 'object'),
                    ),
                    'total'     => array('type' => 'integer'),
                ),
            ),
            'execute_callback'    => array(__CLASS__, 'queue'),
            'permission_callback' => array('SEOProAnalytics_API', 'can_read'),
            'meta'                => array(
                'show_in_rest' => true,
                'annotations'  => array(
                    'readonly'    => true,
                    'destructive' => false,
                    'idempotent'  => true,
                ),
            ),
        ));
        wp_register_ability('seoproanalytics/queue-update', array(
            'label'               => __('Act on a decision queue item', 'seoproanalytics'),
            'description'         => __('Accept an item of the decision queue, mark it done (opens an experiment on its page with the kind\'s measure: CTR, clicks, impressions or position; none for a refresh proposal to leave the page as it is), dismiss it (hidden for 90 days), restore it, or set its effort (1–5) or a note. Use a key from seoproanalytics/queue, with the same period, engine and goal.', 'seoproanalytics'),
            'category'            => self::CATEGORY,
            'input_schema'        => array(
                'type'                 => 'object',
                'additionalProperties' => false,
                'required'             => array('key', 'action'),
                'properties'           => $period + array(
                    'key'       => array(
                        'type'        => 'string',
                        'pattern'     => '^[0-9a-fA-F]{16}$',
                        'description' => __('The item\'s key.', 'seoproanalytics'),
                    ),
                    'action'    => array(
                        'type'        => 'string',
                        'enum'        => SEOProAnalytics_Queue::ACTIONS,
                        'description' => __('accept, done, dismiss, restore, effort or note.', 'seoproanalytics'),
                    ),
                    'effort'    => array(
                        'type'    => 'integer',
                        'minimum' => 1,
                        'maximum' => SEOProAnalytics_Queue::MAX_EFFORT,
                    ),
                    'note'      => array(
                        'type'      => 'string',
                        'maxLength' => 190,
                    ),
                    'name'      => array(
                        'type'        => 'string',
                        'maxLength'   => 190,
                        'description' => __('For done: the experiment\'s name.', 'seoproanalytics'),
                    ),
                    'days'      => array(
                        'type'        => 'integer',
                        'enum'        => SEOProAnalytics_Experiments::WINDOWS,
                        'description' => __('For done: days in each window of the experiment (default 28).', 'seoproanalytics'),
                    ),
                    'threshold' => array(
                        'type'        => 'number',
                        'minimum'     => 0,
                        'description' => __('For done: the smallest change that counts: percent (default 10), or places for position (default 1).', 'seoproanalytics'),
                    ),
                ),
            ),
            'output_schema'       => array('type' => 'object'),
            'execute_callback'    => array(__CLASS__, 'queue_update'),
            'permission_callback' => array('SEOProAnalytics_API', 'can_manage'),
            'meta'                => array(
                'show_in_rest' => true,
                'annotations'  => array(
                    'readonly'    => false,
                    'destructive' => false,
                    'idempotent'  => false,
                ),
            ),
        ));
    }

    /**
     * seoproanalytics/queue.
     *
     * @param array<string,mixed>|null $input Input.
     * @return array<string,mixed>|WP_Error
     */
    public static function queue($input = null) {
        $input = is_array($input) ? $input : array();
        $req   = SEOProAnalytics_Query::request(array_diff_key($input, array('owner' => true)) + array('range' => '90d', 'limit' => 25));
        if (is_wp_error($req)) {
            return $req;
        }
        $owner = self::owner_filter($input);
        if (is_wp_error($owner)) {
            return $owner;
        }
        $engine = isset($input['engine']) ? (string) $input['engine'] : 'google';
        $status = isset($input['status']) ? (string) $input['status'] : 'open';
        $goal   = isset($input['goal']) ? (string) $input['goal'] : '';
        $kind   = isset($input['kind']) ? (string) $input['kind'] : '';
        return SEOProAnalytics_API::on_data(self::data($input), static function () use ($req, $engine, $status, $goal, $kind, $owner) {
            return SEOProAnalytics_Queue::report((array) $req, $engine, $status, $goal, $kind, $owner);
        });
    }

    /**
     * seoproanalytics/queue-update.
     *
     * @param array<string,mixed>|null $input Input.
     * @return array<string,mixed>|WP_Error
     */
    public static function queue_update($input = null) {
        $input = is_array($input) ? $input : array();
        $req   = SEOProAnalytics_Query::request(array_diff_key($input, array('limit' => true)) + array('range' => '90d'));
        if (is_wp_error($req)) {
            return $req;
        }
        $engine = isset($input['engine']) ? (string) $input['engine'] : 'google';
        $goal   = isset($input['goal']) ? (string) $input['goal'] : '';
        $key    = isset($input['key']) ? (string) $input['key'] : '';
        return SEOProAnalytics_API::on_data(self::data($input), static function () use ($key, $input, $req, $engine, $goal) {
            return SEOProAnalytics_Queue::update($key, $input, (array) $req, $engine, $goal);
        });
    }

    /**
     * The experiment abilities.
     *
     * @param array<string,mixed> $data   The data property.
     * @param array<string,mixed> $engine The engine property.
     */
    private static function register_experiments(array $data, array $engine) {
        self::register_change_sets($engine);
        wp_register_ability('seoproanalytics/experiments', array(
            'label'               => __('Experiments', 'seoproanalytics'),
            'description'         => __('Changes made to pages and what they were meant to do, each measured over equal windows before and after against comparable unchanged pages: the effect, the usual spread of unchanged pages, the search engine updates and other changes of the time, and a suggested result (keep, revise, undo or inconclusive). Due ones first. With id, one experiment.', 'seoproanalytics'),
            'category'            => self::CATEGORY,
            'input_schema'        => array(
                'type'                 => 'object',
                'default'              => array(),
                'additionalProperties' => false,
                'properties'           => array(
                    'id'     => array(
                        'type'        => 'integer',
                        'minimum'     => 1,
                        'description' => __('One experiment, by id.', 'seoproanalytics'),
                    ),
                    'status' => array(
                        'type'        => 'string',
                        'enum'        => array('', 'running', 'due', 'decided', 'cancelled'),
                        'description' => __('Only experiments in this state; due means running with data through the review day.', 'seoproanalytics'),
                    ),
                    'page'   => array(
                        'type'        => 'string',
                        'description' => __('Only experiments on this page (a path such as /pricing/).', 'seoproanalytics'),
                    ),
                    'owner'  => self::owner_property(),
                    'data'   => $data,
                ),
            ),
            'output_schema'       => array('type' => 'object'),
            'execute_callback'    => array(__CLASS__, 'experiments'),
            'permission_callback' => array('SEOProAnalytics_API', 'can_read'),
            'meta'                => array(
                'show_in_rest' => true,
                'annotations'  => array(
                    'readonly'    => true,
                    'destructive' => false,
                    'idempotent'  => true,
                ),
            ),
        ));
        wp_register_ability('seoproanalytics/ab-tests', array(
            'label'               => __('A/B tests', 'seoproanalytics'),
            'description'         => __('A/B tests of blocks on the site\'s pages: each test with its status, variants, visits per variant, leader and verdict (no_data, too_early, winner, control or unclear), running tests first. With id, one test\'s variants side by side: visits, every goal\'s conversions and rate, revenue per currency, bounce rate, engaged time and clicks inside the variant, with the uplift against the control (the first variant), its 95% interval and the Bayesian probability to beat the control. A test\'s numbers cover its life; visits that saw two or more of its variants are counted apart as mixed. thresholds gives the minimum sample before a call. Read-only; tests are started and ended in the block editor. Not the same as seoproanalytics/experiments.', 'seoproanalytics'),
            'category'            => self::CATEGORY,
            'input_schema'        => array(
                'type'                 => 'object',
                'default'              => array(),
                'additionalProperties' => false,
                'properties'           => array(
                    'id'      => array(
                        'type'        => 'string',
                        'pattern'     => '^[a-z0-9]{6,32}$',
                        'description' => __('One test, by id.', 'seoproanalytics'),
                    ),
                    'status'  => array(
                        'type'        => 'string',
                        'enum'        => array_merge(array(''), array_keys(SEOProAnalytics_AB_Tests::STATUSES)),
                        'description' => __('Only tests in this state.', 'seoproanalytics'),
                    ),
                    'filters' => array(
                        'type'        => 'array',
                        'items'       => array('type' => 'string'),
                        'description' => __('Only these visits: dimension:operator:value strings, as for the statistics (such as device:is:mobile).', 'seoproanalytics'),
                    ),
                    'data'    => $data,
                ),
            ),
            'output_schema'       => array('type' => 'object'),
            'execute_callback'    => array(__CLASS__, 'ab_tests'),
            'permission_callback' => array('SEOProAnalytics_API', 'can_read'),
            'meta'                => array(
                'show_in_rest' => true,
                'annotations'  => array(
                    'readonly'    => true,
                    'destructive' => false,
                    'idempotent'  => true,
                ),
            ),
        ));
        wp_register_ability('seoproanalytics/experiment-record', array(
            'label'               => __('Record or decide an experiment', 'seoproanalytics'),
            'description'         => __('Record an experiment before its result is known (a change to a page and what it should do: the measure, the direction and the smallest change that counts), or decide one (keep, revise, undo or inconclusive, with a note), or cancel it. Starting from a change in the change log fills in its time and page.', 'seoproanalytics'),
            'category'            => self::CATEGORY,
            'input_schema'        => array(
                'type'                 => 'object',
                'additionalProperties' => false,
                'required'             => array('action'),
                'properties'           => array(
                    'action'     => array(
                        'type'        => 'string',
                        'enum'        => array('add', 'decide', 'note', 'cancel'),
                        'description' => __('add a new experiment; decide, note or cancel one (with id).', 'seoproanalytics'),
                    ),
                    'id'         => array(
                        'type'        => 'integer',
                        'minimum'     => 1,
                        'description' => __('For decide, note and cancel: the experiment.', 'seoproanalytics'),
                    ),
                    'name'       => array(
                        'type'        => 'string',
                        'maxLength'   => 190,
                        'description' => __('For add: the change and what it should do, in one line.', 'seoproanalytics'),
                    ),
                    'change'     => array(
                        'type'        => 'integer',
                        'minimum'     => 0,
                        'description' => __('For add: the change it measures (its id from seoproanalytics/markers); its time is the start and its page the page.', 'seoproanalytics'),
                    ),
                    'start'      => array(
                        'type'        => 'string',
                        'description' => __('For add without change: when the change was made, in the site time zone (2026-10-05 or 2026-10-05 14:30); without it, now.', 'seoproanalytics'),
                    ),
                    'page'       => array(
                        'type'        => 'string',
                        'description' => __('For add: its page (a path such as /pricing/), or several, comma-separated (up to 50).', 'seoproanalytics'),
                    ),
                    'days'       => array(
                        'type'        => 'integer',
                        'enum'        => SEOProAnalytics_Experiments::WINDOWS,
                        'description' => __('For add: days in each window, before and after (default 28).', 'seoproanalytics'),
                    ),
                    'engine'     => $engine,
                    'metric'     => array(
                        'type'        => 'string',
                        'enum'        => array_values(SEOProAnalytics_Experiments::METRICS),
                        'description' => __('For add: what it should change (default clicks). Visits are visits from search landing on the pages; conversions need goal.', 'seoproanalytics'),
                    ),
                    'direction'  => array(
                        'type'        => 'string',
                        'enum'        => array_values(SEOProAnalytics_Experiments::DIRECTIONS),
                        'description' => __('For add: expected direction (default up; for position, up means a better place).', 'seoproanalytics'),
                    ),
                    'threshold'  => array(
                        'type'        => 'number',
                        'minimum'     => 0,
                        'description' => __('For add: the smallest change that counts: percent (default 10), or places for position (default 1).', 'seoproanalytics'),
                    ),
                    'goal'       => array(
                        'type'        => 'string',
                        'description' => __('For add with metric conversions: the goal\'s id.', 'seoproanalytics'),
                    ),
                    'hypothesis' => array(
                        'type'        => 'string',
                        'maxLength'   => 2000,
                        'description' => __('For add: the reasoning, in more words.', 'seoproanalytics'),
                    ),
                    'result'     => array(
                        'type'        => 'string',
                        'enum'        => array_values(SEOProAnalytics_Experiments::RESULTS),
                        'description' => __('For decide: the result.', 'seoproanalytics'),
                    ),
                    'restore'    => array('type' => 'boolean', 'description' => __('For decide undo: restore the linked change set on live data.', 'seoproanalytics')),
                    'note'       => array(
                        'type'        => 'string',
                        'maxLength'   => 2000,
                        'description' => __('Why, or what was learnt.', 'seoproanalytics'),
                    ),
                    'data'       => $data,
                ),
            ),
            'output_schema'       => array('type' => 'object'),
            'execute_callback'    => array(__CLASS__, 'experiment_record'),
            'permission_callback' => array('SEOProAnalytics_API', 'can_manage'),
            'meta'                => array(
                'show_in_rest' => true,
                'annotations'  => array(
                    'readonly'    => false,
                    'destructive' => false,
                    'idempotent'  => false,
                ),
            ),
        ));
    }

    /** @param array<string,mixed> $engine Search engine property. */
    private static function register_change_sets(array $engine) {
        $base = array(
            'category' => self::CATEGORY,
            'output_schema' => array('type' => 'object'),
            'meta' => array('show_in_rest' => true, 'annotations' => array('readonly' => false, 'destructive' => true, 'idempotent' => false)),
            'permission_callback' => array('SEOProAnalytics_API', 'can_manage'),
        );
        wp_register_ability('seoproanalytics/change-sets', array(
            'label' => __('Change sets', 'seoproanalytics'),
            'description' => __('Read full backups of reviewed page changes on live data. With id, one set; otherwise newest 50, paged with before.', 'seoproanalytics'),
            'input_schema' => array('type' => 'object', 'default' => array(), 'additionalProperties' => false, 'properties' => array('id' => array('type' => 'integer', 'minimum' => 1), 'before' => array('type' => 'integer', 'minimum' => 0))),
            'execute_callback' => array(__CLASS__, 'change_sets'),
            'permission_callback' => array('SEOProAnalytics_API', 'can_read'),
            'meta' => array('show_in_rest' => true, 'annotations' => array('readonly' => true, 'destructive' => false, 'idempotent' => true)),
        ) + $base);
        wp_register_ability('seoproanalytics/change-set-apply', array(
            'label' => __('Apply a reviewed change set', 'seoproanalytics'),
            'description' => __('Preview with dry_run, or apply a reviewed plan to live pages. Plan: name and pages, each with post id and title, seo_title or seo_description pairs [now, new]. Stale or non-editable pages are skipped. Full old values are kept; read-back or address differences stop the run. Optional experiment starts at apply time.', 'seoproanalytics'),
            'input_schema' => array('type' => 'object', 'additionalProperties' => false, 'required' => array('plan'), 'properties' => array(
                'plan' => array('type' => 'object', 'additionalProperties' => true),
                'dry_run' => array('type' => 'boolean'),
                'experiment' => array('type' => 'string'),
                'metric' => array('type' => 'string', 'enum' => array_values(SEOProAnalytics_Experiments::METRICS)),
                'days' => array('type' => 'integer', 'enum' => SEOProAnalytics_Experiments::WINDOWS),
                'engine' => $engine,
                'direction' => array('type' => 'string', 'enum' => array_values(SEOProAnalytics_Experiments::DIRECTIONS)),
                'threshold' => array('type' => 'number', 'minimum' => 0),
                'hypothesis' => array('type' => 'string'),
                'goal' => array('type' => 'string'),
            )),
            'execute_callback' => array(__CLASS__, 'change_set_apply'),
        ) + $base);
        wp_register_ability('seoproanalytics/change-set-undo', array(
            'label' => __('Undo a change set', 'seoproanalytics'),
            'description' => __('Restore live values still equal to what this set wrote, with edit_post permission per page. Later edits are reported and left untouched. Read-back verifies each restoration.', 'seoproanalytics'),
            'input_schema' => array('type' => 'object', 'additionalProperties' => false, 'required' => array('id'), 'properties' => array('id' => array('type' => 'integer', 'minimum' => 1))),
            'execute_callback' => array(__CLASS__, 'change_set_undo'),
        ) + $base);
    }

    /** @param array<string,mixed>|null $input Input. @return array<string,mixed>|WP_Error */
    public static function change_sets($input = null) {
        require_once __DIR__ . '/class-seoproanalytics-change-sets.php';
        return !empty($input['id']) ? SEOProAnalytics_Change_Sets::get((int) $input['id']) : SEOProAnalytics_Change_Sets::list_sets((int) ($input['before'] ?? 0));
    }

    /** @param array<string,mixed>|null $input Input. @return array<string,mixed>|WP_Error */
    public static function change_set_apply($input = null) {
        require_once __DIR__ . '/class-seoproanalytics-change-sets.php';
        return SEOProAnalytics_Change_Sets::apply((array) ($input['plan'] ?? array()), $input ?? array());
    }

    /** @param array<string,mixed>|null $input Input. @return array<string,mixed>|WP_Error */
    public static function change_set_undo($input = null) {
        require_once __DIR__ . '/class-seoproanalytics-change-sets.php';
        return SEOProAnalytics_Change_Sets::undo((int) ($input['id'] ?? 0));
    }

    /**
     * seoproanalytics/experiments.
     *
     * @param array<string,mixed>|null $input Input.
     * @return array<string,mixed>|WP_Error
     */
    public static function experiments($input = null) {
        $input = is_array($input) ? $input : array();
        $owner = self::owner_filter($input);
        if (is_wp_error($owner)) {
            return $owner;
        }
        return SEOProAnalytics_API::on_data(self::data($input), static function () use ($input, $owner) {
            if (!empty($input['id'])) {
                return SEOProAnalytics_Experiments::get((int) $input['id']);
            }
            return SEOProAnalytics_Experiments::list_experiments(array(
                'status' => isset($input['status']) ? (string) $input['status'] : '',
                'page'   => isset($input['page']) ? (string) $input['page'] : '',
                'owner'  => $owner,
            ));
        });
    }

    /**
     * seoproanalytics/ab-tests.
     *
     * @param array<string,mixed>|null $input Input.
     * @return array<string,mixed>|WP_Error
     */
    public static function ab_tests($input = null) {
        $input = is_array($input) ? $input : array();
        $args  = array(
            'filters' => isset($input['filters']) && is_array($input['filters']) ? $input['filters'] : array(),
            'status'  => isset($input['status']) ? (string) $input['status'] : '',
        );
        return SEOProAnalytics_API::on_data(self::data($input), static function () use ($input, $args) {
            if (!empty($input['id'])) {
                return SEOProAnalytics_AB_Report::get((string) $input['id'], $args);
            }
            return SEOProAnalytics_AB_Report::list_tests($args);
        });
    }

    /**
     * seoproanalytics/experiment-record.
     *
     * @param array<string,mixed>|null $input Input.
     * @return array<string,mixed>|WP_Error
     */
    public static function experiment_record($input = null) {
        $input  = is_array($input) ? $input : array();
        $action = isset($input['action']) ? (string) $input['action'] : '';
        return SEOProAnalytics_API::on_data(self::data($input), static function () use ($input, $action) {
            if ($action === 'add') {
                return SEOProAnalytics_Experiments::add($input);
            }
            if (empty($input['id'])) {
                return new WP_Error('seoproanalytics_experiment', __('Give the experiment\'s id (seoproanalytics/experiments lists them).', 'seoproanalytics'), array('status' => 400));
            }
            return SEOProAnalytics_Experiments::update((int) $input['id'], $input);
        });
    }

    /**
     * seoproanalytics/content.
     *
     * @param array<string,mixed>|null $input Input.
     * @return array<string,mixed>|WP_Error
     */
    public static function content($input = null) {
        $input = is_array($input) ? $input : array();
        $req   = SEOProAnalytics_Query::request(array_diff_key($input, array('owner' => true)) + array('range' => '30d', 'compare' => 'none', 'limit' => 25));
        if (is_wp_error($req)) {
            return $req;
        }
        $owner = self::owner_filter($input);
        if (is_wp_error($owner)) {
            return $owner;
        }
        $sort   = isset($input['sort']) ? (string) $input['sort'] : '';
        $order  = isset($input['order']) ? (string) $input['order'] : '';
        $goal   = isset($input['goal']) ? (string) $input['goal'] : '';
        $engine = isset($input['engine']) ? (string) $input['engine'] : 'google';
        return SEOProAnalytics_API::on_data(self::data($input), static function () use ($req, $sort, $goal, $engine, $order, $owner) {
            return SEOProAnalytics_Content::report((array) $req, $sort, $goal, $engine, $order, $owner);
        });
    }

    /**
     * The owner filter property of a list.
     *
     * @return array<string,mixed>
     */
    private static function owner_property() {
        return array(
            'type'        => 'string',
            'description' => __('Only those of this owner: me, none (owned by nobody) or a user id (seoproanalytics/owners lists the people). For people who can edit posts.', 'seoproanalytics'),
        );
    }

    /**
     * An input's owner filter (SEOProAnalytics_Owners::filter()).
     *
     * @param array<string,mixed> $input Input.
     * @return int|null|WP_Error
     */
    private static function owner_filter(array $input) {
        require_once __DIR__ . '/class-seoproanalytics-owners.php';
        return SEOProAnalytics_Owners::filter(isset($input['owner']) ? $input['owner'] : '');
    }

    /**
     * The owner abilities: who owns what, and setting owners.
     *
     * @param array<string,mixed> $data   The data property.
     * @param array<string,mixed> $engine The engine property.
     */
    private static function register_owners(array $data, array $engine) {
        require_once __DIR__ . '/class-seoproanalytics-owners.php';
        wp_register_ability('seoproanalytics/owners', array(
            'label'               => __('SEO owners', 'seoproanalytics'),
            'description'         => __('Who is responsible for the SEO of what: each person who can own (roles chosen in Settings → Data → Owners, listed in roles) with their pages, open Plan items (new and accepted, with those items\' potential clicks per 28 days), open search targets and running experiments (and those due for review), then what nobody owns. By default a page\'s owner is its post\'s author unless someone set another; a target\'s and a Plan item\'s, their page\'s; an experiment\'s, its page\'s, else whoever recorded it; the settings can give each place nobody or one person instead. Lists take owner (me, none or a user id) to show one person\'s work; each row has owner (id, name, and source: assigned, default or none).', 'seoproanalytics'),
            'category'            => self::CATEGORY,
            'input_schema'        => array(
                'type'                 => 'object',
                'default'              => array(),
                'additionalProperties' => false,
                'properties'           => array(
                    'engine' => $engine,
                    'range'  => array(
                        'type'        => 'string',
                        'enum'        => SEOProAnalytics_Query::RANGES,
                        'default'     => '90d',
                        'description' => __('The Plan\'s period, in the site time zone.', 'seoproanalytics'),
                    ),
                    'goal'   => array(
                        'type'        => 'string',
                        'description' => __('The goal whose conversions give a page its value; the first goal when left out.', 'seoproanalytics'),
                    ),
                    'data'   => $data,
                ),
            ),
            'output_schema'       => array(
                'type'       => 'object',
                'properties' => array(
                    'roles'      => array('type' => 'array', 'items' => array('type' => 'string')),
                    'people'     => array('type' => 'array', 'items' => array('type' => 'object')),
                    'nobody'     => array('type' => 'object'),
                    'plan'       => array('type' => 'object'),
                    'can_assign' => array('type' => 'boolean'),
                    'can_take'   => array('type' => 'boolean'),
                    'me'         => array('type' => 'integer'),
                ),
            ),
            'execute_callback'    => array(__CLASS__, 'owners'),
            'permission_callback' => array('SEOProAnalytics_API', 'can_see_owners'),
            'meta'                => array(
                'show_in_rest' => true,
                'annotations'  => array(
                    'readonly'    => true,
                    'destructive' => false,
                    'idempotent'  => true,
                ),
            ),
        ));
        wp_register_ability('seoproanalytics/owners-set', array(
            'label'               => __('Set SEO owners', 'seoproanalytics'),
            'description'         => __('Set who is responsible for the SEO of pages, search targets, Plan items or experiments: a person who can own, none (nobody, on purpose: overrides the default) or default (forget the owner set, so the default applies again). Administrators give work to anyone; people who can own take work nobody else was given (me) and give their own back (default). Answers the ids set and those skipped and why.', 'seoproanalytics'),
            'category'            => self::CATEGORY,
            'input_schema'        => array(
                'type'                 => 'object',
                'additionalProperties' => false,
                'required'             => array('kind', 'ids', 'user'),
                'properties'           => array(
                    'kind' => array(
                        'type'        => 'string',
                        'enum'        => array_values(SEOProAnalytics_Owners::KINDS),
                        'description' => __('page, target, item (a Plan item) or experiment.', 'seoproanalytics'),
                    ),
                    'ids'  => array(
                        'type'        => 'array',
                        'items'       => array('type' => 'string'),
                        'minItems'    => 1,
                        'maxItems'    => SEOProAnalytics_Owners::MAX_IDS,
                        'description' => __('Pages (paths such as /pricing/ or addresses on this site), target searches, Plan item keys (seoproanalytics/queue) or experiment ids.', 'seoproanalytics'),
                    ),
                    'user' => array(
                        'type'        => 'string',
                        'description' => __('A user id (seoproanalytics/owners lists the people), me, none or default.', 'seoproanalytics'),
                    ),
                    'data' => $data,
                ),
            ),
            'output_schema'       => array('type' => 'object'),
            'execute_callback'    => array(__CLASS__, 'owners_set'),
            'permission_callback' => array('SEOProAnalytics_API', 'can_see_owners'),
            'meta'                => array(
                'show_in_rest' => true,
                'annotations'  => array(
                    'readonly'    => false,
                    'destructive' => false,
                    'idempotent'  => true,
                ),
            ),
        ));
    }

    /**
     * seoproanalytics/owners.
     *
     * @param array<string,mixed>|null $input Input.
     * @return array<string,mixed>|WP_Error
     */
    public static function owners($input = null) {
        $input = is_array($input) ? $input : array();
        $req   = SEOProAnalytics_Query::request($input + array('range' => '90d', 'compare' => 'none'));
        if (is_wp_error($req)) {
            return $req;
        }
        $engine = isset($input['engine']) ? (string) $input['engine'] : 'google';
        $goal   = isset($input['goal']) ? (string) $input['goal'] : '';
        return SEOProAnalytics_API::on_data(self::data($input), static function () use ($req, $engine, $goal) {
            return SEOProAnalytics_Owners::summary((array) $req, $engine, $goal);
        });
    }

    /**
     * seoproanalytics/owners-set.
     *
     * @param array<string,mixed>|null $input Input.
     * @return array<string,mixed>|WP_Error
     */
    public static function owners_set($input = null) {
        $input = is_array($input) ? $input : array();
        return SEOProAnalytics_API::on_data(self::data($input), static function () use ($input) {
            return SEOProAnalytics_Owners::assign(isset($input['kind']) ? (string) $input['kind'] : '', isset($input['ids']) ? array_map('strval', (array) $input['ids']) : array(), isset($input['user']) ? (string) $input['user'] : '');
        });
    }

    /**
     * seoproanalytics/coverage.
     *
     * @param array<string,mixed>|null $input Input.
     * @return array<string,mixed>|WP_Error
     */
    public static function coverage($input = null) {
        $input = is_array($input) ? $input : array();
        $req   = SEOProAnalytics_Query::request($input + array('range' => '90d', 'compare' => 'none'));
        if (is_wp_error($req)) {
            return $req;
        }
        $page = isset($input['page']) ? (string) $input['page'] : '';
        $post = isset($input['post']) ? (int) $input['post'] : 0;
        return SEOProAnalytics_API::on_data(self::data($input), static function () use ($req, $page, $post) {
            return SEOProAnalytics_Coverage::report((array) $req, $page, $post);
        });
    }

    /**
     * seoproanalytics/opportunities.
     *
     * @param array<string,mixed>|null $input Input.
     * @return array<string,mixed>|WP_Error
     */
    public static function opportunities($input = null) {
        $input = is_array($input) ? $input : array();
        $req   = SEOProAnalytics_Query::request($input + array('range' => '30d', 'compare' => 'prev', 'limit' => 25));
        if (is_wp_error($req)) {
            return $req;
        }
        $kind   = isset($input['kind']) ? (string) $input['kind'] : 'striking';
        $engine = isset($input['engine']) ? (string) $input['engine'] : 'google';
        return SEOProAnalytics_API::on_data(self::data($input), static function () use ($req, $kind, $engine) {
            return SEOProAnalytics_Opportunities::report((array) $req, $kind, $engine);
        });
    }

    /**
     * seoproanalytics/audit.
     *
     * @param array<string,mixed>|null $input Input.
     * @return array<string,mixed>|WP_Error
     */
    public static function audit($input = null) {
        $input = is_array($input) ? $input : array();
        $req   = SEOProAnalytics_Query::request($input + array('range' => '30d', 'limit' => 25));
        if (is_wp_error($req)) {
            return $req;
        }
        $finding = isset($input['finding']) ? (string) $input['finding'] : '';
        $engine  = isset($input['engine']) ? (string) $input['engine'] : 'google';
        $sort    = isset($input['sort']) ? (string) $input['sort'] : '';
        $order   = isset($input['order']) ? (string) $input['order'] : '';
        return SEOProAnalytics_API::on_data(self::data($input), static function () use ($req, $finding, $engine, $sort, $order) {
            return SEOProAnalytics_Audit::report((array) $req, $engine, $finding, $sort, $order);
        });
    }

    /**
     * seoproanalytics/vitals: read only, including on demo tables.
     *
     * @param array<string,mixed>|null $input Input.
     * @return array<string,mixed>|WP_Error
     */
    public static function vitals($input = null) {
        $input = is_array($input) ? $input : array();
        $page = (string) ($input['page'] ?? '');
        if ($page !== '' && SEOProAnalytics_Vitals::local_url($page) === '') {
            return new WP_Error('seoproanalytics_vitals_page', __('Use a local page path.', 'seoproanalytics'));
        }
        return SEOProAnalytics_API::on_data(self::data($input), static function () use ($page) { return SEOProAnalytics_Vitals::report($page); });
    }

    /**
     * seoproanalytics/links.
     *
     * @param array<string,mixed>|null $input Input.
     * @return array<string,mixed>|WP_Error
     */
    public static function links($input = null) {
        $input = is_array($input) ? $input : array();
        $req   = SEOProAnalytics_Query::request($input + array('range' => '30d', 'limit' => 25));
        if (is_wp_error($req)) {
            return $req;
        }
        $kind   = isset($input['kind']) ? (string) $input['kind'] : 'orphans';
        $goal   = isset($input['goal']) ? (string) $input['goal'] : '';
        $engine = isset($input['engine']) ? (string) $input['engine'] : 'google';
        return SEOProAnalytics_API::on_data(self::data($input), static function () use ($req, $kind, $goal, $engine) {
            return SEOProAnalytics_Links::report((array) $req, $engine, $kind, $goal);
        });
    }

    /**
     * seoproanalytics/business-profiles.
     *
     * @param array<string,mixed>|null $input Input.
     * @return array<string,mixed>|WP_Error
     */
    public static function business_profiles($input = null) {
        $input = is_array($input) ? $input : array();
        $req   = SEOProAnalytics_Query::request(array_intersect_key($input, array_flip(array('range', 'from', 'to', 'filters'))) + array('range' => '30d'));
        if (is_wp_error($req)) {
            return $req;
        }
        return SEOProAnalytics_API::on_data(self::data($input), static function () use ($req) {
            return SEOProAnalytics_Business_Profiles::report((array) $req);
        });
    }

    /**
     * seoproanalytics/indexation.
     *
     * @param array<string,mixed>|null $input Input.
     * @return array<string,mixed>|WP_Error
     */
    public static function indexation($input = null) {
        $input = is_array($input) ? $input : array();
        $req   = SEOProAnalytics_Query::request(array_diff_key($input, array('days' => 1)) + array('limit' => 25));
        if (is_wp_error($req)) {
            return $req;
        }
        $kind   = isset($input['kind']) ? (string) $input['kind'] : 'pages';
        $days   = isset($input['days']) ? (int) $input['days'] : SEOProAnalytics_Indexation::DAYS;
        $engine = isset($input['engine']) ? (string) $input['engine'] : 'google';
        return SEOProAnalytics_API::on_data(self::data($input), static function () use ($req, $kind, $days, $engine) {
            return SEOProAnalytics_Indexation::report((array) $req, $engine, $kind, $days);
        });
    }

    /**
     * seoproanalytics/backlinks.
     *
     * @param array<string,mixed>|null $input Input.
     * @return array<string,mixed>|WP_Error
     */
    public static function backlinks($input = null) {
        $input = is_array($input) ? $input : array();
        $triage = array_intersect_key($input, SEOProAnalytics_API::backlink_triage_args());
        $req    = SEOProAnalytics_Query::request(array_diff_key($input, array('kind' => 1, 'source' => 1, 'engine' => 1) + $triage) + array('range' => '30d', 'limit' => 25));
        if (is_wp_error($req)) {
            return $req;
        }
        $kind   = isset($input['kind']) ? (string) $input['kind'] : 'links';
        $source = isset($input['source']) ? (string) $input['source'] : '';
        $engine = isset($input['engine']) ? (string) $input['engine'] : 'google';
        return SEOProAnalytics_API::on_data(self::data($input), static function () use ($req, $kind, $source, $engine, $triage) {
            return SEOProAnalytics_Backlinks::report(array('source' => $source, 'engine' => $engine, 'triage' => $triage) + (array) $req, $kind);
        });
    }

    /**
     * seoproanalytics/backlink-classify.
     *
     * @param array<string,mixed>|null $input Input.
     * @return array<string,mixed>|WP_Error
     */
    public static function backlink_classify($input = null) {
        require_once __DIR__ . '/class-seoproanalytics-backlinks-triage.php';
        $input = is_array($input) ? $input : array();
        return SEOProAnalytics_API::on_data(self::data($input), static function () use ($input) {
            return SEOProAnalytics_Backlinks_Triage::classify($input);
        });
    }

    /** Local owner review; export text is wrapped for the ability's object contract. @param array<string,mixed>|null $input Input. @return array<string,mixed>|WP_Error */
    public static function backlink_review($input = null) {
        require_once __DIR__ . '/class-seoproanalytics-backlink-review.php';
        $input = is_array($input) ? $input : array();
        return SEOProAnalytics_API::on_data(isset($input['data']) ? (string) $input['data'] : '', static function () use ($input) {
            return self::review_backlinks($input);
        });
    }

    /**
     * seoproanalytics/backlink-review on its data set: decide, merge, export
     * or list.
     *
     * @param array<string,mixed> $input Input.
     * @return array<string,mixed>|WP_Error
     */
    private static function review_backlinks(array $input) {
        $action = isset($input['action']) ? (string) $input['action'] : 'list';
        if ($action === 'decide') {
            return SEOProAnalytics_Backlink_Review::decide($input);
        }
        $text = isset($input['text']) ? (string) $input['text'] : '';
        if ($action === 'merge') {
            return SEOProAnalytics_Backlink_Review::merge($text);
        }
        if ($action === 'export') {
            $out = SEOProAnalytics_Backlink_Review::export($text);
            return is_wp_error($out) ? $out : array('text' => $out);
        }
        $req = SEOProAnalytics_Query::request($input + array('range' => '30d'));
        return is_wp_error($req) ? $req : SEOProAnalytics_Backlink_Review::report($req);
    }

    /**
     * seoproanalytics/inspections.
     *
     * @param array<string,mixed>|null $input Input.
     * @return array<string,mixed>|WP_Error
     */
    public static function inspections($input = null) {
        $input = is_array($input) ? $input : array();
        $req   = SEOProAnalytics_Query::request(array_diff_key($input, array_flip(array('page', 'verdict', 'coverage', 'finding'))) + array('limit' => 25));
        if (is_wp_error($req)) {
            return $req;
        }
        $req  = (array) $req;
        $page = isset($input['page']) ? trim((string) $input['page']) : '';
        if ($page !== '') {
            $req['filters'] = array(array('dimension' => 'page', 'op' => 'is', 'values' => array($page)));
        }
        $verdict  = isset($input['verdict']) ? (string) $input['verdict'] : '';
        $coverage = isset($input['coverage']) ? (string) $input['coverage'] : '';
        $finding  = isset($input['finding']) ? (string) $input['finding'] : '';
        return SEOProAnalytics_API::on_data(self::data($input), static function () use ($req, $verdict, $coverage, $finding) {
            return SEOProAnalytics_Inspections::report($req, $verdict, $coverage, $finding);
        });
    }

    /**
     * seoproanalytics/crawl-issues.
     *
     * @param array<string,mixed>|null $input Input.
     * @return array<string,mixed>|WP_Error
     */
    public static function crawl_issues($input = null) {
        $input = is_array($input) ? $input : array();
        $req   = SEOProAnalytics_Query::request(array_diff_key($input, array('issue' => true)) + array('limit' => 25));
        if (is_wp_error($req)) {
            return $req;
        }
        $issue = isset($input['issue']) ? (string) $input['issue'] : '';
        return SEOProAnalytics_API::on_data(self::data($input), static function () use ($req, $issue) {
            return SEOProAnalytics_Bing_Site_Data::report((array) $req, $issue);
        });
    }

    /**
     * seoproanalytics/crawl.
     *
     * @param array<string,mixed>|null $input Input.
     * @return array<string,mixed>|WP_Error
     */
    public static function crawl($input = null) {
        $input = is_array($input) ? $input : array();
        $req   = SEOProAnalytics_Query::request(array_diff_key($input, array('kind' => true, 'status' => true, 'finding' => true, 'goal' => true, 'engine' => true)) + array('range' => '30d', 'limit' => 25));
        if (is_wp_error($req)) {
            return $req;
        }
        $kind    = isset($input['kind']) ? (string) $input['kind'] : '';
        $status  = isset($input['status']) ? (string) $input['status'] : '';
        $finding = isset($input['finding']) ? (string) $input['finding'] : '';
        return SEOProAnalytics_API::on_data(self::data($input), static function () use ($req, $kind, $status, $finding, $input) {
            return SEOProAnalytics_Crawl::report((array) $req, $kind, $status, $finding, (string) ($input['goal'] ?? ''), (string) ($input['engine'] ?? 'google'));
        });
    }

    /**
     * seoproanalytics/crawl-import.
     *
     * @param array<string,mixed>|null $input Input.
     * @return array<string,mixed>|WP_Error
     */
    public static function crawl_import($input = null) {
        $input = is_array($input) ? $input : array();
        if (!empty($input['undo'])) {
            $deleted = SEOProAnalytics_Crawl_Import::undo((int) $input['undo']);
            return is_wp_error($deleted) ? $deleted : array('deleted' => $deleted, 'imports' => SEOProAnalytics_Crawl_Import::imports());
        }
        if (!empty($input['imports'])) {
            return array('imports' => SEOProAnalytics_Crawl_Import::imports(), 'job' => SEOProAnalytics_Crawl_Import::status());
        }
        $sources = array();
        foreach (isset($input['files']) && is_array($input['files']) ? $input['files'] : array() as $one) {
            if (is_array($one) && isset($one['rows']) && is_array($one['rows'])) {
                $sources[] = array('name' => isset($one['name']) ? (string) $one['name'] : 'rows.json', 'rows' => $one['rows']);
            }
        }
        $stream = null;
        if (isset($input['text']) && is_string($input['text']) && $input['text'] !== '') {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- a memory stream for the given text, no file.
            $stream = fopen('php://temp', 'w+');
            if (!$stream) {
                return new WP_Error('seoproanalytics_crawl_text', __('The text could not be read.', 'seoproanalytics'));
            }
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- a memory stream of the given text.
            fwrite($stream, $input['text']);
            rewind($stream);
            $sources[] = array('name' => isset($input['name']) ? (string) $input['name'] : 'crawl.csv', 'stream' => $stream);
        }
        if (!$sources) {
            $job = SEOProAnalytics_Crawl_Import::status();
            return $job['status'] === 'running' ? SEOProAnalytics_Crawl_Import::run(SEOProAnalytics_Crawl_Import::POLL_BUDGET) : $job;
        }
        try {
            return SEOProAnalytics_Crawl_Import::start($sources, isset($input['format']) ? (string) $input['format'] : '', !empty($input['complete']), isset($input['record']) && is_array($input['record']) ? $input['record'] : array(), 'upload', !empty($input['partial']));
        } finally {
            if ($stream) {
                // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- the memory stream.
                fclose($stream);
            }
        }
    }

    /**
     * seoproanalytics/search.
     *
     * @param array<string,mixed>|null $input Input.
     * @return array<string,mixed>|WP_Error
     */
    public static function search($input = null) {
        $input = is_array($input) ? $input : array();
        $req   = SEOProAnalytics_Query::request($input + array('range' => '30d', 'limit' => 25));
        if (is_wp_error($req)) {
            return $req;
        }
        $kind  = isset($input['kind']) ? (string) $input['kind'] : 'queries';
        $page  = isset($input['page']) ? (string) $input['page'] : '';
        $query  = isset($input['query']) ? (string) $input['query'] : '';
        $engine = isset($input['engine']) ? (string) $input['engine'] : 'google';
        $sort   = isset($input['sort']) ? (string) $input['sort'] : '';
        $order  = isset($input['order']) ? (string) $input['order'] : '';
        return SEOProAnalytics_API::on_data(self::data($input), static function () use ($req, $kind, $page, $query, $engine, $sort, $order) {
            return SEOProAnalytics_Search::report((array) $req, $kind, $page, $query, $engine, $sort, $order);
        });
    }

    /**
     * seoproanalytics/markers.
     *
     * @param array<string,mixed>|null $input Input.
     * @return array<string,mixed>|WP_Error
     */
    public static function markers($input = null) {
        $input = is_array($input) ? $input : array();
        $req   = SEOProAnalytics_Query::request($input + array('range' => '30d'));
        if (is_wp_error($req)) {
            return $req;
        }
        $args = array(
            'page'    => isset($input['page']) ? (string) $input['page'] : '',
            'kinds'   => isset($input['kinds']) ? (string) $input['kinds'] : '',
            'limit'   => SEOProAnalytics_Changes::MAX_LIMIT,
            'order'   => 'asc',
            'running' => true,
        );
        $answer = SEOProAnalytics_API::on_data(self::data($input), static function () use ($req, $args) {
            return SEOProAnalytics_Changes::list_changes((array) $req, $args);
        });
        if (is_wp_error($answer)) {
            return $answer;
        }
        return array(
            'range'   => $answer['range'],
            'markers' => $answer['changes'],
            'total'   => $answer['total'],
        );
    }

    /**
     * seoproanalytics/annotate.
     *
     * @param array<string,mixed>|null $input Input.
     * @return array<string,mixed>|WP_Error
     */
    public static function annotate($input = null) {
        $input = is_array($input) ? $input : array();
        return SEOProAnalytics_API::on_data(self::data($input), static function () use ($input) {
            return SEOProAnalytics_Changes::annotate(
                isset($input['note']) ? (string) $input['note'] : '',
                isset($input['page']) ? (string) $input['page'] : '',
                isset($input['time']) ? (string) $input['time'] : ''
            );
        });
    }

    /**
     * The data set asked for.
     *
     * @param array<string,mixed> $input Input.
     * @return string
     */
    private static function data(array $input) {
        return isset($input['data']) && $input['data'] === 'demo' ? 'demo' : 'live';
    }
}

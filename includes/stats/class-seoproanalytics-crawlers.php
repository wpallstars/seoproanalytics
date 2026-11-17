<?php
/**
 * Crawler visits: which search engines, AI crawlers, SEO tools and other
 * bots request which pages, and the HTTP status each got.
 *
 * Bots do not run the tracker, so the server notes them: on a front-end
 * request whose user agent looks like a bot (SEOProAnalytics_UA's pattern,
 * one regular expression for every request), at shutdown, after the
 * page's output, one row per site-local day, crawler, page and status in
 * the bots table is added to (one upsert by primary key; only the bot's
 * request waits for it). People's requests stop at that one match. Pages
 * a page cache serves without WordPress are not seen, nor files the web
 * server sends itself; robots.txt, sitemaps and feeds are.
 *
 * Crawlers are named from CRAWLERS (first match wins); other bots count
 * as "Other bot", so the dictionary cannot grow with made-up names. For
 * the same reason, pages not found are kept by address only for search
 * engines and AI crawlers.
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

final class SEOProAnalytics_Crawlers {

    /** Kinds of report rows. */
    const KINDS = array('bots', 'pages', 'statuses', 'days');

    /** Groups of crawler. */
    const GROUPS = array('search', 'ai', 'seo', 'social', 'monitor', 'other');

    /**
     * Known crawlers: user agent text (lower case) => [name, group]. More
     * specific texts come first: the first match names the crawler.
     */
    const CRAWLERS = array(
        'google-inspectiontool' => array('Google URL Inspection', 'search'),
        'google-extended'       => array('Google-Extended', 'ai'),
        'googleother'           => array('GoogleOther', 'search'),
        'adsbot-google'         => array('Google AdsBot', 'search'),
        'mediapartners-google'  => array('Google AdSense', 'search'),
        'storebot-google'       => array('Google StoreBot', 'search'),
        'googlebot-image'       => array('Googlebot Image', 'search'),
        'googlebot-video'       => array('Googlebot Video', 'search'),
        'googlebot'             => array('Googlebot', 'search'),
        'feedfetcher-google'    => array('Google Feedfetcher', 'search'),
        'bingpreview'           => array('Bing Preview', 'search'),
        'bingbot'               => array('Bingbot', 'search'),
        'msnbot'                => array('Bingbot', 'search'),
        'yandex'                => array('YandexBot', 'search'),
        'baiduspider'           => array('Baiduspider', 'search'),
        'duckduckbot'           => array('DuckDuckBot', 'search'),
        'duckassistbot'         => array('DuckAssistBot', 'ai'),
        'applebot-extended'     => array('Applebot-Extended', 'ai'),
        'applebot'              => array('Applebot', 'search'),
        'slurp'                 => array('Yahoo Slurp', 'search'),
        'seznambot'             => array('SeznamBot', 'search'),
        'naver'                 => array('Naver Yeti', 'search'),
        'qwantify'              => array('Qwant', 'search'),
        'qwantbot'              => array('Qwant', 'search'),
        'mojeekbot'             => array('MojeekBot', 'search'),
        'yeti/'                 => array('Naver Yeti', 'search'),
        'petalbot'              => array('PetalBot', 'search'),
        'sogou'                 => array('Sogou', 'search'),
        'gptbot'                => array('GPTBot (OpenAI)', 'ai'),
        'chatgpt-user'          => array('ChatGPT-User (OpenAI)', 'ai'),
        'oai-searchbot'         => array('OAI-SearchBot (OpenAI)', 'ai'),
        'claudebot'             => array('ClaudeBot (Anthropic)', 'ai'),
        'claude-user'           => array('Claude-User (Anthropic)', 'ai'),
        'claude-searchbot'      => array('Claude-SearchBot (Anthropic)', 'ai'),
        'claude-web'            => array('Claude-Web (Anthropic)', 'ai'),
        'anthropic-ai'          => array('Anthropic AI', 'ai'),
        'perplexitybot'         => array('PerplexityBot', 'ai'),
        'perplexity-user'       => array('Perplexity-User', 'ai'),
        'meta-externalagent'    => array('Meta-ExternalAgent', 'ai'),
        'meta-externalfetcher'  => array('Meta-ExternalFetcher', 'ai'),
        'ccbot'                 => array('CCBot (Common Crawl)', 'ai'),
        'bytespider'            => array('Bytespider (ByteDance)', 'ai'),
        'amazonbot'             => array('Amazonbot', 'ai'),
        'cohere-ai'             => array('Cohere', 'ai'),
        'diffbot'               => array('Diffbot', 'ai'),
        'youbot'                => array('YouBot', 'ai'),
        'mistralai-user'        => array('MistralAI-User', 'ai'),
        'ai2bot'                => array('AI2Bot', 'ai'),
        'timpibot'              => array('Timpibot', 'ai'),
        'ahrefsbot'             => array('AhrefsBot', 'seo'),
        'ahrefssiteaudit'       => array('Ahrefs Site Audit', 'seo'),
        'semrushbot'            => array('SemrushBot', 'seo'),
        'siteauditbot'          => array('Semrush Site Audit', 'seo'),
        'mj12bot'               => array('MJ12bot (Majestic)', 'seo'),
        'dotbot'                => array('DotBot (Moz)', 'seo'),
        'rogerbot'              => array('Rogerbot (Moz)', 'seo'),
        'screaming frog'        => array('Screaming Frog', 'seo'),
        'sitebulb'              => array('Sitebulb', 'seo'),
        'dataforseobot'         => array('DataForSeoBot', 'seo'),
        'serpstatbot'           => array('SerpstatBot', 'seo'),
        'barkrowler'            => array('Barkrowler', 'seo'),
        'blexbot'               => array('BLEXBot', 'seo'),
        'seokicks'              => array('SEOkicks', 'seo'),
        'lighthouse'            => array('Lighthouse', 'monitor'),
        'pagespeed'             => array('PageSpeed Insights', 'monitor'),
        'gtmetrix'              => array('GTmetrix', 'monitor'),
        'pingdom'               => array('Pingdom', 'monitor'),
        'uptimerobot'           => array('UptimeRobot', 'monitor'),
        'statuscake'            => array('StatusCake', 'monitor'),
        'site24x7'              => array('Site24x7', 'monitor'),
        'betteruptime'          => array('Better Stack', 'monitor'),
        'jetpack'               => array('Jetpack', 'monitor'),
        'wordpress/'            => array('WordPress', 'monitor'),
        'wp-rocket'             => array('WP Rocket', 'monitor'),
        'litespeed'             => array('LiteSpeed Cache', 'monitor'),
        'facebookexternalhit'   => array('Facebook', 'social'),
        'facebookcatalog'       => array('Facebook', 'social'),
        'twitterbot'            => array('X (Twitter)', 'social'),
        'linkedinbot'           => array('LinkedIn', 'social'),
        'pinterest'             => array('Pinterest', 'social'),
        'slackbot'              => array('Slack', 'social'),
        'discordbot'            => array('Discord', 'social'),
        'telegrambot'           => array('Telegram', 'social'),
        'whatsapp'              => array('WhatsApp', 'social'),
        'skypeuripreview'       => array('Skype', 'social'),
        'redditbot'             => array('Reddit', 'social'),
        'embedly'               => array('Embedly', 'social'),
        'ia_archiver'           => array('Internet Archive', 'other'),
        'archive.org_bot'       => array('Internet Archive', 'other'),
    );

    /** Name of bots that match no known crawler. */
    const OTHER = 'Other bot';

    /** Most rows of a kind. */
    const MAX_LIMIT = 100;

    /** @var bool Whether shutdown should record this request. */
    private static $pending = false;

    /** @var string This request's user agent, when it is a bot's. */
    private static $agent = '';

    /**
     * Register hooks (front-end requests from bots only). The settings are
     * read in note(), not here: this runs while plugins load, before
     * translations may load.
     */
    public static function init() {
        // One check of the user agent; nothing else for people's requests.
        $agent = isset($_SERVER['HTTP_USER_AGENT']) ? substr((string) wp_unslash($_SERVER['HTTP_USER_AGENT']), 0, 512) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- compared with fixed texts only; never stored or printed.
        if ($agent === '' || preg_match(SEOProAnalytics_UA::BOT_PATTERN, $agent) !== 1) {
            return;
        }
        self::$agent = $agent;
        add_action('template_redirect', array(__CLASS__, 'note'), 0);
        add_action('do_robots', array(__CLASS__, 'note'), 0);
        add_action('shutdown', array(__CLASS__, 'record'), 0);
    }

    /**
     * template_redirect: this is a front-end request (not admin, AJAX,
     * REST or cron), to be recorded at shutdown while counting.
     */
    public static function note() {
        self::$pending = !wp_doing_ajax() && !wp_doing_cron() && !(defined('REST_REQUEST') && REST_REQUEST)
            && SEOProAnalytics_Statistics::collecting() && SEOProAnalytics_Statistics::crawlers();
    }

    /**
     * The crawler a user agent names.
     *
     * @param string $agent User agent.
     * @return array{0:string,1:string} Name, group.
     */
    public static function identify($agent) {
        $agent = strtolower((string) $agent);
        foreach (self::CRAWLERS as $text => $crawler) {
            if (strpos($agent, $text) !== false) {
                return $crawler;
            }
        }
        return array(self::OTHER, 'other');
    }

    /**
     * shutdown: add this request to its day, crawler, page and status.
     */
    public static function record() {
        global $wpdb;
        if (!self::$pending) {
            return;
        }
        self::$pending = false;
        $before = SEOProAnalytics_Schema::use_set('live');
        if (!SEOProAnalytics_Schema::is_current()) {
            SEOProAnalytics_Schema::use_set($before);
            return;
        }
        require_once __DIR__ . '/class-seoproanalytics-dict.php';
        $uri  = isset($_SERVER['REQUEST_URI']) ? (string) wp_unslash($_SERVER['REQUEST_URI']) : '/'; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- stored as a dictionary text through prepared queries.
        $path = (string) wp_parse_url($uri, PHP_URL_PATH);
        $path = substr($path === '' ? '/' : $path, 0, 300);
        $code = http_response_code();
        $code = is_int($code) ? max(0, min(999, $code)) : 200;
        list($name, $group) = self::identify(self::$agent);
        // Scanners probe thousands of made-up addresses: a page not found
        // is kept by address only for search engines and AI crawlers
        // (links they follow); others' count as one page (path id 0).
        if ($code === 404 && !in_array($group, array('search', 'ai'), true)) {
            $path = '';
        }
        $bot  = SEOProAnalytics_Dict::ids(SEOProAnalytics_Schema::DICT_BOT, array($name));
        $page = SEOProAnalytics_Dict::ids(SEOProAnalytics_Schema::DICT_PATH, array($path));
        $now  = time();
        $day  = (new DateTimeImmutable('@' . $now))->setTimezone(wp_timezone())->setTime(0, 0)->getTimestamp();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- our own table by its primary key; one row per day, crawler, page and status.
        $wpdb->query($wpdb->prepare(
            'INSERT INTO %i (ts, bot_id, path_id, status, hits, last) VALUES (%d, %d, %d, %d, 1, %d) ON DUPLICATE KEY UPDATE hits = hits + 1, last = VALUES(last)',
            SEOProAnalytics_Schema::table('bots'),
            $day,
            (int) ($bot[SEOProAnalytics_Dict::clean($name)] ?? 0),
            (int) ($page[SEOProAnalytics_Dict::clean($path)] ?? 0),
            $code,
            $now
        ));
        SEOProAnalytics_Schema::use_set($before);
    }

    /**
     * The crawler visits report.
     *
     * @param array<string,mixed> $req   From SEOProAnalytics_Query::request(); only the range and compare apply.
     * @param string              $kind  One of KINDS.
     * @param string              $page  Only this page (path; * for any text); '' for all.
     * @param string              $group Only this group of crawler (GROUPS); '' for all.
     * @param string              $bot   Only this crawler (its name); '' for all.
     * @return array<string,mixed>
     */
    public static function report(array $req, $kind = 'bots', $page = '', $group = '', $bot = '') {
        $kind  = in_array($kind, self::KINDS, true) ? (string) $kind : 'bots';
        $group = in_array($group, self::GROUPS, true) ? (string) $group : '';
        $page  = trim((string) $page);
        $bot   = trim((string) $bot);
        // Crawler rows are written outside processing, which versions the cache: a new entry every 5 minutes.
        $ask   = $req + array('kind' => $kind, 'page' => $page, 'group' => $group, 'bot' => $bot, 'slot' => (int) floor(time() / 300));
        return SEOProAnalytics_Query::cached('crawlers', $ask, static function () use ($req, $kind, $page, $group, $bot) {
            $range  = SEOProAnalytics_Query::range($req);
            $scope  = self::scope($range, $page, $group, $bot);
            $totals = self::totals($scope);
            $answer = array(
                'range'   => SEOProAnalytics_Query::range_out($range),
                'kind'    => $kind,
                'page'    => $page,
                'group'   => $group,
                'bot'     => $bot,
                'enabled' => SEOProAnalytics_Statistics::crawlers(),
                'totals'  => $totals,
                'rows'    => self::rows($scope, $kind, min(self::MAX_LIMIT, (int) $req['limit']), (int) $req['offset']),
            );
            $other = SEOProAnalytics_Query::compare_range($range, $req['compare']);
            if ($other) {
                $then              = self::totals(self::scope($other, $page, $group, $bot));
                $answer['compare'] = array(
                    'range'  => SEOProAnalytics_Query::range_out($other),
                    'totals' => $then,
                    'change' => SEOProAnalytics_Query::change($totals, $then),
                );
            }
            return $answer;
        });
    }

    /**
     * Requests by crawler group for one page over the last 30 days, for
     * the toolbar (SEOProAnalytics_Toolbar).
     *
     * @param string $path Page path.
     * @return array{hits:int,ai:int,search:int,last:int}
     */
    public static function page_summary($path) {
        $req = SEOProAnalytics_Query::request(array('range' => '30d', 'compare' => 'none', 'limit' => self::MAX_LIMIT));
        if (is_wp_error($req)) {
            return array('hits' => 0, 'ai' => 0, 'search' => 0, 'last' => 0);
        }
        $rows = self::report($req, 'bots', $path)['rows'];
        $out  = array('hits' => 0, 'ai' => 0, 'search' => 0, 'last' => 0);
        foreach ($rows as $row) {
            $out['hits'] += $row['hits'];
            $out['last']  = max($out['last'], $row['last']);
            if (isset($out[$row['group']])) {
                $out[$row['group']] += $row['hits'];
            }
        }
        return $out;
    }

    /**
     * The shared FROM and WHERE of a period.
     *
     * @param array<string,mixed> $range From SEOProAnalytics_Query::range().
     * @param string              $page  Page path, or ''.
     * @param string              $group Group, or ''.
     * @param string              $bot   Crawler name, or ''.
     * @return array{sql:string,args:array<int,mixed>}|null Null when nothing can match.
     */
    private static function scope(array $range, $page, $group, $bot) {
        $where = '';
        $args  = array(SEOProAnalytics_Schema::table('bots'), self::day_start((int) $range['from']), (int) $range['to']);
        if ($page !== '') {
            $ids = array_map('intval', SEOProAnalytics_Query::dict_ids(SEOProAnalytics_Schema::DICT_PATH, array(
                'dimension' => 'page',
                'op'        => strpos($page, '*') !== false ? 'matches' : 'is',
                'values'    => array($page),
            )));
            if (!$ids) {
                return null;
            }
            $where .= ' AND b.path_id IN (' . implode(', ', array_fill(0, count($ids), '%d')) . ')';
            $args   = array_merge($args, $ids);
        }
        $names = array();
        if ($bot !== '') {
            $names = array($bot);
        } elseif ($group !== '') {
            foreach (self::CRAWLERS as $crawler) {
                if ($crawler[1] === $group) {
                    $names[$crawler[0]] = true;
                }
            }
            $names = array_keys($names);
            if ($group === 'other') {
                $names[] = self::OTHER;
            }
        }
        if ($names) {
            $ids = array_values(array_filter(array_map('intval', SEOProAnalytics_Dict::find(SEOProAnalytics_Schema::DICT_BOT, $names))));
            if (!$ids) {
                return null;
            }
            $where .= ' AND b.bot_id IN (' . implode(', ', array_fill(0, count($ids), '%d')) . ')';
            $args   = array_merge($args, $ids);
        }
        return array('sql' => "FROM %i b WHERE b.ts >= %d AND b.ts < %d$where", 'args' => $args);
    }

    /**
     * The start of the site-local day of a time: rows are by day.
     *
     * @param int $ts Time.
     * @return int
     */
    private static function day_start($ts) {
        return (new DateTimeImmutable('@' . $ts))->setTimezone(wp_timezone())->setTime(0, 0)->getTimestamp();
    }

    /**
     * Totals of a period.
     *
     * @param array{sql:string,args:array<int,mixed>}|null $scope From scope().
     * @return array{hits:int,crawlers:int,pages:int,errors:int,redirects:int,not_found:int}
     */
    private static function totals($scope) {
        global $wpdb;
        $out = array('hits' => 0, 'crawlers' => 0, 'pages' => 0, 'errors' => 0, 'redirects' => 0, 'not_found' => 0);
        if ($scope === null) {
            return $out;
        }
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, PluginCheck.Security.DirectDB.UnescapedDBParameter -- our own table by its primary key's leading ts; the scope holds only placeholders.
        $row = (array) $wpdb->get_row($wpdb->prepare("SELECT SUM(b.hits) AS hits, COUNT(DISTINCT b.bot_id) AS crawlers, COUNT(DISTINCT b.path_id) AS pages, SUM(IF(b.status >= 500, b.hits, 0)) AS errors, SUM(IF(b.status >= 300 AND b.status < 400, b.hits, 0)) AS redirects, SUM(IF(b.status >= 400 AND b.status < 500, b.hits, 0)) AS not_found {$scope['sql']}", $scope['args']), ARRAY_A);
        foreach (array_keys($out) as $key) {
            $out[$key] = isset($row[$key]) ? (int) $row[$key] : 0;
        }
        return $out;
    }

    /**
     * Rows of one kind.
     *
     * @param array{sql:string,args:array<int,mixed>}|null $scope  From scope().
     * @param string                                       $kind   One of KINDS.
     * @param int                                          $limit  Rows.
     * @param int                                          $offset Rows skipped.
     * @return array<int,array<string,mixed>>
     */
    private static function rows($scope, $kind, $limit, $offset) {
        global $wpdb;
        if ($scope === null) {
            return array();
        }
        $by   = array('bots' => 'b.bot_id', 'pages' => 'b.path_id', 'statuses' => 'b.status', 'days' => 'b.ts')[$kind];
        $sort = $kind === 'days' ? 'b.ts' : 'hits DESC, ' . $by;
        $args = array_merge($scope['args'], array(max(1, $limit), max(0, $offset)));
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, PluginCheck.Security.DirectDB.UnescapedDBParameter -- as in totals(); $by and $sort are fixed column names.
        $rows = (array) $wpdb->get_results($wpdb->prepare("SELECT $by AS k, SUM(b.hits) AS hits, COUNT(DISTINCT b.path_id) AS pages, COUNT(DISTINCT b.bot_id) AS crawlers, MAX(b.last) AS last, SUM(IF(b.status >= 400, b.hits, 0)) AS failed {$scope['sql']} GROUP BY $by ORDER BY $sort LIMIT %d OFFSET %d", $args), ARRAY_A);
        $text = in_array($kind, array('bots', 'pages'), true) ? SEOProAnalytics_Query::texts(array_map('intval', array_column($rows, 'k'))) : array();
        $out  = array();
        foreach ($rows as $row) {
            $key  = (int) $row['k'];
            $item = array(
                'hits'     => (int) $row['hits'],
                'pages'    => (int) $row['pages'],
                'crawlers' => (int) $row['crawlers'],
                'failed'   => (int) $row['failed'],
                'last'     => (int) $row['last'],
            );
            if ($kind === 'bots') {
                $name  = isset($text[$key]) ? $text[$key] : self::OTHER;
                $item  = array('name' => $name, 'group' => self::group_of($name)) + $item;
            } elseif ($kind === 'pages') {
                // Path id 0: pages not found, asked for by bots other than search engines and AI crawlers.
                $item = array('path' => isset($text[$key]) ? $text[$key] : '', 'not_found' => $key === 0) + $item;
            } elseif ($kind === 'statuses') {
                $item = array('status' => $key) + $item;
            } else {
                $item = array('date' => wp_date('Y-m-d', $key)) + $item;
            }
            $out[] = $item;
        }
        return $out;
    }

    /**
     * The group of a crawler's name.
     *
     * @param string $name Name.
     * @return string
     */
    private static function group_of($name) {
        foreach (self::CRAWLERS as $crawler) {
            if ($crawler[0] === $name) {
                return $crawler[1];
            }
        }
        return 'other';
    }
}

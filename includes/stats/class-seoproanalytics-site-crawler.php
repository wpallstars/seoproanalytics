<?php
/**
 * Opt-in, same-origin HTML crawl; external targets need separate consent.
 * No visitor hooks. Rows feed the existing latest-state crawl importer.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 * Additional terms (GPL-3.0 section 7(b)): ATTRIBUTION.txt
 * Provenance: wpas-c8662019b99fb07b. Copies, even of part, keep these lines.
 *
 * @package SEOProAnalytics
 */

if (!defined('ABSPATH')) {
    exit;
}

final class SEOProAnalytics_Site_Crawler {
    const OPTION = 'seoproanalytics_site_crawler';
    const QUOTA = 'seoproanalytics_site_crawler_quota';
    const READY = 'seoproanalytics_site_crawler_readiness';
    const HOOK = 'seoproanalytics_site_crawler';
    const PAGES = 5000;
    const LINKS = 10000;
    const DEPTH = 10;
    const BUDGET = 15;
    const DAILY = 1000;
    const BYTES = 1048576;

    /** Register only callbacks; never read job options on a visitor page. */
    public static function init() {
        add_filter('pre_update_option_' . SEOProAnalytics_Settings::OPTION, array(__CLASS__, 'validate'), 10, 2);
        add_action('seoproanalytics_setting_panel', array(__CLASS__, 'panel'));
        add_filter('site_status_tests', array(__CLASS__, 'health_tests'));
    }

    /** @return string Identifiable HTTP user agent. */
    private static function agent() {
        return 'SEO Pro Analytics (+' . home_url('/') . ')';
    }

    /** @return array<string,mixed> Private persisted job. */
    private static function job() {
        $job = get_option(self::OPTION, array());
        return is_array($job) ? $job : array();
    }

    /** @return array<string,mixed> Public progress, without the graph or HTML. */
    public static function status() {
        $job = self::job();
        return array('status' => $job['status'] ?? 'idle', 'started' => $job['started'] ?? 0,
            'pages' => $job['page_count'] ?? count($job['pages'] ?? array()), 'links' => $job['link_count'] ?? count($job['links'] ?? array()),
            'queued' => count($job['queue'] ?? array()), 'complete' => $job['complete'] ?? false,
            'import_id' => $job['import_id'] ?? 0, 'error' => $job['error'] ?? '',
            'enabled' => (bool) SEOProAnalytics_Settings::get('crawl_site'),
            'external' => (bool) SEOProAnalytics_Settings::get('crawl_external'));
    }

    /** @param callable $work Serialized state transition. @return mixed */
    private static function locked(callable $work) {
        global $wpdb;
        $key = 'seoproan-builtin-' . get_current_blog_id();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- job-owned connection lock, released on process death.
        if ((string) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 0)', $key)) !== '1') {
            return new WP_Error('seoproanalytics_crawl_busy', __('The built-in crawler is busy.', 'seoproanalytics'), array('status' => 409));
        }
        try {
            wp_cache_delete(self::OPTION, 'options');
            return $work();
        } finally {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- release only this job's lock.
            $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $key));
        }
    }

    /** @param string $url Address. @return bool Exact scheme, host and port, not tracking host aliases. */
    private static function own($url) {
        $parts = wp_parse_url($url);
        $home = wp_parse_url(home_url('/'));
        if (!is_array($parts) || !is_array($home) || isset($parts['user']) || isset($parts['pass'])) {
            return false;
        }
        foreach (array('scheme', 'host', 'port') as $key) {
            if (strtolower((string) ($parts[$key] ?? '')) !== strtolower((string) ($home[$key] ?? ''))) {
                return false;
            }
        }
        return true;
    }

    /** @param string $url Address. @param string $method GET or HEAD. @return array|WP_Error */
    private static function request($url, $method = 'GET') {
        $args = array('method' => $method, 'timeout' => 3, 'redirection' => 0, 'limit_response_size' => self::BYTES,
            'user-agent' => self::agent(), 'cookies' => array(), 'headers' => array('Accept' => 'text/html,*/*;q=0.1'));
        // Own-origin requests must work on private/local installations, too.
        // Redirects are NEVER automatic: each new own target is queued and checked.
        return self::own($url) ? wp_remote_request($url, $args) : wp_safe_remote_request($url, $args);
    }

    /**
     * Local diagnostics by default. Network probes require an explicit admin/CLI
     * action; the external probe additionally requires explicit external consent.
     *
     * @param bool $probe Probe the site's loopback.
     * @param bool $external Probe outbound HTTP too.
     * @return array<string,mixed>
     */
    public static function readiness($probe = false, $external = false) {
        require_once __DIR__ . '/class-seoproanalytics-crawl.php';
        require_once __DIR__ . '/class-seoproanalytics-crawl-import.php';
        $checks = array();
        $add = static function ($check, $status, $detail, $scope = 'site') use (&$checks) {
            $checks[] = compact('check', 'status', 'detail', 'scope');
        };
        $add('parser', class_exists('DOMDocument') ? 'pass' : 'fail', __('Enable the PHP DOM extension to parse HTML.', 'seoproanalytics'));
        $memory = wp_convert_hr_to_bytes((string) ini_get('memory_limit'));
        $add('memory', $memory === -1 || $memory >= 128 * MB_IN_BYTES ? 'pass' : 'fail', 'memory_limit=' . ini_get('memory_limit') . '; ' . __('Allow at least 128M.', 'seoproanalytics'));
        $seconds = (int) ini_get('max_execution_time');
        $add('time', $seconds === 0 || $seconds >= 20 ? 'pass' : 'fail', 'max_execution_time=' . $seconds . '; ' . __('Allow at least 20 seconds for a batch.', 'seoproanalytics'));
        $curl = function_exists('curl_version') ? curl_version() : array();
        $add('HTTP transport', $curl && !empty($curl['ssl_version']) ? 'pass' : 'warn', $curl ? 'curl ' . $curl['version'] . ' / ' . ($curl['ssl_version'] ?? '') : __('Enable curl with SSL; WordPress may use streams instead.', 'seoproanalytics'));
        $late = SEOProAnalytics_Collection::jobs_late();
        $add('cron', $late !== null && $late < SEOProAnalytics_Collection::JOBS_LATE ? 'pass' : 'warn', __('Run WordPress cron regularly; check late or missing jobs.', 'seoproanalytics'));
        if (SEOProAnalytics_Collection::wp_cron_off()) {
            $add('server cron', 'warn', __('DISABLE_WP_CRON is set. Verify a real server cron runs wp-cron.php; the plugin cannot inspect the server crontab.', 'seoproanalytics'));
        }
        foreach (SEOProAnalytics_Crawl::sizes() as $name => $size) {
            $limit = $name === 'crawl_pages' ? SEOProAnalytics_Crawl_Import::MAX_PAGES : SEOProAnalytics_Crawl_Import::MAX_LINKS;
            $add($name, (int) $size['rows'] < $limit ? 'pass' : 'warn', sprintf('%d estimated rows / %d row import cap; data %d bytes. Check database quota with your host.', $size['rows'], $limit, $size['data']));
        }
        $loop = $probe ? self::request(home_url('/')) : null;
        $code = is_array($loop) ? wp_remote_retrieve_response_code($loop) : 0;
        /* translators: %d: HTTP status code, zero when no answer. */
        $add('loopback', $probe ? ($code >= 200 && $code < 300 ? 'pass' : 'fail') : 'warn', $probe ? sprintf(__('HTTP %d. For 401/403/429, allow-list the SEO Pro Analytics user agent and remove loopback authentication/firewall blocks.', 'seoproanalytics'), $code) : __('Not probed. Open the Crawl this site card to check this server.', 'seoproanalytics'));
        $blocked = defined('WP_HTTP_BLOCK_EXTERNAL') && WP_HTTP_BLOCK_EXTERNAL;
        $http = new WP_Http();
        $policy_blocks = $http->block_request('https://status.search.google.com/incidents.json');
        $add('outbound policy', $policy_blocks ? 'fail' : 'pass', 'WP_HTTP_BLOCK_EXTERNAL=' . ($blocked ? 'true' : 'false') . '; WP_ACCESSIBLE_HOSTS=' . (defined('WP_ACCESSIBLE_HOSTS') ? (string) WP_ACCESSIBLE_HOSTS : '') . '; ' . __('Allow the readiness host and intended link targets before enabling external checks.', 'seoproanalytics'), 'external');
        // This existing search-status endpoint is already documented by the plugin.
        $out = $probe && $external ? self::request('https://status.search.google.com/incidents.json', 'HEAD') : null;
        $code = is_array($out) ? wp_remote_retrieve_response_code($out) : 0;
        /* translators: %d: HTTP status code, zero when no answer. */
        $add('outbound probe', $probe && $external ? ($code >= 200 && $code < 400 ? 'pass' : 'fail') : 'warn', $probe && $external ? sprintf(__('HTTP %d. Allow outbound HTTPS in WordPress and the host firewall.', 'seoproanalytics'), $code) : __('Not probed. Explicitly check external readiness before enabling external links.', 'seoproanalytics'), 'external');
        $site = $probe;
        $outside = $probe && $external;
        foreach ($checks as $check) {
            if ($check['status'] === 'fail') {
                if ($check['scope'] === 'site') {
                    $site = false;
                } else {
                    $outside = false;
                }
            }
        }
        $answer = array('site' => $site, 'external' => $site && $outside, 'checks' => $checks, 'checked' => time());
        if ($probe) {
            update_option(self::READY, $answer, false);
        }
        return $answer;
    }

    /** CLI opt-in with a narrowly scoped readiness override; external consent is never inferred.
     * @param bool $external Explicit external consent. @param bool $force Override readiness. @return void
     */
    public static function enable($external, $force) {
        $GLOBALS['seoproanalytics_crawl_force'] = $force && defined('WP_CLI') && WP_CLI;
        try {
            SEOProAnalytics_Settings::set('crawl_site', true);
            if ($external) {
                SEOProAnalytics_Settings::set('crawl_external', true);
            }
        } finally {
            unset($GLOBALS['seoproanalytics_crawl_force']);
        }
    }

    /** @param mixed $value New settings. @param mixed $old Old settings. @return mixed */
    public static function validate($value, $old) {
        if (!is_array($value)) {
            return $value;
        }
        $site = !empty($value['crawl_site']);
        $external = $site && !empty($value['crawl_external']);
        $force = defined('WP_CLI') && WP_CLI && isset($GLOBALS['seoproanalytics_crawl_force']) && $GLOBALS['seoproanalytics_crawl_force'] === true;
        if (!$force && (($site && empty($old['crawl_site'])) || ($external && empty($old['crawl_external'])))) {
            $ready = self::readiness(true, $external);
            $site = $ready['site'];
            $external = $external && $ready['external'];
            if (!$site || (!empty($value['crawl_external']) && !$external)) {
                add_settings_error(SEOProAnalytics_Settings::OPTION, 'crawl_readiness', __('Crawl readiness failed. Open Crawl this site for the reasons and fixes.', 'seoproanalytics'));
            }
        }
        $value['crawl_site'] = $site;
        $value['crawl_external'] = $site && $external;
        return $value;
    }

    /** @param bool $force CLI-only readiness override, never consent. @return array<string,mixed>|WP_Error */
    public static function start($force = false) {
        return self::locked(static function () use ($force) {
            if (!SEOProAnalytics_Settings::get('crawl_site') || SEOProAnalytics_Schema::set() !== 'live') {
                return new WP_Error('seoproanalytics_crawl_disabled', __('Enable Crawl this site on live data first.', 'seoproanalytics'), array('status' => 409));
            }
            if (in_array(self::status()['status'], array('running', 'importing'), true)) {
                return new WP_Error('seoproanalytics_crawl_busy', __('A built-in crawl is already running.', 'seoproanalytics'), array('status' => 409));
            }
            $external = (bool) SEOProAnalytics_Settings::get('crawl_external');
            $ready = self::readiness(true, $external);
            if (!($force && defined('WP_CLI') && WP_CLI) && (!$ready['site'] || ($external && !$ready['external']))) {
                return new WP_Error('seoproanalytics_crawl_readiness', __('Server readiness failed. Check doctor or the Crawl this site card.', 'seoproanalytics'), array('status' => 409, 'readiness' => $ready));
            }
            update_option(self::OPTION, array('status' => 'running', 'started' => time(), 'progress' => time(), 'home' => home_url('/'), 'phase' => 'robots',
                'queue' => array(array(home_url('/'), 0)), 'seen' => array(home_url('/') => true), 'pages' => array(), 'links' => array(),
                'targets' => array(), 'robots' => array(), 'limited' => false, 'external' => $external), false);
            wp_schedule_single_event(time() + 1, self::HOOK);
            return self::status();
        });
    }

    /** @return array<string,mixed>|WP_Error Stop only collection; a staged import is owned by the importer. */
    public static function stop() {
        return self::locked(static function () {
            $job = self::job();
            $job = array_intersect_key($job, array_flip(array('started', 'import_id')));
            $job['status'] = 'stopped';
            update_option(self::OPTION, $job, false);
            wp_clear_scheduled_hook(self::HOOK);
            return self::status();
        });
    }

    /** Cron runner: all requests fit a bounded batch and schedule recovery first. */
    public static function run() {
        self::locked(static function () {
            $job = self::job();
            if (!in_array($job['status'] ?? '', array('running', 'importing'), true)) {
                return;
            }
            if (!SEOProAnalytics_Settings::get('crawl_site') || ($job['home'] ?? '') !== home_url('/') || (int) ($job['progress'] ?? $job['started']) < time() - DAY_IN_SECONDS) {
                update_option(self::OPTION, array('status' => 'stopped', 'error' => __('Crawl disabled, expired or site address changed. Start a fresh run.', 'seoproanalytics')), false);
                wp_clear_scheduled_hook(self::HOOK);
                return;
            }
            if (!wp_next_scheduled(self::HOOK)) {
                wp_schedule_single_event(time() + MINUTE_IN_SECONDS, self::HOOK);
            }
            if ($job['status'] === 'importing') {
                require_once __DIR__ . '/class-seoproanalytics-crawl-import.php';
                $import = SEOProAnalytics_Crawl_Import::status();
                if ((int) $import['id'] !== (int) $job['import_id']) {
                    $job['status'] = 'superseded';
                    update_option(self::OPTION, $job, false);
                    wp_clear_scheduled_hook(self::HOOK);
                    return;
                }
                $import = SEOProAnalytics_Crawl_Import::run(self::BUDGET);
                $job['progress'] = time();
                if (!in_array($import['status'], array('running', 'restoring'), true)) {
                    $job['status'] = $import['status'];
                    $job['error'] = $import['error'] ?? '';
                    wp_clear_scheduled_hook(self::HOOK);
                }
                update_option(self::OPTION, $job, false);
                return;
            }
            $until = microtime(true) + self::BUDGET;
            if ($job['phase'] === 'robots') {
                $parts = wp_parse_url(home_url('/')) ?: array();
                if (empty($parts['scheme']) || empty($parts['host'])) {
                    return;
                }
                $root = $parts['scheme'] . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');
                $response = self::request($root . '/robots.txt');
                $code = is_array($response) ? wp_remote_retrieve_response_code($response) : 0;
                if ($code !== 404 && ($code < 200 || $code >= 300)) {
                    $job = array('status' => 'failed', 'error' => __('Could not read robots.txt safely. Allow the crawler user agent, then start again.', 'seoproanalytics'));
                } else {
                    $job['robots'] = self::robots(wp_remote_retrieve_body($response));
                    $job['phase'] = 'pages';
                    usleep(500000);
                }
            }
            while (($job['status'] ?? '') === 'running' && $job['queue'] && microtime(true) + 4 < $until) {
                list($url, $depth) = array_shift($job['queue']);
                if (!self::allowed($url, $job['robots'])) {
                    $job['limited'] = true;
                    continue;
                }
                if (!self::room(48 * MB_IN_BYTES)) {
                    $job['limited'] = true;
                    $job['queue'] = array();
                    break;
                }
                self::page($url, $depth, $job);
                $job['progress'] = time();
                // Persist each fetched page: a crash can repeat one request, not the run.
                update_option(self::OPTION, $job, false);
                usleep(500000);
                if (count($job['pages']) >= self::PAGES || strlen((string) wp_json_encode($job)) > 12 * MB_IN_BYTES) {
                    $job['limited'] = true;
                    $job['queue'] = array();
                }
            }
            if (($job['status'] ?? '') === 'running' && !$job['queue'] && microtime(true) + 8 < $until) {
                self::finish($job);
            }
            update_option(self::OPTION, $job, false);
            if (!in_array($job['status'], array('running', 'importing'), true)) {
                wp_clear_scheduled_hook(self::HOOK);
            }
        });
    }

    /** @param string $text robots.txt. @return array<int,array<string,mixed>> Selected rules. */
    private static function robots($text) {
        $groups = array();
        $index = -1;
        $last = '';
        foreach (preg_split('/\r?\n/', $text) ?: array() as $line) {
            $line = trim(explode('#', $line, 2)[0]);
            if ($line === '') {
                continue;
            }
            $pair = explode(':', $line, 2);
            if (count($pair) !== 2) {
                continue;
            }
            $name = strtolower(trim($pair[0]));
            $value = trim($pair[1]);
            if ($name === 'user-agent') {
                if ($last !== 'user-agent') {
                    ++$index;
                    $groups[$index] = array('agents' => array(), 'rules' => array());
                }
                $groups[$index]['agents'][] = strtolower($value);
            } elseif ($index >= 0 && in_array($name, array('allow', 'disallow'), true) && $value !== '') {
                $groups[$index]['rules'][] = array('allow' => $name === 'allow', 'path' => substr($value, 0, 2048));
            }
            $last = $name;
        }
        $selected = array();
        $specific = false;
        foreach ($groups as $group) {
            $match = false;
            foreach ($group['agents'] as $agent) {
                if ($agent !== '*' && $agent !== '' && (strpos('seo pro analytics', $agent) !== false || strpos('seoproanalytics', $agent) !== false)) {
                    $match = true;
                }
            }
            if ($match && !$specific) {
                $selected = array();
                $specific = true;
            }
            if ($match || (!$specific && in_array('*', $group['agents'], true))) {
                $selected = array_merge($selected, $group['rules']);
            }
        }
        return $selected;
    }

    /** @param string $url Target. @param array $rules Selected robots rules. @return bool */
    private static function allowed($url, array $rules) {
        $path = (string) wp_parse_url($url, PHP_URL_PATH);
        $length = -1;
        $allow = true;
        foreach ($rules as $rule) {
            $pattern = str_replace('\*', '.*', preg_quote($rule['path'], '~'));
            $pattern = substr($pattern, -2) === '\$' ? substr($pattern, 0, -2) . '$' : $pattern;
            if (preg_match('~^' . $pattern . '~', $path) && (strlen($rule['path']) > $length || (strlen($rule['path']) === $length && $rule['allow']))) {
                $length = strlen($rule['path']);
                $allow = $rule['allow'];
            }
        }
        return $allow;
    }

    /** @param string $url Target. @param int $depth Depth. @param array $job Job. */
    private static function enqueue($url, $depth, array &$job) {
        if (!self::own($url) || wp_parse_url($url, PHP_URL_QUERY) !== null || isset($job['seen'][$url])) {
            return;
        }
        if ($depth > self::DEPTH || count($job['seen']) >= self::PAGES) {
            $job['limited'] = true;
            return;
        }
        $job['seen'][$url] = true;
        $job['queue'][] = array($url, $depth);
    }

    /** @param string $url Page. @param int $depth Depth. @param array $job Job. */
    private static function page($url, $depth, array &$job) {
        $start = microtime(true);
        $response = self::request($url);
        $code = is_array($response) ? wp_remote_retrieve_response_code($response) : 0;
        $body = wp_remote_retrieve_body($response);
        $row = array('url' => $url, 'status' => $code, 'depth' => $depth, 'response_time_ms' => (int) ((microtime(true) - $start) * 1000), 'size' => strlen($body));
        $type = self::header($response, 'content-type');
        $location = self::header($response, 'location');
        if ($code >= 300 && $code < 400) {
            $target = self::resolve($location, $url);
            $row['redirect_url'] = $target;
            if ($location === '' || !self::own($target) || wp_parse_url($target, PHP_URL_QUERY) !== null || isset($job['seen'][$target])) {
                // A forbidden, ambiguous or looping redirect is not full discovery.
                $job['limited'] = true;
            } else {
                self::enqueue($target, $depth, $job);
            }
        }
        if ($code < 200 || $code >= 400) {
            $job['limited'] = true;
        }
        if ($body !== '' && ($type === '' || stripos($type, 'html') !== false) && $code >= 200 && $code < 300) {
            $previous = libxml_use_internal_errors(true);
            $dom = new DOMDocument();
            $loaded = $dom->loadHTML('<?xml encoding="UTF-8">' . $body, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
            if ($loaded) {
                self::html($dom, $url, $depth, $response, $row, $job);
            } else {
                $job['limited'] = true;
            }
            if (strlen($body) >= self::BYTES) {
                $job['limited'] = true;
            }
        }
        $job['pages'][] = $row;
    }

    /** @param string $href Reference. @param string $base Base. @return string Safe HTTP address without a fragment. */
    private static function resolve($href, $base) {
        $url = WP_Http::make_absolute_url(trim($href), $base);
        $url = explode('#', $url, 2)[0];
        $parts = wp_parse_url($url);
        return is_array($parts) && in_array($parts['scheme'] ?? '', array('http', 'https'), true) && !isset($parts['user']) && !isset($parts['pass']) && strlen($url) <= 2048 ? $url : '';
    }

    /** @param array|WP_Error $response HTTP answer. @param string $name Header. @return string */
    private static function header($response, $name) {
        $value = wp_remote_retrieve_header($response, $name);
        return is_array($value) ? implode(', ', $value) : $value;
    }

    /** @param DOMDocument $dom HTML. @param string $url Page. @param int $depth Depth. @param array|WP_Error $response HTTP. @param array $row Page row. @param array $job Job. */
    private static function html(DOMDocument $dom, $url, $depth, $response, array &$row, array &$job) {
        $title = $dom->getElementsByTagName('title')->item(0);
        $row['title'] = substr($title ? $title->textContent : '', 0, 1200);
        $row['canonical'] = $url;
        $base = $url;
        $base_tag = $dom->getElementsByTagName('base')->item(0);
        if ($base_tag instanceof DOMElement) {
            $base = self::resolve($base_tag->getAttribute('href'), $url) ?: $url;
        }
        $row['robots'] = self::header($response, 'x-robots-tag');
        foreach ($dom->getElementsByTagName('meta') as $meta) {
            if (in_array(strtolower($meta->getAttribute('name')), array('robots', 'seoproanalytics', 'seo pro analytics'), true)) {
                $row['robots'] .= ',' . $meta->getAttribute('content');
            }
        }
        foreach ($dom->getElementsByTagName('link') as $link) {
            if (strtolower($link->getAttribute('rel')) === 'canonical') {
                $row['canonical'] = self::resolve($link->getAttribute('href'), $base);
                break;
            }
        }
        $follow = !preg_match('/\b(nofollow|noindex|none)\b/i', $row['robots']);
        foreach ($dom->getElementsByTagName('a') as $link) {
            $target = self::resolve($link->getAttribute('href'), $base);
            if ($target === '') {
                continue;
            }
            if (count($job['links']) >= self::LINKS) {
                $job['limited'] = true;
                break;
            }
            $follow_link = $follow && !preg_match('/\b(nofollow|sponsored|ugc)\b/i', $link->getAttribute('rel'));
            if (!$follow_link && self::own($target)) {
                $job['limited'] = true;
            }
            $job['links'][] = array('source_url' => $url, 'target_url' => $target, 'anchor' => substr(trim($link->textContent), 0, 120), 'follow' => $follow_link);
            if ($follow_link && self::own($target)) {
                self::enqueue($target, $depth + 1, $job);
            } elseif ($follow_link && $job['external'] && !self::own($target)) {
                $job['targets'][$target] = $job['targets'][$target] ?? null;
            }
        }
    }

    /** @param array $job Job. Import staged observations, never treating unchecked targets as successful. */
    private static function finish(array &$job) {
        if (!$job['pages']) {
            $job = array('status' => 'failed', 'error' => __('No permitted page was fetched. Check robots.txt and loopback access.', 'seoproanalytics'));
            return;
        }
        if (!empty($job['targets']) && SEOProAnalytics_Settings::get('crawl_external')) {
            $until = microtime(true) + 3;
            $quota = get_option(self::QUOTA, array());
            $quota = is_array($quota) && ($quota['day'] ?? '') === gmdate('Y-m-d') ? $quota : array('day' => gmdate('Y-m-d'), 'count' => 0, 'hosts' => array(), 'targets' => array());
            foreach ($job['targets'] as $url => $status) {
                if ($status !== null) {
                    continue;
                }
                if (isset($quota['targets'][$url])) {
                    $job['targets'][$url] = $quota['targets'][$url];
                    continue;
                }
                $host = (string) wp_parse_url($url, PHP_URL_HOST);
                if ($quota['count'] >= self::DAILY) {
                    break;
                }
                if (microtime(true) > $until || ($quota['hosts'][$host] ?? 0) > microtime(true) - 0.5) {
                    update_option(self::QUOTA, $quota, false);
                    return;
                }
                ++$quota['count'];
                $quota['hosts'][$host] = microtime(true);
                // Charge and persist before requesting, even if the worker dies.
                // An interrupted attempt stays unknown, never a fabricated failure.
                $quota['targets'][$url] = '';
                update_option(self::QUOTA, $quota, false);
                $answer = self::request($url, 'HEAD');
                $code = is_array($answer) ? wp_remote_retrieve_response_code($answer) : 0;
                if (in_array($code, array(405, 501), true)) {
                    usleep(500000);
                    $answer = self::request($url);
                    $code = is_array($answer) ? wp_remote_retrieve_response_code($answer) : 0;
                }
                $job['targets'][$url] = $code;
                $job['progress'] = time();
                $quota['targets'][$url] = $code;
                $quota['hosts'][$host] = microtime(true);
                update_option(self::QUOTA, $quota, false);
                return; // One target per batch, including any GET fallback.
            }
            update_option(self::QUOTA, $quota, false);
        }
        require_once __DIR__ . '/class-seoproanalytics-crawl-import.php';
        if (!self::room(32 * MB_IN_BYTES)) {
            $job['status'] = 'failed';
            $job['error'] = __('Not enough memory to stage the crawl. Increase PHP memory_limit, then start again.', 'seoproanalytics');
            unset($job['pages'], $job['links'], $job['queue'], $job['seen'], $job['targets']);
            return;
        }
        $statuses = array();
        foreach ($job['pages'] as $row) {
            $statuses[$row['url']] = $row['status'];
        }
        foreach ($job['links'] as &$link) {
            $link['target_status'] = $statuses[$link['target_url']] ?? ($job['targets'][$link['target_url']] ?? '');
            unset($link['follow']);
        }
        unset($link);
        $complete = !$job['limited'] && count($job['pages']) < self::PAGES;
        $links = $job['links'] ?: array(array('source_url' => home_url('/'), 'target_url' => ''));
        $import = SEOProAnalytics_Crawl_Import::start(array(array('name' => 'builtin-pages.json', 'rows' => $job['pages']), array('name' => 'builtin-links.json', 'rows' => $links)), 'csv', $complete, array('verification_started' => $job['started']), 'builtin');
        if (is_wp_error($import)) {
            if ($import->get_error_code() === 'seoproanalytics_crawl_busy') {
                return;
            }
            $job['status'] = 'failed';
            $job['error'] = $import->get_error_message();
            return;
        }
        $job['status'] = 'importing';
        $job['import_id'] = $import['id'];
        $job['complete'] = $complete;
        $job['page_count'] = count($job['pages']);
        $job['link_count'] = count($job['links']);
        $job['progress'] = time();
        // No persistent duplicate graph once the importer owns the staged rows.
        $job['pages'] = array();
        $job['links'] = array();
        $job['targets'] = array();
        $job['seen'] = array();
    }

    /** Hourly scheduler, with a stable host-spread weekly slot. */
    public static function schedule() {
        if (!SEOProAnalytics_Settings::get('crawl_site') || SEOProAnalytics_Settings::get('crawl_schedule') !== 'weekly') {
            return;
        }
        $hash = (int) hexdec(substr(hash('sha256', (string) wp_parse_url(home_url('/'), PHP_URL_HOST)), 0, 7));
        if ((int) gmdate('w') !== $hash % 7 || (int) gmdate('G') !== (int) floor($hash / 7) % 24) {
            return;
        }
        $job = self::job();
        if ((int) ($job['started'] ?? 0) < time() - 6 * DAY_IN_SECONDS) {
            self::start();
        }
    }

    /** @param array $tests Site Health tests. @return array */
    public static function health_tests(array $tests) {
        $tests['direct']['seoproanalytics_crawl'] = array('label' => __('Built-in site crawl readiness', 'seoproanalytics'), 'test' => array(__CLASS__, 'health'));
        return $tests;
    }

    /** @return array Site Health result. No network probe on an ordinary health read. */
    public static function health() {
        $saved = get_option(self::READY, array());
        $ready = is_array($saved) && (int) ($saved['checked'] ?? 0) > time() - HOUR_IN_SECONDS ? $saved : self::readiness();
        $details = array();
        foreach ($ready['checks'] as $check) {
            $details[] = esc_html($check['check'] . ': ' . $check['status'] . ' — ' . $check['detail']);
        }
        return array('label' => __('Built-in crawl is opt-in; check readiness before enabling', 'seoproanalytics'), 'status' => 'recommended',
            'badge' => array('label' => 'SEO Pro Analytics', 'color' => 'blue'), 'description' => '<p>' . implode('<br>', $details) . '</p>', 'actions' => '', 'test' => 'seoproanalytics_crawl');
    }

    /** @param string $key Settings panel key. */
    public static function panel($key) {
        if ($key !== 'crawl_site') {
            return;
        }
        echo '<p>' . esc_html__('Check this server before switching on. External readiness contacts Google Search Status only after you explicitly request it. No JavaScript is executed by the crawler.', 'seoproanalytics') . '</p>';
        echo '<button type="button" class="button" id="seoproan-crawl-ready">' . esc_html__('Check this server', 'seoproanalytics') . '</button> ';
        echo '<button type="button" class="button" id="seoproan-crawl-external">' . esc_html__('Check external readiness (contacts Google)', 'seoproanalytics') . '</button>';
        echo '<pre id="seoproan-crawl-checks" aria-live="polite"></pre>';
        $config = array('url' => rest_url('seoproanalytics/v1/crawl/readiness'), 'nonce' => wp_create_nonce('wp_rest'));
        ?>
        <script>
        (function (config) {
            document.addEventListener('DOMContentLoaded', function () {
            const site = document.querySelector('[data-seoproan-setting="crawl_site"]');
            const external = document.querySelector('[data-seoproan-setting="crawl_external"]');
            const output = document.getElementById('seoproan-crawl-checks');
            if (!site || !external || !output) return;
            site.disabled = !site.checked;
            external.disabled = !external.checked;
            let externalReady = false;
            async function check(outside) {
                output.textContent = 'Checking…';
                try {
                    const response = await fetch(config.url, {method: 'POST', headers: {'Content-Type': 'application/json', 'X-WP-Nonce': config.nonce}, body: JSON.stringify({external: outside})});
                    if (!response.ok) throw new Error('Readiness request failed');
                    const data = await response.json();
                    externalReady = data.external;
                    site.disabled = !site.checked && !data.site;
                    external.disabled = !external.checked && !(data.external && site.checked);
                    output.textContent = data.checks.map(row => row.check + ': ' + row.status + ' — ' + row.detail).join('\n');
                } catch (error) { output.textContent = error.message; }
            }
            document.getElementById('seoproan-crawl-ready').addEventListener('click', () => check(false));
            document.getElementById('seoproan-crawl-external').addEventListener('click', () => check(true));
            site.addEventListener('change', () => { external.disabled = !external.checked && !(externalReady && site.checked); });
            const card = site.closest('.seoproan-setting');
            if (card) {
                const toggle = card.querySelector('[data-seoproan-panel-toggle]');
                if (toggle) toggle.addEventListener('click', () => { if (!output.textContent) check(false); });
            }
            });
        })(<?php echo wp_json_encode($config, JSON_HEX_TAG | JSON_HEX_AMP); ?>);
        </script>
        <?php
    }

    /** Remove only live crawler state, including daily external budgets. */
    public static function reset() {
        if (SEOProAnalytics_Schema::set() === 'live') {
            wp_clear_scheduled_hook(self::HOOK);
            delete_option(self::OPTION);
            delete_option(self::QUOTA);
            delete_option(self::READY);
        }
    }

    /** @param int $reserve Bytes needed for checkpoints and importer copies. @return bool */
    private static function room($reserve) {
        $limit = wp_convert_hr_to_bytes((string) ini_get('memory_limit'));
        return $limit === -1 || memory_get_usage(true) + $reserve < $limit;
    }
}

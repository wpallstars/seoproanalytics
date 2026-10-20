<?php
/**
 * WP-CLI commands: wp seoproanalytics stats, timeseries, breakdown, realtime,
 * goals, funnels, properties, clicks, search, changes, annotate, experiments, queue, loop, search-updates,
 * process, rollup, prune, doctor, demo and purge-caches. Reports come from
 * the same engine as the REST API, so the numbers match, on live data or
 * with --data=demo the demo data (docs/architecture.md → Interfaces).
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

/**
 * Read SEO Pro Analytics statistics and look after collection.
 *
 * ## EXAMPLES
 *
 *     wp seoproanalytics stats --range=30d --compare=prev
 *     wp seoproanalytics breakdown page --range=7d --filter=channel:is:organic_search
 *     wp seoproanalytics doctor
 */
final class SEOProAnalytics_CLI { // NOSONAR: WP-CLI discovers the public command facade; private steps preserve its compatible command surface.

    private const CHANGE_PERCENT_FORMAT = '%+.1f%%';
    private const PERCENT_FORMAT = '%.1f%%';
    private const CONNECTIONS_PATH = 'includes/stats/class-seoproanalytics-connections.php';
    private const SEARCH_IMPORT_PATH = 'includes/stats/class-seoproanalytics-search-import.php';
    private const CONFIG_PATH = '/config.php';
    private const DATA_OFF = 'off (SEO Pro Analytics → Settings → Data)';

    /**
     * WP-CLI makes this only to run one of these commands.
     */
    public function __construct() {
        SEOProAnalytics_API::short_floats();
    }

    /**
     * List open and recently resolved statistics alerts.
     *
     * ## OPTIONS
     *
     * [--run]
     * : Check health and send due emails now (administrator only).
     *
     * [--format=<format>]
     * : table or json. Default: table.
     *
     * @param string[] $args Positional arguments (unused).
     * @param array<string,string> $assoc Options.
     */
    public function alerts($args, $assoc) { // NOSONAR: WP-CLI supplies positional arguments to this command.
        require_once __DIR__ . '/class-seoproanalytics-alerts.php';
        if (isset($assoc['run'])) {
            if (!current_user_can('manage_options')) {
                WP_CLI::error('Use --user with an administrator for --run.');
            }
            SEOProAnalytics_Alerts::run();
        }
        $alerts = SEOProAnalytics_Alerts::open();
        if ($this->format($assoc) === 'json') {
            WP_CLI::line((string) wp_json_encode(array('alerts' => $alerts), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            WP_CLI\Utils\format_items('table', $alerts, array('key', 'message', 'since', 'sent', 'resolved', 'action'));
        }
    }

    /**
     * Print a tracker test token valid for fifteen minutes on this site.
     * Append it as the seoproanalytics_test query parameter to an uncached page.
     *
     * @subcommand test-token
     */
    public function test_token() {
        WP_CLI::line(SEOProAnalytics_Test_Mode::token());
    }

    /**
     * Show page experience field data (the add-on's field-data connection,
     * or the demo's).
     *
     * ## OPTIONS
     *
     * [<page>]
     * : Local page path; omit for monitored pages and origin history.
     *
     * [--data=<data>]
     * : live or demo.
     *
     * @param string[] $args Arguments.
     * @param array<string,string> $assoc Options.
     */
    public function vitals($args, $assoc) {
        SEOProAnalytics_API::load();
        $page = $args[0] ?? '';
        if ($page !== '' && SEOProAnalytics_Vitals::local_url($page) === '') {
            WP_CLI::error('Use a local page path.');
        }
        $answer = SEOProAnalytics_API::on_data($assoc['data'] ?? 'live', static function () use ($page) { return SEOProAnalytics_Vitals::report($page); });
        if (is_wp_error($answer)) {
            WP_CLI::error($answer->get_error_message());
        }
        WP_CLI::line((string) wp_json_encode($answer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    /**
     * Read notification status or queue and send this site's page addresses.
     *
     * ## OPTIONS
     *
     * [<action>]
     * : status (default), check (fetch the site's own key address now) or
     * send. Sending needs --user with an administrator.
     *
     * [<url>...]
     * : This site's addresses to send. The hourly allowance still applies.
     *
     * @param string[] $args Arguments.
     * @param array $assoc Options.
     */
    public function indexnow($args, $assoc) {
        unset($assoc);
        require_once __DIR__ . '/class-seoproanalytics-indexnow.php';
        $action = $args[0] ?? 'status';
        if ($action === 'send') {
            if (!SEOProAnalytics_Settings::can_change()) {
                WP_CLI::error('Use --user with an administrator to send notifications.');
            }
            if (!SEOProAnalytics_IndexNow::enabled()) {
                WP_CLI::error('Enable IndexNow under Settings → Data first.');
            }
            $urls = array_slice($args, 1);
            if (!$urls || count($urls) > SEOProAnalytics_IndexNow::LIMIT) {
                WP_CLI::error('Provide between 1 and 10,000 page addresses.');
            }
            foreach ($urls as $url) {
                if (SEOProAnalytics_IndexNow::url($url) === '') {
                    WP_CLI::error('Every address must be an HTTP(S) page on this site, without credentials or fragments.');
                }
            }
            SEOProAnalytics_IndexNow::enqueue($urls);
            SEOProAnalytics_IndexNow::run();
        } elseif ($action === 'check') {
            if (!SEOProAnalytics_IndexNow::enabled()) {
                WP_CLI::error('Enable IndexNow under Settings → Data first.');
            }
            SEOProAnalytics_IndexNow::check_key(true);
        } elseif ($action !== 'status') {
            WP_CLI::error('Use status, check or send.');
        }
        WP_CLI::line((string) wp_json_encode(SEOProAnalytics_IndexNow::status(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    /**
     * Send a sample email or read the private send log (administrator only).
     *
     * ## OPTIONS
     *
     * <action>
     * : test or log.
     *
     * [--to=<email>]
     * : Test recipient; defaults to the current user's email.
     *
     * [--format=<format>]
     * : table or json.
     * ---
     * default: table
     * options:
     *   - table
     *   - json
     * ---
     *
     * @param string[] $args  Positional arguments.
     * @param array    $assoc Options.
     */
    public function email($args, $assoc) {
        if (!current_user_can('manage_options')) {
            WP_CLI::error('Use --user with an administrator to manage email.');
        }
        require_once SEOPROANALYTICS_DIR . 'includes/stats/class-seoproanalytics-mail.php';
        $action = $args[0] ?? '';
        if ($action === 'test') {
            $result = SEOProAnalytics_Mail::test($assoc['to'] ?? '');
            if (is_wp_error($result)) {
                WP_CLI::error($result->get_error_message());
            }
            WP_CLI::success('Test email accepted by WordPress. Check delivery at the recipient.');
        } elseif ($action === 'log') {
            $format = $assoc['format'] ?? 'table';
            if (!in_array($format, array('table', 'json'), true)) {
                WP_CLI::error('Use --format=table or --format=json.');
            }
            \WP_CLI\Utils\format_items($format, SEOProAnalytics_Mail::log_entries(), array('t', 'kind', 'ref', 'recipients', 'result', 'error'));
        } else {
            WP_CLI::error('Use email test or email log.');
        }
    }

    /**
     * Manage private report links (use --user with an administrator).
     *
     * ## OPTIONS
     *
     * <action>
     * : list, create, link, revoke, renew or email.
     *
     * [<id>]
     * : Share ID for link, revoke, renew or email.
     *
     * [--name=<name>]
     * : Name of the new share.
     *
     * [--views=<json>]
     * : JSON list of saved ViewState objects; default: Overview.
     *
     * [--locked-filters=<json>]
     * : JSON list of filters enforced on every report.
     *
     * [--max-days=<days>]
     * : Only the last N days; 0 means any period.
     *
     * [--expires=<timestamp>]
     * : Unix expiry timestamp; 0 means no expiry.
     *
     * [--email=<json>]
     * : Schedule object for create: enabled, recipients, interval, hour, intro.
     *
     * [--test]
     * : Email the latest complete period to the current user without recording it.
     *
     * [--to=<email>]
     * : Recipient override for email --test only.
     *
     * @param string[] $args Positional arguments.
     * @param array    $assoc Options.
     */
    public function share($args, $assoc) {
        if (!current_user_can('manage_options')) {
            WP_CLI::error('Use --user with an administrator to manage shared reports.');
        }
        $action = $args[0] ?? 'list';
        if ($action === 'list') {
            $answer = array_values(array_map(array('SEOProAnalytics_Shares', 'summary'), SEOProAnalytics_Shares::all()));
        } elseif ($action === 'create') {
            $answer = SEOProAnalytics_Shares::save(array(
                'name' => $assoc['name'] ?? 'Shared report',
                'views' => isset($assoc['views']) ? json_decode($assoc['views'], true) : array(array('view' => 'overview', 'range' => '30d', 'compare' => 'none')),
                'locked_filters' => isset($assoc['locked-filters']) ? json_decode($assoc['locked-filters'], true) : array(),
                'max_days' => $assoc['max-days'] ?? 0,
                'expires' => $assoc['expires'] ?? 0,
                'email' => isset($assoc['email']) ? json_decode($assoc['email'], true) : array(),
                'hide_realtime' => true, 'hide_sensitive' => true,
            ));
        } elseif (in_array($action, array('revoke', 'renew'), true)) {
            $answer = SEOProAnalytics_Shares::revoke($args[1] ?? '', $action === 'renew');
        } elseif ($action === 'link') {
            $answer = SEOProAnalytics_Shares::link($args[1] ?? '');
        } elseif ($action === 'email') {
            if (isset($assoc['to']) && !isset($assoc['test'])) {
                WP_CLI::error('--to is only available with --test. Scheduled recipients are never overridden.');
            }
            require_once __DIR__ . '/class-seoproanalytics-email-reports.php';
            $answer = SEOProAnalytics_Email_Reports::send($args[1] ?? '', isset($assoc['test']), $assoc['to'] ?? '');
        } else {
            WP_CLI::error('Use list, create, link, revoke, renew or email.');
            return;
        }
        if (is_wp_error($answer)) {
            WP_CLI::error($answer->get_error_message());
            return;
        }
        if ($action === 'link') {
            WP_CLI::line($answer['url']);
            return;
        }
        WP_CLI::line((string) wp_json_encode($answer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    /**
     * Headline metrics: visitors, visits, pageviews, views per visit,
     * bounce rate, visit duration (seconds) and events.
     *
     * ## OPTIONS
     *
     * [--range=<range>]
     * : realtime, today, yesterday, 24h, 7d, 28d, 30d, 90d, 91d, 182d, 364d, week, month, year, 12mo, lastyear, all or custom.
     * ---
     * default: 7d
     * ---
     *
     * [--from=<date>]
     * : First day of a custom range (YYYY-MM-DD).
     *
     * [--to=<date>]
     * : Last day of a custom range (YYYY-MM-DD).
     *
     * [--compare=<compare>]
     * : none; prev (the period before: this week, month or year so far meets the same part of the previous one); week (a week earlier); month_weekday or month (a month earlier, the same weekdays or the same dates); year_weekday or year (a year earlier: 52 weeks back, the same weekdays, or the same dates).
     *   typical averages four earlier same-weekday periods (stats and timeseries only, whole-day ranges up to 28 days); other reports omit comparison.
     * ---
     * default: none
     * ---
     *
     * [--filter=<filters>]
     * : dimension:operator:value, several separated by ";" (comma means any of), or a JSON list.
     *
     * [--data=<data>]
     * : live, or demo for the demo data (wp seoproanalytics demo make).
     * ---
     * default: live
     * options:
     *   - live
     *   - demo
     * ---
     *
     * [--format=<format>]
     * : table, json, csv or yaml.
     * ---
     * default: table
     * ---
     *
     * ## EXAMPLES
     *
     *     wp seoproanalytics stats --range=30d --compare=prev
     *     wp seoproanalytics stats --filter="page:matches:/blog/*" --format=json
     *
     * @param string[]             $args  Positional arguments.
     * @param array<string,string> $assoc Options.
     */
    public function stats($args, $assoc) { // NOSONAR: WP-CLI passes positional arguments even when this command uses only options.
        $req    = $this->request($assoc);
        $answer = $this->on_data($assoc, static function () use ($req) {
            return SEOProAnalytics_Query::stats($req);
        });
        if ($this->format($assoc) === 'json') {
            WP_CLI::line((string) wp_json_encode($answer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            return;
        }
        $items = array();
        foreach ($answer['metrics'] as $metric => $value) {
            $item = array('metric' => $metric, 'value' => $value);
            if (isset($answer['compare'])) {
                $item['compare'] = $answer['compare']['metrics'][$metric];
                $change          = $answer['compare']['change'][$metric];
                $item['change']  = $change === null ? '' : sprintf(self::CHANGE_PERCENT_FORMAT, $change * 100);
            }
            $items[] = $item;
        }
        $this->range_line($answer['range']);
        WP_CLI\Utils\format_items($this->format($assoc), $items, array_keys($items[0]));
    }

    /**
     * Metrics per hour, day or month.
     *
     * ## OPTIONS
     *
     * [--range=<range>]
     * : As for stats.
     * ---
     * default: 7d
     * ---
     *
     * [--from=<date>]
     * : First day of a custom range.
     *
     * [--to=<date>]
     * : Last day of a custom range.
     *
     * [--grain=<grain>]
     * : auto, hour, day or month.
     * ---
     * default: auto
     * ---
     *
     * [--filter=<filters>]
     * : As for stats.
     *
     * [--data=<data>]
     * : live or demo.
     * ---
     * default: live
     * options:
     *   - live
     *   - demo
     * ---
     *
     * [--format=<format>]
     * : table, json, csv or yaml.
     * ---
     * default: table
     * ---
     *
     * @param string[]             $args  Positional arguments.
     * @param array<string,string> $assoc Options.
     */
    public function timeseries($args, $assoc) { // NOSONAR: WP-CLI passes positional arguments even when this command uses only options.
        $req    = $this->request($assoc);
        $answer = $this->on_data($assoc, static function () use ($req) {
            return SEOProAnalytics_Query::timeseries($req);
        });
        if ($this->format($assoc) === 'json') {
            WP_CLI::line((string) wp_json_encode($answer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            return;
        }
        $this->range_line($answer['range']);
        WP_CLI\Utils\format_items($this->format($assoc), $answer['points'], array('t', 'visitors', 'visits', 'pageviews', 'bounce_rate', 'visit_duration', 'events'));
    }

    /**
     * Top values of a dimension.
     *
     * ## OPTIONS
     *
     * <dimension>
     * : channel, source, utm_source, utm_medium, utm_campaign, utm_term, utm_content, country, device, browser, os, language, login, entry, exit, page, not_found, search, no_results, author, category, post_type, event or variant (A/B test variants seen, as test-id:variant-slug).
     *
     * [--range=<range>]
     * : As for stats.
     * ---
     * default: 7d
     * ---
     *
     * [--from=<date>]
     * : First day of a custom range.
     *
     * [--to=<date>]
     * : Last day of a custom range.
     *
     * [--filter=<filters>]
     * : As for stats.
     *
     * [--limit=<limit>]
     * : Most rows.
     * ---
     * default: 10
     * ---
     *
     * [--offset=<offset>]
     * : Rows to skip.
     * ---
     * default: 0
     * ---
     *
     * [--data=<data>]
     * : live or demo.
     * ---
     * default: live
     * options:
     *   - live
     *   - demo
     * ---
     *
     * [--format=<format>]
     * : table, json, csv or yaml.
     * ---
     * default: table
     * ---
     *
     * ## EXAMPLES
     *
     *     wp seoproanalytics breakdown source --range=30d
     *     wp seoproanalytics breakdown page --filter="channel:is:organic_search,ai" --limit=20
     *
     * @param string[]             $args  Positional arguments.
     * @param array<string,string> $assoc Options.
     */
    public function breakdown($args, $assoc) {
        $assoc['dimension'] = $args[0];
        $req                = $this->request($assoc);
        $answer             = $this->on_data($assoc, static function () use ($req) {
            return SEOProAnalytics_Query::breakdown($req);
        });
        if (is_wp_error($answer)) {
            WP_CLI::error($answer->get_error_message());
            return;
        }
        if ($this->format($assoc) === 'json') {
            WP_CLI::line((string) wp_json_encode($answer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            return;
        }
        $this->range_line($answer['range']);
        if (!$answer['rows']) {
            WP_CLI::line(__('No visits in this range.', 'seoproanalytics'));
            return;
        }
        $fields = array_values(array_diff(array_keys($answer['rows'][0]), array('value')));
        WP_CLI\Utils\format_items($this->format($assoc), $answer['rows'], $fields);
    }

    /**
     * Goals: their conversions and revenue (report), or add, change, list
     * and delete them (administrators' work; goals are per data set).
     *
     * A goal is a page viewed (a path; * for any text) or an event sent (a
     * name). Conversion rate: visits that reached it ÷ visits. Revenue is
     * per currency.
     *
     * ## OPTIONS
     *
     * [<action>]
     * : report, list, add, update or delete.
     * ---
     * default: report
     * options:
     *   - report
     *   - list
     *   - add
     *   - update
     *   - delete
     * ---
     *
     * [<id>]
     * : Goal id, for update and delete.
     *
     * [--name=<name>]
     * : Name, for add and update.
     *
     * [--kind=<kind>]
     * : page or event, for add and update.
     *
     * [--match=<match>]
     * : Page path (/pricing/, /blog/*) or event name (Purchase), for add and update.
     *
     * [--prop=<prop>]
     * : Optional exact property key for an event goal; requires --value.
     *
     * [--value=<value>]
     * : Property value (* for any text). Set both --prop and --value empty to remove the condition.
     *
     * [--range=<range>]
     * : As for stats.
     * ---
     * default: 7d
     * ---
     *
     * [--from=<date>]
     * : First day of a custom range.
     *
     * [--to=<date>]
     * : Last day of a custom range.
     *
     * [--compare=<compare>]
     * : none, prev, week, month_weekday, month, year_weekday or year (as for stats).
     * ---
     * default: none
     * ---
     *
     * [--filter=<filters>]
     * : As for stats.
     *
     * [--data=<data>]
     * : live or demo.
     * ---
     * default: live
     * options:
     *   - live
     *   - demo
     * ---
     *
     * [--format=<format>]
     * : table, json, csv or yaml.
     * ---
     * default: table
     * ---
     *
     * ## EXAMPLES
     *
     *     wp seoproanalytics goals --range=30d --compare=prev
     *     wp seoproanalytics goals add --name="Purchase" --kind=event --match=Purchase
     *     wp seoproanalytics goals add --name="Viewed pricing" --kind=page --match=/pricing/
     *     wp seoproanalytics goals add --name="Quiz opened" --kind=event --match="Outbound link" --prop=url --value="https://quiz.example.com/*"
     *     wp seoproanalytics goals delete ab12cd34
     *
     * @param string[]             $args  Positional arguments.
     * @param array<string,string> $assoc Options.
     */
    public function goals($args, $assoc) {
        $action = isset($args[0]) ? (string) $args[0] : 'report';
        if ($action !== 'report') {
            $this->define('goals', $action, isset($args[1]) ? (string) $args[1] : '', $assoc, static function ($id) use ($assoc) {
                return SEOProAnalytics_Goals::save_goal(array_intersect_key($assoc, array_flip(array('name', 'kind', 'match', 'prop', 'value'))), $id);
            });
            return;
        }
        $req    = $this->request($assoc);
        $answer = $this->on_data($assoc, static function () use ($req) {
            return SEOProAnalytics_Conversions::goals($req);
        });
        if ($this->format($assoc) === 'json') {
            WP_CLI::line((string) wp_json_encode($answer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            return;
        }
        $this->range_line($answer['range']);
        if (!$answer['goals']) {
            WP_CLI::line(__('No goals yet. Add one: wp seoproanalytics goals add --name="Purchase" --kind=event --match=Purchase', 'seoproanalytics'));
            return;
        }
        $items = array();
        foreach ($answer['goals'] as $goal) {
            $item = array(
                'id'              => $goal['id'],
                'name'            => $goal['name'],
                'kind'            => $goal['kind'],
                'match'           => $goal['match'],
                'prop'            => isset($goal['prop']) ? $goal['prop'] : '',
                'value'           => isset($goal['value']) ? $goal['value'] : '',
                'visitors'        => $goal['visitors'],
                'visits'          => $goal['visits'],
                'completions'     => $goal['completions'],
                'conversion_rate' => $goal['conversion_rate'],
                'revenue'         => self::money_text($goal['revenue']),
            );
            if (isset($goal['change'])) {
                $item['change'] = $goal['change']['visits'] === null ? '' : sprintf(self::CHANGE_PERCENT_FORMAT, $goal['change']['visits'] * 100);
            }
            $items[] = $item;
        }
        /* translators: %d: visits */
        WP_CLI::log(sprintf(__('%d visits in this range.', 'seoproanalytics'), $answer['visits']));
        WP_CLI\Utils\format_items($this->format($assoc), $items, array_keys($items[0]));
    }

    /**
     * Funnels: visits at each step, in order within one visit (report), or
     * add, change, list and delete them.
     *
     * ## OPTIONS
     *
     * [<action>]
     * : report, list, add, update or delete.
     * ---
     * default: report
     * options:
     *   - report
     *   - list
     *   - add
     *   - update
     *   - delete
     * ---
     *
     * [<id>]
     * : Funnel id, for update and delete.
     *
     * [--name=<name>]
     * : Name, for add and update.
     *
     * [--steps=<steps>]
     * : 2 to 12 steps as kind:match separated by ";" (page:/pricing/;page:/cart/;event:Purchase), or a JSON list of {name, kind, match, prop?, value?}.
     *
     * [--range=<range>]
     * : As for stats.
     * ---
     * default: 7d
     * ---
     *
     * [--from=<date>]
     * : First day of a custom range.
     *
     * [--to=<date>]
     * : Last day of a custom range.
     *
     * [--compare=<compare>]
     * : none, prev, week, month_weekday, month, year_weekday or year (as for stats).
     * ---
     * default: none
     * ---
     *
     * [--filter=<filters>]
     * : As for stats.
     *
     * [--data=<data>]
     * : live or demo.
     * ---
     * default: live
     * options:
     *   - live
     *   - demo
     * ---
     *
     * [--format=<format>]
     * : table, json, csv or yaml.
     * ---
     * default: table
     * ---
     *
     * ## EXAMPLES
     *
     *     wp seoproanalytics funnels --range=30d
     *     wp seoproanalytics funnels add --name=Checkout --steps="page:/pricing/;page:/cart/;page:/checkout/;event:Purchase"
     *
     * @param string[]             $args  Positional arguments.
     * @param array<string,string> $assoc Options.
     */
    public function funnels($args, $assoc) {
        $action = isset($args[0]) ? (string) $args[0] : 'report';
        if ($action !== 'report') {
            $this->define('funnels', $action, isset($args[1]) ? (string) $args[1] : '', $assoc, static function ($id) use ($assoc) {
                $input = array_intersect_key($assoc, array('name' => true));
                if (isset($assoc['steps'])) {
                    $input['steps'] = self::steps((string) $assoc['steps']);
                }
                return SEOProAnalytics_Goals::save_funnel($input, $id);
            });
            return;
        }
        $req    = $this->request($assoc);
        $answer = $this->on_data($assoc, static function () use ($req) {
            return SEOProAnalytics_Conversions::funnels($req);
        });
        if ($this->format($assoc) === 'json') {
            WP_CLI::line((string) wp_json_encode($answer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            return;
        }
        $this->range_line($answer['range']);
        if (!$answer['funnels']) {
            WP_CLI::line(__('No funnels yet. Add one: wp seoproanalytics funnels add --name=Checkout --steps="page:/pricing/;page:/cart/;event:Purchase"', 'seoproanalytics'));
            return;
        }
        foreach ($answer['funnels'] as $funnel) {
            WP_CLI::log('');
            /* translators: 1: funnel name, 2: id, 3: completed visits, 4: visits that started it, 5: percentage */
            WP_CLI::log(sprintf(__('%1$s (%2$s): %3$d of %4$d visits completed it (%5$s).', 'seoproanalytics'), $funnel['name'], $funnel['id'], $funnel['completed'], $funnel['entered'], sprintf(self::PERCENT_FORMAT, $funnel['completion_rate'] * 100)));
            WP_CLI\Utils\format_items($this->format($assoc), $funnel['steps'], array('name', 'kind', 'match', 'visits', 'rate', 'step_rate', 'dropped'));
        }
    }

    /**
     * Business profiles (Settings → Data): the Business profiles channel
     * and each listed profile's visits, conversions and revenue from its
     * tagged link, with the link to paste as the profile's website.
     *
     * ## OPTIONS
     *
     * [--range=<range>]
     * : As for stats.
     * ---
     * default: 30d
     * ---
     *
     * [--from=<date>]
     * : First day of a custom range.
     *
     * [--to=<date>]
     * : Last day of a custom range.
     *
     * [--filter=<filters>]
     * : As for stats.
     *
     * [--data=<data>]
     * : live or demo.
     * ---
     * default: live
     * options:
     *   - live
     *   - demo
     * ---
     *
     * [--format=<format>]
     * : table, json, csv or yaml.
     * ---
     * default: table
     * ---
     *
     * ## EXAMPLES
     *
     *     wp seoproanalytics business-profiles --range=90d
     *     wp seoproanalytics business-profiles --format=json
     *
     * @subcommand business-profiles
     *
     * @param string[]             $args  Positional arguments.
     * @param array<string,string> $assoc Options.
     */
    public function business_profiles($args, $assoc) {
        unset($args);
        $req    = $this->request($assoc);
        $answer = $this->on_data($assoc, static function () use ($req) {
            return SEOProAnalytics_Business_Profiles::report($req);
        });
        if ($this->format($assoc) === 'json') {
            WP_CLI::line((string) wp_json_encode($answer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            return;
        }
        $this->range_line($answer['range']);
        $channel = $answer['channel'];
        /* translators: 1: visits, 2: conversions */
        WP_CLI::log(sprintf(__('Business profiles channel: %1$d visits, %2$d conversions.', 'seoproanalytics'), $channel['visits'], $channel['conversions']));
        if (!$answer['profiles']) {
            WP_CLI::line(__('No business profiles listed. Turn on Settings → Data → Business profiles and list each one: platform | name | profile address | page.', 'seoproanalytics'));
            return;
        }
        $items = array();
        foreach ($answer['profiles'] as $profile) {
            $items[] = array(
                'name'        => $profile['name'],
                'platform'    => $profile['platform'],
                'page'        => $profile['page'],
                'campaign'    => $profile['campaign'],
                'visits'      => $profile['visits'],
                'conversions' => $profile['conversions'],
                'revenue'     => self::money_text($profile['revenue']),
                'link'        => $profile['link'],
            );
        }
        WP_CLI\Utils\format_items($this->format($assoc), $items, array_keys($items[0]));
    }

    /**
     * Custom properties sent with events and pages: the keys, or the
     * values of one key, with counts, visits and event revenue.
     *
     * ## OPTIONS
     *
     * [<key>]
     * : Property whose values to list; without it, the keys are listed.
     *
     * [--event=<event>]
     * : Only properties sent with this event.
     *
     * [--range=<range>]
     * : As for stats.
     * ---
     * default: 7d
     * ---
     *
     * [--from=<date>]
     * : First day of a custom range.
     *
     * [--to=<date>]
     * : Last day of a custom range.
     *
     * [--filter=<filters>]
     * : As for stats.
     *
     * [--limit=<limit>]
     * : Most rows.
     * ---
     * default: 10
     * ---
     *
     * [--data=<data>]
     * : live or demo.
     * ---
     * default: live
     * options:
     *   - live
     *   - demo
     * ---
     *
     * [--format=<format>]
     * : table, json, csv or yaml.
     * ---
     * default: table
     * ---
     *
     * ## EXAMPLES
     *
     *     wp seoproanalytics properties --range=30d
     *     wp seoproanalytics properties plan --event=Purchase --range=12mo
     *
     * @param string[]             $args  Positional arguments.
     * @param array<string,string> $assoc Options.
     */
    public function properties($args, $assoc) {
        $key    = isset($args[0]) ? (string) $args[0] : '';
        $event  = isset($assoc['event']) ? (string) $assoc['event'] : '';
        $req    = $this->request($assoc);
        $answer = $this->on_data($assoc, static function () use ($req, $key, $event) {
            return SEOProAnalytics_Conversions::properties($req, $key, $event);
        });
        if ($this->format($assoc) === 'json') {
            WP_CLI::line((string) wp_json_encode($answer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            return;
        }
        $this->range_line($answer['range']);
        if (!$answer['rows']) {
            WP_CLI::line(__('No properties in this range.', 'seoproanalytics'));
            return;
        }
        $rows = array();
        foreach ($answer['rows'] as $row) {
            $row['revenue'] = self::money_text($row['revenue']);
            unset($row['value']);
            $rows[] = $row;
        }
        $fields = $key === '' ? array('label', 'count', 'visits', 'share') : array('label', 'count', 'visits', 'share', 'revenue');
        WP_CLI\Utils\format_items($this->format($assoc), $rows, $fields);
    }

    /**
     * Clicks and form submits (autocapture): totals, then clicked
     * elements, dead clicks, link destinations, file links or forms sent.
     *
     * ## OPTIONS
     *
     * [<kind>]
     * : elements, dead, links, downloads, forms or pages.
     * ---
     * default: elements
     * options:
     *   - elements
     *   - dead
     *   - links
     *   - downloads
     *   - forms
     *   - pages
     * ---
     *
     * [--page=<path>]
     * : Only clicks on this page (* for any text).
     *
     * [--range=<range>]
     * : As for stats.
     * ---
     * default: 7d
     * ---
     *
     * [--from=<date>]
     * : First day of a custom range.
     *
     * [--to=<date>]
     * : Last day of a custom range.
     *
     * [--compare=<compare>]
     * : none, prev, week, month_weekday, month, year_weekday or year (as for stats; the totals).
     * ---
     * default: none
     * ---
     *
     * [--filter=<filters>]
     * : As for stats.
     *
     * [--limit=<limit>]
     * : Most rows.
     * ---
     * default: 10
     * ---
     *
     * [--data=<data>]
     * : live or demo.
     * ---
     * default: live
     * options:
     *   - live
     *   - demo
     * ---
     *
     * [--format=<format>]
     * : table, json, csv or yaml.
     * ---
     * default: table
     * ---
     *
     * ## EXAMPLES
     *
     *     wp seoproanalytics clicks --range=30d
     *     wp seoproanalytics clicks dead --page=/pricing/
     *     wp seoproanalytics clicks links --range=30d --format=json
     *
     * @param string[]             $args  Positional arguments.
     * @param array<string,string> $assoc Options.
     */
    public function clicks($args, $assoc) {
        $kind   = isset($args[0]) ? (string) $args[0] : 'elements';
        $page   = isset($assoc['page']) ? (string) $assoc['page'] : '';
        $req    = $this->request($assoc);
        $answer = $this->on_data($assoc, static function () use ($req, $kind, $page) {
            return SEOProAnalytics_Clicks::report($req, $kind, $page);
        });
        if ($this->format($assoc) === 'json') {
            WP_CLI::line((string) wp_json_encode($answer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            return;
        }
        $this->range_line($answer['range']);
        $totals = $answer['totals'];
        /* translators: 1: clicks, 2: dead clicks, 3: percentage, 4: outbound, 5: affiliate, 6: file links, 7: form submits, 8: visits */
        WP_CLI::log(sprintf(__('%1$d clicks, %2$d dead (%3$s); %4$d outbound, %5$d affiliate, %6$d file links; %7$d forms sent; in %8$d visits.', 'seoproanalytics'), $totals['clicks'], $totals['dead'], sprintf(self::PERCENT_FORMAT, $totals['dead_rate'] * 100), $totals['outbound'], $totals['affiliate'], $totals['downloads'], $totals['forms'], $totals['visits']));
        if ($page !== '') {
            self::page_info_lines($page, $answer['page_info']);
        }
        if (isset($answer['compare'])) {
            /* translators: 1: clicks in the other period, 2: change */
            WP_CLI::log(sprintf(__('Compared: %1$d clicks (%2$s).', 'seoproanalytics'), $answer['compare']['totals']['clicks'], self::change_text($answer['compare']['change']['clicks'])));
        }
        if (!$answer['rows']) {
            WP_CLI::line(__('No clicks of this kind in this range.', 'seoproanalytics'));
            return;
        }
        $rows = array();
        foreach ($answer['rows'] as $row) {
            $row['flags'] = implode(' ', array_keys(array_filter(array(
                'outbound'  => $row['outbound'],
                'affiliate' => $row['affiliate'],
                'download'  => $row['download'],
            ))));
            $rows[] = $row;
        }
        $fields = array(
            'elements'  => array('selector', 'label', 'count', 'visits', 'share', 'dead', 'dead_rate'),
            'dead'      => array('selector', 'label', 'target', 'count', 'visits'),
            'links'     => array('target', 'label', 'flags', 'count', 'visits', 'share'),
            'downloads' => array('target', 'label', 'count', 'visits', 'share'),
            'forms'     => array('label', 'selector', 'target', 'fields', 'count', 'visits'),
            'pages'     => array('path', 'count', 'dead', 'dead_rate', 'links', 'forms', 'visits'),
        );
        WP_CLI\Utils\format_items($this->format($assoc), $rows, $fields[$answer['kind']]);
    }

    /**
     * Print the page a report is about: its path, address, post and edit link.
     *
     * @param string                   $page Path.
     * @param array<string,mixed>|null $info Page info, or null when unknown.
     */
    private static function page_info_lines($page, $info) {
        /* translators: %s: page path. */
        WP_CLI::log(sprintf(__('Page: %s', 'seoproanalytics'), $page));
        if ($info === null) {
            return;
        }
        WP_CLI::log($info['url']);
        if ($info['post_id']) {
            /* translators: %d: post ID. */
            WP_CLI::log(sprintf(__('Post ID: %d', 'seoproanalytics'), $info['post_id']));
        }
        if ($info['edit_url'] !== null) {
            WP_CLI::log($info['edit_url']);
        }
    }

    /**
     * A relative change as a signed percentage, or – when there is none.
     *
     * @param float|null $change Change (0.1 is +10%).
     * @return string
     */
    private static function change_text($change) {
        return $change === null ? '–' : sprintf(self::CHANGE_PERCENT_FORMAT, $change * 100);
    }

    /**
     * Search (Google Search Console's imported days): clicks, impressions,
     * CTR and average position, then search queries, pages, countries,
     * devices, search appearances or the chart's days (weeks or months).
     * The period is cut at the newest day with search data (about three
     * days ago); visit filters do not apply, page filters do.
     *
     * ## OPTIONS
     *
     * [<kind>]
     * : queries, pages, countries, devices, appearance (countries, devices and appearance for the whole site, Google only) or days (the chart's points by day, week or month, newest first).
     * ---
     * default: queries
     * options:
     *   - queries
     *   - pages
     *   - countries
     *   - devices
     *   - appearance
     *   - days
     * ---
     *
     * [--page=<path>]
     * : Only searches that showed this page (* for any text).
     *
     * [--query=<query>]
     * : Only this search query (* for any text).
     *
     * [--sort=<sort>]
     * : Order of the rows: impressions, clicks, ctr or position; days also by day. Impressions when left out (days: day, newest first).
     * ---
     * options:
     *   - impressions
     *   - clicks
     *   - ctr
     *   - position
     *   - day
     * ---
     *
     * [--order=<order>]
     * : desc (most first) or asc. Left out: lowest first for position, most (or newest) first for the rest.
     * ---
     * options:
     *   - desc
     *   - asc
     * ---
     *
     * [--engine=<engine>]
     * : google (Search Console), bing (Bing Webmaster Tools; no countries or devices) or all (Combined: every engine with data added up; no countries or devices).
     * ---
     * default: google
     * options:
     *   - google
     *   - bing
     *   - all
     * ---
     *
     * [--range=<range>]
     * : As for stats.
     * ---
     * default: 30d
     * ---
     *
     * [--from=<date>]
     * : First day of a custom range.
     *
     * [--to=<date>]
     * : Last day of a custom range.
     *
     * [--compare=<compare>]
     * : none, prev, week, month_weekday, month, year_weekday or year (as for stats).
     * ---
     * default: none
     * ---
     *
     * [--filter=<filters>]
     * : Page filters, as for stats (others do not apply to search data).
     *
     * [--limit=<limit>]
     * : Most rows.
     * ---
     * default: 10
     * ---
     *
     * [--offset=<offset>]
     * : Rows skipped (for the next rows).
     * ---
     * default: 0
     * ---
     *
     * [--data=<data>]
     * : live or demo.
     * ---
     * default: live
     * options:
     *   - live
     *   - demo
     * ---
     *
     * [--format=<format>]
     * : table, json, csv or yaml.
     * ---
     * default: table
     * ---
     *
     * ## EXAMPLES
     *
     *     wp seoproanalytics search --range=90d --compare=prev
     *     wp seoproanalytics search days --range=30d --limit=100
     *     wp seoproanalytics search queries --page=/pricing/
     *     wp seoproanalytics search pages --query="seo pro analytics" --format=json
     *     wp seoproanalytics search pages --sort=position
     *     wp seoproanalytics search --engine=bing
     *     wp seoproanalytics search --engine=all
     *
     * @param string[]             $args  Positional arguments.
     * @param array<string,string> $assoc Options.
     */
    public function search($args, $assoc) {
        $kind   = isset($args[0]) ? (string) $args[0] : 'queries';
        $page   = isset($assoc['page']) ? (string) $assoc['page'] : '';
        $query  = isset($assoc['query']) ? (string) $assoc['query'] : '';
        $engine = isset($assoc['engine']) ? (string) $assoc['engine'] : 'google';
        $sort   = isset($assoc['sort']) ? (string) $assoc['sort'] : '';
        $order  = isset($assoc['order']) ? (string) $assoc['order'] : '';
        $req    = $this->request($assoc + array('range' => '30d'));
        $answer = $this->on_data($assoc, static function () use ($req, $kind, $page, $query, $engine, $sort, $order) {
            return SEOProAnalytics_Search::report($req, $kind, $page, $query, $engine, $sort, $order);
        });
        if ($this->format($assoc) === 'json') {
            WP_CLI::line((string) wp_json_encode($answer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            return;
        }
        $this->search_connected($answer);
        $this->range_line($answer['range']);
        if ($answer['through'] !== '') {
            /* translators: %s: a day (YYYY-MM-DD). */
            WP_CLI::log(sprintf(__('Search data through %s (final days only).', 'seoproanalytics'), $answer['through']));
        }
        if ($answer['ignored']) {
            /* translators: %s: filter dimensions. */
            WP_CLI::log(sprintf(__('Not applied to search data: %s.', 'seoproanalytics'), implode(', ', $answer['ignored'])));
        }
        $totals = $answer['totals'];
        /* translators: 1: clicks, 2: impressions, 3: CTR, 4: average position */
        WP_CLI::log(sprintf(__('%1$d clicks, %2$d impressions, CTR %3$s, average position %4$s.', 'seoproanalytics'), $totals['clicks'], $totals['impressions'], sprintf(self::PERCENT_FORMAT, $totals['ctr'] * 100), self::position_text($totals)));
        if (isset($answer['compare'])) {
            self::search_compare_line($answer['compare']);
        }
        if (!$answer['rows']) {
            WP_CLI::line(__('No search data of this kind in this range.', 'seoproanalytics'));
            return;
        }
        $rows = array();
        foreach ($answer['rows'] as $row) {
            $row['ctr']   = sprintf(self::PERCENT_FORMAT, $row['ctr'] * 100);
            $row['share'] = sprintf(self::PERCENT_FORMAT, $row['share'] * 100);
            $rows[]       = $row;
        }
        $first = array(
            'queries'    => 'value',
            'pages'      => 'path',
            'countries'  => 'label',
            'devices'    => 'label',
            'appearance' => 'label',
            'days'       => 'label',
        );
        WP_CLI\Utils\format_items($this->format($assoc), $rows, array($first[$answer['kind']], 'clicks', 'impressions', 'ctr', 'position', 'share'));
    }

    /**
     * Average position with one decimal, or – without impressions.
     *
     * @param array<string,mixed> $totals Search totals.
     * @return string
     */
    private static function position_text(array $totals) {
        return $totals['impressions'] ? sprintf('%.1f', $totals['position']) : '–';
    }

    /**
     * Print the search totals of the period compared.
     *
     * @param array<string,mixed> $compare The answer's compare part.
     */
    private static function search_compare_line(array $compare) {
        $then   = $compare['totals'];
        $change = $compare['change'];
        $places = $change['position'] === null ? '–' : sprintf('%+.1f', $change['position']);
        /* translators: 1: clicks, 2: change, 3: impressions, 4: change, 5: position, 6: change in places (lower is better) */
        WP_CLI::log(sprintf(__('Compared: %1$d clicks (%2$s), %3$d impressions (%4$s), position %5$s (%6$s places).', 'seoproanalytics'), $then['clicks'], self::change_text($change['clicks']), $then['impressions'], self::change_text($change['impressions']), self::position_text($then), $places));
    }

    /**
     * Warn when a search report's engine is not connected (live data).
     *
     * @param array<string,mixed> $answer A search, opportunities or content answer.
     */
    private function search_connected(array $answer) {
        if (!empty($answer['connected'])) {
            return;
        }
        if (isset($answer['engine']) && $answer['engine'] === 'bing') {
            WP_CLI::warning(__('Bing Webmaster Tools is not connected: wp seoproanalytics connect bing --key-file=<file>.', 'seoproanalytics'));
            return;
        }
        WP_CLI::warning(__('Google Search Console is not connected: wp seoproanalytics connect search-console --key-file=<file>.', 'seoproanalytics'));
    }

    /**
     * Search opportunities from Google Search Console's imported days:
     * striking distance (a page's query at position 4–20 that could reach
     * the top three: potential clicks), low CTR (a top-10 query with a CTR
     * well under the site's own at that position: clicks missed) or decay
     * (pages losing clicks against the previous period, with the likely
     * cause: position, demand, ctr or gone), missing (a top-20 query
     * whose words its page does not have, or has only some of) or overlap
     * (a query for which two or more pages each get at least 10% of the
     * impressions: a candidate to review, with each page's share and
     * whether the page with most impressions changed between the halves
     * of the period). The period is cut at the newest day with search data
     * and to its newest 366 days.
     *
     * ## OPTIONS
     *
     * [<kind>]
     * : striking, ctr, decay, missing or overlap.
     * ---
     * default: striking
     * options:
     *   - striking
     *   - ctr
     *   - decay
     *   - missing
     *   - overlap
     * ---
     *
     * [--engine=<engine>]
     * : google (Search Console), bing (Bing Webmaster Tools) or all (Combined: every engine with data added up).
     * ---
     * default: google
     * options:
     *   - google
     *   - bing
     *   - all
     * ---
     *
     * [--range=<range>]
     * : As for stats.
     * ---
     * default: 30d
     * ---
     *
     * [--from=<date>]
     * : First day of a custom range.
     *
     * [--to=<date>]
     * : Last day of a custom range.
     *
     * [--compare=<compare>]
     * : For decay: prev (the default), or year or year_weekday for a year earlier.
     * ---
     * default: prev
     * ---
     *
     * [--filter=<filters>]
     * : Page filters, as for stats (others do not apply to search data).
     *
     * [--limit=<limit>]
     * : Most rows.
     * ---
     * default: 10
     * ---
     *
     * [--data=<data>]
     * : live or demo.
     * ---
     * default: live
     * options:
     *   - live
     *   - demo
     * ---
     *
     * [--format=<format>]
     * : table, json, csv or yaml.
     * ---
     * default: table
     * ---
     *
     * ## EXAMPLES
     *
     *     wp seoproanalytics opportunities
     *     wp seoproanalytics opportunities ctr --range=90d
     *     wp seoproanalytics opportunities decay --format=json
     *     wp seoproanalytics opportunities overlap --range=90d
     *
     * @param string[]             $args  Positional arguments.
     * @param array<string,string> $assoc Options.
     */
    public function opportunities($args, $assoc) {
        $kind   = isset($args[0]) ? (string) $args[0] : 'striking';
        $engine = isset($assoc['engine']) ? (string) $assoc['engine'] : 'google';
        $req    = $this->request($assoc + array('range' => '30d', 'compare' => 'prev'));
        $answer = $this->on_data($assoc, static function () use ($req, $kind, $engine) {
            return SEOProAnalytics_Opportunities::report($req, $kind, $engine);
        });
        if ($this->format($assoc) === 'json') {
            WP_CLI::line((string) wp_json_encode($answer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            return;
        }
        $this->search_connected($answer);
        $this->range_line($answer['range']);
        if ($answer['cut']) {
            /* translators: %d: days */
            WP_CLI::log(sprintf(__('Only the newest %d days of the period are read.', 'seoproanalytics'), $answer['days']));
        }
        if (!empty($answer['compare'])) {
            /* translators: 1: start, 2: end */
            WP_CLI::log(sprintf(__('Against %1$s to %2$s.', 'seoproanalytics'), substr($answer['compare']['range']['from'], 0, 10), substr($answer['compare']['range']['to'], 0, 10)));
        }
        if ($answer['ignored']) {
            /* translators: %s: filter dimensions. */
            WP_CLI::log(sprintf(__('Not applied to search data: %s.', 'seoproanalytics'), implode(', ', $answer['ignored'])));
        }
        if (!$answer['rows']) {
            WP_CLI::line(__('No opportunities of this kind in this range.', 'seoproanalytics'));
            return;
        }
        $rows = array();
        foreach ($answer['rows'] as $row) {
            $rows[] = self::opportunity_row($answer['kind'], $row);
        }
        if ($answer['kind'] === 'overlap') {
            WP_CLI::log(__('Candidates to review, not faults: two pages can both be right for one search. switched: the page with most impressions in each half of the period; potential: clicks with the best of the pages\' CTRs.', 'seoproanalytics'));
        }
        WP_CLI\Utils\format_items($this->format($assoc), $rows, array_keys($rows[0]));
        if ($answer['kind'] === 'decay' && $answer['updates']) {
            /* translators: %s: search engine updates */
            WP_CLI::log(sprintf(__('Search engine updates in these periods: %s.', 'seoproanalytics'), implode('; ', array_column($answer['updates'], 'label'))));
        }
    }

    /**
     * One opportunity as a table row.
     *
     * @param string              $kind striking, ctr, decay, overlap or missing.
     * @param array<string,mixed> $row  Report row.
     * @return array<string,mixed>
     */
    private static function opportunity_row($kind, array $row) {
        if ($kind === 'decay') {
            return self::decay_row($row);
        }
        if ($kind === 'overlap') {
            return self::overlap_row($row);
        }
        if ($kind === 'missing') {
            return array(
                'path'        => $row['path'],
                'query'       => $row['query'],
                'impressions' => $row['impressions'],
                'clicks'      => $row['clicks'],
                'position'    => sprintf('%.1f', $row['position']),
                'match'       => $row['match'],
                'missing'     => implode(' ', $row['missing']),
                'question'    => $row['question'] ? 'yes' : '',
            );
        }
        return array(
            'path'         => $row['path'],
            'query'        => $row['query'],
            'clicks'       => $row['clicks'],
            'impressions'  => $row['impressions'],
            'ctr'          => self::percent_text($row['ctr']),
            'expected_ctr' => self::percent_text($row['expected_ctr']),
            'position'     => sprintf('%.1f', $row['position']),
            'potential'    => $row['potential'],
        );
    }

    /**
     * A page losing clicks as a table row.
     *
     * @param array<string,mixed> $row Report row.
     * @return array<string,mixed>
     */
    private static function decay_row(array $row) {
        return array(
            'path'     => $row['path'],
            'clicks'   => $row['clicks'],
            'was'      => $row['compare']['clicks'],
            'lost'     => $row['lost'],
            'position' => $row['impressions'] ? sprintf('%.1f', $row['position']) : '–',
            'was_pos'  => sprintf('%.1f', $row['compare']['position']),
            'cause'    => $row['cause'],
            'queries'  => implode('; ', array_column($row['queries'], 'query')),
            'changes'  => implode('; ', array_map(static function ($c) {
                return substr((string) $c['t'], 0, 10) . ' ' . $c['label'];
            }, $row['changes'])),
        );
    }

    /**
     * A search shown with several pages as a table row.
     *
     * @param array<string,mixed> $row Report row.
     * @return array<string,mixed>
     */
    private static function overlap_row(array $row) {
        $pages = implode('; ', array_map(static function ($page) {
            return sprintf('%s %s pos %.1f', $page['path'], self::percent_text($page['share']), $page['position']);
        }, $row['pages']));
        if ($row['page_count'] > count($row['pages'])) {
            $pages .= sprintf('; +%d', $row['page_count'] - count($row['pages']));
        }
        return array(
            'query'       => $row['query'],
            'impressions' => $row['impressions'],
            'clicks'      => $row['clicks'],
            'pages'       => $pages,
            'switched'    => $row['switched'] ? implode(' → ', $row['leaders']) : '',
            'potential'   => $row['potential'],
        );
    }

    /**
     * The content audit: published pages with findings, by search impressions.
     *
     * Findings come from each page's WordPress content and SEO plugin
     * fields: title or description missing, long or the same as another
     * page's; no H1 or several; images without alt text; a thin page with
     * impressions but no clicks; noindex or a canonical address elsewhere
     * on a page with impressions. Facts are read when a post is saved and
     * by the daily cron, 200 posts a day; run reads the next posts now.
     *
     * ## OPTIONS
     *
     * [<action>]
     * : list (the pages with findings) or run (read the next posts' facts now).
     * ---
     * default: list
     * options:
     *   - list
     *   - run
     * ---
     *
     * [--finding=<finding>]
     * : Only pages with this finding: noindex, canonical, robots_blocked, not_indexed, google_canonical, bing_crawl, thin, title_missing, title_duplicate, title_long, description_missing, description_duplicate, description_long, h1_none, h1_several, rich_errors or images_alt.
     *
     * [--sort=<sort>]
     * : Order of the pages (list): impressions (when left out), clicks, ctr or position.
     * ---
     * options:
     *   - impressions
     *   - clicks
     *   - ctr
     *   - position
     * ---
     *
     * [--order=<order>]
     * : desc (most first) or asc. Left out: lowest first for position, most first for the rest.
     * ---
     * options:
     *   - desc
     *   - asc
     * ---
     *
     * [--engine=<engine>]
     * : google (Search Console) or bing (Bing Webmaster Tools), for the search figures.
     * ---
     * default: google
     * options:
     *   - google
     *   - bing
     * ---
     *
     * [--range=<range>]
     * : As for stats.
     * ---
     * default: 30d
     * ---
     *
     * [--from=<date>]
     * : First day of a custom range.
     *
     * [--to=<date>]
     * : Last day of a custom range.
     *
     * [--filter=<filters>]
     * : Page filters, as for stats.
     *
     * [--limit=<limit>]
     * : Most rows (list; 20 when left out), or most posts read (run; a daily batch, 200, when left out).
     *
     * [--data=<data>]
     * : live or demo (list).
     * ---
     * default: live
     * options:
     *   - live
     *   - demo
     * ---
     *
     * [--format=<format>]
     * : table, json, csv or yaml.
     * ---
     * default: table
     * ---
     *
     * ## EXAMPLES
     *
     *     wp seoproanalytics audit
     *     wp seoproanalytics audit --finding=description_missing --limit=50
     *     wp seoproanalytics audit run --limit=1000
     *     wp seoproanalytics audit --data=demo --format=json
     *
     * @param string[]             $args  Positional arguments.
     * @param array<string,string> $assoc Options.
     */
    public function audit($args, $assoc) {
        require_once SEOPROANALYTICS_DIR . 'includes/stats/class-seoproanalytics-audit.php';
        $action = isset($args[0]) ? (string) $args[0] : 'list';
        if ($action === 'run') {
            self::audit_run($assoc);
            return;
        }
        $engine  = isset($assoc['engine']) ? (string) $assoc['engine'] : 'google';
        $finding = isset($assoc['finding']) ? (string) $assoc['finding'] : '';
        $sort    = isset($assoc['sort']) ? (string) $assoc['sort'] : '';
        $order   = isset($assoc['order']) ? (string) $assoc['order'] : '';
        $req     = $this->request($assoc + array('range' => '30d', 'limit' => '20'));
        $answer  = $this->on_data($assoc, static function () use ($req, $engine, $finding, $sort, $order) {
            return SEOProAnalytics_Audit::report($req, $engine, $finding, $sort, $order);
        });
        if ($this->format($assoc) === 'json') {
            WP_CLI::line((string) wp_json_encode($answer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            return;
        }
        $this->search_connected($answer);
        $this->range_line($answer['range']);
        /* translators: 1: pages with facts, 2: date the oldest was read, 3: pages with findings */
        WP_CLI::log(sprintf(__('%1$d pages read (the oldest on %2$s); %3$d with findings.', 'seoproanalytics'), $answer['checked']['pages'], $answer['checked']['oldest'] ? substr((string) $answer['checked']['oldest'], 0, 10) : '–', $answer['pages']));
        $counts = array_filter($answer['counts']);
        if ($counts) {
            WP_CLI::log(implode(', ', array_map(static function ($name, $n) {
                return $name . ' ' . $n;
            }, array_keys($counts), $counts)));
        }
        if (!$answer['rows']) {
            WP_CLI::line(__('No pages with findings.', 'seoproanalytics'));
            return;
        }
        $rows = array();
        foreach ($answer['rows'] as $row) {
            $rows[] = array(
                'path'        => $row['path'],
                'impressions' => $row['impressions'],
                'clicks'      => $row['clicks'],
                'position'    => self::position_text($row),
                'words'       => $row['facts']['words'],
                'findings'    => implode(', ', $row['findings']),
            );
        }
        WP_CLI\Utils\format_items($this->format($assoc), $rows, array_keys($rows[0]));
    }

    /**
     * wp seoproanalytics audit run: read a batch of posts now.
     *
     * @param array<string,string> $assoc Options (limit).
     */
    private static function audit_run(array $assoc) {
        if (!SEOProAnalytics_Schema::maybe_upgrade()) {
            WP_CLI::error(__('The tables could not be made.', 'seoproanalytics'));
        }
        $limit = isset($assoc['limit']) ? max(1, (int) $assoc['limit']) : SEOProAnalytics_Audit::BATCH;
        $done  = SEOProAnalytics_Audit::batch($limit, 600);
        $all   = $done['done'] ? ' ' . __('Every post has been looked at; the next run starts from the first.', 'seoproanalytics') : '';
        /* translators: 1: posts read, 2: posts looked at */
        WP_CLI::success(sprintf(__('Read %1$d posts of %2$d looked at.', 'seoproanalytics'), $done['read'], $done['looked']) . $all);
    }

    /**
     * Internal links: orphan pages, converting pages with few links in, and
     * links missing between pages that share a search.
     *
     * Links are read from the text of published pages with the content
     * audit's facts (when a post is saved and by the daily cron; run
     * `wp seoproanalytics audit run` to read more now). Orphans: no other
     * page's text links to them. Converting: pages whose visits from search
     * reach the goal 3 times or more with 2 or fewer pages linking in. The
     * front page is in neither list (menus link to it). Missing: a page shows for a search but
     * does not link to the page that gets most of its clicks.
     *
     * ## OPTIONS
     *
     * [--kind=<kind>]
     * : orphans, converting or missing.
     * ---
     * default: orphans
     * options:
     *   - orphans
     *   - converting
     *   - missing
     * ---
     *
     * [--goal=<goal>]
     * : ID of the goal whose conversions are counted; the first goal when left out.
     *
     * [--engine=<engine>]
     * : google (Search Console) or bing (Bing Webmaster Tools), for the search figures.
     * ---
     * default: google
     * options:
     *   - google
     *   - bing
     * ---
     *
     * [--range=<range>]
     * : As for stats.
     * ---
     * default: 30d
     * ---
     *
     * [--from=<date>]
     * : First day of a custom range.
     *
     * [--to=<date>]
     * : Last day of a custom range.
     *
     * [--filter=<filters>]
     * : Page filters, as for stats.
     *
     * [--limit=<limit>]
     * : Most rows (20 when left out).
     *
     * [--data=<data>]
     * : live or demo.
     * ---
     * default: live
     * options:
     *   - live
     *   - demo
     * ---
     *
     * [--format=<format>]
     * : table, json, csv or yaml.
     * ---
     * default: table
     * ---
     *
     * ## EXAMPLES
     *
     *     wp seoproanalytics links
     *     wp seoproanalytics links --kind=missing --limit=50
     *     wp seoproanalytics links --kind=converting --data=demo
     *     wp seoproanalytics links --data=demo --format=json
     *
     * @param string[]             $args  Positional arguments.
     * @param array<string,string> $assoc Options.
     */
    public function links($args, $assoc) {
        require_once SEOPROANALYTICS_DIR . 'includes/stats/class-seoproanalytics-links.php';
        $engine = isset($assoc['engine']) ? (string) $assoc['engine'] : 'google';
        $kind   = isset($assoc['kind']) ? (string) $assoc['kind'] : 'orphans';
        $goal   = isset($assoc['goal']) ? (string) $assoc['goal'] : '';
        $req    = $this->request($assoc + array('range' => '30d', 'limit' => '20'));
        $answer = $this->on_data($assoc, static function () use ($req, $engine, $kind, $goal) {
            return SEOProAnalytics_Links::report($req, $engine, $kind, $goal);
        });
        if ($this->format($assoc) === 'json') {
            WP_CLI::line((string) wp_json_encode($answer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            return;
        }
        $this->search_connected($answer);
        $this->range_line($answer['range']);
        /* translators: 1: pages whose links were read, 2: published pages read by the audit */
        WP_CLI::log(sprintf(__('Links read on %1$d of %2$d pages.', 'seoproanalytics'), $answer['read']['read'], $answer['read']['pages']) . ($answer['goal'] ? ' ' . sprintf(/* translators: %s: goal name */ __('Goal: %s.', 'seoproanalytics'), $answer['goal']['name']) : ''));
        WP_CLI::log(implode(', ', array_map(static function ($name, $n) {
            return $name . ' ' . $n;
        }, array_keys($answer['counts']), $answer['counts'])));
        if (!$answer['rows']) {
            WP_CLI::line(__('No pages.', 'seoproanalytics'));
            return;
        }
        $rows = array();
        foreach ($answer['rows'] as $row) {
            $line = array(
                'path'        => $row['path'],
                'impressions' => $row['impressions'],
                'clicks'      => $row['clicks'],
            );
            if ($answer['kind'] === 'missing') {
                $line += array(
                    'link_to'  => $row['to']['path'],
                    'searches' => implode(', ', array_column($row['queries'], 'query')),
                );
            } else {
                $line += array(
                    'visits'      => $row['visits'],
                    'conversions' => $row['conversions'] === null ? '–' : $row['conversions'],
                    'links_in'    => $row['links_in'],
                    'linked_from' => implode(', ', $row['from']),
                );
            }
            $rows[] = $line;
        }
        WP_CLI\Utils\format_items($this->format($assoc), $rows, array_keys($rows[0]));
    }

    /**
     * Indexation: pages search engines do not seem to show.
     *
     * Pages: published pages (read by the content audit) with no search
     * impressions in the engine's newest days, published before them;
     * never shown, or shown before and not since. Sitemap: other
     * addresses in the site's own sitemaps (category, tag and author
     * archives, and other plugins'), listed that long, with none. Pages
     * that ask not to be indexed or name another page as canonical are
     * left out. The sitemaps are read by the daily cron; `run` reads them
     * now. For Google, rows inspected by URL Inspection add Google's reason
     * and last crawl (`wp seoproanalytics inspect`).
     *
     * ## OPTIONS
     *
     * [<action>]
     * : list (the pages) or run (read the site's sitemaps now).
     * ---
     * default: list
     * options:
     *   - list
     *   - run
     * ---
     *
     * [--kind=<kind>]
     * : pages or sitemap.
     * ---
     * default: pages
     * options:
     *   - pages
     *   - sitemap
     * ---
     *
     * [--days=<days>]
     * : Days without search impressions, and since publishing or first listing (7 to 365).
     * ---
     * default: 28
     * ---
     *
     * [--engine=<engine>]
     * : google (Search Console) or bing (Bing Webmaster Tools).
     * ---
     * default: google
     * options:
     *   - google
     *   - bing
     * ---
     *
     * [--filter=<filters>]
     * : Page filters, as for stats.
     *
     * [--limit=<limit>]
     * : Most rows (20 when left out).
     *
     * [--data=<data>]
     * : live or demo (list).
     * ---
     * default: live
     * options:
     *   - live
     *   - demo
     * ---
     *
     * [--format=<format>]
     * : table, json, csv or yaml.
     * ---
     * default: table
     * ---
     *
     * ## EXAMPLES
     *
     *     wp seoproanalytics indexation
     *     wp seoproanalytics indexation --kind=sitemap
     *     wp seoproanalytics indexation --days=56 --limit=100
     *     wp seoproanalytics indexation run
     *     wp seoproanalytics indexation --data=demo --format=json
     *
     * @param string[]             $args  Positional arguments.
     * @param array<string,string> $assoc Options.
     */
    public function indexation($args, $assoc) {
        require_once SEOPROANALYTICS_DIR . 'includes/stats/class-seoproanalytics-indexation.php';
        $action = isset($args[0]) ? (string) $args[0] : 'list';
        if ($action === 'run') {
            self::indexation_run();
            return;
        }
        $engine = isset($assoc['engine']) ? (string) $assoc['engine'] : 'google';
        $kind   = isset($assoc['kind']) ? (string) $assoc['kind'] : 'pages';
        $days   = isset($assoc['days']) ? (int) $assoc['days'] : SEOProAnalytics_Indexation::DAYS;
        $req    = $this->request(array_diff_key($assoc, array('days' => 1)) + array('limit' => '20'));
        $answer = $this->on_data($assoc, static function () use ($req, $engine, $kind, $days) {
            return SEOProAnalytics_Indexation::report($req, $engine, $kind, $days);
        });
        if ($this->format($assoc) === 'json') {
            WP_CLI::line((string) wp_json_encode($answer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            return;
        }
        $this->search_connected($answer);
        $this->range_line($answer['range']);
        $read = $answer['read'];
        /* translators: 1: pages with their published time, 2: published pages read by the audit, 3: sitemap addresses */
        WP_CLI::log(sprintf(__('Published times read on %1$d of %2$d pages; %3$d other sitemap addresses.', 'seoproanalytics'), $read['published'], $read['pages'], $read['sitemap']['addresses']) . ($read['sitemap']['read'] && !$read['sitemap']['enabled'] ? ' ' . __('WordPress’s sitemaps are off.', 'seoproanalytics') : ''));
        WP_CLI::log(implode(', ', array_map(static function ($name, $n) {
            return $name . ' ' . $n;
        }, array_keys($answer['counts']), $answer['counts'])));
        if (!$answer['rows']) {
            WP_CLI::line(__('No pages.', 'seoproanalytics'));
            return;
        }
        $rows = array();
        foreach ($answer['rows'] as $row) {
            $rows[] = self::indexation_row($answer['kind'], $answer['inspections'] !== null, $row);
        }
        WP_CLI\Utils\format_items($this->format($assoc), $rows, array_keys($rows[0]));
    }

    /**
     * wp seoproanalytics indexation run: read the sitemaps now.
     */
    private static function indexation_run() {
        if (!SEOProAnalytics_Schema::maybe_upgrade()) {
            WP_CLI::error(__('The tables could not be made.', 'seoproanalytics'));
        }
        $done = SEOProAnalytics_Indexation::read_sitemaps(120);
        if (!$done['enabled']) {
            WP_CLI::warning(__('WordPress’s sitemaps are off (an SEO plugin may make its own); no addresses were read.', 'seoproanalytics'));
            return;
        }
        $partial = $done['complete'] ? '' : ' ' . __('Not every sitemap was read (time or address limit).', 'seoproanalytics');
        /* translators: %d: sitemap addresses */
        WP_CLI::success(sprintf(__('Read %d sitemap addresses besides the posts.', 'seoproanalytics'), $done['addresses']) . $partial);
    }

    /**
     * One indexation report row as a table row.
     *
     * @param string              $kind     pages or sitemap.
     * @param bool                $inspects Whether URL Inspection results are known.
     * @param array<string,mixed> $row      Report row.
     * @return array<string,mixed>
     */
    private static function indexation_row($kind, $inspects, array $row) {
        $line = array(
            'path'            => $row['path'],
            'state'           => $row['state'],
            'last_impression' => $row['last_impression'] === null ? '–' : $row['last_impression'],
            'days'            => $row['age'],
        );
        if ($kind === 'pages') {
            $line += array(
                'published' => substr((string) $row['published'], 0, 10),
                'words'     => $row['words'],
                'links_in'  => $row['links_in'],
            );
        } else {
            $line += array(
                'first_seen' => substr((string) $row['first_seen'], 0, 10),
                'source'     => $row['source'],
            );
        }
        if ($inspects) {
            $line['google'] = self::google_state(isset($row['google']) && is_array($row['google']) ? $row['google'] : null);
        }
        return $line;
    }

    /**
     * Google's reason from URL Inspection, or its verdict when it gives none.
     *
     * @param array<string,mixed>|null $google Inspection, or null when none.
     * @return string
     */
    private static function google_state($google) {
        if ($google === null) {
            return '–';
        }
        return (string) ($google['coverage'] !== null ? $google['coverage'] : $google['verdict']);
    }

    /**
     * Backlinks: pages of other sites that link to the site's pages.
     *
     * Found by opening the pages that sent visits (Referral channel; the
     * other site's home page when the browser gave only its address) and
     * reading their links to the site. The daily cron checks new pages
     * and each page again weekly, within 20 seconds; `check` does it now,
     * for up to two minutes, even when the setting is off. A link missing
     * on two checks in a row, or on a page that is gone, is lost.
     *
     * ## OPTIONS
     *
     * [<kind>]
     * : links (live links, newest first), domains (the sites linking), pages (the site's pages linked to), lost (links lost in the period), reported (the referring pages link exports named, and their check), needs (pages with queries at position 4–20 with fewer useful referring domains than the median of the site's pages in the top three: potential clicks × gap first), impact (pages that gained useful referring domains: search clicks before and after the first new link, against the whole site, with the change to start an experiment from: associated, not proof of cause), check (check the pages now), import (a CSV export), imports (the exports imported), review and disavow (spam review and the disavow list), classify (set the owner's classes of links, referring pages or sites) or lists (the class lists of Settings → Backlinks).
     * ---
     * default: links
     * options:
     *   - links
     *   - domains
     *   - pages
     *   - lost
     *   - reported
     *   - needs
     *   - impact
     *   - check
     *   - import
     *   - imports
     *   - review
     *   - disavow
     *   - classify
     *   - lists
     * ---
     *
     * [<file>]
     * : With import: the backlink export CSV file.
     *
     * [--ids=<ids>]
     * : With classify: link ids (the id column of links and lost), comma-separated.
     *
     * [--pages=<ids>]
     * : With classify: referring page ids (the id column of reported), comma-separated. Every link on the page has its classes, found now or later.
     *
     * [--sites=<sites>]
     * : With classify: sites (example.com, with its subdomains) or pages (example.com/page/), comma-separated: put on or taken off the class lists of Settings → Backlinks, so every link from them has the classes, found now or later.
     *
     * [--classes=<classes>]
     * : With classify: class names, comma-separated (brand_citation, nap_citation, cross_site, documentation, forum, general, guest_post, home_page, integration, link_swap, listicle, logo_linked, monitor, needs_updating, niche_edit, organic_mention, paid_link, partner, press_organic, press_release, private_blog, profile_page, blog, quote, redirect, site_footer, social_media, statistics, topical, url_linked, url_unlinked, video, wiki, ignore, spam). With links, lost, domains or reported: only rows whose every class is among these (none: rows without a class).
     *
     * [--mode=<mode>]
     * : With classify: set (exactly these classes; empty clears), add or remove.
     *
     * [--rel=<rel>]
     * : With links or lost: only these rel values, comma-separated (followed, nofollow, sponsored, ugc).
     *
     * [--search=<text>]
     * : With links or lost: only links with this text in the linking page, the page linked to or the link text.
     *
     * [--search-in=<fields>]
     * : Where --search looks: source, page, anchor (comma-separated).
     *
     * [--date=<date>]
     * : The date --date-from and --date-to apply to: first_seen (default), last_seen or lost.
     *
     * [--date-from=<day>]
     * : Only links whose date is on or after this day (YYYY-MM-DD).
     *
     * [--date-to=<day>]
     * : Only links whose date is on or before this day (YYYY-MM-DD).
     *
     * [--site-dr-min=<n>]
     * : Only links whose linking site's rank (DR, 0-100) is at least n (links without it are left out).
     *
     * [--site-dr-max=<n>]
     * : Only links whose linking site's rank is at most n.
     *
     * [--site-ld-min=<n>]
     * : Only links whose linking site has at least n referring domains (LD).
     *
     * [--site-ld-max=<n>]
     * : Only links whose linking site has at most n referring domains.
     *
     * [--page-dr-min=<n>]
     * : Only links whose linking page's rank is at least n.
     *
     * [--page-dr-max=<n>]
     * : Only links whose linking page's rank is at most n.
     *
     * [--page-ld-min=<n>]
     * : Only links whose linking page has at least n referring domains.
     *
     * [--page-ld-max=<n>]
     * : Only links whose linking page has at most n referring domains.
     *
     * [--sort=<column>]
     * : With links or lost: source, page, anchor, first_seen, last_seen, lost, site_dr, site_ld, page_dr, page_ld or value (the link's value, 0–100, an estimate from rank, rel and classes).
     *
     * [--order=<order>]
     * : asc or desc.
     *
     * [--engine=<engine>]
     * : With needs or impact: the search data read, google, bing or all (Combined).
     * ---
     * default: google
     * options:
     *   - google
     *   - bing
     *   - all
     * ---
     *
     * [--source=<source>]
     * : Export provider (gsc, ahrefs, semrush, majestic, moz, bing, generic), or the report's source filter.
     *
     * [--all]
     * : With check: open every referring page now, not only those due.
     *
     * [--scope=<scope>]
     * : With review: domain or url (use --user with an administrator).
     *
     * [--target=<target>]
     * : Domain or source URL to review.
     *
     * [--decision=<decision>]
     * : keep, disavow or undecided. Without it, list review rows as JSON.
     *
     * [--merge=<file>]
     * : With disavow: merge a readable local existing text list into this export.
     *
     * [--range=<range>]
     * : Period for new and lost links, and the sites' visits (30d when left out).
     *
     * [--limit=<limit>]
     * : Most rows (20 when left out).
     *
     * [--data=<data>]
     * : live or demo.
     * ---
     * default: live
     * options:
     *   - live
     *   - demo
     * ---
     *
     * [--format=<format>]
     * : table, json, csv or yaml.
     * ---
     * default: table
     * ---
     *
     * ## EXAMPLES
     *
     *     wp seoproanalytics backlinks
     *     wp seoproanalytics backlinks domains --range=90d
     *     wp seoproanalytics backlinks lost --range=12mo
     *     wp seoproanalytics backlinks check
     *     wp seoproanalytics backlinks check --all
     *     wp seoproanalytics backlinks reported --source=gsc
     *     wp seoproanalytics backlinks needs --range=90d
     *     wp seoproanalytics backlinks impact --range=12mo --engine=all
     *     wp seoproanalytics backlinks --sort=value
     *     wp seoproanalytics backlinks import links.csv
     *     wp seoproanalytics backlinks imports
     *     wp seoproanalytics backlinks --sort=site_dr --rel=followed --site-dr-min=30
     *     wp seoproanalytics backlinks --classes=none,monitor,guest_post --search=award
     *     wp seoproanalytics backlinks classify --ids=12,15 --classes=spam --mode=add --user=admin
     *     wp seoproanalytics backlinks classify --sites=hypestat.com --classes=spam --mode=add --user=admin
     *     wp seoproanalytics backlinks classify --pages=40 --classes=listicle,topical --user=admin
     *     wp seoproanalytics backlinks domains --classes=none
     *     wp seoproanalytics backlinks lists --user=admin
     *     wp seoproanalytics backlinks --data=demo --format=json
     *
     * @param string[]             $args  Positional arguments.
     * @param array<string,string> $assoc Options.
     */
    public function backlinks($args, $assoc) {
        require_once SEOPROANALYTICS_DIR . 'includes/stats/class-seoproanalytics-backlinks.php';
        $kind = isset($args[0]) ? (string) $args[0] : 'links';
        if ($kind === 'review' || $kind === 'disavow') {
            $this->backlinks_review($kind, $assoc);
            return;
        }
        if ($kind === 'import') {
            self::backlinks_import($args, $assoc);
            return;
        }
        if ($kind === 'check') {
            $this->backlinks_check($assoc);
            return;
        }
        if ($kind === 'imports') {
            $this->backlinks_imports($assoc);
            return;
        }
        if ($kind === 'classify') {
            $this->backlinks_classify($assoc);
            return;
        }
        if ($kind === 'lists') {
            $this->backlinks_lists($assoc);
            return;
        }
        $this->backlinks_report($kind, $assoc);
    }

    /**
     * wp seoproanalytics backlinks classify: set, add or remove the classes of
     * links, referring pages or sites.
     *
     * @param array<string,string> $assoc Options (ids, pages, sites, classes, mode, data).
     */
    private function backlinks_classify(array $assoc) {
        require_once __DIR__ . '/class-seoproanalytics-backlinks-triage.php';
        if (!current_user_can('manage_options')) {
            WP_CLI::error(__('Use --user with an administrator to classify links.', 'seoproanalytics'));
        }
        $params = array(
            'ids'     => isset($assoc['ids']) ? (string) $assoc['ids'] : '',
            'pages'   => isset($assoc['pages']) ? (string) $assoc['pages'] : '',
            'sites'   => isset($assoc['sites']) ? (string) $assoc['sites'] : '',
            'classes' => isset($assoc['classes']) ? (string) $assoc['classes'] : '',
            'mode'    => isset($assoc['mode']) ? (string) $assoc['mode'] : 'set',
        );
        $done = $this->on_data($assoc, static function () use ($params) {
            return SEOProAnalytics_Backlinks_Triage::classify($params);
        });
        if (is_wp_error($done)) {
            WP_CLI::error($done->get_error_message());
        }
        $what = array(
            'links' => __('Links changed', 'seoproanalytics'),
            'pages' => __('Referring pages changed', 'seoproanalytics'),
            'sites' => __('Class lists changed', 'seoproanalytics'),
        );
        if (!empty($done['skipped'])) {
            /* translators: %s: class names */
            WP_CLI::warning(sprintf(__('Not given to whole sites, as they describe one page: %s. Give a page (example.com/page/) instead.', 'seoproanalytics'), implode(', ', (array) $done['skipped'])));
        }
        /* translators: 1: what changed (links, referring pages or class lists), 2: how many, 3: mode (set, add or remove), 4: classes */
        WP_CLI::success(sprintf(__('%1$s: %2$d (%3$s: %4$s).', 'seoproanalytics'), $what[(string) ($done['scope'] ?? 'links')] ?? $what['links'], $done['updated'], $done['mode'], $done['classes'] ? implode(', ', $done['classes']) : '–'));
    }

    /**
     * wp seoproanalytics backlinks lists: the class lists, one row per line.
     *
     * @param array<string,string> $assoc Options (format).
     */
    private function backlinks_lists(array $assoc) {
        require_once __DIR__ . '/class-seoproanalytics-backlinks-triage.php';
        require_once __DIR__ . '/class-seoproanalytics-backlink-lists.php';
        if (!current_user_can('manage_options')) {
            WP_CLI::error(__('Use --user with an administrator to read the class lists.', 'seoproanalytics'));
        }
        $all    = SEOProAnalytics_Backlink_Lists::all();
        $format = isset($assoc['format']) ? (string) $assoc['format'] : 'table';
        if ($format === 'json') {
            WP_CLI::line((string) wp_json_encode($all, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            return;
        }
        if (!$all['enabled']) {
            WP_CLI::warning(__('The class lists are off (Settings → Backlinks).', 'seoproanalytics'));
        }
        $rows = array();
        foreach ($all['lists'] as $name => $lines) {
            foreach ($lines as $line) {
                $rows[] = array('class' => $name, 'line' => $line);
            }
        }
        WP_CLI\Utils\format_items($format, $rows, array('class', 'line'));
    }

    /**
     * The links lists' filters, search and sort from CLI options
     * (--date-from as date_from, and so on).
     *
     * @param array<string,string> $assoc Options.
     * @return array<string,string>
     */
    private static function backlink_triage(array $assoc) {
        $out = array();
        foreach (array_keys(SEOProAnalytics_API::backlink_triage_args()) as $key) {
            $option = str_replace('_', '-', $key);
            if (isset($assoc[$option])) {
                $out[$key] = (string) $assoc[$option];
            } elseif (isset($assoc[$key])) {
                $out[$key] = (string) $assoc[$key];
            }
        }
        return $out;
    }

    /**
     * wp seoproanalytics backlinks imports: the link export imports, newest first.
     *
     * @param array<string,string> $assoc Options (format).
     */
    private function backlinks_imports(array $assoc) {
        require_once __DIR__ . '/class-seoproanalytics-backlinks-history.php';
        $rows = SEOProAnalytics_Backlinks_History::out();
        if (!$rows) {
            WP_CLI::line(__('No link exports imported.', 'seoproanalytics'));
            return;
        }
        $fields = array('id', 'started', 'user', 'via', 'name', 'bytes', 'source', 'status', 'total', 'accepted', 'skipped', 'file');
        WP_CLI\Utils\format_items($this->format($assoc), $rows, $fields);
    }

    /**
     * wp seoproanalytics backlinks review|disavow: decisions and the disavow
     * export.
     *
     * @param string               $kind  review or disavow.
     * @param array<string,string> $assoc Options.
     */
    private function backlinks_review($kind, array $assoc) {
        require_once __DIR__ . '/class-seoproanalytics-backlink-review.php';
        if (!current_user_can('manage_options')) {
            WP_CLI::error(__('Use --user with an administrator for backlink decisions and exports.', 'seoproanalytics'));
        }
        $merge  = isset($assoc['merge']) ? self::disavow_merge_file($assoc['merge']) : '';
        $req    = $this->request($assoc + array('range' => '30d', 'limit' => '25'));
        $answer = $this->on_data($assoc, static function () use ($kind, $assoc, $req, $merge) {
            if ($kind === 'disavow') {
                return SEOProAnalytics_Backlink_Review::export($merge);
            }
            return isset($assoc['decision']) ? SEOProAnalytics_Backlink_Review::decide($assoc) : SEOProAnalytics_Backlink_Review::report($req);
        });
        if (is_wp_error($answer)) {
            WP_CLI::error($answer->get_error_message());
        }
        WP_CLI::line(is_string($answer) ? rtrim($answer, "\n") : (string) wp_json_encode($answer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    /**
     * Read a disavow list to merge into the export.
     *
     * @param string $file Local file.
     * @return string
     */
    private static function disavow_merge_file($file) {
        if (strpos($file, '://') !== false || !is_file($file) || !is_readable($file) || filesize($file) > SEOProAnalytics_Backlink_Review::MAX_BYTES) {
            WP_CLI::error(__('Give a readable local text file of at most 2 MB.', 'seoproanalytics'));
        }
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- bounded local file supplied by the CLI operator.
        $merge = file_get_contents($file);
        if ($merge === false) {
            WP_CLI::error(__('The list could not be read.', 'seoproanalytics'));
        }
        return (string) $merge;
    }

    /**
     * wp seoproanalytics backlinks import <file>: import a CSV of backlinks.
     *
     * @param string[]             $args  Positional arguments.
     * @param array<string,string> $assoc Options (source).
     */
    private static function backlinks_import(array $args, array $assoc) {
        require_once __DIR__ . '/class-seoproanalytics-backlinks-import.php';
        if (!isset($args[1]) || !is_readable($args[1])) {
            WP_CLI::error(__('Give a readable CSV file.', 'seoproanalytics'));
        }
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- local file explicitly supplied by the CLI operator.
        $stream = fopen($args[1], 'r');
        if (!$stream) {
            WP_CLI::error(__('The CSV could not be opened.', 'seoproanalytics'));
            return;
        }
        try {
            $job = SEOProAnalytics_Backlinks_Import::start($stream, isset($assoc['source']) ? $assoc['source'] : '');
        } finally {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- the CLI import stream.
            fclose($stream);
        }
        if (is_wp_error($job)) {
            WP_CLI::error($job->get_error_message());
        }
        require_once __DIR__ . '/class-seoproanalytics-backlinks-history.php';
        SEOProAnalytics_Backlinks_History::add($job, (string) $args[1], (string) $args[1], 'cli');
        do {
            $job = SEOProAnalytics_Backlinks_Import::run(20);
            WP_CLI::log(sprintf('%d/%d', $job['done'], $job['total']));
        } while ($job['status'] === 'running');
        if ($job['status'] === 'error') {
            WP_CLI::error(__('The import could not finish. Check database writes.', 'seoproanalytics'));
        }
        WP_CLI::line((string) wp_json_encode($job));
    }

    /**
     * wp seoproanalytics backlinks check: open the referring pages due.
     *
     * @param array<string,string> $assoc Options (all, format).
     */
    private function backlinks_check(array $assoc) {
        if (!SEOProAnalytics_Schema::maybe_upgrade()) {
            WP_CLI::error(__('The tables could not be made.', 'seoproanalytics'));
        }
        $done = SEOProAnalytics_Backlinks::run_locked(120, true, !empty($assoc['all']));
        if ($done === null) {
            WP_CLI::error(__('A check is running already (the daily run or the catch-up after an import); try again in a few minutes.', 'seoproanalytics'));
            return;
        }
        if ($this->format($assoc) === 'json') {
            WP_CLI::line((string) wp_json_encode($done, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            return;
        }
        $more = $done['more'] ? ' ' . __('More pages are due; run it again.', 'seoproanalytics') : '';
        /* translators: 1: referring pages read from visits, 2: pages opened, 3: new links, 4: lost links, 5: pages that could not be opened, 6: pages skipped (no link and no visit since their last check) */
        WP_CLI::success(sprintf(__('Referring pages from visits: %1$d. Opened: %2$d. New links: %3$d. Lost links: %4$d. Could not open: %5$d. Skipped: %6$d.', 'seoproanalytics'), $done['new_pages'], $done['checked'], $done['links_new'], $done['links_lost'], $done['errors'], $done['skipped']) . $more);
    }

    /**
     * wp seoproanalytics backlinks [links|domains|pages|new|lost]: the report.
     *
     * @param string               $kind  Report kind.
     * @param array<string,string> $assoc Options.
     */
    private function backlinks_report($kind, array $assoc) {
        $req           = $this->request($assoc + array('range' => '30d', 'limit' => '20'));
        $req['source'] = isset($assoc['source']) ? $assoc['source'] : '';
        $req['engine'] = isset($assoc['engine']) ? $assoc['engine'] : 'google';
        $req['triage'] = self::backlink_triage($assoc);
        $answer        = $this->on_data($assoc, static function () use ($req, $kind) {
            return SEOProAnalytics_Backlinks::report($req, $kind);
        });
        if (is_wp_error($answer)) {
            WP_CLI::error($answer->get_error_message());
        }
        if ($this->format($assoc) === 'json') {
            WP_CLI::line((string) wp_json_encode($answer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            return;
        }
        $this->range_line($answer['range']);
        $t    = $answer['totals'];
        $read = $answer['read'];
        /* translators: 1: referring sites, 2: live links, 3: new links in the period, 4: lost links in the period */
        WP_CLI::log(sprintf(__('Referring sites: %1$d. Live links: %2$d. New in the period: %3$d. Lost in the period: %4$d.', 'seoproanalytics'), $t['domains'], $t['links'], $t['new'], $t['lost']));
        /* translators: 1: referring pages checked, 2: referring pages known */
        WP_CLI::log(sprintf(__('Referring pages checked: %1$d of %2$d.', 'seoproanalytics'), $read['checked'], $read['pages']) . ($read['enabled'] ? '' : ' ' . __('The check is off (Settings → Data).', 'seoproanalytics')));
        /* translators: 1: referring pages link exports named, 2: their sites, 3: of those pages, the ones checked */
        WP_CLI::log(sprintf(__('Reported by link exports: %1$d pages from %2$d sites, %3$d checked.', 'seoproanalytics'), $t['reported'], $t['reported_domains'], $t['reported_checked']) . ($read['next'] ? ' ' . __('Catching up: the next check is within minutes.', 'seoproanalytics') : ''));
        if (in_array($kind, array('needs', 'impact'), true)) {
            // Without search data there is nothing to judge: say so before "none".
            $this->search_connected($answer);
        }
        if ($kind === 'needs' && isset($answer['search']['top'])) {
            /* translators: 1: the site's pages in the top three, 2: their median useful referring domains */
            WP_CLI::log(sprintf(__('Pages in the top three: %1$d, with a median of %2$s useful referring domains. A page needs links when it has fewer.', 'seoproanalytics'), $answer['search']['top']['pages'], number_format_i18n((float) $answer['search']['top']['median'], 1)));
        }
        if ($kind === 'impact') {
            WP_CLI::log(__('Pages that gained useful referring domains: search clicks per day in the 28 days before the first new link and the 28 days from then, against the whole site. Associated with the links, not proof they caused the change; start an experiment from the change to test it.', 'seoproanalytics'));
        }
        if (!$answer['rows']) {
            $none = array(
                'needs'  => __('No page in striking distance has fewer useful referring domains than the pages in the top three.', 'seoproanalytics'),
                'impact' => __('No page gained useful referring domains in the period.', 'seoproanalytics'),
            );
            WP_CLI::line(isset($none[$kind]) ? $none[$kind] : __('No backlinks.', 'seoproanalytics'));
            return;
        }
        $rows = array();
        foreach ($answer['rows'] as $row) {
            $rows[] = self::backlink_row($answer['kind'], $row);
        }
        WP_CLI\Utils\format_items($this->format($assoc), $rows, array_keys($rows[0]));
    }

    /**
     * One backlinks report row as a table row.
     *
     * @param string              $kind Report kind.
     * @param array<string,mixed> $row  Report row.
     * @return array<string,mixed>
     */
    private static function backlink_row($kind, array $row) {
        if ($kind === 'needs') {
            return array('page' => $row['page'], 'need' => $row['need'], 'potential' => $row['potential'], 'gap' => $row['gap'], 'useful' => $row['useful'], 'domains' => $row['domains'], 'followed' => $row['followed'], 'impressions' => $row['impressions'], 'position' => $row['position'], 'queries' => implode(' | ', array_column($row['queries'], 'query')));
        }
        if ($kind === 'impact') {
            $pct = static function ($value) {
                return $value === null ? '' : sprintf('%+.1f%%', $value);
            };
            return array('page' => $row['page'], 'new_domains' => $row['new_domains'], 'sites' => implode(' ', array_column($row['hosts'], 'host')), 'first_seen' => substr((string) $row['first_seen'], 0, 10), 'status' => $row['status'], 'clicks_before' => $row['before']['clicks'], 'clicks_after' => $row['after']['clicks'], 'change' => $pct($row['change']), 'site_change' => $pct($row['site_change']), 'relative' => $row['relative'] === null ? '' : sprintf('%+.1f', $row['relative']), 'change_id' => $row['change_id'] === null ? '' : $row['change_id']);
        }
        if ($kind === 'reported') {
            return array('id' => $row['id'], 'source' => $row['source'], 'classes' => implode(' ', $row['classes']), 'state' => $row['state'], 'links' => $row['links'], 'reported' => substr((string) $row['reported'], 0, 10), 'checked' => substr((string) $row['checked'], 0, 10), 'found' => implode(' ', $row['found']));
        }
        $first = substr((string) $row['first_seen'], 0, 10);
        if ($kind === 'domains') {
            return array('host' => $row['host'], 'classes' => implode(' ', $row['classes']), 'links' => $row['links'], 'followed' => $row['followed'], 'pages' => $row['pages'], 'new' => $row['new'], 'lost' => $row['lost'], 'visits' => $row['visits'], 'first_seen' => $first);
        }
        if ($kind === 'pages') {
            $mix = array();
            foreach ($row['mix'] as $name => $count) {
                if ($count) {
                    $mix[] = $name . ':' . $count;
                }
            }
            return array('page' => $row['page'], 'useful' => $row['useful'], 'followed' => $row['followed'], 'domains' => $row['domains'], 'median_dr' => $row['median_dr'] === null ? '' : $row['median_dr'], 'mix' => implode(' ', $mix), 'links' => $row['links'], 'new' => $row['new'], 'first_seen' => $first);
        }
        $metric = static function ($value) {
            return $value === null ? '' : $value;
        };
        $line   = array('id' => $row['id'], 'source' => $row['source'], 'page' => $row['page'], 'anchor' => $row['anchor'], 'rel' => implode(' ', $row['rel']), 'value' => $row['value'], 'site_dr' => $metric($row['site_dr']), 'site_ld' => $metric($row['site_ld']), 'page_dr' => $metric($row['page_dr']), 'page_ld' => $metric($row['page_ld']), 'classes' => implode(' ', $row['classes']), 'first_seen' => $first, 'last_seen' => substr((string) $row['last_seen'], 0, 10));
        if ($kind === 'lost') {
            $line['lost'] = substr((string) $row['lost'], 0, 10);
        }
        return $line;
    }

    /**
     * Google's URL Inspection: how Google indexed the site's pages (the
     * version in Google's index, not a live test), and Search Console's
     * sitemaps.
     *
     * The SEO Pro Analytics Connect add-on's Search Console connection
     * reads them; the demo data has them too. Lists the pages inspected,
     * newest first.
     *
     * ## OPTIONS
     *
     * [<page>]
     * : A page (a path such as /pricing/, or its address): show Google's view of it.
     *
     * [--sitemaps]
     * : List the property's sitemaps as last read from Search Console.
     *
     * [--verdict=<verdict>]
     * : Only pages with this verdict: PASS, PARTIAL, FAIL or NEUTRAL.
     *
     * [--coverage=<coverage>]
     * : Only pages with this coverage state, in Google's words.
     *
     * [--finding=<finding>]
     * : Only pages with this finding: robots_blocked, not_indexed, google_canonical or rich_errors.
     *
     * [--limit=<limit>]
     * : Most rows (20 when left out).
     *
     * [--data=<data>]
     * : live or demo.
     * ---
     * default: live
     * options:
     *   - live
     *   - demo
     * ---
     *
     * [--format=<format>]
     * : table, json, csv or yaml.
     * ---
     * default: table
     * ---
     *
     * ## EXAMPLES
     *
     *     wp seoproanalytics inspect
     *     wp seoproanalytics inspect /pricing/
     *     wp seoproanalytics inspect --finding=google_canonical
     *     wp seoproanalytics inspect --sitemaps
     *     wp seoproanalytics inspect --data=demo --format=json
     *
     * @param string[]             $args  Positional arguments.
     * @param array<string,string> $assoc Options.
     */
    public function inspect($args, $assoc) {
        require_once SEOPROANALYTICS_DIR . 'includes/stats/class-seoproanalytics-inspections.php';
        require_once SEOPROANALYTICS_DIR . 'includes/stats/class-seoproanalytics-changes.php';
        $json = $this->format($assoc) === 'json';
        $page = isset($args[0]) ? self::inspect_page((string) $args[0]) : '';
        if (!empty($assoc['sitemaps'])) {
            $this->inspect_sitemaps($json, $assoc);
            return;
        }
        $req    = $this->request($assoc + array('limit' => '20'));
        $filter = $page !== '' ? array(array('dimension' => 'page', 'op' => 'is', 'values' => array($page))) : array();
        $answer = $this->on_data($assoc, static function () use ($req, $assoc, $filter) {
            return SEOProAnalytics_Inspections::report(array_merge($req, $filter ? array('filters' => $filter) : array()), $assoc['verdict'] ?? '', $assoc['coverage'] ?? '', $assoc['finding'] ?? '');
        });
        if ($json) {
            WP_CLI::line((string) wp_json_encode($answer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            return;
        }
        $this->inspect_list($answer, $page, $assoc);
    }

    /**
     * Print the pages inspected.
     *
     * @param array<string,mixed>  $answer From SEOProAnalytics_Inspections::report().
     * @param string               $page   The page asked about, or empty.
     * @param array<string,string> $assoc  Options.
     */
    private function inspect_list(array $answer, $page, array $assoc) {
        $progress = $answer['progress'];
        /* translators: 1: pages inspected, 2: inspections today, 3: daily cap */
        WP_CLI::log(sprintf(__('Pages inspected: %1$d. Today: %2$d of %3$d.', 'seoproanalytics'), $answer['inspected'], $progress['used'], $progress['daily']) . ($answer['connected'] ? '' : ' ' . __('Search Console is not connected (Settings → Connections; the SEO Pro Analytics Connect add-on).', 'seoproanalytics')));
        if ($progress['error']) {
            /* translators: %s: Google's message */
            WP_CLI::warning(sprintf(__('The last run stopped: %s', 'seoproanalytics'), $progress['error']));
        }
        if (!$answer['rows']) {
            WP_CLI::line($page !== '' ? __('The page is not inspected yet.', 'seoproanalytics') : __('No page is inspected yet.', 'seoproanalytics'));
            return;
        }
        $rows = array();
        foreach ($answer['rows'] as $row) {
            $rows[] = array(
                'page'             => $row['path'],
                'verdict'          => (string) $row['verdict'],
                'coverage'         => (string) ($row['error'] !== null ? $row['error'] : $row['coverage']),
                'last_crawl'       => substr((string) $row['last_crawl'], 0, 10),
                'google_canonical' => (string) $row['google_canonical'],
                'findings'         => implode(', ', $row['findings']),
                'checked'          => substr((string) $row['checked'], 0, 10),
            );
        }
        WP_CLI\Utils\format_items($this->format($assoc), $rows, array_keys($rows[0]));
    }

    /**
     * The page given to inspect, as a path on this site.
     *
     * @param string $page A path or an address.
     * @return string Empty when none was given.
     */
    private static function inspect_page($page) {
        $page = trim($page);
        if ($page === '') {
            return '';
        }
        $page = strpos($page, '/') === 0 ? $page : SEOProAnalytics_Changes::path($page);
        if ($page === '' || $page[0] !== '/') {
            WP_CLI::error(__('Give a page as a path such as /pricing/, or its address on this site.', 'seoproanalytics'));
        }
        return $page;
    }

    /**
     * wp seoproanalytics inspect --sitemaps: the property's sitemaps, as kept.
     *
     * @param bool                 $json  As JSON.
     * @param array<string,string> $assoc Options.
     */
    private function inspect_sitemaps($json, array $assoc) {
        $all = $this->on_data($assoc, static function () {
            return SEOProAnalytics_Inspections::sitemaps();
        });
        if ($json) {
            WP_CLI::line((string) wp_json_encode($all, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            return;
        }
        if (!$all['submitted']) {
            /* translators: %s: the site's sitemap address */
            WP_CLI::warning(sprintf(__('The site\'s sitemap %s is not submitted in Search Console.', 'seoproanalytics'), (string) $all['own']));
        }
        if (!$all['rows']) {
            WP_CLI::line(__('No sitemap is submitted for the property.', 'seoproanalytics'));
            return;
        }
        $rows = array();
        foreach ($all['rows'] as $row) {
            $rows[] = array(
                'sitemap'    => $row['path'],
                'type'       => $row['type'] . ($row['index'] ? ' (index)' : ''),
                'submitted'  => substr((string) $row['submitted'], 0, 10),
                'downloaded' => substr((string) $row['downloaded'], 0, 10),
                'errors'     => $row['errors'],
                'warnings'   => $row['warnings'],
                'problems'   => implode(', ', $row['problems']),
            );
        }
        WP_CLI\Utils\format_items($this->format($assoc), $rows, array_keys($rows[0]));
    }

    /**
     * Bing's crawl issues of the site's addresses (Bing Webmaster Tools),
     * errors first, with its crawl stats, sitemaps and URL submission quota.
     *
     * While Bing Webmaster Tools is connected, the hourly import reads the
     * crawl facts once a day and Bing's inbound links once a week (into
     * Backlinks, found: bing), each run within 60 requests and 20 seconds.
     * --run reads both now. Issues: redirect_301, redirect_302 (not
     * errors), client_error, server_error, robots_blocked, malware,
     * important_blocked, dns and timeout; errors on published pages are
     * also the content audit's bing_crawl finding.
     *
     * ## OPTIONS
     *
     * [--issue=<issue>]
     * : Only addresses with this issue, or errors for every error.
     *
     * [--run]
     * : Read the crawl facts and carry the links read on now.
     *
     * [--stats]
     * : List the crawl stats by day instead.
     *
     * [--sitemaps]
     * : List Bing's sitemaps instead.
     *
     * [--limit=<limit>]
     * : Most rows (20 when left out).
     *
     * [--data=<data>]
     * : live or demo.
     * ---
     * default: live
     * options:
     *   - live
     *   - demo
     * ---
     *
     * [--format=<format>]
     * : table, json, csv or yaml.
     * ---
     * default: table
     * ---
     *
     * ## EXAMPLES
     *
     *     wp seoproanalytics crawl-issues
     *     wp seoproanalytics crawl-issues --issue=errors
     *     wp seoproanalytics crawl-issues --run
     *     wp seoproanalytics crawl-issues --stats
     *     wp seoproanalytics crawl-issues --sitemaps
     *     wp seoproanalytics crawl-issues --data=demo --format=json
     *
     * @subcommand crawl-issues
     *
     * @param string[]             $args  Positional arguments.
     * @param array<string,string> $assoc Options.
     */
    public function crawl_issues($args, $assoc) {
        unset($args);
        require_once SEOPROANALYTICS_DIR . 'includes/stats/class-seoproanalytics-bing-site-data.php';
        if (!empty($assoc['run'])) {
            if (($assoc['data'] ?? 'live') === 'demo') {
                WP_CLI::error(__('Bing is asked about live data only.', 'seoproanalytics'));
            }
            if (!SEOProAnalytics_Schema::maybe_upgrade()) {
                WP_CLI::error(__('The tables could not be made.', 'seoproanalytics'));
            }
            $done = SEOProAnalytics_Bing_Site_Data::run(true);
            if (is_wp_error($done)) {
                WP_CLI::error($done->get_error_message());
                return;
            }
            /* translators: 1: crawl issues, 2: links read, 3: pages of the site with links */
            WP_CLI::success(sprintf(__('Read from Bing: %1$d crawl issues; links read so far %2$d, to %3$d pages.', 'seoproanalytics'), $done['issues'], $done['links']['links'], $done['links']['targets']) . ($done['links']['running'] ? ' ' . __('The links read carries on in the hourly import.', 'seoproanalytics') : ''));
        }
        $req    = $this->request($assoc + array('limit' => '20'));
        $answer = $this->on_data($assoc, static function () use ($req, $assoc) {
            return SEOProAnalytics_Bing_Site_Data::report($req, $assoc['issue'] ?? '');
        });
        if ($this->format($assoc) === 'json') {
            WP_CLI::line((string) wp_json_encode($answer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            return;
        }
        $this->crawl_list($answer, $assoc);
    }

    /**
     * A crawl of the site made elsewhere, imported: what each page served
     * once the theme, plugins, caching and the host had their say, and
     * links that fail or redirect.
     *
     * list (the default) gives pages with a problem; --kind=links the links
     * that fail or redirect; --kind=clicked outbound and affiliate
     * destinations clicked in the range whose crawl answer failed. import
     * reads one crawl's exports (up to 10 CSV or JSON files: LibreCrawl's,
     * the aidevops site crawler's, or any with an address and status
     * column; links files need source and target columns) and writes them
     * here. Built-in crawling requires explicit opt-in. imports lists the imports; undo <id>
     * deletes an import's rows. Pages that answered an error, are served
     * with noindex or a canonical elsewhere that WordPress does not set,
     * or link to addresses that fail or redirect, are content audit
     * findings and Plan items; a page whose answer changed between two
     * imports gets a served change on the timeline.
     *
     * ## OPTIONS
     *
     * [<action>]
     * : list, import, imports, undo, enable, run, status or stop.
     * ---
     * default: list
     * options:
     *   - list
     *   - import
     *   - imports
     *   - undo
     *   - clicked
     *   - enable
     *   - run
     *   - status
     *   - stop
     * ---
     *
     * [<file>...]
     * : import: the export files of one crawl. undo: the import's ID.
     *
     * [--crawler=<crawler>]
     * : import: librecrawl, aidevops or csv; told by the columns when left out.
     *
     * [--complete]
     * : import: confirm the crawl covered the whole site without reaching its page limit.
     *
     * [--external]
     * : enable: separately consent to checking external link targets.
     *
     * [--force]
     * : enable/run: bypass server readiness, never robots.txt or HTTP safety.
     *
     * [--kind=<kind>]
     * : list: pages, links or clicked.
     *
     * [--status=<status>]
     * : list: errors or redirects; links also blocked (external 401/403/429/999, check by hand); pages also noindex, canonical or title_missing.
     *
     * [--finding=<finding>]
     * : list: only pages with this finding, including slow (traffic-weighted timing and HTML size with field data), crawl_error, served_noindex, served_canonical, served_title, served_description, broken_links or redirect_links.
     *
     * [--range=<range>]
     * : clicked: the clicks' range (30d when left out).
     *
     * [--partial]
     * : import: replace only covered pages (at most 100), keeping other findings.
     *
     * [--limit=<limit>]
     * : Most rows (20 when left out).
     *
     * [--data=<data>]
     * : live or demo (list only).
     * ---
     * default: live
     * options:
     *   - live
     *   - demo
     * ---
     *
     * [--format=<format>]
     * : table, json, csv or yaml.
     * ---
     * default: table
     * ---
     *
     * ## EXAMPLES
     *
     *     wp seoproanalytics crawl
     *     wp seoproanalytics crawl import crawl-data.csv broken-links.csv redirects.csv
     *     wp seoproanalytics crawl import librecrawl-export.json --crawler=librecrawl
     *     wp seoproanalytics crawl --kind=links --status=errors
     *     wp seoproanalytics crawl --kind=clicked --range=90d
     *     wp seoproanalytics crawl imports
     *     wp seoproanalytics crawl undo 12
     *     wp seoproanalytics crawl --data=demo --format=json
     *
     * @param string[]             $args  Positional arguments.
     * @param array<string,string> $assoc Options.
     */
    public function crawl($args, $assoc) {
        require_once SEOPROANALYTICS_DIR . 'includes/stats/class-seoproanalytics-crawl.php';
        require_once SEOPROANALYTICS_DIR . 'includes/stats/class-seoproanalytics-crawl-import.php';
        $action = isset($args[0]) ? (string) $args[0] : 'list';
        if (in_array($action, array('enable', 'run', 'status', 'stop'), true)) {
            require_once __DIR__ . '/class-seoproanalytics-site-crawler.php';
            if ($action === 'enable') {
                SEOProAnalytics_Site_Crawler::enable(isset($assoc['external']), isset($assoc['force']));
                $answer = SEOProAnalytics_Site_Crawler::status();
            } elseif ($action === 'run') {
                $answer = SEOProAnalytics_Site_Crawler::status();
                if (!in_array($answer['status'], array('running', 'importing'), true)) {
                    $answer = SEOProAnalytics_Site_Crawler::start(isset($assoc['force']));
                }
                if (!is_wp_error($answer)) {
                    SEOProAnalytics_Site_Crawler::run();
                    $answer = SEOProAnalytics_Site_Crawler::status();
                }
            } else {
                $answer = $action === 'stop' ? SEOProAnalytics_Site_Crawler::stop() : SEOProAnalytics_Site_Crawler::status();
            }
            if (is_wp_error($answer)) {
                WP_CLI::error($answer->get_error_message());
                return;
            }
            WP_CLI::line((string) wp_json_encode($answer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            return;
        }
        if ($action === 'import') {
            $this->crawl_import(array_slice($args, 1), $assoc);
            return;
        }
        if ($action === 'imports' || $action === 'undo') {
            if ($action === 'undo') {
                $deleted = SEOProAnalytics_Crawl_Import::undo(isset($args[1]) ? (int) $args[1] : 0);
                if (is_wp_error($deleted)) {
                    WP_CLI::error($deleted->get_error_message());
                    return;
                }
                /* translators: %d: rows deleted */
                WP_CLI::success(sprintf(__('Undone: %d rows deleted.', 'seoproanalytics'), $deleted));
            }
            $rows = array();
            foreach (SEOProAnalytics_Crawl_Import::imports() as $one) {
                $rows[] = array('id' => $one['id'], 'status' => $one['status'], 'format' => $one['format'], 'files' => implode(', ', array_column($one['files'], 'name')), 'started' => $one['started'], 'pages' => $one['pages'], 'links' => $one['links'], 'skipped' => $one['skipped'], 'changes' => $one['changes'], 'totals' => $this->format($assoc) === 'json' ? $one['totals'] : wp_json_encode($one['totals']));
            }
            if (!$rows) {
                WP_CLI::line(__('No crawl was imported yet.', 'seoproanalytics'));
                return;
            }
            WP_CLI\Utils\format_items($this->format($assoc), $rows, array_keys($rows[0]));
            return;
        }
        $kind   = $action === 'clicked' ? 'clicked' : ($assoc['kind'] ?? '');
        $req    = $this->request($assoc + array('limit' => '20', 'range' => '30d'));
        $answer = $this->on_data($assoc, static function () use ($req, $assoc, $kind) {
            return SEOProAnalytics_Crawl::report($req, $kind, $assoc['status'] ?? '', $assoc['finding'] ?? '', $assoc['goal'] ?? '', $assoc['engine'] ?? 'google');
        });
        if ($this->format($assoc) === 'json') {
            WP_CLI::line((string) wp_json_encode($answer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            return;
        }
        $this->crawl_rows($answer, $assoc);
    }

    /**
     * wp seoproanalytics crawl import <file>...: stage the files and write them.
     *
     * @param string[]             $files Files.
     * @param array<string,string> $assoc Options (crawler).
     */
    private function crawl_import(array $files, array $assoc) {
        if (!$files) {
            WP_CLI::error(__('Give the crawl\'s export files.', 'seoproanalytics'));
        }
        $sources = array();
        foreach ($files as $file) {
            if (strpos($file, '://') !== false || !is_file($file) || !is_readable($file)) {
                /* translators: %s: file */
                WP_CLI::error(sprintf(__('%s is not a readable local file.', 'seoproanalytics'), $file));
            }
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- local file explicitly supplied by the CLI operator.
            $stream = fopen($file, 'r');
            if (!$stream) {
                /* translators: %s: file */
                WP_CLI::error(sprintf(__('%s could not be opened.', 'seoproanalytics'), $file));
                return;
            }
            $sources[] = array('name' => basename($file), 'stream' => $stream);
        }
        try {
            $job = SEOProAnalytics_Crawl_Import::start($sources, $assoc['crawler'] ?? '', isset($assoc['complete']), array(), 'upload', isset($assoc['partial']));
        } finally {
            foreach ($sources as $source) {
                // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- the CLI import streams.
                fclose($source['stream']);
            }
        }
        if (is_wp_error($job)) {
            WP_CLI::error($job->get_error_message());
            return;
        }
        do {
            $was = (int) $job['done'];
            $job = SEOProAnalytics_Crawl_Import::run(20);
            WP_CLI::log(sprintf('%d/%d', $job['done'], $job['total']));
            if ($job['status'] === 'running' && (int) $job['done'] === $was) {
                // Removing older rows, or another run (cron, a watching
                // screen) holds the lock: don't spin.
                sleep(1);
            }
        } while ($job['status'] === 'running');
        if ($job['status'] !== 'done') {
            WP_CLI::error(isset($job['error']) ? (string) $job['error'] : __('The import could not finish. Check database writes.', 'seoproanalytics'));
        }
        /* translators: 1: import ID, 2: crawler, 3: pages, 4: links kept, 5: rows skipped, 6: changes on the timeline */
        WP_CLI::success(sprintf(__('Crawl import %1$d (%2$s): %3$d pages, %4$d links that fail or redirect, %5$d rows skipped, %6$d served changes.', 'seoproanalytics'), $job['id'], $job['format'], $job['pages'], $job['links'], $job['skipped'], $job['changes']));
    }

    /**
     * Print the crawl report's rows.
     *
     * @param array<string,mixed>  $answer From SEOProAnalytics_Crawl::report().
     * @param array<string,string> $assoc  Options.
     */
    private function crawl_rows(array $answer, array $assoc) {
        if ($answer['read'] === null && !$answer['counts']['pages'] && !$answer['counts']['links']) {
            WP_CLI::line(__('No crawl was imported yet: wp seoproanalytics crawl import <files>.', 'seoproanalytics'));
            return;
        }
        /* translators: 1: when, 2: crawler, 3: pages, 4: pages that failed, 5: links that fail, 6: external links blocked */
        WP_CLI::log(sprintf(__('Crawl read %1$s (%2$s): %3$d pages, %4$d failed; %5$d links fail; %6$d external links blocked (the linked site refused the crawler; check by hand).', 'seoproanalytics'), (string) $answer['read'], (string) $answer['format'], $answer['counts']['pages'], $answer['counts']['error'] + $answer['counts']['none'], $answer['counts']['broken_links'], $answer['counts']['blocked_links']));
        $rows = array();
        foreach ($answer['rows'] as $row) {
            if ($answer['kind'] === 'links') {
                $rows[] = array('from' => $row['from'], 'target' => $row['target'], 'status' => $row['status'] ? $row['status'] : '', 'answer' => $row['answer'], 'hops' => $row['hops'], 'placement' => $row['placement'], 'anchor' => $row['anchor']);
            } elseif ($answer['kind'] === 'clicked') {
                $rows[] = array('target' => $row['target'], 'status' => $row['status'] ? $row['status'] : '', 'clicks' => $row['clicks'], 'affiliate' => $row['affiliate'] ? 'yes' : '', 'pages' => implode(', ', $row['pages']));
            } elseif ($answer['finding'] === 'slow') {
                $field = array_map(static function ($sample) { return $sample['form_factor'] . ' ' . $sample['metric'] . ': ' . $sample['p75'] . ' ms (' . $sample['status'] . ')'; }, $row['field']);
                $rows[] = array('path' => $row['path'], 'response_ms' => $row['response_ms'], 'html_bytes' => $row['bytes'], 'field' => implode('; ', $field), 'visits' => $row['visits'], 'clicks' => $row['clicks'], 'conversions' => $row['conversions']);
            } else {
                $rows[] = array('path' => $row['path'], 'status' => $row['status'] === null ? '' : ($row['status'] ? $row['status'] : 'none'), 'hops' => $row['hops'], 'flags' => implode(', ', $row['flags']), 'findings' => implode(', ', $row['findings']), 'broken' => $row['broken'], 'redirects' => $row['redirects']);
            }
        }
        if (!$rows) {
            WP_CLI::line(__('Nothing to list.', 'seoproanalytics'));
            return;
        }
        WP_CLI\Utils\format_items($this->format($assoc), $rows, array_keys($rows[0]));
    }

    /**
     * Print Bing's crawl issues, stats or sitemaps.
     *
     * @param array<string,mixed>  $answer From SEOProAnalytics_Bing_Site_Data::report().
     * @param array<string,string> $assoc  Options.
     */
    private function crawl_list(array $answer, array $assoc) {
        if (!$answer['connected']) {
            WP_CLI::warning(__('Bing Webmaster Tools is not connected (Settings → Connections).', 'seoproanalytics'));
        }
        if ($answer['error'] !== null) {
            /* translators: %s: Bing's message */
            WP_CLI::warning(sprintf(__('The last read stopped: %s', 'seoproanalytics'), $answer['error']));
        }
        if ($answer['quota']) {
            /* translators: 1: addresses a day, 2: addresses a month */
            WP_CLI::log(sprintf(__('URL submission quota: %1$d a day, %2$d a month.', 'seoproanalytics'), $answer['quota']['daily'], $answer['quota']['monthly']));
        }
        $limit = max(1, (int) ($assoc['limit'] ?? 20));
        if (!empty($assoc['stats'])) {
            $rows = array_slice(array_reverse($answer['stats']), 0, $limit);
        } elseif (!empty($assoc['sitemaps'])) {
            $rows = array_slice($answer['sitemaps'], 0, $limit);
        } else {
            /* translators: 1: addresses with crawl errors, 2: addresses listed */
            WP_CLI::log(sprintf(__('Addresses with crawl errors: %1$d. Listed: %2$d.', 'seoproanalytics'), $answer['counts']['errors'], $answer['total']));
            $rows = array();
            foreach ($answer['rows'] as $row) {
                $rows[] = array('address' => $row['url'], 'http' => $row['http'] ? $row['http'] : '', 'issues' => implode(', ', $row['issues']), 'inlinks' => $row['inlinks']);
            }
        }
        if (!$rows) {
            WP_CLI::line($answer['read'] !== null ? __('Nothing to list.', 'seoproanalytics') : __('Bing’s site data has not been read yet; it is read in the hourly import.', 'seoproanalytics'));
            return;
        }
        WP_CLI\Utils\format_items($this->format($assoc), $rows, array_keys($rows[0]));
    }

    /**
     * Search targets: the searches the site chose to win and the page
     * meant for each, with how search treats them now.
     *
     * List gives each target's clicks, impressions and position on any
     * page, the page search shows most for it, and its state: ranking (the
     * page meant for it), wrong_page (another page), no_page (none chosen)
     * or not_shown (no impressions). The period is cut at the newest day
     * with search data and to its newest 366 days.
     *
     * Import reads a list from a file (or - for standard input): CSV or
     * tab-separated text (a header row naming query, page, priority and
     * status, or those columns in that order), JSON, or the aidevops search
     * targets table (TOON: phrase, target_url, priority, status). Targets
     * are added or updated by query; rows without search text, with an
     * address that is not on this site, or with a priority or status that
     * cannot be read are skipped and listed.
     *
     * Suggest lists the focus keywords the SEO plugin (Rank Math, Yoast
     * SEO, SEOPress or All in One SEO) keeps for published posts, each with
     * its page and state: new, clash (the focus keyword of more than one
     * page, so never imported: choose its page and import it as a list) or
     * targeted (a target already). Import --from=seo-plugin adds the new
     * ones (or the searches named) as targeted with their page, priority
     * 50, and leaves existing targets as they are.
     *
     * ## OPTIONS
     *
     * [<action>]
     * : list, import, suggest, set or delete.
     * ---
     * default: list
     * options:
     *   - list
     *   - import
     *   - suggest
     *   - set
     *   - delete
     * ---
     *
     * [<what>...]
     * : For import: the file (- for standard input); with --from=seo-plugin (or suggest --import), the suggested searches to import (every new one when left out). For delete: the searches.
     *
     * [--from=<from>]
     * : For import: seo-plugin imports the SEO plugin's focus keywords instead of a file.
     * ---
     * options:
     *   - seo-plugin
     * ---
     *
     * [--all-keywords]
     * : For suggest and import --from=seo-plugin: every focus keyword of a page, not only its main one.
     *
     * [--import]
     * : For suggest: import the new suggestions (or the searches named), as import --from=seo-plugin does.
     *
     * [--only-new]
     * : For import: add new searches only; searches already listed are skipped and left as they are.
     *
     * [--replace]
     * : For import: delete the targets that are not in the list.
     *
     * [--allintitle=<n>]
     * : For set: Google allintitle result count (a non-negative whole number).
     *
     * [--volume=<n>]
     * : For set: monthly search volume (a non-negative whole number).
     *
     * [--allintitle_measured=<date>]
     * : For set: count measurement date, YYYY-MM-DD (today when omitted).
     *
     * [--volume_measured=<date>]
     * : For set: volume measurement date, YYYY-MM-DD (today when omitted).
     *
     * [--all]
     * : For delete: delete every target.
     *
     * [--yes]
     * : For delete --all: do not ask.
     *
     * [--status=<status>]
     * : For list: all, open (candidate, targeted and live), candidate, targeted, live, won or retired.
     * ---
     * default: all
     * ---
     *
     * [--owner=<owner>]
     * : For list: only targets of this owner: a user id, none, or me with --user (wp seoproanalytics owners).
     *
     * [--range=<range>]
     * : As for stats.
     * ---
     * default: 30d
     * ---
     *
     * [--compare=<compare>]
     * : As for stats: any comparison but none adds the position and clicks then.
     *
     * [--engine=<engine>]
     * : google (Search Console) or bing (Bing Webmaster Tools).
     * ---
     * default: google
     * options:
     *   - google
     *   - bing
     * ---
     *
     * [--limit=<limit>]
     * : Most rows (100 when left out).
     *
     * [--data=<data>]
     * : live or demo.
     * ---
     * default: live
     * options:
     *   - live
     *   - demo
     * ---
     *
     * [--format=<format>]
     * : table, json, csv or yaml.
     * ---
     * default: table
     * ---
     *
     * ## EXAMPLES
     *
     *     wp seoproanalytics targets
     *     wp seoproanalytics targets --status=open --range=90d
     *     wp seoproanalytics targets import targets.csv
     *     wp seoproanalytics targets set "privacy friendly analytics" --allintitle=40 --volume=200
     *     wp seoproanalytics targets import - < keywords.toon
     *     wp seoproanalytics targets suggest
     *     wp seoproanalytics targets import --from=seo-plugin
     *     wp seoproanalytics targets import --from=seo-plugin "core web vitals"
     *     wp seoproanalytics targets delete "privacy friendly analytics"
     *     wp seoproanalytics targets --data=demo --format=json
     *
     * @param string[]             $args  Positional arguments.
     * @param array<string,string> $assoc Options.
     */
    public function targets($args, $assoc) {
        require_once SEOPROANALYTICS_DIR . 'includes/stats/class-seoproanalytics-targets.php';
        $action = isset($args[0]) ? (string) $args[0] : 'list';
        $what   = array_slice($args, 1);
        if ($action === 'set') {
            $this->targets_set($what, $assoc);
            return;
        }
        if ($action === 'import') {
            $this->targets_import($what, $assoc);
            return;
        }
        if ($action === 'suggest' && !empty($assoc['import'])) {
            $this->targets_import($what, array('from' => 'seo-plugin') + $assoc);
            return;
        }
        if ($action === 'suggest') {
            $this->targets_suggest($assoc);
            return;
        }
        if ($action === 'delete') {
            $this->targets_delete($what, $assoc);
            return;
        }
        if ($action !== 'list') {
            WP_CLI::error(__('The action is list, import, suggest, set or delete.', 'seoproanalytics'));
        }
        $engine = isset($assoc['engine']) ? (string) $assoc['engine'] : 'google';
        $status = isset($assoc['status']) ? (string) $assoc['status'] : 'all';
        $owner  = $this->owner_filter($assoc);
        $req    = $this->request(array_diff_key($assoc, array('status' => 1, 'owner' => 1)) + array('range' => '30d', 'limit' => (string) SEOProAnalytics_Targets::LIMIT));
        $answer = $this->on_data($assoc, static function () use ($req, $engine, $status, $owner) {
            return SEOProAnalytics_Targets::report($req, $engine, $status, $owner);
        });
        if (is_wp_error($answer)) {
            WP_CLI::error($answer->get_error_message());
        }
        if ($this->format($assoc) === 'json') {
            WP_CLI::line((string) wp_json_encode($answer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            return;
        }
        $this->search_connected($answer);
        $this->range_line($answer['range']);
        WP_CLI::log(implode(', ', array_map(static function ($name, $n) {
            return $name . ' ' . $n;
        }, array_keys($answer['counts']), $answer['counts'])));
        if (!$answer['rows']) {
            WP_CLI::line(__('No targets. Import a list with: wp seoproanalytics targets import <file>', 'seoproanalytics'));
            return;
        }
        $rows = array_map(array(__CLASS__, 'target_row'), $answer['rows']);
        WP_CLI\Utils\format_items($this->format($assoc), $rows, array_keys($rows[0]));
    }

    /**
     * One search target as a table row.
     *
     * @param array<string,mixed> $row Report row.
     * @return array<string,mixed>
     */
    private static function target_row(array $row) {
        return array(
            'query'       => $row['query'],
            'allintitle'  => $row['allintitle'],
            'volume'      => $row['volume'],
            'kgr'         => $row['kgr'],
            'kgr_band'    => $row['kgr_band'],
            'measured'    => wp_json_encode($row['measured']),
            'priority'    => $row['priority'],
            'status'      => $row['status'],
            'state'       => $row['state'],
            'page'        => $row['page'] ? $row['page']['path'] : '–',
            'shown'       => $row['shown'] ? $row['shown']['path'] : '–',
            'position'    => $row['position'] === null ? '–' : $row['position'],
            'clicks'      => $row['clicks'],
            'impressions' => $row['impressions'],
            'owner'       => self::owner_text($row['owner'] ?? null),
        );
    }

    /**
     * wp seoproanalytics targets set: save a target's research measurements.
     *
     * @param string[]             $what  The search.
     * @param array<string,string> $assoc Options.
     */
    private function targets_set(array $what, array $assoc) {
        $fields = array_intersect_key($assoc, array_flip(array('allintitle', 'volume', 'allintitle_measured', 'volume_measured')));
        $query  = isset($what[0]) ? $what[0] : '';
        $done   = $this->on_data($assoc, static function () use ($query, $fields) {
            return SEOProAnalytics_Targets::set($query, $fields);
        });
        if (is_wp_error($done)) {
            WP_CLI::error($done->get_error_message());
        }
        WP_CLI::success(__('Target research measurements saved.', 'seoproanalytics'));
    }

    /**
     * wp seoproanalytics targets suggest: the SEO plugin's focus keywords as
     * target suggestions.
     *
     * @param array<string,string> $assoc Options (all-keywords, data, format).
     */
    private function targets_suggest(array $assoc) {
        require_once SEOPROANALYTICS_DIR . 'includes/stats/class-seoproanalytics-target-sources.php';
        $all    = !empty($assoc['all-keywords']);
        $answer = $this->on_data($assoc, static function () use ($all) {
            return SEOProAnalytics_Target_Sources::suggestions($all);
        });
        if ($this->format($assoc) === 'json') {
            WP_CLI::line((string) wp_json_encode($answer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            return;
        }
        /* translators: 1: SEO plugin, 2: pages read */
        WP_CLI::log(sprintf(__('SEO plugin: %1$s; %2$d pages with focus keywords.', 'seoproanalytics'), $answer['plugin'] !== '' ? $answer['plugin'] : '–', $answer['pages']));
        if ($answer['more']) {
            /* translators: %d: posts read */
            WP_CLI::warning(sprintf(__('More posts have focus keywords than the %d read.', 'seoproanalytics'), $answer['max_posts']));
        }
        WP_CLI::log(implode(', ', array_map(static function ($name, $n) {
            return $name . ' ' . $n;
        }, array_keys($answer['counts']), $answer['counts'])));
        if (!$answer['rows']) {
            WP_CLI::line(__('No focus keywords found. Set them in the SEO plugin, or import a list with: wp seoproanalytics targets import <file>', 'seoproanalytics'));
            return;
        }
        $rows = array_map(static function ($row) {
            return array(
                'query'  => $row['query'],
                'state'  => $row['state'],
                'pages'  => implode(' ', array_column($row['pages'], 'path')),
                'target' => $row['target'] ? $row['target']['status'] . ($row['target']['page'] !== '' ? ' ' . $row['target']['page'] : '') : '–',
            );
        }, $answer['rows']);
        WP_CLI\Utils\format_items($this->format($assoc), $rows, array_keys($rows[0]));
    }

    /**
     * wp seoproanalytics targets delete: delete some targets, or all.
     *
     * @param string[]             $what  The searches.
     * @param array<string,string> $assoc Options (all, yes).
     */
    private function targets_delete(array $what, array $assoc) {
        $all = !empty($assoc['all']);
        if ($all) {
            WP_CLI::confirm(__('Delete every search target?', 'seoproanalytics'), $assoc);
        }
        $done = $this->on_data($assoc, static function () use ($what, $all) {
            return SEOProAnalytics_Targets::delete($what, $all);
        });
        if (is_wp_error($done)) {
            WP_CLI::error($done->get_error_message());
        }
        /* translators: 1: targets deleted, 2: targets left */
        WP_CLI::success(sprintf(__('Deleted %1$d targets; %2$d left.', 'seoproanalytics'), $done['deleted'], $done['total']));
    }

    /**
     * Read the target list to import.
     *
     * @param string $file The file, or - for standard input.
     * @return string
     */
    private static function targets_text($file) {
        if ($file === '') {
            WP_CLI::error(__('Name the file to import, or - for standard input.', 'seoproanalytics'));
        }
        if ($file === '-') {
            return (string) stream_get_contents(STDIN); // phpcs:ignore WordPress.WP.AlternativeFunctions -- reads the list piped in.
        }
        if (is_readable($file) && is_file($file)) {
            return (string) file_get_contents($file, false, null, 0, SEOProAnalytics_Targets::MAX_BYTES + 1); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a local file the operator names.
        }
        /* translators: %s: file name */
        WP_CLI::error(sprintf(__('Cannot read %s.', 'seoproanalytics'), $file));
        return '';
    }

    /**
     * wp seoproanalytics targets import: read the list and import it.
     *
     * @param string[]             $what  The file (- for standard input).
     * @param array<string,string> $assoc Options.
     */
    private function targets_import(array $what, array $assoc) {
        $done = $this->targets_imported($what, $assoc);
        if ($this->format($assoc) === 'json') {
            WP_CLI::line((string) wp_json_encode($done, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            return;
        }
        foreach ($done['skipped'] as $skip) {
            /* translators: 1: row number, 2: search query, 3: why it is skipped */
            WP_CLI::warning(sprintf(__('Row %1$d (%2$s) skipped: %3$s', 'seoproanalytics'), $skip['row'], $skip['query'] !== '' ? $skip['query'] : '–', $skip['message']));
        }
        /* translators: 1: format, 2: added, 3: updated, 4: deleted, 5: skipped, 6: total */
        WP_CLI::success(sprintf(__('Read %1$s: %2$d added, %3$d updated, %4$d deleted, %5$d skipped; %6$d targets.', 'seoproanalytics'), $done['format'], $done['added'], $done['updated'], $done['removed'], count($done['skipped']), $done['total']));
    }

    /**
     * The import's answer: the SEO plugin's focus keywords (--from=seo-plugin),
     * or the list read from the file; exits on an error.
     *
     * @param string[]             $what  The file (- for standard input), or the searches with --from=seo-plugin.
     * @param array<string,string> $assoc Options.
     * @return array<string,mixed>
     */
    private function targets_imported(array $what, array $assoc) {
        $replace  = !empty($assoc['replace']);
        $only_new = !empty($assoc['only-new']);
        if (isset($assoc['from']) && (string) $assoc['from'] === 'seo-plugin') {
            require_once SEOPROANALYTICS_DIR . 'includes/stats/class-seoproanalytics-target-sources.php';
            $all = !empty($assoc['all-keywords']);
            return $this->on_data($assoc, static function () use ($what, $all) {
                return SEOProAnalytics_Target_Sources::import(array_map('strval', $what), $all);
            });
        }
        $text = self::targets_text(isset($what[0]) ? (string) $what[0] : '');
        return $this->on_data($assoc, static function () use ($text, $replace, $only_new) {
            return self::targets_import_text($text, $replace, $only_new);
        });
    }

    /**
     * Parse a target list (list or TOON) and import it.
     *
     * @param string $text     The list.
     * @param bool   $replace  Delete the targets not in it.
     * @param bool   $only_new Leave the targets already listed as they are.
     * @return array<string,mixed>|WP_Error
     */
    private static function targets_import_text($text, $replace, $only_new) {
        $parsed = SEOProAnalytics_Targets::parse($text);
        if (is_wp_error($parsed)) {
            return $parsed;
        }
        $done = SEOProAnalytics_Targets::import($parsed['rows'], $parsed['format'] === 'toon' ? 'aidevops' : 'list', $replace, $only_new);
        return is_wp_error($done) ? $done : array('format' => $parsed['format']) + $done;
    }

    /**
     * Query coverage of one page: the Google Search Console queries it
     * shows for, each with how far the page's own words cover it (title,
     * heading, text, partial or none), the words it lacks and whether it
     * is a question; and the focus keywords of Rank Math, Yoast SEO,
     * SEOPress or All in One SEO when one is active. The period is cut at
     * the newest day with search data and to its newest 366 days.
     *
     * ## OPTIONS
     *
     * <page>
     * : A path such as /pricing/, or a post ID.
     *
     * [--missing]
     * : Only queries the page does not cover (partial or none).
     *
     * [--questions]
     * : Only questions.
     *
     * [--range=<range>]
     * : As for stats.
     * ---
     * default: 90d
     * ---
     *
     * [--from=<date>]
     * : First day of a custom range.
     *
     * [--to=<date>]
     * : Last day of a custom range.
     *
     * [--data=<data>]
     * : live or demo.
     * ---
     * default: live
     * options:
     *   - live
     *   - demo
     * ---
     *
     * [--format=<format>]
     * : table, json, csv or yaml.
     * ---
     * default: table
     * ---
     *
     * ## EXAMPLES
     *
     *     wp seoproanalytics coverage /blog/speed-up-wordpress/
     *     wp seoproanalytics coverage 42 --missing
     *     wp seoproanalytics coverage /docs/faq/ --questions --format=json
     *
     * @param string[]             $args  Positional arguments.
     * @param array<string,string> $assoc Options.
     */
    public function coverage($args, $assoc) {
        $target = isset($args[0]) ? trim((string) $args[0]) : '';
        $post   = preg_match('/^\d+$/', $target) ? (int) $target : 0;
        $page   = $post ? '' : $target;
        $req    = $this->request($assoc + array('range' => '90d', 'compare' => 'none'));
        $answer = $this->on_data($assoc, static function () use ($req, $page, $post) {
            return SEOProAnalytics_Coverage::report($req, $page, $post);
        });
        $rows = array_values(array_filter($answer['rows'], static function ($row) use ($assoc) {
            return (empty($assoc['missing']) || in_array($row['match'], array('partial', 'none'), true)) && (empty($assoc['questions']) || $row['question']);
        }));
        if ($this->format($assoc) === 'json') {
            WP_CLI::line((string) wp_json_encode(array('rows' => $rows) + $answer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            return;
        }
        if (!$answer['connected']) {
            WP_CLI::warning(__('Google Search Console is not connected: wp seoproanalytics connect search-console --key-file=<file>.', 'seoproanalytics'));
        }
        $this->range_line($answer['range']);
        if (!$answer['text']) {
            WP_CLI::log(__('This page is not one post, so its words are not read: every query shows as not covered.', 'seoproanalytics'));
        }
        $totals = $answer['totals'];
        /* translators: 1: queries, 2: share of impressions covered, 3: queries not covered, 4: questions */
        WP_CLI::log(sprintf(__('%1$d queries; %2$s of impressions on queries the page covers; %3$d not covered; %4$d questions.', 'seoproanalytics'), $totals['queries'], sprintf(self::PERCENT_FORMAT, $totals['covered'] * 100), $totals['missing'], $totals['questions']));
        foreach ($answer['focus'] as $focus) {
            /* translators: 1: focus keyword, 2: SEO plugin, 3: match, 4: impressions */
            WP_CLI::log(sprintf(__('Focus keyword "%1$s" (%2$s): %3$s on the page, %4$d impressions.', 'seoproanalytics'), $focus['keyword'], $focus['source'], $focus['match'], $focus['impressions']));
        }
        if (!$rows) {
            WP_CLI::line(__('No queries of this kind in this range.', 'seoproanalytics'));
            return;
        }
        $out = array();
        foreach ($rows as $row) {
            $out[] = array(
                'query'       => $row['query'],
                'impressions' => $row['impressions'],
                'clicks'      => $row['clicks'],
                'position'    => sprintf('%.1f', $row['position']),
                'match'       => $row['match'],
                'missing'     => implode(' ', $row['missing']),
                'question'    => $row['question'] ? 'yes' : '',
            );
        }
        WP_CLI\Utils\format_items($this->format($assoc), $out, array_keys($out[0]));
    }

    /**
     * Content performance: per page, Google Search Console's clicks,
     * impressions, CTR and position with the visits from search that
     * landed on it (bounce rate, views per visit, time) and how many of
     * them reached a goal. The period is cut at the newest day with
     * search data.
     *
     * ## OPTIONS
     *
     * [--sort=<sort>]
     * : Order of the pages; impressions when left out. Conversions and conversion_rate need a goal.
     * ---
     * options:
     *   - impressions
     *   - clicks
     *   - ctr
     *   - position
     *   - visits
     *   - bounce_rate
     *   - visit_duration
     *   - conversions
     *   - conversion_rate
     * ---
     *
     * [--order=<order>]
     * : desc (most first) or asc. Left out: lowest first for position, most first for the rest.
     * ---
     * options:
     *   - desc
     *   - asc
     * ---
     *
     * [--goal=<id>]
     * : ID of the goal counted (wp seoproanalytics goals); the first when left out.
     *
     * [--engine=<engine>]
     * : google (Search Console), bing (Bing Webmaster Tools) or all (Combined: every engine with data added up) for the search figures.
     * ---
     * default: google
     * options:
     *   - google
     *   - bing
     *   - all
     * ---
     *
     * [--range=<range>]
     * : As for stats.
     * ---
     * default: 30d
     * ---
     *
     * [--from=<date>]
     * : First day of a custom range.
     *
     * [--to=<date>]
     * : Last day of a custom range.
     *
     * [--compare=<compare>]
     * : none, prev, week, month_weekday, month, year_weekday or year (as for stats).
     * ---
     * default: none
     * ---
     *
     * [--filter=<filters>]
     * : Page filters, as for stats (others do not apply to search data).
     *
     * [--owner=<owner>]
     * : Only pages of this owner: a user id, none, or me with --user (wp seoproanalytics owners).
     *
     * [--limit=<limit>]
     * : Most rows.
     * ---
     * default: 10
     * ---
     *
     * [--data=<data>]
     * : live or demo.
     * ---
     * default: live
     * options:
     *   - live
     *   - demo
     * ---
     *
     * [--format=<format>]
     * : table, json, csv or yaml.
     * ---
     * default: table
     * ---
     *
     * ## EXAMPLES
     *
     *     wp seoproanalytics content
     *     wp seoproanalytics content --sort=conversions --range=90d
     *     wp seoproanalytics content --filter=page:/blog/* --format=json
     *
     * @param string[]             $args  Positional arguments.
     * @param array<string,string> $assoc Options.
     */
    public function content($args, $assoc) { // NOSONAR: WP-CLI passes positional arguments even when this command uses only options.
        $sort   = isset($assoc['sort']) ? (string) $assoc['sort'] : '';
        $order  = isset($assoc['order']) ? (string) $assoc['order'] : '';
        $goal   = isset($assoc['goal']) ? (string) $assoc['goal'] : '';
        $engine = isset($assoc['engine']) ? (string) $assoc['engine'] : 'google';
        $owner  = $this->owner_filter($assoc);
        $req    = $this->request(array_diff_key($assoc, array('owner' => 1)) + array('range' => '30d', 'compare' => 'none'));
        $answer = $this->on_data($assoc, static function () use ($req, $sort, $goal, $engine, $order, $owner) {
            return SEOProAnalytics_Content::report($req, $sort, $goal, $engine, $order, $owner);
        });
        if ($this->format($assoc) === 'json') {
            WP_CLI::line((string) wp_json_encode($answer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            return;
        }
        $this->search_connected($answer);
        $this->range_line($answer['range']);
        if ($answer['partial']) {
            WP_CLI::log($answer['landings_from'] === ''
                ? __('Visits from search are still being summarised.', 'seoproanalytics')
                /* translators: %s: date */
                : sprintf(__('Visits from search are summarised from %s; older days are still being summarised.', 'seoproanalytics'), $answer['landings_from']));
        }
        if ($answer['ignored']) {
            /* translators: %s: filter dimensions. */
            WP_CLI::log(sprintf(__('Not applied to search data: %s.', 'seoproanalytics'), implode(', ', $answer['ignored'])));
        }
        $goal_name = $answer['goal'] ? $answer['goal']['name'] : '';
        if ($goal_name !== '') {
            /* translators: %s: goal name */
            WP_CLI::log(sprintf(__('Conversions: %s.', 'seoproanalytics'), $goal_name));
        }
        if (!$answer['rows']) {
            WP_CLI::line(__('No pages with search clicks or visits from search in this range.', 'seoproanalytics'));
            return;
        }
        $pct  = static function ($value) {
            return sprintf(self::PERCENT_FORMAT, (float) $value * 100);
        };
        $rows = array();
        foreach ($answer['rows'] as $row) {
            $item = array(
                'path'        => $row['path'],
                'clicks'      => $row['clicks'],
                'impressions' => $row['impressions'],
                'ctr'         => $pct($row['ctr']),
                'position'    => $row['impressions'] ? sprintf('%.1f', $row['position']) : '–',
                'visits'      => $row['visits'],
                'bounce_rate' => $pct($row['bounce_rate']),
                'duration'    => $row['visit_duration'] . 's',
            );
            if ($goal_name !== '') {
                $item['conversions']     = $row['conversions'];
                $item['conversion_rate'] = $pct($row['conversion_rate']);
            }
            $item['owner'] = self::owner_text($row['owner'] ?? null);
            $rows[] = $item;
        }
        WP_CLI\Utils\format_items($this->format($assoc), $rows, array_keys($rows[0]));
    }

    /**
     * The change log: posts published, unpublished and edited, SEO fields,
     * prices, stock and coupons, plugins, themes, WordPress, settings,
     * search engine updates and notes, newest first.
     *
     * ## OPTIONS
     *
     * [--page=<path>]
     * : Only changes to this page (* for any text), and the site-wide ones.
     *
     * [--kind=<kinds>]
     * : Only these kinds or groups (content, seo, product, site, search, note), comma-separated.
     *
     * [--range=<range>]
     * : As for stats.
     * ---
     * default: 30d
     * ---
     *
     * [--from=<date>]
     * : First day of a custom range.
     *
     * [--to=<date>]
     * : Last day of a custom range.
     *
     * [--limit=<limit>]
     * : Most rows.
     * ---
     * default: 50
     * ---
     *
     * [--offset=<offset>]
     * : Rows to skip.
     * ---
     * default: 0
     * ---
     *
     * [--data=<data>]
     * : live or demo.
     * ---
     * default: live
     * options:
     *   - live
     *   - demo
     * ---
     *
     * [--format=<format>]
     * : table, json, csv or yaml.
     * ---
     * default: table
     * ---
     *
     * ## EXAMPLES
     *
     *     wp seoproanalytics changes
     *     wp seoproanalytics changes --page=/pricing/ --range=90d
     *     wp seoproanalytics changes --kind=product,plugin_updated --format=json
     *     wp seoproanalytics changes --kind=search --range=12mo
     *
     * @param string[]             $args  Positional arguments.
     * @param array<string,string> $assoc Options.
     */
    public function changes($args, $assoc) {
        unset($args);
        $req    = $this->request($assoc + array('range' => '30d'));
        $opts   = array(
            'page'   => isset($assoc['page']) ? (string) $assoc['page'] : '',
            'kinds'  => isset($assoc['kind']) ? (string) $assoc['kind'] : '',
            'limit'  => isset($assoc['limit']) ? (int) $assoc['limit'] : 50,
            'offset' => isset($assoc['offset']) ? (int) $assoc['offset'] : 0,
        );
        $answer = $this->on_data($assoc, static function () use ($req, $opts) {
            return SEOProAnalytics_Changes::list_changes($req, $opts);
        });
        if (is_wp_error($answer)) {
            WP_CLI::error($answer->get_error_message());
        }
        if ($this->format($assoc) === 'json') {
            WP_CLI::line((string) wp_json_encode($answer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            return;
        }
        $this->range_line($answer['range']);
        if (!$answer['changes']) {
            WP_CLI::line(__('No changes in this range.', 'seoproanalytics'));
            return;
        }
        $rows = array();
        foreach ($answer['changes'] as $change) {
            $rows[] = array(
                't'      => $change['t'],
                'kind'   => $change['kind'],
                'path'   => (string) $change['path'],
                'label'  => $change['label'],
                'source' => $change['source'],
                'user'   => (string) $change['user'],
            );
        }
        WP_CLI\Utils\format_items($this->format($assoc), $rows, array('t', 'kind', 'path', 'label', 'source', 'user'));
        if ($answer['total'] > count($rows) + $opts['offset']) {
            /* translators: 1: changes shown, 2: changes in the range */
            WP_CLI::log(sprintf(__('%1$d of %2$d changes; see more with --offset.', 'seoproanalytics'), count($rows), $answer['total']));
        }
    }

    /**
     * Add a note to the timeline (something the change log cannot see,
     * such as a newsletter sent or a sale), or delete one.
     *
     * Notes show as markers on the charts and in the Changes section, and
     * in `wp seoproanalytics changes --kind=note`.
     *
     * ## OPTIONS
     *
     * [<note>]
     * : What happened (up to 190 characters).
     *
     * [--page=<path>]
     * : The page it is about; without it, the whole site.
     *
     * [--time=<when>]
     * : When, in the site time zone (2026-10-05 or "2026-10-05 14:30"); without it, now.
     *
     * [--delete=<id>]
     * : Delete this note instead (its id from `changes --kind=note --format=json`).
     *
     * [--data=<data>]
     * : live or demo.
     * ---
     * default: live
     * options:
     *   - live
     *   - demo
     * ---
     *
     * [--porcelain]
     * : Print only the new note's id.
     *
     * ## EXAMPLES
     *
     *     wp seoproanalytics annotate "Newsletter sent"
     *     wp seoproanalytics annotate "Sale started" --page=/shop/ --time="2026-10-05 09:00"
     *     wp seoproanalytics annotate --delete=42
     *
     * @param string[]             $args  Positional arguments.
     * @param array<string,string> $assoc Options.
     */
    public function annotate($args, $assoc) {
        if (isset($assoc['delete'])) {
            $id      = (int) $assoc['delete'];
            $deleted = $this->on_data($assoc, static function () use ($id) {
                return SEOProAnalytics_Changes::delete_note($id);
            });
            if (!$deleted) {
                /* translators: %d: note id */
                WP_CLI::error(sprintf(__('There is no note %d.', 'seoproanalytics'), $id));
            }
            /* translators: %d: note id */
            WP_CLI::success(sprintf(__('Note %d deleted.', 'seoproanalytics'), $id));
            return;
        }
        $note   = isset($args[0]) ? (string) $args[0] : '';
        $page   = isset($assoc['page']) ? (string) $assoc['page'] : '';
        $when   = isset($assoc['time']) ? (string) $assoc['time'] : '';
        $answer = $this->on_data($assoc, static function () use ($note, $page, $when) {
            return SEOProAnalytics_Changes::annotate($note, $page, $when);
        });
        if (is_wp_error($answer)) {
            WP_CLI::error($answer->get_error_message());
        }
        if (isset($assoc['porcelain'])) {
            WP_CLI::line((string) $answer['id']);
            return;
        }
        /* translators: 1: note id, 2: time, 3: page path or "the whole site" */
        WP_CLI::success(sprintf(__('Note %1$d added at %2$s, on %3$s.', 'seoproanalytics'), $answer['id'], $answer['t'], $answer['path'] !== null ? $answer['path'] : __('the whole site', 'seoproanalytics')));
    }

    /**
     * Experiments: a change and what it should do, measured before and
     * after against pages that were left alone, with the search engine
     * updates and other changes of the time named. The plugin suggests
     * keep, revise, undo or inconclusive; you decide.
     *
     * ## OPTIONS
     *
     * [<action>]
     * : list, add, show, decide, cancel, note or delete.
     * ---
     * default: list
     * options:
     *   - list
     *   - add
     *   - show
     *   - decide
     *   - cancel
     *   - note
     *   - delete
     * ---
     *
     * [<id-or-name>]
     * : The experiment's id (show, decide, cancel, note, delete) or, for add, its name: the change and what it should do, in one line.
     *
     * [<result>]
     * : For decide: keep, revise, undo or inconclusive.
     *
     * [--change=<id>]
     * : For add: the change it measures (its id from `changes --format=json`); its time is the start and its page the page.
     *
     * [--start=<when>]
     * : For add without --change: when the change was made (2026-10-05 or "2026-10-05 14:30"); without it, now.
     *
     * [--page=<paths>]
     * : For add: its page, or several, comma-separated (up to 50). For list: only experiments on this page.
     *
     * [--days=<days>]
     * : For add: days in each window: 7, 14, 28, 56 or 84.
     * ---
     * default: 28
     * ---
     *
     * [--engine=<engine>]
     * : For add: google or bing.
     * ---
     * default: google
     * ---
     *
     * [--metric=<metric>]
     * : For add: clicks, impressions, ctr, position, visits or conversions.
     * ---
     * default: clicks
     * ---
     *
     * [--direction=<direction>]
     * : For add: up or down (for position, up means a better place).
     * ---
     * default: up
     * ---
     *
     * [--threshold=<n>]
     * : For add: the smallest change that counts: percent (default 10), or places for position (default 1).
     *
     * [--goal=<id>]
     * : For add with --metric=conversions: the goal's id.
     *
     * [--hypothesis=<text>]
     * : For add: the reasoning, in more words.
     *
     * [--note=<text>]
     * : A note: for add, decide, cancel and note.
     *
     * [--status=<status>]
     * : For list: running, due, decided or cancelled.
     *
     * [--owner=<owner>]
     * : For list: only those of this owner: a user id, none, or me with --user (wp seoproanalytics owners).
     *
     * [--data=<data>]
     * : live or demo.
     * ---
     * default: live
     * options:
     *   - live
     *   - demo
     * ---
     *
     * [--format=<format>]
     * : table, json, csv or yaml.
     * ---
     * default: table
     * ---
     *
     * [--porcelain]
     * : For add: print only the new experiment's id.
     *
     * [--restore]
     * : For decide undo: restore the linked change set on live data.
     *
     * ## EXAMPLES
     *
     *     wp seoproanalytics experiments
     *     wp seoproanalytics experiments add "Shorter title lifts CTR" --change=812 --metric=ctr
     *     wp seoproanalytics experiments add "Internal links lift the guide" --page=/guide/ --metric=position --start=2026-09-01
     *     wp seoproanalytics experiments show 3 --format=json
     *     wp seoproanalytics experiments decide 3 keep --note="CTR up 18% against 120 unchanged pages"
     *     wp seoproanalytics experiments list --status=due
     *
     * @param string[]             $args  Positional arguments.
     * @param array<string,string> $assoc Options.
     */
    public function experiments($args, $assoc) {
        $action = isset($args[0]) ? (string) $args[0] : 'list';
        $second = isset($args[1]) ? (string) $args[1] : '';
        $id     = (int) $second;
        if (in_array($action, array('show', 'decide', 'cancel', 'note', 'delete'), true) && $id <= 0) {
            WP_CLI::error(__('Give the experiment\'s id: wp seoproanalytics experiments list shows them.', 'seoproanalytics'));
        }
        if ($action === 'delete') {
            $this->experiments_delete($id, $assoc);
            return;
        }
        if ($action === 'list') {
            $this->experiments_list($assoc);
            return;
        }

        $answer = $this->on_data($assoc, static function () use ($action, $second, $id, $args, $assoc) {
            return self::experiment_action($action, $second, $id, $args, $assoc);
        });
        if (is_wp_error($answer)) {
            WP_CLI::error($answer->get_error_message());
        }
        if ($action === 'add' && isset($assoc['porcelain'])) {
            WP_CLI::line((string) $answer['id']);
            return;
        }
        if ($this->format($assoc) === 'json') {
            self::json_line($answer);
            return;
        }
        $this->experiment_detail($answer);
        if ($action !== 'show') {
            $state = $answer['result'] !== null ? $answer['status'] . ' (' . $answer['result'] . ')' : $answer['status'];
            /* translators: 1: experiment id, 2: its state */
            WP_CLI::success(sprintf(__('Experiment %1$d: %2$s.', 'seoproanalytics'), $answer['id'], $state));
        }
    }

    /**
     * wp seoproanalytics experiments delete.
     *
     * @param int                  $id    Experiment id.
     * @param array<string,string> $assoc Options.
     */
    private function experiments_delete($id, array $assoc) {
        $deleted = $this->on_data($assoc, static function () use ($id) {
            return SEOProAnalytics_Experiments::delete($id);
        });
        if (!$deleted) {
            /* translators: %d: experiment id */
            WP_CLI::error(sprintf(__('There is no experiment %d.', 'seoproanalytics'), $id));
        }
        /* translators: %d: experiment id */
        WP_CLI::success(sprintf(__('Experiment %d deleted.', 'seoproanalytics'), $id));
    }

    /**
     * wp seoproanalytics experiments list.
     *
     * @param array<string,string> $assoc Options (status, page).
     */
    private function experiments_list(array $assoc) {
        $opts   = array(
            'status' => isset($assoc['status']) ? (string) $assoc['status'] : '',
            'page'   => isset($assoc['page']) ? (string) $assoc['page'] : '',
            'owner'  => $this->owner_filter($assoc),
        );
        $answer = $this->on_data($assoc, static function () use ($opts) {
            return SEOProAnalytics_Experiments::list_experiments($opts);
        });
        if (is_wp_error($answer)) {
            WP_CLI::error($answer->get_error_message());
        }
        if ($this->format($assoc) === 'json') {
            self::json_line($answer);
            return;
        }
        if (!$answer['experiments']) {
            WP_CLI::line(__('No experiments yet: wp seoproanalytics experiments add records one.', 'seoproanalytics'));
            return;
        }
        $rows = array_map(array($this, 'experiment_row'), $answer['experiments']);
        WP_CLI\Utils\format_items($this->format($assoc), $rows, array_keys($rows[0]));
    }

    /**
     * Add, show or change an experiment (in the data set chosen).
     *
     * @param string               $action add, show, decide, cancel or note.
     * @param string               $second The name (add) or id.
     * @param int                  $id     Experiment id.
     * @param string[]             $args   Positional arguments.
     * @param array<string,string> $assoc  Options.
     * @return array<string,mixed>|WP_Error
     */
    private static function experiment_action($action, $second, $id, array $args, array $assoc) {
        if ($action === 'add') {
            return SEOProAnalytics_Experiments::add(self::experiment_fields($second, $assoc));
        }
        if ($action === 'show') {
            return SEOProAnalytics_Experiments::get($id);
        }
        return SEOProAnalytics_Experiments::update($id, array(
            'action' => $action,
            'result' => isset($args[2]) ? (string) $args[2] : '',
            'note'   => isset($assoc['note']) ? (string) $assoc['note'] : '',
            'restore' => isset($assoc['restore']),
        ));
    }

    /**
     * Preview, apply and restore reviewed title and SEO field changes (live only).
     *
     * ## OPTIONS
     *
     * [<action>]
     * : list, show, apply or undo. Default list.
     *
     * [<file-or-id>]
     * : JSON plan file for apply; set id for show or undo.
     *
     * [--dry-run]
     * : Preview only, without any writes.
     *
     * [--yes]
     * : Skip apply confirmation.
     *
     * [--experiment=<name>]
     * : Start an experiment on the applied pages at apply time.
     *
     * [--metric=<metric>]
     * : clicks, impressions, ctr, position, visits or conversions.
     *
     * [--days=<days>]
     * : 7, 14, 28, 56 or 84.
     *
     * [--engine=<engine>]
     * : google or bing.
     *
     * [--direction=<direction>]
     * : up or down.
     *
     * [--threshold=<n>]
     * : Minimum percent change, or places for position.
     *
     * [--hypothesis=<text>]
     * : Experiment reasoning.
     *
     * [--goal=<id>]
     * : Goal for conversions.
     *
     * [--before=<id>]
     * : List sets older than this id, at most 50.
     *
     * [--format=<format>]
     * : json or table (details are JSON).
     *
     * @subcommand change-sets
     * @param string[] $args Positional arguments.
     * @param array<string,string> $assoc Options.
     */
    public function change_sets($args, $assoc) {
        require_once __DIR__ . '/class-seoproanalytics-change-sets.php';
        $action = $args[0] ?? 'list';
        $id = (int) ($args[1] ?? 0);
        if ($action === 'apply') {
            if (empty($args[1]) || !is_readable($args[1])) {
                WP_CLI::error(__('Give a readable JSON plan file.', 'seoproanalytics'));
            }
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local, explicitly named CLI input.
            $plan = json_decode((string) file_get_contents($args[1]), true);
            if (!is_array($plan)) {
                WP_CLI::error(__('The plan must be a JSON object.', 'seoproanalytics'));
            }
            $answer = SEOProAnalytics_Change_Sets::preview($plan);
            if (is_wp_error($answer)) {
                WP_CLI::error($answer->get_error_message());
            }
            self::json_line($answer);
            if (isset($assoc['dry-run'])) {
                return;
            }
            WP_CLI::confirm(__('Apply this reviewed plan to live pages?', 'seoproanalytics'), $assoc);
            $options = self::experiment_fields((string) ($assoc['experiment'] ?? ''), $assoc);
            $options['experiment'] = $assoc['experiment'] ?? '';
            $answer = SEOProAnalytics_Change_Sets::apply($plan, $options);
        } elseif ($action === 'undo' && $id > 0) {
            $answer = SEOProAnalytics_Change_Sets::undo($id);
        } elseif ($action === 'show' && $id > 0) {
            $answer = SEOProAnalytics_Change_Sets::get($id);
        } elseif ($action === 'list') {
            $answer = SEOProAnalytics_Change_Sets::list_sets((int) ($assoc['before'] ?? 0));
        } else {
            WP_CLI::error(__('Use list, show <id>, apply <file> or undo <id>.', 'seoproanalytics'));
            return;
        }
        if (is_wp_error($answer)) {
            WP_CLI::error($answer->get_error_message());
        }
        self::json_line($answer);
    }

    /**
     * A new experiment's fields from the command's options.
     *
     * @param string               $name  Its name.
     * @param array<string,string> $assoc Options.
     * @return array<string,mixed>
     */
    private static function experiment_fields($name, array $assoc) {
        $text = static function ($key, $default) use ($assoc) {
            return isset($assoc[$key]) ? (string) $assoc[$key] : $default;
        };
        return array(
            'name'       => $name,
            'change'     => isset($assoc['change']) ? (int) $assoc['change'] : 0,
            'start'      => $text('start', ''),
            'pages'      => $text('page', ''),
            'days'       => isset($assoc['days']) ? (int) $assoc['days'] : SEOProAnalytics_Experiments::DAYS,
            'engine'     => $text('engine', 'google'),
            'metric'     => $text('metric', 'clicks'),
            'direction'  => $text('direction', 'up'),
            'threshold'  => $text('threshold', ''),
            'goal'       => $text('goal', ''),
            'hypothesis' => $text('hypothesis', ''),
            'note'       => $text('note', ''),
        );
    }

    /**
     * A/B tests of blocks: every test with its visits per variant, leader
     * and verdict, or one test's variants side by side (every goal,
     * revenue, bounce rate, engaged time, clicks, uplift with its 95%
     * interval and the probability to beat the control). A test's numbers
     * cover its life; visits that saw two or more of its variants are
     * counted apart as mixed. Tests are started and ended in the block
     * editor. Not to be confused with experiments (a change and its
     * expected effect on search).
     *
     * ## OPTIONS
     *
     * [<id>]
     * : A test's id (from the list): show that test.
     *
     * [--status=<status>]
     * : For the list: draft, running, paused or ended.
     *
     * [--filter=<filters>]
     * : Only these visits, as for stats (such as channel:organic_search).
     *
     * [--data=<data>]
     * : live or demo.
     * ---
     * default: live
     * options:
     *   - live
     *   - demo
     * ---
     *
     * [--format=<format>]
     * : table, json, csv or yaml.
     * ---
     * default: table
     * ---
     *
     * ## EXAMPLES
     *
     *     wp seoproanalytics ab-tests
     *     wp seoproanalytics ab-tests --status=running --data=demo
     *     wp seoproanalytics ab-tests demohead01 --data=demo
     *     wp seoproanalytics ab-tests k3j9x2ab --filter="device:mobile" --format=json
     *
     * @subcommand ab-tests
     *
     * @param string[]             $args  Positional arguments.
     * @param array<string,string> $assoc Options.
     */
    public function ab_tests($args, $assoc) {
        $id      = isset($args[0]) ? (string) $args[0] : '';
        $filters = array();
        if (isset($assoc['filter'])) {
            $filter  = trim((string) $assoc['filter']);
            $filters = $filter !== '' && $filter[0] === '[' ? $filter : array_values(array_filter(array_map('trim', explode(';', $filter))));
        }
        $status = isset($assoc['status']) ? (string) $assoc['status'] : '';
        $answer = $this->on_data($assoc, static function () use ($id, $filters, $status) {
            return $id !== ''
                ? SEOProAnalytics_AB_Report::get($id, array('filters' => $filters))
                : SEOProAnalytics_AB_Report::list_tests(array('filters' => $filters, 'status' => $status));
        });
        if (is_wp_error($answer)) {
            WP_CLI::error($answer->get_error_message());
        }
        if ($this->format($assoc) === 'json') {
            self::json_line($answer);
            return;
        }
        if ($id === '') {
            $this->ab_tests_list($answer, $assoc);
            return;
        }
        $this->ab_test_detail($answer, $assoc);
    }

    /**
     * Print the list of A/B tests.
     *
     * @param array<string,mixed>  $answer From SEOProAnalytics_AB_Report::list_tests().
     * @param array<string,string> $assoc  Options.
     */
    private function ab_tests_list(array $answer, array $assoc) {
        if (!$answer['tests']) {
            WP_CLI::line(__('No A/B tests yet: add an A/B test block to a post or page and start it.', 'seoproanalytics'));
            return;
        }
        $rows = array();
        foreach ($answer['tests'] as $test) {
            $rows[] = array(
                'id'       => $test['id'],
                'name'     => $test['name'],
                'status'   => $test['status'],
                'page'     => (string) $test['post']['path'],
                'started'  => substr((string) $test['started'], 0, 10),
                'visits'   => $test['visits'],
                'mixed'    => $test['mixed']['visits'],
                'variants' => implode(', ', array_map(static function ($v) {
                    return sprintf('%s %d (%s)', $v['label'], $v['visits'], self::percent_text($v['rate']));
                }, $test['variants'])),
                'metric'   => $test['primary']['name'],
                'leader'   => $test['leader'] ? $test['leader']['label'] : '',
                'verdict'  => $test['verdict']['code'],
            );
        }
        WP_CLI\Utils\format_items($this->format($assoc), $rows, array_keys($rows[0]));
    }

    /**
     * Print one A/B test's variants side by side.
     *
     * @param array<string,mixed>  $answer From SEOProAnalytics_AB_Report::get().
     * @param array<string,string> $assoc  Options.
     */
    private function ab_test_detail(array $answer, array $assoc) {
        $test = $answer['test'];
        /* translators: 1: test id, 2: name, 3: status */
        WP_CLI::log(sprintf(__('A/B test %1$s: %2$s (%3$s)', 'seoproanalytics'), $test['id'], $test['name'], $test['status']));
        /* translators: 1: first day, 2: last day, 3: days, 4: visits, 5: mixed visits */
        WP_CLI::log(sprintf(__('%1$s to %2$s (%3$d days): %4$d visits saw it; %5$d saw more than one variant (left out).', 'seoproanalytics'), substr($answer['period']['from'], 0, 10), substr($answer['period']['to'], 0, 10), $answer['period']['days'], $answer['visits'], $answer['mixed']['visits']));
        $rows = array_map(array(__CLASS__, 'ab_variant_row'), $answer['variants']);
        $keys = array();
        foreach ($rows as $row) {
            $keys = array_merge($keys, array_keys($row));
        }
        $keys = array_values(array_unique($keys));
        foreach ($rows as $i => $row) {
            $rows[$i] = array_merge(array_fill_keys($keys, ''), $row);
        }
        WP_CLI\Utils\format_items($this->format($assoc), $rows, $keys);
        WP_CLI::log($answer['verdict']['text']);
    }

    /**
     * One A/B test variant as a table row.
     *
     * @param array<string,mixed> $v Variant.
     * @return array<string,mixed>
     */
    private static function ab_variant_row(array $v) {
        $row = array(
            'variant'     => $v['label'] . ($v['control'] ? ' ' . __('(control)', 'seoproanalytics') : ''),
            'visits'      => $v['visits'],
            'bounce_rate' => self::percent_text($v['bounce_rate']),
            'engaged'     => $v['engaged_time'] . 's',
            'clicked'     => self::percent_text($v['click_rate']),
        );
        foreach ($v['goals'] as $goal) {
            $row[$goal['name']] = sprintf('%d (%s)', $goal['conversions'], self::percent_text($goal['rate']));
            if ($goal['revenue']) {
                $row[$goal['name'] . ' revenue'] = self::money_text($goal['revenue']);
            }
        }
        $p               = $v['primary'];
        $row['uplift']   = $p['uplift'] === null ? '' : sprintf(self::CHANGE_PERCENT_FORMAT, $p['uplift'] * 100);
        $row['interval'] = $p['interval'] === null ? '' : sprintf('%+.1f%% to %+.1f%%', $p['interval'][0] * 100, $p['interval'][1] * 100);
        $row['beats']    = $p['probability'] === null ? '' : self::percent_text($p['probability']);
        $row['verdict']  = $p['verdict'];
        return $row;
    }

    /**
     * A rate as a percentage with one decimal.
     *
     * @param float|int $rate 0 to 1.
     * @return string
     */
    private static function percent_text($rate) {
        return sprintf(self::PERCENT_FORMAT, (float) $rate * 100);
    }

    /**
     * The decision queue: one ranked list of search work, made from
     * Opportunities (low CTR, missing from the page, striking distance,
     * losing clicks, overlapping pages), the content audit, internal links,
     * indexation, the refresh planner (update, leave, protect or merge a
     * page losing clicks) and search targets (shown with another page, or
     * high priority in striking distance). Each item says why it is listed and how its score is
     * made: potential clicks per 28 days × value (how well the page's
     * visits from search convert) × confidence ÷ effort. Pages with a
     * running experiment are left out. Done opens an experiment on the
     * page (not for a page left as it is); dismissed items stay hidden for 90 days.
     *
     * ## OPTIONS
     *
     * [<action>]
     * : list, accept, done, dismiss, restore, effort or note.
     * ---
     * default: list
     * options:
     *   - list
     *   - accept
     *   - done
     *   - dismiss
     *   - restore
     *   - effort
     *   - note
     * ---
     *
     * [<key>]
     * : The item's key (from the list), for every action but list.
     *
     * [<effort>]
     * : For effort: 1 (least) to 5.
     *
     * [--status=<status>]
     * : For list: open (new and accepted), new, accepted, done, dismissed or all.
     * ---
     * default: open
     * ---
     *
     * [--kind=<kind>]
     * : For list: only items of this kind: ctr, missing, striking, decay, overlap, audit, links, index, refresh or target.
     *
     * [--owner=<owner>]
     * : For list: only items of this owner: a user id, none, or me with --user (wp seoproanalytics owners).
     *
     * [--engine=<engine>]
     * : google or bing.
     * ---
     * default: google
     * ---
     *
     * [--goal=<id>]
     * : The goal whose conversions give a page its value; the first goal when left out.
     *
     * [--range=<range>]
     * : The period the list is made from, as for stats (at most its newest 366 days are read).
     * ---
     * default: 90d
     * ---
     *
     * [--from=<date>]
     * : First day of a custom range.
     *
     * [--to=<date>]
     * : Last day of a custom range.
     *
     * [--filter=<filters>]
     * : Page filters, as for stats.
     *
     * [--limit=<limit>]
     * : Most items.
     * ---
     * default: 20
     * ---
     *
     * [--note=<text>]
     * : A note (up to 190 characters), with any action.
     *
     * [--name=<name>]
     * : For done: the experiment's name; without it, one made from the item.
     *
     * [--days=<days>]
     * : For done: days in each window of the experiment: 7, 14, 28, 56 or 84.
     * ---
     * default: 28
     * ---
     *
     * [--threshold=<n>]
     * : For done: the smallest change that counts: percent (default 10), or places for position (default 1).
     *
     * [--data=<data>]
     * : live or demo.
     * ---
     * default: live
     * options:
     *   - live
     *   - demo
     * ---
     *
     * [--format=<format>]
     * : table, json, csv or yaml.
     * ---
     * default: table
     * ---
     *
     * ## EXAMPLES
     *
     *     wp seoproanalytics queue
     *     wp seoproanalytics queue --status=done --format=json
     *     wp seoproanalytics queue --kind=refresh --range=30d
     *     wp seoproanalytics queue accept 3f9c0a1b2d4e5f60
     *     wp seoproanalytics queue done 3f9c0a1b2d4e5f60 --note="New title and description"
     *     wp seoproanalytics queue effort 3f9c0a1b2d4e5f60 3
     *     wp seoproanalytics queue dismiss 3f9c0a1b2d4e5f60 --note="Brand query; not worth it"
     *
     * @param string[]             $args  Positional arguments.
     * @param array<string,string> $assoc Options.
     */
    public function queue($args, $assoc) {
        $action = isset($args[0]) ? (string) $args[0] : 'list';
        $key    = isset($args[1]) ? (string) $args[1] : '';
        $engine = isset($assoc['engine']) ? (string) $assoc['engine'] : 'google';
        $goal   = isset($assoc['goal']) ? (string) $assoc['goal'] : '';
        $req    = $this->request(array_diff_key($assoc, array('owner' => 1)) + array('range' => '90d', 'limit' => '20', 'compare' => 'none'));
        if ($action !== 'list') {
            $this->queue_update($action, $key, $args, $assoc, $req, $engine, $goal);
            return;
        }
        $status = isset($assoc['status']) ? (string) $assoc['status'] : 'open';
        $kind   = isset($assoc['kind']) ? (string) $assoc['kind'] : '';
        $owner  = $this->owner_filter($assoc);
        $answer = $this->on_data($assoc, static function () use ($req, $engine, $status, $goal, $kind, $owner) {
            return SEOProAnalytics_Queue::report($req, $engine, $status, $goal, $kind, $owner);
        });
        if (is_wp_error($answer)) {
            WP_CLI::error($answer->get_error_message());
        }
        if ($this->format($assoc) === 'json') {
            self::json_line($answer);
            return;
        }
        $this->queue_list($answer, $assoc);
    }

    /**
     * wp seoproanalytics queue <action> <key>: change an item's state.
     *
     * @param string               $action accept, done, dismiss, restore, effort or note.
     * @param string               $key    Item key.
     * @param string[]             $args   Positional arguments.
     * @param array<string,string> $assoc  Options.
     * @param array<string,mixed>  $req    Report request.
     * @param string               $engine Search engine.
     * @param string               $goal   Goal id.
     */
    private function queue_update($action, $key, array $args, array $assoc, array $req, $engine, $goal) {
        if ($key === '') {
            WP_CLI::error(__('Give the item\'s key: wp seoproanalytics queue lists them.', 'seoproanalytics'));
        }
        $input  = array(
            'action'    => $action,
            'effort'    => isset($args[2]) ? (int) $args[2] : 0,
            'note'      => isset($assoc['note']) ? (string) $assoc['note'] : '',
            'name'      => isset($assoc['name']) ? (string) $assoc['name'] : '',
            'days'      => isset($assoc['days']) ? (int) $assoc['days'] : SEOProAnalytics_Experiments::DAYS,
            'threshold' => isset($assoc['threshold']) ? (string) $assoc['threshold'] : '',
        );
        $answer = $this->on_data($assoc, static function () use ($key, $input, $req, $engine, $goal) {
            return SEOProAnalytics_Queue::update($key, $input, $req, $engine, $goal);
        });
        if (is_wp_error($answer)) {
            WP_CLI::error($answer->get_error_message());
        }
        if ($this->format($assoc) === 'json') {
            self::json_line($answer);
            return;
        }
        WP_CLI\Utils\format_items('table', array($this->queue_row($answer)), array_keys($this->queue_row($answer)));
        if ($answer['experiment']) {
            /* translators: 1: experiment id, 2: its name, 3: review day */
            WP_CLI::log(sprintf(__('Experiment %1$d: %2$s (review %3$s).', 'seoproanalytics'), $answer['experiment']['id'], $answer['experiment']['name'], $answer['experiment']['review']));
        }
        /* translators: 1: item key, 2: its state */
        WP_CLI::success(sprintf(__('Item %1$s: %2$s.', 'seoproanalytics'), $answer['key'], $answer['status']));
    }

    /**
     * Print the decision queue.
     *
     * @param array<string,mixed>  $answer From SEOProAnalytics_Queue::report().
     * @param array<string,string> $assoc  Options.
     */
    private function queue_list(array $answer, array $assoc) {
        $this->search_connected($answer);
        $this->range_line($answer['range']);
        /* translators: 1: new, 2: accepted, 3: done, 4: dismissed */
        WP_CLI::log(sprintf(__('New %1$d, accepted %2$d, done %3$d, dismissed %4$d.', 'seoproanalytics'), $answer['counts']['new'], $answer['counts']['accepted'], $answer['counts']['done'], $answer['counts']['dismissed']));
        if ($answer['left_out']) {
            /* translators: %d: items */
            WP_CLI::log(sprintf(_n('%d item left out: its page has a running experiment.', '%d items left out: their pages have a running experiment.', $answer['left_out'], 'seoproanalytics'), $answer['left_out']));
        }
        if (!$answer['items']) {
            WP_CLI::line(__('Nothing to do in this state for this period.', 'seoproanalytics'));
            return;
        }
        $rows = array_map(array($this, 'queue_row'), $answer['items']);
        WP_CLI\Utils\format_items($this->format($assoc), $rows, array_keys($rows[0]));
        WP_CLI::log(__('Score = potential clicks per 28 days × value × confidence ÷ effort. --format=json gives each item\'s why and figures.', 'seoproanalytics'));
    }

    /**
     * One queue item as a table row.
     *
     * @param array<string,mixed> $item Item.
     * @return array<string,string|int|float>
     */
    private function queue_row(array $item) {
        $exp = is_array($item['experiment']) ? $item['experiment'] : null;
        return array(
            'key'        => $item['key'],
            'status'     => $item['status'] . ($item['found'] ? '' : ' *'),
            // With the audit finding, links or indexation list, or refresh proposal.
            'kind'       => $item['kind'] . (isset($item['finding']) && $item['finding'] !== '' ? ': ' . $item['finding'] : ''),
            'page'       => $item['path'],
            'query'      => (string) $item['query'],
            'score'      => $item['score'],
            'clicks'     => $item['parts']['clicks'],
            'value'      => $item['parts']['value'],
            'confidence' => $item['parts']['confidence'],
            'effort'     => $item['parts']['effort'],
            'experiment' => $exp ? self::queue_experiment_text($exp) : '',
            'owner'      => self::owner_text($item['owner'] ?? null),
        );
    }

    /**
     * A queue item's experiment: its id and result, or its state with the
     * suggested result.
     *
     * @param array<string,mixed> $exp Experiment summary.
     * @return string
     */
    private static function queue_experiment_text(array $exp) {
        if ($exp['result'] !== null) {
            return '#' . $exp['id'] . ' ' . (string) $exp['result'];
        }
        $text = '#' . $exp['id'] . ' ' . ($exp['due'] ? 'due' : (string) $exp['status']);
        return $exp['suggested'] !== null ? $text . ' (' . $exp['suggested'] . ')' : $text;
    }

    /**
     * The loop export: one answer per cycle for an agent. The decision
     * queue's open items, the experiments due for review, running and
     * decided in the last 90 days with their results, and the period's
     * search figures per query and page in the aidevops export layout
     * (query, page, clicks, impressions, CTR, position). Steps for an
     * agent: docs/seo-loop-recipes.md.
     *
     * ## OPTIONS
     *
     * [--engine=<engine>]
     * : google or bing.
     * ---
     * default: google
     * ---
     *
     * [--goal=<id>]
     * : The goal whose conversions give a page its value in the queue; the first goal when left out.
     *
     * [--range=<range>]
     * : The period, as for stats (at most its newest 366 days are read).
     * ---
     * default: 30d
     * ---
     *
     * [--from=<date>]
     * : First day of a custom range.
     *
     * [--to=<date>]
     * : Last day of a custom range.
     *
     * [--filter=<filters>]
     * : Page filters, as for stats.
     *
     * [--limit=<limit>]
     * : Most queue items.
     * ---
     * default: 20
     * ---
     *
     * [--rows=<rows>]
     * : Most export rows (a search and a page each), most impressions first.
     * ---
     * default: 1000
     * ---
     *
     * [--data=<data>]
     * : live or demo.
     * ---
     * default: live
     * options:
     *   - live
     *   - demo
     * ---
     *
     * [--format=<format>]
     * : table (a summary), json (the whole answer, as GET /loop) or toon (only the export, as an aidevops export file).
     * ---
     * default: table
     * ---
     *
     * ## EXAMPLES
     *
     *     wp seoproanalytics loop
     *     wp seoproanalytics loop --format=json
     *     wp seoproanalytics loop --data=demo --format=json
     *     wp seoproanalytics loop --range=90d --rows=5000 --format=toon > gsc-export.toon
     *
     * @param string[]             $args  Positional arguments.
     * @param array<string,string> $assoc Options.
     */
    public function loop($args, $assoc) {
        require_once SEOPROANALYTICS_DIR . 'includes/stats/class-seoproanalytics-loop.php';
        $engine = isset($assoc['engine']) ? (string) $assoc['engine'] : 'google';
        $goal   = isset($assoc['goal']) ? (string) $assoc['goal'] : '';
        $rows   = isset($assoc['rows']) ? (int) $assoc['rows'] : SEOProAnalytics_Loop::ROWS;
        $format = isset($assoc['format']) ? (string) $assoc['format'] : 'table';
        $req    = $this->request(array_diff_key($assoc, array('rows' => 1, 'format' => 1)) + array('range' => SEOProAnalytics_Loop::RANGE, 'limit' => (string) SEOProAnalytics_Loop::ITEMS, 'compare' => 'none'));
        $answer = $this->on_data($assoc, static function () use ($req, $engine, $goal, $rows) {
            return SEOProAnalytics_Loop::report($req, $engine, $goal, $rows);
        });
        if (is_wp_error($answer)) {
            WP_CLI::error($answer->get_error_message());
        }
        if ($format === 'toon') {
            WP_CLI::line(rtrim(SEOProAnalytics_Loop::toon($answer['export']), "\n"));
            return;
        }
        if ($format === 'json') {
            WP_CLI::line((string) wp_json_encode($answer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            return;
        }
        $this->search_connected($answer);
        $this->range_line($answer['range']);
        $sum = $answer['summary'];
        /* translators: 1: new, 2: accepted, 3: experiments due, 4: running, 5: decided lately, 6: days, 7: export rows */
        WP_CLI::log(sprintf(__('Queue: %1$d new, %2$d accepted. Experiments: %3$d due, %4$d running, %5$d decided in the last %6$d days. Export: %7$d rows.', 'seoproanalytics'), $sum['new'], $sum['accepted'], $sum['due'], $sum['running'], $sum['decided'], $answer['experiments']['recent_days'], $sum['rows']));
        if ($answer['experiments']['due']) {
            WP_CLI::log(__('Due for review (decide with: wp seoproanalytics experiments decide <id> <result>):', 'seoproanalytics'));
            $due = array_map(array($this, 'experiment_row'), $answer['experiments']['due']);
            WP_CLI\Utils\format_items('table', $due, array_keys($due[0]));
        }
        if ($answer['queue']['items']) {
            WP_CLI::log(__('Open queue items, best first:', 'seoproanalytics'));
            $items = array_map(array($this, 'queue_row'), $answer['queue']['items']);
            WP_CLI\Utils\format_items('table', $items, array_keys($items[0]));
        }
        WP_CLI::log(__('--format=json gives the whole answer (as GET /loop); --format=toon the export rows as an aidevops export file.', 'seoproanalytics'));
    }

    /**
     * One experiment as a table row.
     *
     * @param array<string,mixed> $item Experiment.
     * @return array<string,string|int>
     */
    private function experiment_row(array $item) {
        $m = is_array($item['measurement']) ? $item['measurement'] : array();
        return array(
            'id'        => $item['id'],
            'status'    => $item['due'] ? 'due' : $item['status'],
            'name'      => $item['name'],
            'pages'     => implode(', ', $item['pages']),
            'start'     => substr((string) $item['start'], 0, 10),
            'review'    => $item['review'],
            'metric'    => $item['metric'] . ' ' . $item['direction'],
            'effect'    => isset($m['effect']) ? self::effect_text($item['metric'], $m['effect']) : '',
            'suggested' => isset($m['suggested']) ? (string) $m['suggested'] : '',
            'result'    => (string) $item['result'],
            'owner'     => self::owner_text($item['owner'] ?? null),
        );
    }

    /**
     * Print one experiment and its measurement.
     *
     * @param array<string,mixed> $item Experiment.
     */
    private function experiment_detail(array $item) {
        $m = is_array($item['measurement']) ? $item['measurement'] : array();
        /* translators: 1: id, 2: name */
        WP_CLI::log(sprintf(__('Experiment %1$d: %2$s', 'seoproanalytics'), $item['id'], $item['name']));
        /* translators: 1: pages, 2: start, 3: measure, 4: direction, 5: threshold */
        WP_CLI::log(sprintf(__('Pages: %1$s. Start: %2$s. Expected: %3$s %4$s by at least %5$s.', 'seoproanalytics'), implode(', ', $item['pages']), $item['start'], $item['metric'], $item['direction'], self::threshold_text($item['metric'], $item['threshold'])));
        if (!$m) {
            /* translators: %s: state */
            WP_CLI::log(sprintf(__('Status: %s.', 'seoproanalytics'), $item['status']));
            return;
        }
        /* translators: 1: before from, 2: before to, 3: after from, 4: after to, 5: review day */
        WP_CLI::log(sprintf(__('Before %1$s to %2$s; after %3$s to %4$s; review %5$s.', 'seoproanalytics'), $m['windows']['before']['from'], $m['windows']['before']['to'], $m['windows']['after']['from'], $m['windows']['after']['to'], $m['review']));
        if ($m['state'] === 'running') {
            /* translators: 1: days of data so far, 2: days needed, 3: newest day with data */
            WP_CLI::log(sprintf(__('Running: %1$d of %2$d days of data after the change (data through %3$s).', 'seoproanalytics'), $m['so_far'], $m['days'], (string) $m['through']));
            return;
        }
        $rows = array();
        foreach (array('pages', 'group') as $side) {
            foreach (array('before', 'after') as $when) {
                $rows[] = array('who' => $side, 'when' => $when, 'pages' => $m[$side]['count']) + array_map(static function ($v) {
                    return $v === null ? '–' : $v;
                }, $m[$side][$when]);
            }
        }
        WP_CLI\Utils\format_items('table', $rows, array_keys($rows[0]));
        WP_CLI::log((string) $m['summary']);
        if ($m['noise']) {
            /* translators: 1: low, 2: high, 3: pages */
            WP_CLI::log(sprintf(__('Usual spread of unchanged pages: %1$s to %2$s (%3$d pages).', 'seoproanalytics'), self::effect_text($item['metric'], $m['noise']['low']), self::effect_text($item['metric'], $m['noise']['high']), $m['noise']['pages']));
        }
        self::confounders_lines($m['confounders']);
        /* translators: 1: suggested result, 2: reasons */
        WP_CLI::log(sprintf(__('Suggested: %1$s (%2$s).', 'seoproanalytics'), $m['suggested'], implode(', ', $m['reasons'])));
        if ($item['note'] !== '') {
            /* translators: %s: note */
            WP_CLI::log(sprintf(__('Note: %s', 'seoproanalytics'), $item['note']));
        }
    }

    /**
     * An experiment's threshold as text: places for position, else percent.
     *
     * @param string           $metric    Metric name.
     * @param string|int|float $threshold Threshold.
     * @return string
     */
    private static function threshold_text($metric, $threshold) {
        if ($metric !== 'position') {
            return $threshold . '%';
        }
        /* translators: %s: places, such as 1 or 1.5 */
        return sprintf(_n('%s place', '%s places', (int) ceil((float) $threshold), 'seoproanalytics'), $threshold);
    }

    /**
     * Print what else changed during an experiment, by kind.
     *
     * @param array<string,array<int,array<string,mixed>>> $confounders Changes by kind.
     */
    private static function confounders_lines(array $confounders) {
        foreach (array('updates' => __('Search engine updates', 'seoproanalytics'), 'site' => __('Site-wide changes', 'seoproanalytics'), 'pages' => __('Other changes on its pages', 'seoproanalytics')) as $key => $label) {
            if ($confounders[$key]) {
                WP_CLI::log($label . ': ' . implode('; ', array_map(static function ($c) {
                    return substr((string) $c['t'], 0, 10) . ' ' . $c['label'];
                }, $confounders[$key])));
            }
        }
    }

    /**
     * An effect as text: places up or down for position (the JSON keeps
     * the change in position, lower is better), else percent.
     *
     * @param string     $metric Metric name.
     * @param float|null $effect Effect.
     * @return string
     */
    private static function effect_text($metric, $effect) {
        if ($effect === null) {
            return '–';
        }
        return $metric === 'position' ? SEOProAnalytics_Experiments::places_text($effect) : sprintf(self::CHANGE_PERCENT_FORMAT, $effect * 100);
    }

    /**
     * Run a definition action (list, add, update, delete) for goals or
     * funnels on the --data set; exits on an error.
     *
     * @param string               $type   goals or funnels.
     * @param string               $action list, add, update or delete.
     * @param string               $id     Id, for update and delete.
     * @param array<string,string> $assoc  Options.
     * @param callable             $save   Takes the id ('' to add); saves.
     */
    private function define($type, $action, $id, array $assoc, callable $save) {
        if (in_array($action, array('update', 'delete'), true) && $id === '') {
            /* translators: %s: goals or funnels */
            WP_CLI::error(sprintf(__('Give the id: wp seoproanalytics %s list shows them.', 'seoproanalytics'), $type));
        }
        $answer = $this->on_data($assoc, static function () use ($type, $action, $id, $save) {
            return self::define_action($type, $action, $id, $save);
        });
        if (is_wp_error($answer)) {
            WP_CLI::error($answer->get_error_message());
        }
        if ($action === 'delete') {
            WP_CLI::success(__('Deleted.', 'seoproanalytics'));
            return;
        }
        if ($action === 'list') {
            $this->define_list($answer, $assoc);
            return;
        }
        WP_CLI::line((string) wp_json_encode($answer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        WP_CLI::success($action === 'add' ? __('Added.', 'seoproanalytics') : __('Updated.', 'seoproanalytics'));
    }

    /**
     * List, delete or save goals or funnels (in the data set chosen).
     *
     * @param string   $type   goals or funnels.
     * @param string   $action list, add, update or delete.
     * @param string   $id     Id, for update and delete.
     * @param callable $save   Takes the id ('' to add); saves.
     * @return mixed
     */
    private static function define_action($type, $action, $id, callable $save) {
        if ($action === 'list') {
            return $type === 'funnels' ? SEOProAnalytics_Goals::funnels() : SEOProAnalytics_Goals::goals();
        }
        if ($action === 'delete') {
            return SEOProAnalytics_Goals::delete($type, $id) ? true : new WP_Error('seoproanalytics_not_found', __('There is no such goal or funnel.', 'seoproanalytics'));
        }
        return $save($action === 'update' ? $id : '');
    }

    /**
     * Print the goals or funnels.
     *
     * @param mixed                $answer Goals or funnels.
     * @param array<string,string> $assoc  Options.
     */
    private function define_list($answer, array $assoc) {
        if ($this->format($assoc) === 'json') {
            WP_CLI::line((string) wp_json_encode($answer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            return;
        }
        $items = array();
        foreach ((array) $answer as $item) {
            if (isset($item['steps'])) {
                $item['steps'] = implode(' → ', array_map(static function ($step) {
                    return $step['kind'] . ':' . $step['match'];
                }, $item['steps']));
            }
            $items[] = $item;
        }
        if (!$items) {
            WP_CLI::line(__('None yet.', 'seoproanalytics'));
            return;
        }
        WP_CLI\Utils\format_items($this->format($assoc), $items, array_keys($items[0]));
    }

    /**
     * Funnel steps from "kind:match;kind:match" or a JSON list.
     *
     * @param string $text Steps.
     * @return array<int,array<string,string>>
     */
    private static function steps($text) {
        $text = trim($text);
        if ($text !== '' && $text[0] === '[') {
            $json = json_decode($text, true);
            return is_array($json) ? $json : array();
        }
        $steps = array();
        foreach (array_filter(array_map('trim', explode(';', $text))) as $part) {
            $pair    = explode(':', $part, 2);
            $steps[] = array('kind' => $pair[0], 'match' => isset($pair[1]) ? $pair[1] : '');
        }
        return $steps;
    }

    /**
     * Revenue per currency as text: "USD 1234.00; GBP 99.00".
     *
     * @param array<int,array{currency:string,amount:float}> $money Revenue.
     * @return string
     */
    private static function money_text(array $money) {
        return implode('; ', array_map(static function ($row) {
            return sprintf('%s %.2f', $row['currency'], $row['amount']);
        }, $money));
    }

    /**
     * Visitors on the site in the last 30 minutes.
     *
     * ## OPTIONS
     *
     * [--data=<data>]
     * : live or demo.
     * ---
     * default: live
     * options:
     *   - live
     *   - demo
     * ---
     *
     * [--format=<format>]
     * : table or json.
     * ---
     * default: table
     * ---
     *
     * @param string[]             $args  Positional arguments.
     * @param array<string,string> $assoc Options.
     */
    public function realtime($args, $assoc) { // NOSONAR: WP-CLI passes positional arguments even when this command uses only options.
        $answer = $this->on_data($assoc, array('SEOProAnalytics_Query', 'realtime'));
        if ($this->format($assoc) === 'json') {
            WP_CLI::line((string) wp_json_encode($answer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            return;
        }
        /* translators: 1: visitors, 2: pageviews */
        WP_CLI::line(sprintf(__('%1$d visitors and %2$d pageviews in the last 30 minutes.', 'seoproanalytics'), $answer['visitors'], $answer['pageviews']));
        if ($answer['pages']) {
            WP_CLI\Utils\format_items('table', $answer['pages'], array('label', 'count'));
        }
    }

    /**
     * Process buffered hits now, instead of waiting for the minute cron.
     *
     * @param string[]             $args  Positional arguments.
     * @param array<string,string> $assoc Options.
     */
    public function process($args, $assoc) {
        require_once SEOPROANALYTICS_DIR . 'includes/stats/class-seoproanalytics-processor.php';
        $start  = microtime(true);
        $totals = SEOProAnalytics_Processor::run();
        WP_CLI::success(sprintf(
            /* translators: 1: lines, 2: pageviews, 3: events, 4: clicks and form submits, 5: bot hits, 6: skipped hits, 7: milliseconds */
            __('%1$d lines: %2$d pageviews, %3$d events, %4$d clicks, %5$d bot hits dropped, %6$d skipped, in %7$d ms.', 'seoproanalytics'),
            $totals['lines'],
            $totals['pageviews'],
            $totals['events'],
            $totals['clicks'],
            $totals['bots'],
            $totals['skipped'],
            (int) round((microtime(true) - $start) * 1000)
        ));
    }

    /**
     * Summarise finished days into the daily summaries now, instead of
     * waiting for the minute cron, or rebuild given days.
     *
     * A day is summarised an hour after it ends, once every hit received
     * by then is processed. Reports read the summaries for whole days.
     *
     * ## OPTIONS
     *
     * [--from=<date>]
     * : Rebuild days from this one (YYYY-MM-DD), with --to.
     *
     * [--to=<date>]
     * : Last day to rebuild. Days must be over, and not past their retention.
     *
     * ## EXAMPLES
     *
     *     wp seoproanalytics rollup
     *     wp seoproanalytics rollup --from=2026-10-01 --to=2026-10-05
     *
     * @param string[]             $args  Positional arguments.
     * @param array<string,string> $assoc Options.
     */
    public function rollup($args, $assoc) { // NOSONAR: WP-CLI passes positional arguments even when this command uses only options.
        $this->need_tables();
        $from = isset($assoc['from']) ? (string) $assoc['from'] : '';
        $to   = isset($assoc['to']) ? (string) $assoc['to'] : '';
        if ($from !== '' || $to !== '') {
            $tz    = wp_timezone();
            $first = DateTimeImmutable::createFromFormat('!Y-m-d', $from, $tz);
            $last  = DateTimeImmutable::createFromFormat('!Y-m-d', $to, $tz);
            if (!$first || !$last || $first->format('Y-m-d') !== $from || $last->format('Y-m-d') !== $to || $first > $last) {
                WP_CLI::error(__('Give --from and --to as dates (YYYY-MM-DD), from not after to.', 'seoproanalytics'));
                return;
            }
            if ($last >= new DateTimeImmutable('today', $tz)) {
                WP_CLI::error(__('Only days that are over can be summarised.', 'seoproanalytics'));
                return;
            }
            $built = 0;
            for ($day = $first; $day <= $last; $day = $day->modify('+1 day')) {
                if (SEOProAnalytics_Rollup::summarise($day)) {
                    $built++;
                } else {
                    /* translators: %s: a date */
                    WP_CLI::warning(sprintf(__('%s not rebuilt: its visits are past their retention, or a query failed.', 'seoproanalytics'), $day->format('Y-m-d')));
                }
            }
            /* translators: %d: number of days */
            WP_CLI::success(sprintf(_n('%d day rebuilt.', '%d days rebuilt.', $built, 'seoproanalytics'), $built));
            return;
        }

        $days = 0;
        do {
            $done  = SEOProAnalytics_Rollup::catch_up(microtime(true));
            $days += $done;
        } while ($done > 0);
        $through = SEOProAnalytics_Rollup::through();
        WP_CLI::success(sprintf(
            /* translators: 1: number of days, 2: a date or "none yet" */
            _n('%1$d day summarised; summaries through %2$s.', '%1$d days summarised; summaries through %2$s.', $days, 'seoproanalytics'),
            $days,
            $through !== '' ? $through : __('none yet', 'seoproanalytics')
        ));
    }

    /**
     * Delete visits, pageviews and events past their retention now (75
     * and 120 months by default; SEO Pro Analytics → Settings → Data), and
     * imported search data by page and query past its own (25 months).
     * Daily summaries and search totals are kept, and no visit newer than
     * the last summarised day goes.
     *
     * ## OPTIONS
     *
     * [--dry-run]
     * : Count the rows that would be deleted, and delete nothing.
     *
     * ## EXAMPLES
     *
     *     wp seoproanalytics prune --dry-run
     *
     * @param string[]             $args  Positional arguments.
     * @param array<string,string> $assoc Options.
     */
    public function prune($args, $assoc) {
        $this->need_tables();
        $months = SEOProAnalytics_Rollup::retention();
        /* translators: 1: months visits are kept, 2: months events are kept, 3: months search data is kept, 4: months crawl import history is kept (0: forever) */
        WP_CLI::log(sprintf(__('Retention: visits %1$d months, events %2$d months, search data %3$d months, crawl import history %4$d months (0: forever; SEO Pro Analytics → Settings → Data).', 'seoproanalytics'), $months['visits'], $months['events'], $months['search'], $months['crawl']));
        require_once SEOPROANALYTICS_DIR . self::CONNECTIONS_PATH;
        require_once SEOPROANALYTICS_DIR . self::SEARCH_IMPORT_PATH;
        // Visits and events go only once their days are summarised; search data by its own retention.
        $summarised = SEOProAnalytics_Rollup::through() !== '';
        if (!$summarised) {
            WP_CLI::log(__('No day is summarised yet, so no visits or events are deleted.', 'seoproanalytics'));
        }
        if (!empty($assoc['dry-run'])) {
            $items = array();
            foreach (SEOProAnalytics_Rollup::prune_counts() + SEOProAnalytics_Search_Import::prune_counts() as $table => $rows) {
                $items[] = array('table' => $table, 'rows' => $rows);
            }
            if ($items) {
                WP_CLI\Utils\format_items('table', $items, array('table', 'rows'));
            }
            WP_CLI::success(__('Dry run: nothing deleted.', 'seoproanalytics'));
            return;
        }
        $deleted = 0;
        do {
            $done     = SEOProAnalytics_Rollup::prune(microtime(true));
            $deleted += $done['deleted'];
        } while (!$done['done']);
        delete_option(SEOProAnalytics_Search_Import::PRUNED_OPTION);
        $deleted += SEOProAnalytics_Search_Import::prune(microtime(true));
        /* translators: %d: number of rows */
        WP_CLI::success(sprintf(_n('%d row deleted.', '%d rows deleted.', $deleted, 'seoproanalytics'), $deleted));
    }

    /**
     * Stop when the tables are older than the plugin (an admin page
     * upgrades them).
     */
    private function need_tables() {
        require_once SEOPROANALYTICS_DIR . 'includes/stats/class-seoproanalytics-rollup.php';
        if (!SEOProAnalytics_Schema::is_current()) {
            WP_CLI::error(__('The tables need an upgrade: open any admin page, then try again.', 'seoproanalytics'));
        }
    }

    /**
     * Search engine updates: fetch them now, or show the last fetch.
     *
     * Fetching asks Google's Search Status Dashboard and the other feeds
     * set under Settings → Data, once each, and adds what is new to the
     * change log; the daily job does the same while the setting is on.
     *
     * ## OPTIONS
     *
     * <action>
     * : fetch (now, even with the setting off) or status.
     * ---
     * options:
     *   - fetch
     *   - status
     * ---
     *
     * [--format=<format>]
     * : table or json.
     * ---
     * default: table
     * ---
     *
     * ## EXAMPLES
     *
     *     wp seoproanalytics search-updates fetch
     *     wp seoproanalytics changes --kind=search_update --range=12mo
     *
     * @subcommand search-updates
     *
     * @param string[]             $args  Positional arguments.
     * @param array<string,string> $assoc Options.
     */
    public function search_updates($args, $assoc) {
        require_once SEOPROANALYTICS_DIR . 'includes/stats/class-seoproanalytics-search-updates.php';
        $state = $args[0] === 'fetch' ? SEOProAnalytics_Search_Updates::run(true) : SEOProAnalytics_Search_Updates::state();
        if ($this->format($assoc) === 'json') {
            WP_CLI::line((string) wp_json_encode($state + array('enabled' => SEOProAnalytics_Statistics::search_updates()), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            return;
        }
        if (!SEOProAnalytics_Statistics::search_updates()) {
            WP_CLI::log(__('Search engine updates are off (SEO Pro Analytics → Settings → Data): the daily job fetches nothing.', 'seoproanalytics'));
        }
        if (!$state['sources']) {
            WP_CLI::log(__('Not fetched yet.', 'seoproanalytics'));
            return;
        }
        $rows = array();
        foreach ($state['sources'] as $source) {
            $rows[] = array(
                'source'  => (string) $source['name'],
                'status'  => !empty($source['ok']) ? 'ok' : 'fail',
                'entries' => (int) $source['entries'],
                'added'   => (int) $source['added'],
                'updated' => (int) $source['updated'],
                'when'    => human_time_diff((int) $source['at']) . ' ago',
                'detail'  => !empty($source['ok']) ? (string) $source['url'] : (string) $source['error'],
            );
        }
        WP_CLI\Utils\format_items('table', $rows, array('source', 'status', 'entries', 'added', 'updated', 'when', 'detail'));
        $failed = array_filter($rows, static function ($row) {
            return $row['status'] === 'fail';
        });
        if ($args[0] === 'fetch' && $failed) {
            /* translators: %d: number of sources */
            WP_CLI::warning(sprintf(_n('%d source failed; the daily job asks again tomorrow.', '%d sources failed; the daily job asks again tomorrow.', count($failed), 'seoproanalytics'), count($failed)));
        }
    }

    /**
     * Connect an outside data source, or change its property.
     *
     * Bing Webmaster Tools: verify the site there, then copy the API key
     * from Settings → API access. The key is stored encrypted; the import
     * of the 16 months Bing keeps starts in cron straight after.
     *
     * The SEO Pro Analytics Connect add-on's connections (Google Search
     * Console, Chrome UX Report and the paid providers) connect here too
     * while it is active; its documentation lists their options.
     *
     * ## OPTIONS
     *
     * <source>
     * : The source: bing, or one the add-on registers.
     *
     * [--key-file=<file>]
     * : A file with the key; - reads it from standard input. Without it,
     * the saved key is kept (to change the site or settings).
     *
     * [--property=<property>]
     * : The site (https://example.com/). Without it, the one for this site.
     *
     * [--format=<format>]
     * : table or json.
     * ---
     * default: table
     * ---
     *
     * ## EXAMPLES
     *
     *     wp seoproanalytics connect bing --key-file=- < bing-api-key.txt
     *     wp seoproanalytics connect bing --property=https://example.com/
     *
     * @param string[]             $args  Positional arguments.
     * @param array<string,string> $assoc Options.
     */
    public function connect($args, $assoc) {
        $this->load_connections();
        $source = (string) $args[0];
        $key    = isset($assoc['key-file']) ? self::key_text((string) $assoc['key-file']) : '';
        $input  = array(
            'key'      => $key,
            'property' => isset($assoc['property']) ? (string) $assoc['property'] : '',
        );
        /**
         * Filter the fields WP-CLI's connect gives a source, for an add-on
         * connection with options of its own.
         *
         * @param array<string,mixed>  $input  The fields: key (the key file's text) and property.
         * @param string               $source Source key.
         * @param array<string,string> $assoc  The command's options.
         */
        $input  = (array) apply_filters('seoproanalytics_cli_connect_input', $input, $source, $assoc);
        $status = SEOProAnalytics_Connections::connect($source, $input);
        if (is_wp_error($status)) {
            self::connect_failed($source, $status);
            return;
        }
        $this->connection_status($status, $assoc);
        if ($this->format($assoc) !== 'json') {
            WP_CLI::success(
                isset($status['imported'])
                    /* translators: 1: source name, 2: the command that runs it now */
                    ? sprintf(__('%1$s is connected. The import runs in cron; %2$s runs it now.', 'seoproanalytics'), $status['name'], 'wp seoproanalytics ' . $source . ' import')
                    /* translators: %s: source name */
                    : sprintf(__('%s is connected. Its read runs in cron.', 'seoproanalytics'), $status['name'])
            );
        }
    }

    /**
     * Read a source's key.
     *
     * @param string $file The key file, or - for standard input.
     * @return string
     */
    private static function key_text($file) {
        if ($file === '-') {
            return (string) stream_get_contents(STDIN); // phpcs:ignore WordPress.WP.AlternativeFunctions -- reads the key piped in.
        }
        if (is_readable($file)) {
            return (string) file_get_contents($file); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a local file the operator names.
        }
        /* translators: %s: file name */
        WP_CLI::error(sprintf(__('Cannot read %s.', 'seoproanalytics'), $file));
        return '';
    }

    /**
     * Say why a connection failed: the account and the properties or
     * sites the key can read, when known, then the error.
     *
     * @param string   $source Source key.
     * @param WP_Error $status The error.
     */
    private static function connect_failed($source, WP_Error $status) {
        $data = $status->get_error_data();
        if (is_array($data) && !empty($data['account'])) {
            /* translators: %s: the key's account */
            WP_CLI::log(sprintf(__('Account: %s', 'seoproanalytics'), $data['account']));
        }
        if (is_array($data) && !empty($data['properties'])) {
            WP_CLI::log($source === 'bing' ? __('Verified sites of the key:', 'seoproanalytics') : __('Properties it can read:', 'seoproanalytics'));
            foreach ($data['properties'] as $property) {
                WP_CLI::log('  ' . $property);
            }
        }
        WP_CLI::error($status->get_error_message());
    }

    /**
     * Disconnect an outside data source: forget its credentials. Its
     * imported data stays unless --delete-data is given.
     *
     * ## OPTIONS
     *
     * <source>
     * : The source: bing, or one of the add-on's (its saved key is
     * forgotten even while the add-on is not active).
     *
     * [--delete-data]
     * : Also delete the data imported from it.
     *
     * [--yes]
     * : Do not ask before deleting data.
     *
     * ## EXAMPLES
     *
     *     wp seoproanalytics disconnect bing
     *     wp seoproanalytics disconnect search-console --delete-data
     *
     * @param string[]             $args  Positional arguments.
     * @param array<string,string> $assoc Options.
     */
    public function disconnect($args, $assoc) {
        $this->load_connections();
        $delete = !empty($assoc['delete-data']);
        if ($delete) {
            WP_CLI::confirm(__('Delete the search data imported from it?', 'seoproanalytics'), $assoc);
        }
        $status = SEOProAnalytics_Connections::disconnect($args[0], $delete);
        if (is_wp_error($status)) {
            WP_CLI::error($status->get_error_message());
            return;
        }
        /* translators: 1: source name, 2: number of rows */
        WP_CLI::success($delete ? sprintf(__('%1$s is disconnected; %2$d rows deleted.', 'seoproanalytics'), $status['name'], $status['deleted']) : sprintf(__('%s is disconnected; its imported data stays.', 'seoproanalytics'), $status['name']));
    }

    /**
     * Search Console imports: show the status, import now, list imports,
     * undo one, or import days again.
     *
     * The SEO Pro Analytics Connect add-on connects Search Console; while
     * it does, the job imports each day once Search Console marks it final
     * (about three days later), and on connecting the 16 months it keeps,
     * newest first, a minute apart in cron. `import` does the same now,
     * until it is done.
     *
     * ## OPTIONS
     *
     * <action>
     * : status, import, imports (the last 20), undo (one import, by --id)
     * or reimport (--from and --to).
     * ---
     * options:
     *   - status
     *   - import
     *   - imports
     *   - undo
     *   - reimport
     * ---
     *
     * [--id=<id>]
     * : The import to undo.
     *
     * [--from=<day>]
     * : First day to import again (Y-m-d).
     *
     * [--to=<day>]
     * : Last day to import again (Y-m-d).
     *
     * [--format=<format>]
     * : table or json.
     * ---
     * default: table
     * ---
     *
     * ## EXAMPLES
     *
     *     wp seoproanalytics search-console status
     *     wp seoproanalytics search-console import
     *     wp seoproanalytics search-console undo --id=12
     *     wp seoproanalytics search-console reimport --from=2026-09-01 --to=2026-09-07
     *
     * @subcommand search-console
     *
     * @param string[]             $args  Positional arguments.
     * @param array<string,string> $assoc Options.
     */
    public function search_console($args, $assoc) {
        $this->imports_command('search-console', $args, $assoc);
    }

    /**
     * Bing Webmaster Tools imports: show the status, import now, list
     * imports, undo one, or import days again.
     *
     * Bing gives the site's clicks and impressions by day, and its top
     * pages and queries by week (each week's figures are stored on its
     * last day). A day is imported once its week is in, about a week
     * later; on connecting, the 16 months Bing keeps. Then each page's
     * queries, one request a page. `import` does all of it now.
     *
     * ## OPTIONS
     *
     * <action>
     * : status, import, imports (the last 20), undo (one import, by --id)
     * or reimport (--from and --to).
     * ---
     * options:
     *   - status
     *   - import
     *   - imports
     *   - undo
     *   - reimport
     * ---
     *
     * [--id=<id>]
     * : The import to undo.
     *
     * [--from=<day>]
     * : First day to import again (Y-m-d).
     *
     * [--to=<day>]
     * : Last day to import again (Y-m-d).
     *
     * [--format=<format>]
     * : table or json.
     * ---
     * default: table
     * ---
     *
     * ## EXAMPLES
     *
     *     wp seoproanalytics bing status
     *     wp seoproanalytics bing import
     *     wp seoproanalytics bing undo --id=12
     *
     * @param string[]             $args  Positional arguments.
     * @param array<string,string> $assoc Options.
     */
    public function bing($args, $assoc) {
        $this->imports_command('bing', $args, $assoc);
    }

    /**
     * Move from another statistics plugin: list the plugins found, import
     * one's history (or see what an import would do), list imports, undo
     * one, or remove what the plugin left behind.
     *
     * An import fills only days before SEO Pro Analytics's own first day and
     * not filled by another import, so running it again adds nothing. It
     * also carries over the plugin's settings that are still at our
     * default. cleanup lists exactly what the plugin left on this site
     * and, with --yes, deletes it; it is refused while the plugin is
     * active, and cannot be undone.
     *
     * ## OPTIONS
     *
     * <action>
     * : list, run, imports (the last 20), undo (by --id) or cleanup.
     * ---
     * options:
     *   - list
     *   - run
     *   - imports
     *   - undo
     *   - cleanup
     * ---
     *
     * [<source>]
     * : The plugin, for run and cleanup: burst-statistics, koko-analytics, statify, wp-statistics, independent (Independent Analytics), slimstat, matomo (Matomo for WordPress) or jetpack (Jetpack Stats).
     *
     * [--dry-run]
     * : run: only say what it would do (days, rows, overlap, settings). cleanup: only list (the default without --yes).
     *
     * [--requests]
     * : run: also list what the plugin's adapter asked for (Jetpack Stats: each WordPress.com request, its HTTP code, the answer's keys and counts; never tokens or the answers themselves), to paste into a problem report. (Not --debug: that is WP-CLI's own.)
     *
     * [--prefer=<source>]
     * : run: when another plugin not imported yet has statistics on the same days, the one whose counts fill them (it imports first).
     *
     * [--from=<day>]
     * : run: first day (Y-m-d).
     *
     * [--to=<day>]
     * : run: last day (Y-m-d).
     *
     * [--settings=<keys>]
     * : run: the settings to carry over, comma-separated keys (the dry run lists them), or none. Default: every one the dry run would change.
     *
     * [--id=<id>]
     * : undo: the import.
     *
     * [--yes]
     * : cleanup: delete the list without asking.
     *
     * [--format=<format>]
     * : table or json.
     * ---
     * default: table
     * ---
     *
     * ## EXAMPLES
     *
     *     wp seoproanalytics migrate list
     *     wp seoproanalytics migrate run burst-statistics --dry-run
     *     wp seoproanalytics migrate run burst-statistics
     *     wp seoproanalytics migrate run statify --prefer=koko-analytics
     *     wp seoproanalytics migrate run wp-statistics --settings=tracking_skip_roles
     *     wp seoproanalytics migrate run jetpack --dry-run --requests
     *     wp seoproanalytics migrate undo --id=12
     *     wp seoproanalytics migrate cleanup burst-statistics --dry-run
     *
     * @param string[]             $args  Positional arguments.
     * @param array<string,string> $assoc Options.
     */
    public function migrate($args, $assoc) {
        require_once SEOPROANALYTICS_DIR . 'includes/stats/class-seoproanalytics-migrate.php';
        $this->need_tables();
        $action = $args[0];
        $source = isset($args[1]) ? (string) $args[1] : '';
        $json   = $this->format($assoc) === 'json';
        if (in_array($action, array('run', 'cleanup'), true) && $source === '') {
            WP_CLI::error(__('Name the plugin: wp seoproanalytics migrate list shows them.', 'seoproanalytics'));
        }
        switch ($action) {
            case 'list':
                $this->migrate_list($json);
                return;
            case 'imports':
                $this->migrate_imports($json);
                return;
            case 'undo':
                $this->migrate_undo($assoc);
                return;
            case 'cleanup':
                $this->migrate_cleanup($source, $assoc, $json);
                return;
            default:
                $this->migrate_run($source, $assoc, $json);
        }
    }

    /**
     * Print data as pretty JSON, Unicode as is.
     *
     * @param mixed $data Data.
     */
    private static function json_line($data) {
        WP_CLI::line((string) wp_json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /**
     * wp seoproanalytics migrate list: the statistics plugins found.
     *
     * @param bool $json As JSON.
     */
    private function migrate_list($json) {
        $status = SEOProAnalytics_Migrate::status(true);
        if ($json) {
            self::json_line($status);
            return;
        }
        if (!$status['sources']) {
            WP_CLI::log(__('No statistics plugin\'s data found on this site.', 'seoproanalytics'));
            return;
        }
        $rows = array();
        foreach ($status['sources'] as $found) {
            $rows[] = array(
                'source'    => $found['key'],
                'name'      => $found['name'],
                'version'   => $found['version'],
                'plugin'    => $found['plugin']['state'],
                'from'      => $found['from'],
                'to'        => $found['to'],
                'days'      => $found['days'],
                'leftovers' => self::leftovers_text($found),
                'note'      => isset($found['unavailable']) ? (string) $found['unavailable'] : '',
            );
        }
        WP_CLI\Utils\format_items('table', $rows, array('source', 'name', 'version', 'plugin', 'from', 'to', 'days', 'leftovers', 'note'));
        /* translators: %s: day */
        WP_CLI::log(sprintf(__('SEO Pro Analytics\'s own days start %s; imports fill only days before it.', 'seoproanalytics'), $status['own_from']));
    }

    /**
     * Whether a statistics plugin left data behind; while it is active its
     * own data is not leftovers.
     *
     * @param array<string,mixed> $found A source from SEOProAnalytics_Migrate::status().
     * @return string
     */
    private static function leftovers_text(array $found) {
        if ($found['leftovers']) {
            return 'yes';
        }
        return $found['plugin']['state'] === 'active' || $found['plugin']['state'] === 'network' ? 'while active: no' : 'no';
    }

    /**
     * wp seoproanalytics migrate imports: the last imports.
     *
     * @param bool $json As JSON.
     */
    private function migrate_imports($json) {
        $rows = SEOProAnalytics_Migrate::imports();
        if ($json) {
            self::json_line($rows);
            return;
        }
        if (!$rows) {
            WP_CLI::log(__('No imports yet.', 'seoproanalytics'));
            return;
        }
        foreach ($rows as &$row) {
            $row['check'] = isset($row['check']['source']['pageviews']) ? sprintf('%d / %d pageviews', $row['check']['imported']['pageviews'], $row['check']['source']['pageviews']) : '';
        }
        unset($row);
        WP_CLI\Utils\format_items('table', $rows, array('id', 'source', 'status', 'from', 'to', 'days', 'rows', 'check', 'error'));
    }

    /**
     * wp seoproanalytics migrate undo: delete an import's rows.
     *
     * @param array<string,string> $assoc Options (id).
     */
    private function migrate_undo(array $assoc) {
        if (empty($assoc['id'])) {
            WP_CLI::error(__('Give the import with --id (wp seoproanalytics migrate imports lists them).', 'seoproanalytics'));
        }
        $deleted = SEOProAnalytics_Migrate::undo((int) $assoc['id']);
        if (is_wp_error($deleted)) {
            WP_CLI::error($deleted->get_error_message());
            return;
        }
        /* translators: 1: import ID, 2: number of rows */
        WP_CLI::success(sprintf(__('Import %1$d undone: %2$d rows deleted. Settings it carried over stay; run the import again to bring the days back.', 'seoproanalytics'), (int) $assoc['id'], $deleted));
    }

    /**
     * wp seoproanalytics migrate cleanup: list a statistics plugin's leftover
     * data, or with --yes delete it.
     *
     * @param string               $source Source key.
     * @param array<string,string> $assoc  Options (dry-run, yes).
     * @param bool                 $json   As JSON.
     */
    private function migrate_cleanup($source, array $assoc, $json) {
        $dry    = !empty($assoc['dry-run']) || empty($assoc['yes']);
        $result = SEOProAnalytics_Migrate::cleanup($source, true);
        if (is_wp_error($result)) {
            WP_CLI::error($result->get_error_message());
            return;
        }
        if ($json && $dry) {
            self::json_line($result);
            return;
        }
        $this->leftovers_table($result['leftovers']);
        if ($dry) {
            if (empty($assoc['dry-run'])) {
                /* translators: %s: source key */
                WP_CLI::log(sprintf(__('Nothing deleted. Back up the database, then: wp seoproanalytics migrate cleanup %s --yes', 'seoproanalytics'), $source));
            }
            return;
        }
        $result = SEOProAnalytics_Migrate::cleanup($source, false);
        if (is_wp_error($result)) {
            WP_CLI::error($result->get_error_message());
            return;
        }
        if ($json) {
            self::json_line($result);
            return;
        }
        $removed = array();
        foreach ($result['removed'] as $kind => $count) {
            $removed[] = $kind . ': ' . $count;
        }
        /* translators: 1: plugin name, 2: counts */
        WP_CLI::success(sprintf(__('Leftover data of %1$s removed (%2$s). Imported days stay.', 'seoproanalytics'), $result['name'], implode(', ', $removed)));
    }

    /**
     * An import's options from the command's: days, the plugin preferred
     * where two overlap, and the settings to carry over.
     *
     * @param array<string,string> $assoc Options.
     * @return array<string,mixed>
     */
    private static function migrate_options(array $assoc) {
        $run = array(
            'from'   => isset($assoc['from']) ? (string) $assoc['from'] : '',
            'to'     => isset($assoc['to']) ? (string) $assoc['to'] : '',
            'prefer' => isset($assoc['prefer']) ? (string) $assoc['prefer'] : '',
        );
        if (isset($assoc['settings'])) {
            $chosen          = strtolower(trim((string) $assoc['settings']));
            $run['settings'] = $chosen === 'none' || $chosen === '' ? array() : array_values(array_filter(array_map('trim', explode(',', $chosen))));
        }
        return $run;
    }

    /**
     * wp seoproanalytics migrate run: import a statistics plugin's days, or
     * with --dry-run say what it would do.
     *
     * @param string               $source Source key.
     * @param array<string,string> $assoc  Options.
     * @param bool                 $json   As JSON.
     */
    private function migrate_run($source, array $assoc, $json) {
        $run      = self::migrate_options($assoc);
        $requests = !empty($assoc['requests']);
        if (!empty($assoc['dry-run'])) {
            $this->migrate_dry_run($source, $run, $requests, $json);
            return;
        }
        $job = SEOProAnalytics_Migrate::run($source, $run, function ($job) use ($json) {
            if (!$json) {
                self::migrate_progress($job);
            }
        });
        if (is_wp_error($job)) {
            if ($requests) {
                $this->migrate_log($source, $json);
            }
            WP_CLI::error($job->get_error_message());
            return;
        }
        if (!empty($job['locked'])) {
            WP_CLI::error(__('Another request is running this import. Check with wp seoproanalytics migrate imports.', 'seoproanalytics'));
        }
        $ids     = wp_list_pluck($job['queue'], 'id');
        $imports = array_values(array_filter(SEOProAnalytics_Migrate::imports(), function ($row) use ($ids) {
            return in_array($row['id'], $ids, true);
        }));
        if ($json) {
            self::json_line(array('job' => $job, 'imports' => $imports));
            return;
        }
        foreach (array_reverse($imports) as $row) {
            self::migrate_done($row);
        }
        if ($requests) {
            $this->migrate_log($source, false);
        }
    }

    /**
     * wp seoproanalytics migrate run --dry-run: print the plan.
     *
     * @param string              $source   Source key.
     * @param array<string,mixed> $run      Import options.
     * @param bool                $requests Also print what the adapter asked for.
     * @param bool                $json     As JSON.
     */
    private function migrate_dry_run($source, array $run, $requests, $json) {
        $plan = SEOProAnalytics_Migrate::plan($source, $run);
        if (is_wp_error($plan)) {
            if ($requests) {
                $this->migrate_log($source, $json);
            }
            WP_CLI::error($plan->get_error_message());
            return;
        }
        if ($json) {
            self::json_line($requests ? $plan + array('requests' => $this->migrate_requests($source)) : $plan);
            return;
        }
        $this->migrate_plan($plan);
        if ($requests) {
            $this->migrate_log($source, false);
        }
    }

    /**
     * Print an import's progress: each plugin's days not done yet.
     *
     * @param array<string,mixed> $job The import job.
     */
    private static function migrate_progress(array $job) {
        foreach ($job['queue'] as $item) {
            if ($item['total'] && $item['done'] < $item['total']) {
                /* translators: 1: plugin name, 2: days done, 3: days */
                WP_CLI::log(sprintf(__('%1$s: %2$d of %3$d days', 'seoproanalytics'), $item['name'], $item['done'], $item['total']));
            }
        }
    }

    /**
     * Print a finished import: its days and rows, its counts against the
     * plugin's, the settings carried over and any error.
     *
     * @param array<string,mixed> $row From SEOProAnalytics_Migrate::imports().
     */
    private static function migrate_done(array $row) {
        /* translators: 1: plugin name, 2: days, 3: rows, 4: import ID */
        WP_CLI::success(sprintf(__('%1$s: %2$d days imported, %3$d rows (import %4$d).', 'seoproanalytics'), $row['name'], $row['days'], $row['rows'], $row['id']));
        if (!empty($row['check']['source'])) {
            $check = array();
            foreach (array('pageviews', 'visits', 'visitors') as $metric) {
                $check[] = array('metric' => $metric, 'plugin' => $row['check']['source'][$metric], 'imported' => $row['check']['imported'][$metric]);
            }
            WP_CLI\Utils\format_items('table', $check, array('metric', 'plugin', 'imported'));
        }
        foreach ((array) $row['settings'] as $key => $change) {
            /* translators: 1: setting key, 2: before, 3: after */
            WP_CLI::log(sprintf(__('Setting %1$s: %2$s → %3$s', 'seoproanalytics'), $key, $change[0], $change[1]));
        }
        if ($row['error'] !== '') {
            WP_CLI::warning($row['error']);
        }
    }

    /**
     * What an adapter asked for in this request (--requests).
     *
     * @param string $source Adapter key.
     * @return array<int,array<string,string|int>>
     */
    private function migrate_requests($source) {
        $adapter = SEOProAnalytics_Migrate::source($source);
        return $adapter ? $adapter->debug() : array();
    }

    /**
     * Print what an adapter asked for (--requests), to paste into a report.
     *
     * @param string $source Adapter key.
     * @param bool   $json   As JSON.
     */
    private function migrate_log($source, $json) {
        $requests = $this->migrate_requests($source);
        if ($json) {
            WP_CLI::line((string) wp_json_encode(array('requests' => $requests), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            return;
        }
        if (!$requests) {
            WP_CLI::log(__('Requests: the adapter made none.', 'seoproanalytics'));
            return;
        }
        WP_CLI::log(__('Requests: what the adapter asked for (no tokens or answers):', 'seoproanalytics'));
        WP_CLI\Utils\format_items('table', $requests, array_keys($requests[0]));
    }

    /**
     * Print a dry run.
     *
     * @param array<string,mixed> $plan SEOProAnalytics_Migrate::plan().
     */
    private function migrate_plan(array $plan) {
        $skipped = array();
        foreach ($plan['skipped']['imported'] as $by => $count) {
            /* translators: 1: days, 2: source key */
            $skipped[] = sprintf(__('%1$d already imported from %2$s', 'seoproanalytics'), $count, $by);
        }
        $rows = array(
            array('field' => 'plugin', 'value' => $plan['name'] . ' ' . $plan['version'] . ' (' . $plan['plugin']['state'] . ')'),
            array('field' => 'statistics', 'value' => sprintf('%s – %s, %d days', $plan['from'], $plan['to'], $plan['days'])),
            array('field' => 'own days from', 'value' => $plan['own_from'] . sprintf(' (%d days skipped)', $plan['skipped']['own'])),
            array('field' => 'imported before', 'value' => $skipped ? implode('; ', $skipped) : 'none'),
            array('field' => 'would import', 'value' => $plan['import']['days'] ? sprintf('%s – %s, %d days', $plan['import']['from'], $plan['import']['to'], $plan['import']['days']) : 'nothing'),
            array('field' => 'its counts', 'value' => (!empty($plan['estimated']) ? '~' : '') . sprintf('%d pageviews, %d visits, %d visitors', $plan['totals']['pageviews'], $plan['totals']['visits'], $plan['totals']['visitors']) . (!empty($plan['estimated']) ? ' (estimated from a few days: large tables)' : '')),
        );
        foreach ($plan['rows'] as $dimension => $count) {
            $rows[] = array('field' => 'rows: ' . $dimension, 'value' => '~' . $count);
        }
        foreach ($plan['overlap'] as $overlap) {
            $rows[] = array('field' => 'shares days with', 'value' => sprintf('%s: %s – %s, %d days; suggested --prefer=%s', $overlap['name'], $overlap['from'], $overlap['to'], $overlap['days'], $overlap['suggested']));
        }
        foreach ($plan['settings'] as $setting) {
            $rows[] = array('field' => 'setting ' . $setting['key'], 'value' => sprintf('%s → %s (%s)', $setting['now'], $setting['to'], self::setting_text($setting)));
        }
        WP_CLI\Utils\format_items('table', $rows, array('field', 'value'));
        WP_CLI::log(__('Dry run: nothing written.', 'seoproanalytics'));
    }

    /**
     * What a dry run would do with a setting.
     *
     * @param array<string,mixed> $setting From SEOProAnalytics_Migrate::plan().
     * @return string
     */
    private static function setting_text(array $setting) {
        if ($setting['change']) {
            return 'would change';
        }
        return $setting['reason'] === 'same' ? 'already so' : 'kept: changed from the default';
    }

    /**
     * Print a leftovers list.
     *
     * @param array<string,mixed> $list SEOProAnalytics_Migrate_Source::leftovers().
     */
    private function leftovers_table(array $list) {
        $rows = array();
        foreach ($list as $kind => $items) {
            if ($kind === 'network') {
                foreach ((array) $items as $network_kind => $names) {
                    foreach ((array) $names as $name) {
                        $rows[] = array('kind' => 'network ' . $network_kind . ' (kept)', 'name' => $name);
                    }
                }
                continue;
            }
            foreach ((array) $items as $name) {
                $rows[] = array('kind' => $kind, 'name' => $name);
            }
        }
        if (!$rows) {
            WP_CLI::log(__('Nothing left behind.', 'seoproanalytics'));
            return;
        }
        WP_CLI\Utils\format_items('table', $rows, array('kind', 'name'));
    }

    /**
     * Stop with where to get the add-on when a source is not registered.
     *
     * @param string $source Source key.
     */
    private static function need_registered($source) {
        if (!SEOProAnalytics_Connections::source_class($source)) {
            WP_CLI::error(SEOProAnalytics_Connections::needs_addon($source)->get_error_message());
        }
    }

    /**
     * A source's imports subcommand.
     *
     * @param string               $source Source key.
     * @param string[]             $args   Positional arguments.
     * @param array<string,string> $assoc  Options.
     */
    private function imports_command($source, $args, $assoc) {
        $this->load_connections();
        self::need_registered($source);
        $this->need_tables();
        $action = $args[0];
        if ($action === 'imports') {
            $this->imports_list($source, $assoc);
            return;
        }
        if ($action === 'undo') {
            self::imports_undo($source, $assoc);
            return;
        }
        if (!SEOProAnalytics_Connections::get($source)) {
            /* translators: 1: source name, 2: source key */
            WP_CLI::error(sprintf(__('%1$s is not connected: wp seoproanalytics connect %2$s --key-file=<file>.', 'seoproanalytics'), SEOProAnalytics_Connections::status($source)['name'], $source));
        }
        if ($action === 'reimport') {
            self::imports_reimport($source, $assoc);
            return;
        }
        if ($action === 'import') {
            self::imports_run($source);
        }
        $this->connection_status(SEOProAnalytics_Connections::status($source), $assoc);
    }

    /**
     * wp seoproanalytics <source> imports: the last 20 imports.
     *
     * @param string               $source Source key.
     * @param array<string,string> $assoc  Options.
     */
    private function imports_list($source, array $assoc) {
        $rows = SEOProAnalytics_Search_Import::imports($source, 20);
        if ($this->format($assoc) === 'json') {
            self::json_line($rows);
            return;
        }
        if (!$rows) {
            WP_CLI::log(__('No imports yet.', 'seoproanalytics'));
            return;
        }
        WP_CLI\Utils\format_items('table', $rows, array('id', 'status', 'from', 'to', 'days', 'rows', 'error'));
    }

    /**
     * wp seoproanalytics <source> undo: delete an import's rows.
     *
     * @param string               $source Source key.
     * @param array<string,string> $assoc  Options (id).
     */
    private static function imports_undo($source, array $assoc) {
        if (empty($assoc['id'])) {
            /* translators: %s: source key */
            WP_CLI::error(sprintf(__('Give the import with --id (wp seoproanalytics %s imports lists them).', 'seoproanalytics'), $source));
        }
        $deleted = SEOProAnalytics_Search_Import::undo((int) $assoc['id']);
        if (is_wp_error($deleted)) {
            WP_CLI::error($deleted->get_error_message());
            return;
        }
        /* translators: 1: import ID, 2: number of rows, 3: source key */
        WP_CLI::success(sprintf(__('Import %1$d undone: %2$d rows deleted. wp seoproanalytics %3$s reimport brings its days back.', 'seoproanalytics'), (int) $assoc['id'], $deleted, $source));
    }

    /**
     * wp seoproanalytics <source> reimport: import some days again.
     *
     * @param string               $source Source key.
     * @param array<string,string> $assoc  Options (from, to).
     */
    private static function imports_reimport($source, array $assoc) {
        $from = isset($assoc['from']) ? (string) $assoc['from'] : '';
        $to   = isset($assoc['to']) ? (string) $assoc['to'] : $from;
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $to) || $to < $from) {
            WP_CLI::error(__('Give the days with --from and --to (Y-m-d).', 'seoproanalytics'));
        }
        $result = SEOProAnalytics_Search_Import::reimport($source, $from, $to);
        if (is_wp_error($result)) {
            WP_CLI::error($result->get_error_message());
            return;
        }
        /* translators: 1: days, 2: rows, 3: import ID */
        WP_CLI::success(sprintf(__('%1$d days imported again: %2$d rows (import %3$d).', 'seoproanalytics'), $result['days'], $result['rows'], $result['import']));
    }

    /**
     * wp seoproanalytics <source> import: import until done, saying what each
     * step brought.
     *
     * @param string $source Source key.
     */
    private static function imports_run($source) {
        $check = true;
        do {
            $result = SEOProAnalytics_Search_Import::run($source, SEOProAnalytics_Search_Import::BUDGET, $check);
            if (is_wp_error($result)) {
                WP_CLI::error($result->get_error_message());
                return;
            }
            $check = false;
            if ($result['days']) {
                /* translators: 1: days, 2: rows, 3: import ID */
                WP_CLI::log(sprintf(__('%1$d days imported: %2$d rows (import %3$d).', 'seoproanalytics'), $result['days'], $result['rows'], $result['import']));
            }
            if (!empty($result['pages'])) {
                /* translators: %d: pages */
                WP_CLI::log(sprintf(__('Search queries of %d pages imported.', 'seoproanalytics'), $result['pages']));
            }
        } while (!$result['done']);
    }

    /**
     * Print a connection's status.
     *
     * @param array<string,mixed>  $status SEOProAnalytics_Connections::status().
     * @param array<string,string> $assoc  Options.
     */
    private function connection_status(array $status, $assoc) {
        if ($this->format($assoc) === 'json') {
            WP_CLI::line((string) wp_json_encode($status, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            return;
        }
        if (empty($status['connected'])) {
            /* translators: %s: source name */
            WP_CLI::log(sprintf(__('%s is not connected.', 'seoproanalytics'), $status['name']));
            return;
        }
        if (!isset($status['imported'])) {
            WP_CLI\Utils\format_items('table', self::other_status_rows($status), array('field', 'value'));
            return;
        }
        $imported = $status['imported'];
        $rows     = array(
            array('field' => 'account', 'value' => $status['account'] !== '' ? $status['account'] : 'API key (stored encrypted)'),
            array('field' => 'property', 'value' => $status['property']),
            array('field' => 'imported', 'value' => $imported['from'] !== '' ? $imported['from'] . ' – ' . $imported['to'] : 'nothing yet'),
            array('field' => 'history', 'value' => $imported['complete'] ? 'complete' : sprintf('%d of %d days', $imported['days'], $imported['of'])),
            array('field' => 'pages\' queries', 'value' => self::pages_left_text($status['pages_left'])),
            array('field' => 'final through', 'value' => $status['final_through'] !== '' ? $status['final_through'] : 'not asked yet'),
            array('field' => 'last run', 'value' => $status['last_run'] ? human_time_diff($status['last_run']) . ' ago' : 'never'),
            array('field' => 'next run', 'value' => $status['next_run'] ? 'in ' . human_time_diff($status['next_run']) : 'not scheduled'),
        );
        if ($status['error'] !== '') {
            $rows[] = array('field' => 'last error', 'value' => $status['error'] . ' (' . human_time_diff($status['error_at']) . ' ago)');
        }
        WP_CLI\Utils\format_items('table', $rows, array('field', 'value'));
    }

    /**
     * A status not the search import's (an add-on connection with its own
     * read) as field and value rows: the add-on's, or its plain fields.
     *
     * @param array<string,mixed> $status SEOProAnalytics_Connections::status().
     * @return array<int,array{field:string,value:string}>
     */
    private static function other_status_rows(array $status) {
        /**
         * Filter the WP-CLI status table of a connection with its own read.
         *
         * @param array<int,array{field:string,value:string}>|null $rows   Rows, or null for its plain fields.
         * @param array<string,mixed>                              $status SEOProAnalytics_Connections::status().
         */
        $rows = apply_filters('seoproanalytics_cli_status_rows', null, $status);
        if (is_array($rows)) {
            return $rows;
        }
        $rows = array();
        foreach ($status as $field => $value) {
            if (is_scalar($value) && !in_array($field, array('source', 'name', 'connected'), true)) {
                $rows[] = array('field' => (string) $field, 'value' => is_bool($value) ? ($value ? 'yes' : 'no') : (string) $value);
            }
        }
        return $rows;
    }

    /**
     * How far a search source's per-page queries are imported.
     *
     * @param int|null $left Pages left (null: not started).
     * @return string
     */
    private static function pages_left_text($left) {
        if ($left === 0) {
            return 'in';
        }
        return $left === null ? 'due' : sprintf('%d pages left', $left);
    }

    /**
     * Load the connection classes.
     */
    private function load_connections() {
        require_once SEOPROANALYTICS_DIR . self::CONNECTIONS_PATH;
        require_once SEOPROANALYTICS_DIR . self::SEARCH_IMPORT_PATH;
    }

    /**
     * Check that statistics are collected and processed: tables, collector
     * folder and config, salts, endpoint, cron, waiting hits and daily
     * summaries.
     *
     * ## OPTIONS
     *
     * [--format=<format>]
     * : table or json.
     * ---
     * default: table
     * ---
     *
     * @param string[]             $args  Positional arguments.
     * @param array<string,string> $assoc Options.
     */
    public function doctor($args, $assoc) { // NOSONAR: WP-CLI passes positional arguments even when this command uses only options.
        $checks = self::checks();
        if ($this->format($assoc) === 'json') {
            WP_CLI::line((string) wp_json_encode($checks, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            WP_CLI\Utils\format_items('table', $checks, array('check', 'status', 'detail'));
        }
        $failed = count(array_filter($checks, static function ($check) {
            return $check['status'] === 'fail';
        }));
        if ($failed) {
            /* translators: %d: number of failed checks */
            WP_CLI::error(sprintf(_n('%d check failed.', '%d checks failed.', $failed, 'seoproanalytics'), $failed));
        }
        // JSON stays parseable: no line after it.
        if ($this->format($assoc) !== 'json') {
            WP_CLI::success(__('Statistics are collected and processed.', 'seoproanalytics'));
        }
    }

    /**
     * The doctor's checks: check, status (ok, warn, fail), detail.
     *
     * @return array<int,array{check:string,status:string,detail:string}>
     */
    public static function checks() {
        $out = array();
        $add = static function ($check, $ok, $detail, $fail = 'fail') use (&$out) {
            $out[] = array('check' => $check, 'status' => $ok ? 'ok' : $fail, 'detail' => $detail);
        };
        self::check_tables($add);
        $dir = SEOProAnalytics_Collection::dir();
        self::check_collector($add, $dir);
        self::check_daily_jobs($add);
        self::check_connections($add);
        self::check_processing($add, $dir);
        self::check_summaries($add);
        self::check_page_caches($add);
        self::check_purchases($add);
        require_once SEOPROANALYTICS_DIR . 'includes/stats/class-seoproanalytics-mail.php';
        $mail = SEOProAnalytics_Mail::log_entries()[0] ?? null;
        $add('email', !$mail || $mail['result'] === 'sent', $mail ? wp_date('Y-m-d H:i:s', $mail['t']) . ': ' . ($mail['error'] ?: 'last email accepted by WordPress') : 'no email sent yet', 'warn');
        require_once __DIR__ . '/class-seoproanalytics-alerts.php';
        $alerts = array_filter(SEOProAnalytics_Alerts::open(), static function ($alert) { return !$alert['resolved']; });
        $add('alerts', !$alerts, $alerts ? implode(', ', array_column($alerts, 'key')) : 'no open alerts', 'warn');
        require_once __DIR__ . '/class-seoproanalytics-site-crawler.php';
        $ready = SEOProAnalytics_Site_Crawler::readiness((bool) SEOProAnalytics_Settings::get('crawl_site'), (bool) SEOProAnalytics_Settings::get('crawl_external'));
        foreach ($ready['checks'] as $check) {
            $out[] = array('check' => 'crawl ' . $check['check'], 'status' => $check['status'] === 'pass' ? 'ok' : $check['status'], 'detail' => $check['detail']);
        }
        return $out;
    }

    /**
     * Check the schema and its tables.
     *
     * @param callable $add Append a check.
     */
    private static function check_tables(callable $add) {
        global $wpdb;
        $version = (int) get_option(SEOProAnalytics_Schema::OPTION, 0);
        $add('tables', SEOProAnalytics_Schema::is_current(), sprintf('version %d of %d', $version, SEOProAnalytics_Schema::VERSION));
        $missing = array();
        foreach (SEOProAnalytics_Schema::names() as $name) {
            $table = SEOProAnalytics_Schema::table($name);
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- a health check on our own tables.
            if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table))) !== $table) {
                $missing[] = $name;
            }
        }
        $add('table rows', !$missing, $missing ? 'missing: ' . implode(', ', $missing) : 'all ' . count(SEOProAnalytics_Schema::names()) . ' present');
        require_once SEOPROANALYTICS_DIR . 'includes/stats/class-seoproanalytics-crawl.php';
        foreach (SEOProAnalytics_Crawl::sizes() as $name => $size) {
            $add($name . ' size', true, sprintf('%d estimated rows; data %d bytes; indexes %d bytes', $size['rows'], $size['data'], $size['index']));
        }
    }

    /**
     * Check collector files, salt, endpoint and minute jobs.
     *
     * @param callable $add Append a check.
     * @param string   $dir Collector directory.
     */
    private static function check_collector(callable $add, $dir) {
        $add('collector folder', is_dir($dir) && wp_is_writable($dir), $dir);
        $add('collector config', is_file($dir . self::CONFIG_PATH), is_file($dir . self::CONFIG_PATH) ? 'written ' . human_time_diff((int) filemtime($dir . self::CONFIG_PATH)) . ' ago' : 'not written yet (an admin page, a cron or WP-CLI request, or the hourly job writes it)');

        $salts = get_option(SEOProAnalytics_Collection::SALTS_OPTION, array());
        $today = wp_date('Y-m-d');
        $add('daily salt', is_array($salts) && isset($salts[$today]), 'for ' . $today);

        $state = SEOProAnalytics_Collection::state();
        $fast  = !empty($state['fast']);
        $add('endpoint', true, ($fast ? 'collect.php (fast)' : 'REST route') . ': ' . SEOProAnalytics_Collection::endpoint());

        foreach (array(SEOProAnalytics_Collection::CRON_HOOK, SEOProAnalytics_Collection::PROCESS_HOOK) as $hook) {
            $next = wp_next_scheduled($hook);
            $add('cron ' . $hook, (bool) $next, self::next_run($next));
        }
    }

    /**
     * When a scheduled job runs next, or how long it has waited past its time.
     *
     * @param int|false $next wp_next_scheduled().
     * @return string
     */
    private static function next_run($next) {
        if (!$next) {
            return 'not scheduled (an admin page, or the next cron or WP-CLI request, schedules it)';
        }
        return $next >= time() ? 'next in ' . human_time_diff($next) : 'due ' . human_time_diff($next) . ' ago (WP-Cron below)';
    }

    /**
     * Check daily outside-data jobs in their original order.
     *
     * @param callable $add Append a check.
     */
    private static function check_daily_jobs(callable $add) {
        require_once SEOPROANALYTICS_DIR . 'includes/stats/class-seoproanalytics-search-updates.php';
        $updates = SEOProAnalytics_Search_Updates::state();
        $next    = wp_next_scheduled(SEOProAnalytics_Collection::DAILY_HOOK);
        $add('cron ' . SEOProAnalytics_Collection::DAILY_HOOK, (bool) $next, self::next_run($next));
        require_once SEOPROANALYTICS_DIR . 'includes/stats/class-seoproanalytics-backlinks.php';
        $backlinks = SEOProAnalytics_Backlinks::state();
        if (!SEOProAnalytics_Statistics::backlinks()) {
            $add('backlinks check', true, self::DATA_OFF);
        } else {
            $add('backlinks check', true, $backlinks['last'] ? 'last run ' . human_time_diff($backlinks['last']) . ' ago: ' . $backlinks['checked'] . ' page(s) opened, ' . $backlinks['errors'] . ' could not be opened' : 'not run yet');
        }
        require_once SEOPROANALYTICS_DIR . 'includes/stats/class-seoproanalytics-inspections.php';
        $inspections = SEOProAnalytics_Inspections::progress();
        if ($inspections['daily'] < 1) {
            $add('google url inspection', true, self::DATA_OFF);
        } else {
            $add('google url inspection', $inspections['error'] === null, ($inspections['last'] ? 'last run ' . human_time_diff((int) strtotime((string) $inspections['last'])) . ' ago' : 'not run yet (needs Search Console)') . ', ' . $inspections['used'] . ' of ' . $inspections['daily'] . ' today' . ($inspections['error'] !== null ? '; stopped: ' . $inspections['error'] : ''), 'warn');
        }
        self::check_search_updates($add, $updates);
    }

    /**
     * Check the search update feed results.
     *
     * @param callable $add Append a check.
     * @param array    $updates Feed state.
     */
    private static function check_search_updates(callable $add, array $updates) {
        if (!SEOProAnalytics_Statistics::search_updates()) {
            $add('search engine updates', true, self::DATA_OFF);
        } else {
            $failed = array();
            foreach ($updates['sources'] as $source) {
                if (empty($source['ok'])) {
                    $failed[] = $source['name'] . ': ' . $source['error'];
                }
            }
            if ($updates['last']) {
                $when = 'fetched ' . human_time_diff($updates['last']) . ' ago';
                $from = ' from ' . count($updates['sources']) . ' source(s)';
            } else {
                $when = 'not fetched yet';
                $from = '';
            }
            $add('search engine updates', !$failed, $failed ? $when . '; asked again tomorrow: ' . implode('; ', $failed) : $when . $from, 'warn');
        }
    }

    /**
     * Check connected sources and the cron override.
     *
     * @param callable $add Append a check.
     */
    private static function check_connections(callable $add) {
        require_once SEOPROANALYTICS_DIR . self::CONNECTIONS_PATH;
        require_once SEOPROANALYTICS_DIR . self::SEARCH_IMPORT_PATH;
        foreach (SEOProAnalytics_Connections::statuses() as $source) {
            if (empty($source['connected'])) {
                $add(strtolower($source['name']), true, 'not connected (SEO Pro Analytics → Settings → Connections)');
                continue;
            }
            if (!isset($source['imported'])) {
                list($ok, $detail) = self::other_check($source);
                $add(strtolower((string) $source['name']), $ok, $detail, 'warn');
                continue;
            }
            $imported = $source['imported'];
            $detail   = $source['property'] . ': ' . ($imported['from'] !== '' ? 'imported ' . $imported['from'] . ' to ' . $imported['to'] : 'nothing imported yet') . ($imported['complete'] ? '' : sprintf('; history %d of %d days', $imported['days'], $imported['of']));
            $ok       = $source['error'] === '' && $source['next_run'];
            if ($source['error'] !== '') {
                $detail .= '; last error ' . human_time_diff($source['error_at']) . ' ago: ' . $source['error'];
            } elseif (!$source['next_run']) {
                $detail .= '; the import job is not scheduled (reconnect, or open the Connections tab)';
            }
            $add(strtolower($source['name']), (bool) $ok, $detail, 'warn');
        }
        self::check_wp_cron($add);
    }

    /**
     * The doctor's line for a connection with its own read (one of the
     * add-on's): the add-on's, or its last error.
     *
     * @param array<string,mixed> $source SEOProAnalytics_Connections::status().
     * @return array{0:bool,1:string} Whether it is healthy, and the detail.
     */
    private static function other_check(array $source) {
        $error = isset($source['error']) ? (string) $source['error'] : '';
        /**
         * Filter the doctor's line for a connection with its own read.
         *
         * @param array{0:bool,1:string} $check  Whether it is healthy, and the detail.
         * @param array<string,mixed>    $source SEOProAnalytics_Connections::status().
         */
        $check = apply_filters('seoproanalytics_doctor_connection', array($error === '', $error !== '' ? 'last error: ' . $error : 'connected'), $source);
        return is_array($check) && count($check) === 2 ? array((bool) $check[0], (string) $check[1]) : array($error === '', $error);
    }

    /**
     * Check that something runs the scheduled jobs: with DISABLE_WP_CRON,
     * a server cron job must, and the minute job shows whether it does
     * (late past 15 minutes; Site Health's threshold). With WP-Cron on,
     * page loads start them, so lateness only means no recent pages.
     *
     * @param callable $add Append a check.
     */
    private static function check_wp_cron(callable $add) {
        $late = SEOProAnalytics_Collection::jobs_late();
        $ago  = null === $late ? 'not scheduled' : ($late > 0 ? human_time_diff(time() - $late) . ' late' : 'on time');
        if (!SEOProAnalytics_Collection::wp_cron_off()) {
            $add('WP-Cron', true, 'runs when pages load; minute job ' . $ago);
            return;
        }
        if (null === $late) {
            // Nothing to measure yet: the cron seoproanalytics_process check says why.
            $add('WP-Cron', true, 'DISABLE_WP_CRON is set; the minute job is not scheduled yet, so whether a server cron job runs the jobs shows once it is');
            return;
        }
        $running = $late <= SEOProAnalytics_Collection::JOBS_LATE;
        $add(
            'WP-Cron',
            $running,
            $running
                ? 'DISABLE_WP_CRON is set and a server cron job runs the jobs; minute job ' . $ago
                : 'DISABLE_WP_CRON is set but no server cron job runs the jobs (minute job ' . $ago . '): add one every 5 minutes or less running php ' . ABSPATH . 'wp-cron.php or wp cron event run --due-now',
            'warn'
        );
    }

    /**
     * Check buffered hits and the last processing run.
     *
     * @param callable $add Append a check.
     * @param string   $dir Collector directory.
     */
    private static function check_processing(callable $add, $dir) {
        $waiting = 0;
        foreach (array_merge(array($dir . '/buffer.php'), (array) glob($dir . '/processing-*.php')) as $file) {
            $waiting += is_string($file) && is_file($file) ? (int) filesize($file) : 0;
        }
        $processed = get_option(SEOProAnalytics_Collection::PROCESS_OPTION, array());
        $last      = is_array($processed) && isset($processed['last']) ? (int) $processed['last'] : 0;
        // The last run that found hits can be long ago on a quiet site; what matters is whether
        // the minute job is running on time to pick up what waits now.
        $late  = SEOProAnalytics_Collection::jobs_late();
        $stale = $waiting > 0 && (null === $late || $late > 10 * MINUTE_IN_SECONDS);
        $add('processing', !$stale, sprintf('%s waiting; last run %s; minute job %s', size_format($waiting), $last ? human_time_diff($last) . ' ago' : 'never', null === $late ? 'not scheduled' : ($late > 0 ? human_time_diff(time() - $late) . ' late' : 'on time')), 'warn');
    }

    /**
     * Check daily summary progress.
     *
     * @param callable $add Append a check.
     */
    private static function check_summaries(callable $add) {
        // A day is summarised from 01:00 the next day; a day later is behind.
        $through = SEOProAnalytics_Rollup::through();
        $behind  = $through !== '' ? $through < wp_date('Y-m-d', time() - 2 * DAY_IN_SECONDS) : SEOProAnalytics_Rollup::due() !== null;
        $state   = SEOProAnalytics_Rollup::state();
        $add('daily summaries', !$behind, sprintf('through %s; pruned %s', $through !== '' ? $through : 'none yet', isset($state['pruned']) ? (string) $state['pruned'] : 'never'), 'warn');
    }

    /**
     * Check the optional page cache integration.
     *
     * @param callable $add Append a check.
     */
    private static function check_page_caches(callable $add) {
        if (class_exists('SEOProAnalytics_Page_Cache')) {
            $cache  = SEOProAnalytics_Page_Cache::state();
            $purged = isset($cache['purged']) ? (int) $cache['purged'] : 0;
            $caches = isset($cache['caches']) && is_array($cache['caches']) && $cache['caches'] ? implode(', ', $cache['caches']) : 'none known active';
            $why    = isset($cache['why']) ? (string) $cache['why'] : '';
            $detail = $purged ? sprintf('purged %s ago (%s): %s', human_time_diff($purged), $why, $caches) : 'not purged yet (an admin page schedules it after an update)';
            if (wp_next_scheduled(SEOProAnalytics_Page_Cache::PURGE_HOOK)) {
                $detail .= '; a purge for the new tracker is scheduled for the next WP-Cron run';
            }
            $add('page caches', true, $detail);
        }
    }

    /**
     * Check purchase collection and renewal capacity.
     *
     * @param callable $add Append a check.
     */
    private static function check_purchases(callable $add) {
        $shops = array_keys(array_filter(array(
            'WooCommerce' => class_exists('WooCommerce'),
            'Easy Digital Downloads' => function_exists('edd_get_order'),
            'FluentCart' => defined('FLUENTCART_VERSION'),
            'ThriveCart' => SEOProAnalytics_Purchases::thrivecart_secret() !== '',
        )));
        if (!SEOProAnalytics_Purchases::enabled()) {
            $add('purchases', true, 'off (SEO Pro Analytics → Settings → Tracking)');
        } else {
            $purchases = SEOProAnalytics_Purchases::status();
            $joined = $purchases['thrivecart_not_joined'];
            $add('purchases', true, ($shops ? 'recorded from ' . implode(', ', $shops) : 'no supported shop active') . ($joined ? sprintf('; %d ThriveCart orders without a known page load', $joined) : ''));
            $renewals = array();
            foreach ($purchases['renewals'] as $row) {
                $renewals[] = sprintf('%d / %.2f %s', $row['count'], $row['amount'], $row['currency']);
            }
            $full = $purchases['renewal_receipts'] >= SEOProAnalytics_Purchases::KEEP_RENEWAL_IDS;
            $add('renewals', !$full, ($renewals ? implode('; ', $renewals) : 'none recorded') . '; last 400 days; EDD Recurring not verified' . ($full ? '; receipt capacity reached: new renewals are not counted' : ''), 'warn');
        }
    }

    /**
     * Purge the page caches SEO Pro Analytics knows (WP-Optimize, LiteSpeed
     * Cache, WP Rocket and others), so cached pages print the current
     * tracker. Done by itself after an update and after a tracker setting
     * changes.
     *
     * ## EXAMPLES
     *
     *     wp seoproanalytics purge-caches
     *
     * @subcommand purge-caches
     */
    public function purge_caches() {
        $done = SEOProAnalytics_Page_Cache::purge('manual');
        if ($done) {
            /* translators: %s: names of page cache plugins */
            WP_CLI::success(sprintf(__('Purged: %s.', 'seoproanalytics'), implode(', ', $done)));
            return;
        }
        WP_CLI::success(__('No known page cache is active.', 'seoproanalytics'));
    }

    /**
     * Move what the plugin stored under its old name, SEO Pro Stats, to
     * its name now: tables, options, meta, scheduled jobs, capabilities,
     * the collector folder, A/B test blocks in posts and the encrypted
     * connection credentials. Every site of a network. Safe to run again:
     * once moved, it finds nothing.
     *
     * ## OPTIONS
     *
     * [--dry-run]
     * : List what would move; change nothing.
     *
     * [--format=<format>]
     * : Output format.
     * ---
     * default: table
     * options:
     *   - table
     *   - json
     *   - csv
     *   - yaml
     * ---
     *
     * ## EXAMPLES
     *
     *     wp seoproanalytics migrate-name --dry-run
     *     wp seoproanalytics migrate-name
     *
     * @subcommand migrate-name
     *
     * @param string[]             $args  Positional arguments.
     * @param array<string,string> $assoc Options.
     */
    public function migrate_name($args, $assoc) {
        unset($args);
        SEOProAnalytics_Rename::cli($assoc);
    }

    /**
     * Make, show or remove the demo data: made-up visits in tables of
     * their own, for training, screenshots and testing. Live data is
     * never touched. Reports show it with --data=demo, and the screens
     * with the Demo data switch.
     *
     * ## OPTIONS
     *
     * <action>
     * : make (remove any demo data, then make it again), status or remove.
     * ---
     * options:
     *   - make
     *   - status
     *   - remove
     * ---
     *
     * [--days=<days>]
     * : Days of visits back from today, for make.
     * ---
     * default: 400
     * ---
     *
     * ## EXAMPLES
     *
     *     wp seoproanalytics demo make
     *     wp seoproanalytics demo make --days=90
     *     wp seoproanalytics stats --data=demo --range=30d --compare=prev
     *     wp seoproanalytics demo remove
     *
     * @param string[]             $args  Positional arguments.
     * @param array<string,string> $assoc Options.
     */
    public function demo($args, $assoc) {
        $action = isset($args[0]) ? (string) $args[0] : 'status';
        if ($action === 'remove') {
            SEOProAnalytics_Demo::remove();
            WP_CLI::success(__('Demo data removed. Live data is unchanged.', 'seoproanalytics'));
            return;
        }
        if ($action === 'make') {
            $start = microtime(true);
            if (!SEOProAnalytics_Demo::start(isset($assoc['days']) ? (int) $assoc['days'] : SEOProAnalytics_Demo::DAYS)) {
                WP_CLI::error(__('The demo tables could not be made.', 'seoproanalytics'));
            }
            $last = -1.0;
            do {
                $status = SEOProAnalytics_Demo::step(60);
                if ($status['progress'] > $last) {
                    WP_CLI::log(sprintf('%d%%', (int) round($status['progress'] * 100)));
                    $last = $status['progress'];
                } else {
                    sleep(2); // Another request is making it.
                }
            } while ($status['status'] === 'making');
            $totals = SEOProAnalytics_Demo::run(static function () {
                return SEOProAnalytics_Query::stats((array) SEOProAnalytics_Query::request(array('range' => 'all')));
            });
            WP_CLI::success(sprintf(
                /* translators: 1: days, 2: visits, 3: pageviews, 4: events, 5: seconds */
                __('Demo data made: %1$d days, %2$d visits, %3$d pageviews, %4$d events, in %5$d s.', 'seoproanalytics'),
                $status['days'],
                $totals['metrics']['visits'],
                $totals['metrics']['pageviews'],
                $totals['metrics']['events'],
                (int) round(microtime(true) - $start)
            ));
            return;
        }
        $status = SEOProAnalytics_Demo::status();
        WP_CLI\Utils\format_items('table', array($status), array_keys($status));
    }

    /**
     * Who owns the SEO of what: each person who can own, with their pages,
     * open Plan items (and those items' potential clicks per 28 days), open
     * search targets and running experiments (and those due for review),
     * then what nobody owns. Or set owners.
     *
     * By default a page's owner is its post's author unless someone set
     * another; a target's and a Plan item's, their page's; an experiment's,
     * its page's, else whoever recorded it. Settings → Data → Owners
     * chooses the roles that can own (by default administrators, editors,
     * shop managers and authors) and can give each place nobody or one
     * person by default instead.
     *
     * ## OPTIONS
     *
     * [<action>]
     * : list (the default) or set.
     * ---
     * default: list
     * options:
     *   - list
     *   - set
     * ---
     *
     * [<kind>]
     * : For set: page, target, item (a Plan item) or experiment.
     *
     * [<user>]
     * : For set: a user id, none (nobody, on purpose) or default (forget the owner set, so the default applies again). me with --user.
     *
     * [<ids>...]
     * : For set: pages (paths such as /pricing/ or addresses on this site), target searches, Plan item keys or experiment ids.
     *
     * [--engine=<engine>]
     * : For list: google or bing, for the Plan.
     * ---
     * default: google
     * ---
     *
     * [--goal=<id>]
     * : For list: the goal whose conversions give a page its value; the first goal when left out.
     *
     * [--range=<range>]
     * : For list: the Plan's period, as for queue.
     * ---
     * default: 90d
     * ---
     *
     * [--data=<data>]
     * : live or demo.
     * ---
     * default: live
     * options:
     *   - live
     *   - demo
     * ---
     *
     * [--format=<format>]
     * : table, json, csv or yaml.
     * ---
     * default: table
     * ---
     *
     * ## EXAMPLES
     *
     *     wp seoproanalytics owners
     *     wp seoproanalytics owners --format=json
     *     wp seoproanalytics owners set page 12 /pricing/ /features/
     *     wp seoproanalytics owners set target 12 "privacy friendly analytics"
     *     wp seoproanalytics owners set item none 3f9c0a1b2d4e5f60
     *     wp seoproanalytics owners set experiment default 7
     *     wp seoproanalytics queue --owner=12
     *
     * @param string[]             $args  Positional arguments.
     * @param array<string,string> $assoc Options.
     */
    public function owners($args, $assoc) {
        require_once SEOPROANALYTICS_DIR . 'includes/stats/class-seoproanalytics-owners.php';
        $action = isset($args[0]) ? (string) $args[0] : 'list';
        if ($action === 'set') {
            $kind   = isset($args[1]) ? (string) $args[1] : '';
            $user   = isset($args[2]) ? (string) $args[2] : '';
            $ids    = array_slice($args, 3);
            $answer = $this->on_data($assoc, static function () use ($kind, $ids, $user) {
                return SEOProAnalytics_Owners::assign($kind, $ids, $user);
            });
            if (is_wp_error($answer)) {
                WP_CLI::error($answer->get_error_message());
            }
            if ($this->format($assoc) === 'json') {
                self::json_line($answer);
                return;
            }
            foreach ($answer['skipped'] as $skip) {
                WP_CLI::warning($skip['id'] . ': ' . $skip['reason']);
            }
            if (!$answer['set']) {
                WP_CLI::error(__('No owner was set.', 'seoproanalytics'));
            }
            $who = $answer['default'] ? __('the default owner', 'seoproanalytics') : self::owner_text($answer['owner']);
            /* translators: 1: number set, 2: kind, 3: owner */
            WP_CLI::success(sprintf(__('%1$d %2$s set to %3$s.', 'seoproanalytics'), count($answer['set']), $answer['kind'], $who));
            return;
        }
        $engine = isset($assoc['engine']) ? (string) $assoc['engine'] : 'google';
        $goal   = isset($assoc['goal']) ? (string) $assoc['goal'] : '';
        $req    = $this->request($assoc + array('range' => '90d', 'compare' => 'none'));
        $answer = $this->on_data($assoc, static function () use ($req, $engine, $goal) {
            return SEOProAnalytics_Owners::summary($req, $engine, $goal);
        });
        if (is_wp_error($answer)) {
            WP_CLI::error($answer->get_error_message());
        }
        if ($this->format($assoc) === 'json') {
            self::json_line($answer);
            return;
        }
        $rows = array();
        foreach ($answer['people'] as $person) {
            $rows[] = array('id' => $person['id'], 'name' => $person['name']) + array_intersect_key($person, array_flip(array('pages', 'items', 'clicks', 'targets', 'running', 'due')));
        }
        $rows[] = array('id' => '–', 'name' => __('(nobody)', 'seoproanalytics'), 'pages' => '–') + $answer['nobody'];
        WP_CLI\Utils\format_items($this->format($assoc), $rows, array('id', 'name', 'pages', 'items', 'clicks', 'targets', 'running', 'due'));
        WP_CLI::log(__('Items and clicks: open Plan items and their potential clicks per 28 days. Running and due: experiments.', 'seoproanalytics'));
    }

    /**
     * The --owner filter; exits on a bad one.
     *
     * @param array<string,string> $assoc Options.
     * @return int|null
     */
    private function owner_filter(array $assoc) {
        require_once SEOPROANALYTICS_DIR . 'includes/stats/class-seoproanalytics-owners.php';
        $owner = SEOProAnalytics_Owners::filter(isset($assoc['owner']) ? (string) $assoc['owner'] : '');
        if (is_wp_error($owner)) {
            WP_CLI::error($owner->get_error_message());
            return null;
        }
        return $owner;
    }

    /**
     * An owner in a table: the name (default: worked out, not set), or – for nobody.
     *
     * @param array<string,mixed>|null $owner From SEOProAnalytics_Owners::out().
     * @return string
     */
    private static function owner_text($owner) {
        if (!is_array($owner) || $owner['id'] === null) {
            return '–';
        }
        $name = (string) ($owner['name'] ?? '#' . $owner['id']);
        return $owner['source'] === 'default' ? $name . ' ' . __('(default)', 'seoproanalytics') : $name;
    }

    /**
     * Run a report on the data set of the --data option; exits on an error.
     *
     * @param array<string,string> $assoc Options.
     * @param callable             $work  Makes the answer.
     * @return mixed
     */
    private function on_data(array $assoc, callable $work) {
        $answer = SEOProAnalytics_API::on_data(isset($assoc['data']) ? (string) $assoc['data'] : 'live', $work);
        if (is_wp_error($answer)) {
            WP_CLI::error($answer->get_error_message());
        }
        return $answer;
    }

    /**
     * A report request from command options; exits on a bad one.
     *
     * @param array<string,string> $assoc Options.
     * @return array<string,mixed>
     */
    private function request(array $assoc) {
        if (isset($assoc['filter'])) {
            $filter           = trim((string) $assoc['filter']);
            $assoc['filters'] = $filter !== '' && $filter[0] === '[' ? $filter : array_filter(array_map('trim', explode(';', $filter)));
        }
        $req = SEOProAnalytics_Query::request($assoc);
        if (is_wp_error($req)) {
            WP_CLI::error($req->get_error_message());
        }
        return (array) $req;
    }

    /**
     * Output format.
     *
     * @param array<string,string> $assoc Options.
     * @return string
     */
    private function format(array $assoc) {
        $format = isset($assoc['format']) ? (string) $assoc['format'] : 'table';
        return in_array($format, array('table', 'json', 'csv', 'yaml'), true) ? $format : 'table';
    }

    /**
     * Print the range above a table.
     *
     * @param array<string,string> $range Range from an answer.
     */
    private function range_line(array $range) {
        WP_CLI::log(sprintf('%s: %s to %s (%s)', $range['key'], $range['from'], $range['to'], $range['timezone']));
    }
}

WP_CLI::add_command('seoproanalytics', 'SEOProAnalytics_CLI');

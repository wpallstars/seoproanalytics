<?php
/**
 * Connections to outside data sources (Settings → Connections): each
 * source's credentials, encrypted at rest, its choices and its state.
 *
 * One option, autoload off, read only by the Connections tab, the REST
 * routes, WP-CLI and the import job; never on a visitor's page. The
 * credentials are encrypted with libsodium (bundled with WordPress) under
 * a key made from the site's salts (or SEOPROANALYTICS_ENCRYPTION_KEY), and
 * never leave this class except to the source that uses them: not in REST
 * answers, exports, WP-CLI output or logs. Design: docs/architecture.md →
 * Integrations → Connections.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 * Additional terms (GPL-3.0 section 7(b)): ATTRIBUTION.txt
 * Provenance: wpas-c8662019b99fb07b. Copies, even of part, keep these lines.
 *
 * @package SEOProAnalytics
 * @since 0.8.0
 */

if (!defined('ABSPATH')) {
    exit;
}

final class SEOProAnalytics_Connections { // NOSONAR: one store for every source's connection, used by the Connections tab, REST, WP-CLI and the import job; private helpers decompose its status.

    /** Every source's connection (autoload off). */
    const OPTION = 'seoproanalytics_connections';

    /** SEOProAnalytics_Search_Import's file, loaded on connecting and reading status. */
    const IMPORT_FILE = 'includes/stats/class-seoproanalytics-search-import.php';

    /**
     * The free plugin's own sources: key => class, in includes/stats/sources/.
     * Every other source and provider is registered through the filters in
     * sources() and providers().
     */
    const SOURCES = array(
        'bing' => 'SEOProAnalytics_Source_Bing',
    );

    /**
     * The add-on's connections: key => name and kind (source or provider).
     * While one is not registered, Settings → Connections and Search's
     * setup show its name with "(subscription needed)" (placeholders()).
     */
    const ADDON = array(
        'search-console' => array('name' => 'Google Search Console', 'kind' => 'source'),
        'crux'           => array('name' => 'Chrome UX Report', 'kind' => 'source'),
        'dataforseo'     => array('name' => 'DataForSEO', 'kind' => 'provider'),
        'ahrefs'         => array('name' => 'Ahrefs', 'kind' => 'provider'),
        'semrush'        => array('name' => 'Semrush', 'kind' => 'provider'),
        'serper'         => array('name' => 'Serper', 'kind' => 'provider'),
        'serpapi'        => array('name' => 'SerpApi', 'kind' => 'provider'),
        'brave'          => array('name' => 'Brave Search', 'kind' => 'provider'),
        'deepseek'       => array('name' => 'DeepSeek', 'kind' => 'provider'),
        'linkup'         => array('name' => 'Linkup', 'kind' => 'provider'),
        'crawler'        => array('name' => 'LibreCrawl server', 'kind' => 'source'),
    );

    /**
     * The add-on's page, unless SEOPROANALYTICS_CONNECT_URL or the
     * seoproanalytics_connect_url filter gives another.
     */
    const CONNECT_URL = 'https://www.wpallstars.com/';

    /** Prefix of an encrypted value, so a later scheme can be told apart. */
    const CIPHER = 'v1:';

    /**
     * The registered sources: Bing, and those the add-on adds.
     *
     * @return array<string,string> Key => class.
     */
    public static function sources() {
        /**
         * Filter the outside sources whose days the search import reads.
         *
         * @param array<string,string> $sources Key (at most 20 characters) => adapter
         *                                      class, loaded by then, whose KEY is the key.
         */
        $sources = apply_filters('seoproanalytics_connection_sources', self::SOURCES);
        return self::registered($sources, 'KEY', self::SOURCES);
    }

    /**
     * The registered paid providers: none in the free plugin.
     *
     * @return array<string,string> Key => class.
     */
    public static function providers() {
        /**
         * Filter the paid data providers, each with its own weekly read.
         *
         * @param array<string,string> $providers Key (at most 20 characters) => adapter
         *                                        class, loaded by then, whose SOURCE is
         *                                        the key and READER its read's class.
         */
        $providers = apply_filters('seoproanalytics_connection_providers', array());
        return self::registered($providers, 'SOURCE', array());
    }

    /**
     * A registered list: a filter's answer, keeping the entries whose
     * class is this tree's own (loaded here) or already loaded.
     *
     * @param mixed                $list  The filter's answer.
     * @param string               $named The adapter's constant that holds its key.
     * @param array<string,string> $local Every entry this tree has a file for.
     * @return array<string,string>
     */
    private static function registered($list, $named, array $local) {
        $out = array();
        foreach ((array) $list as $key => $class) {
            $key = (string) $key;
            if (!is_string($class) || $key === '' || strlen($key) > 20 || isset($out[$key])) {
                continue;
            }
            if (isset($local[$key]) && $local[$key] === $class && !class_exists($class, false)) {
                require_once SEOPROANALYTICS_DIR . 'includes/stats/sources/class-' . strtolower(str_replace('_', '-', $class)) . '.php';
            }
            if (class_exists($class) && defined($class . '::' . $named) && constant($class . '::' . $named) === $key) {
                $out[$key] = $class;
            }
        }
        return $out;
    }

    /**
     * Whether a key is a registered paid provider.
     *
     * @param string $source Source key.
     * @return bool
     */
    public static function is_provider($source) {
        return isset(self::providers()[$source]);
    }

    /**
     * Load a source's class.
     *
     * @param string $source Source key.
     * @return string|null Its class, or null for a source not registered.
     */
    public static function source_class($source) {
        $providers = self::providers();
        if (isset($providers[$source])) {
            $class = $providers[$source];
            // A provider's adapter works through its read.
            return !defined($class . '::READER') || self::load((string) constant($class . '::READER')) ? $class : null;
        }
        $sources = self::sources();
        return isset($sources[$source]) ? $sources[$source] : null;
    }

    /**
     * A provider's read: its adapter's READER class, loaded. It answers
     * status($source), connected($source, $result, $input),
     * run($source, $force, $budget), delete_data($source),
     * resume($source), retry_now($source) and reset(), and has CLI_BUDGET.
     *
     * @param string $source Provider key.
     * @return string|null The class, or null when the provider is not registered.
     */
    public static function reader($source) {
        $class = self::is_provider($source) ? self::source_class($source) : null;
        return $class && defined($class . '::READER') ? (string) constant($class . '::READER') : null;
    }

    /**
     * Whether a class a registered connection uses (a provider's read) is
     * loaded: the add-on loads its own.
     *
     * @param string $class Class.
     * @return bool Whether it is loaded.
     */
    public static function load($class) {
        return class_exists($class);
    }

    /**
     * Whether a source manages its own connection and job (its adapter's
     * OWN_CONNECTION, such as a crawler): connect, disconnect and status
     * go to its configure($input), disconnect() and status(), and the
     * search import never reads its days.
     *
     * @param string $class Adapter class.
     * @return bool
     */
    private static function own_connection($class) {
        return defined($class . '::OWN_CONNECTION') && constant($class . '::OWN_CONNECTION');
    }

    /**
     * The add-on's connections not registered here, for the
     * "(subscription needed)" lines.
     *
     * @return array<string,array{name:string,kind:string,what:string}> Key => name, kind and what it adds.
     */
    public static function placeholders() {
        $what = array(
            'search-console' => __('Clicks, impressions and positions from Google search, with URL inspection and sitemaps.', 'seoproanalytics'),
            'crux'           => __('Real Chrome users\' page experience: LCP, INP, CLS, FCP and TTFB.', 'seoproanalytics'),
            'dataforseo'     => __('Backlinks and the Google positions of your search targets, with your own account.', 'seoproanalytics'),
            'ahrefs'         => __('Domain Rating and backlinks, with your own account.', 'seoproanalytics'),
            'semrush'        => __('Backlinks and the Google positions of your search targets, with your own account.', 'seoproanalytics'),
            'serper'         => __('The Google positions of your search targets, with your own account.', 'seoproanalytics'),
            'serpapi'        => __('The Google positions of your search targets, with your own account.', 'seoproanalytics'),
            'brave'          => __('Your search targets\' Brave positions, and whether Brave gives AI assistants your pages to answer them from, with your own account.', 'seoproanalytics'),
            'deepseek'       => __('Your search targets\' positions in DeepSeek\'s search, which its AI assistant answers from, with your own account.', 'seoproanalytics'),
            'linkup'         => __('Whether the sources Linkup, a search API AI agents use, gives its answers to your search targets include your pages, and a second look at referring pages the backlinks check cannot open, with your own account.', 'seoproanalytics'),
            'crawler'        => __('Scheduled crawls of this site by your own LibreCrawl server, imported into Site crawl.', 'seoproanalytics'),
        );
        $out = array();
        foreach (array_diff_key(self::ADDON, self::sources() + self::providers()) as $key => $addon) {
            $out[$key] = $addon + array('what' => isset($what[$key]) ? $what[$key] : '');
        }
        return $out;
    }

    /**
     * Where "(subscription needed)" links to: the add-on's page.
     *
     * @return string
     */
    public static function connect_url() {
        $url = defined('SEOPROANALYTICS_CONNECT_URL') && is_string(SEOPROANALYTICS_CONNECT_URL) && SEOPROANALYTICS_CONNECT_URL !== '' ? SEOPROANALYTICS_CONNECT_URL : self::CONNECT_URL;
        /**
         * Filter the address of the page that offers the add-on's connections.
         *
         * @param string $url Address.
         */
        return esc_url_raw((string) apply_filters('seoproanalytics_connect_url', $url));
    }

    /**
     * The error for a connection only the add-on has.
     *
     * @param string $source Source key.
     * @return WP_Error
     */
    public static function needs_addon($source) {
        if (!isset(self::ADDON[$source])) {
            return new WP_Error('seoproanalytics_source_unknown', __('Unknown source.', 'seoproanalytics'), array('status' => 404));
        }
        return new WP_Error(
            'seoproanalytics_needs_addon',
            /* translators: 1: connection name such as Google Search Console, 2: the add-on's page */
            sprintf(__('%1$s needs the SEO Pro Analytics Connect add-on (subscription needed): %2$s', 'seoproanalytics'), self::ADDON[$source]['name'], self::connect_url()),
            array('status' => 402)
        );
    }

    /**
     * Connect a source, or change its choices: check the input with the
     * source (a sign-in), save it, and schedule the import job. Choosing
     * another property starts the import again from the beginning.
     *
     * @param string              $source Source key.
     * @param array<string,mixed> $input  The source's fields (Bing: key, property).
     * @return array<string,mixed>|WP_Error status().
     */
    public static function connect($source, array $input) {
        $class = self::source_class($source);
        if (!$class) {
            return self::needs_addon($source);
        }
        if (self::own_connection($class)) {
            return $class::configure($input);
        }
        $before   = self::get($source);
        $existing = $before ? self::credentials($source) : null;
        $result   = $class::connect($input, is_wp_error($existing) ? null : $existing);
        if (is_wp_error($result)) {
            return $result;
        }
        $saved = self::save($source, $result['credentials'], $result['settings']);
        if (is_wp_error($saved)) {
            return $saved;
        }
        if (self::is_provider($source)) {
            return self::connected_provider($source, $result, $input);
        }
        // Replaced sign-in (a key instead, or a new sign-in): the adapter revokes the old one.
        if (is_array($existing) && $existing !== $result['credentials'] && method_exists($class, 'revoke')) {
            $class::revoke($existing);
        }
        $reset = array('error' => null, 'error_at' => null, 'properties' => count($result['properties']));
        if (!$before || (isset($before['settings']['property']) ? (string) $before['settings']['property'] : '') !== $result['settings']['property']) {
            $reset += array('through' => null, 'back' => null, 'first' => null, 'final' => null, 'checked' => null, 'pairs_from' => null, 'pairs_to' => null, 'pairs_queue' => null);
        }
        self::update_state($source, $reset);
        require_once SEOPROANALYTICS_DIR . self::IMPORT_FILE;
        SEOProAnalytics_Search_Import::schedule();
        // The first import a few seconds from now, not on this request.
        wp_schedule_single_event(time() + 5, SEOProAnalytics_Search_Import::HOOK, array('more'));
        return self::status($source);
    }

    /**
     * A provider just connected (or its settings changed): its read's
     * connected(), then its first read a few seconds from now.
     *
     * @param string              $source Provider key.
     * @param array<string,mixed> $result Its connect() answer.
     * @param array<string,mixed> $input  The fields (resume).
     * @return array<string,mixed> status().
     */
    private static function connected_provider($source, array $result, array $input) {
        $reader = self::reader($source);
        if ($reader) {
            $reader::connected($source, $result, $input);
        }
        require_once SEOPROANALYTICS_DIR . self::IMPORT_FILE;
        SEOProAnalytics_Search_Import::schedule();
        wp_schedule_single_event(time() + 5, SEOProAnalytics_Search_Import::HOOK, array('more'));
        return self::status($source);
    }

    /**
     * The connected search sources (not providers): the search import's.
     *
     * @return string[]
     */
    public static function engines() {
        $out = array();
        foreach (self::sources() as $source => $class) {
            // A source with its own connection runs its own job, not the search import's days.
            if (!self::own_connection($class) && in_array($source, self::connected(), true)) {
                $out[] = $source;
            }
        }
        return $out;
    }

    /**
     * The connected paid providers.
     *
     * @return string[]
     */
    public static function connected_providers() {
        return array_values(array_intersect(self::connected(), array_keys(self::providers())));
    }

    /**
     * Disconnect a source: forget its credentials, and its imported data
     * when asked.
     *
     * @param string $source Source key.
     * @param bool   $delete Also delete its imported data.
     * @return array<string,mixed>|WP_Error status(), with deleted (rows).
     */
    public static function disconnect($source, $delete = false) {
        $class = self::source_class($source);
        if (!$class) {
            return self::forget_kept($source, $delete);
        }
        if (self::own_connection($class)) {
            return $class::disconnect();
        }
        require_once SEOPROANALYTICS_DIR . self::IMPORT_FILE;
        // A sign-in's access is revoked by its adapter, not only forgotten.
        $credentials = self::get($source) ? self::credentials($source) : null;
        if (is_array($credentials) && method_exists($class, 'revoke')) {
            $class::revoke($credentials);
        }
        self::remove($source);
        if ($source === 'bing') {
            // Bing's crawl facts belong to the connection (links already read stay, as an export's do).
            require_once SEOPROANALYTICS_DIR . 'includes/stats/class-seoproanalytics-bing-site-data.php';
            SEOProAnalytics_Bing_Site_Data::reset();
        }
        SEOProAnalytics_Search_Import::schedule();
        if (self::is_provider($source)) {
            // Its read deletes what it found, when asked.
            $reader  = self::reader($source);
            $deleted = $delete && $reader ? (int) $reader::delete_data($source) : 0;
            return self::status($source) + array('deleted' => $deleted);
        }
        if (method_exists($class, 'delete_data')) {
            // A source with its own read and table (Chrome UX Report's field data).
            $deleted = $delete ? (int) $class::delete_data() : 0;
            return self::status($source) + array('deleted' => $deleted);
        }
        $deleted = $delete ? SEOProAnalytics_Search_Import::delete_data($source) : 0;
        return self::status($source) + array('deleted' => $deleted);
    }

    /**
     * Disconnect one of the add-on's connections while the add-on is not
     * registered: its saved, encrypted credentials are forgotten, so no
     * one needs the add-on to remove a key. With $delete, Search Console's
     * imported days go too (the search import's own tables); the add-on's
     * other tables stay until uninstall or the add-on deletes them, and a
     * Google sign-in is revoked only by the add-on or the Google account.
     *
     * @param string $source Source key.
     * @param bool   $delete Also delete Search Console's imported days.
     * @return array<string,mixed>|WP_Error status(), with deleted (rows).
     */
    private static function forget_kept($source, $delete) {
        if (!isset(self::ADDON[$source]) || !self::get($source)) {
            // Nothing saved to forget: say where the connection comes from.
            return self::needs_addon($source);
        }
        require_once SEOPROANALYTICS_DIR . self::IMPORT_FILE;
        self::remove($source);
        SEOProAnalytics_Search_Import::schedule();
        $deleted = $delete && $source === 'search-console' ? SEOProAnalytics_Search_Import::delete_data($source) : 0;
        return self::status($source) + array('deleted' => $deleted);
    }

    /**
     * Whether one of the add-on's connections is saved here while the
     * add-on is not registered (kept, not read).
     *
     * @param string $source Source key.
     * @return bool
     */
    public static function kept($source) {
        return isset(self::ADDON[$source]) && !self::source_class($source) && (bool) self::get($source);
    }

    /**
     * Every source's status().
     *
     * @return array<int,array<string,mixed>>
     */
    public static function statuses() {
        $out = array();
        foreach (array_keys(self::sources() + self::providers()) as $source) {
            $out[] = self::status($source);
        }
        return $out;
    }

    /**
     * A source's status, without credentials: connected, the account and
     * property, how far the import is, the last error, recent imports.
     *
     * @param string $source Source key.
     * @return array<string,mixed>
     */
    public static function status($source) {
        $class = self::source_class($source);
        if ($class && self::own_connection($class)) {
            return $class::status();
        }
        $reader = $class ? self::reader($source) : null;
        if ($reader) {
            return $reader::status($source);
        }
        $conn  = self::get($source);
        require_once SEOPROANALYTICS_DIR . self::IMPORT_FILE;
        $out = array(
            'source'    => $source,
            'name'      => $class ? $class::NAME : (isset(self::ADDON[$source]) ? self::ADDON[$source]['name'] : $source),
            'connected' => (bool) $conn,
        );
        if (!$class) {
            // Kept, not read: its connection stays until the add-on reads it again.
            return $out + array('registered' => false);
        }
        if (!$conn) {
            return $out;
        }
        if (method_exists($class, 'status')) {
            // A source with its own read (Chrome UX Report): its own fields.
            $next = wp_next_scheduled(SEOProAnalytics_Search_Import::HOOK, array('more'));
            return $out + $class::status($conn) + array('next_run' => $next ? $next : wp_next_scheduled(SEOProAnalytics_Search_Import::HOOK), 'imports' => array());
        }
        $state    = $conn['state'];
        $progress = self::progress($class, $state);
        $next     = wp_next_scheduled(SEOProAnalytics_Search_Import::HOOK, array('more'));
        $next     = $next ? $next : wp_next_scheduled(SEOProAnalytics_Search_Import::HOOK);
        $coverage = SEOProAnalytics_Search_Import::coverage((int) $class::ENGINE);
        if ($source === 'bing') {
            // Crawl facts, the URL submission quota and the weekly links read.
            require_once SEOPROANALYTICS_DIR . 'includes/stats/class-seoproanalytics-bing-site-data.php';
            $out['site_data'] = SEOProAnalytics_Bing_Site_Data::status();
        }
        return $out + array(
            'method'       => self::text($conn['settings'], 'method', 'key'),
            'account'      => self::text($conn['settings'], 'account'),
            'property'     => self::text($conn['settings'], 'property'),
            'connected_at' => $conn['connected'],
            'imported'     => array(
                'from'     => $coverage['from'],
                'to'       => $coverage['to'],
                'days'     => min($progress['done'], $progress['total']),
                'of'       => $progress['total'],
                'complete' => $progress['complete'],
            ),
            // Pages whose queries are still to import (a source that gives them page by page); null: none due.
            'pages_left'    => self::pages_left($state),
            'final_through' => self::text($state, 'final'),
            'checked'       => self::number($state, 'checked'),
            'last_run'      => self::number($state, 'last_run'),
            'next_run'      => $next ? (int) $next : 0,
            'error'         => self::text($state, 'error'),
            'error_at'      => self::number($state, 'error_at'),
            'imports'       => SEOProAnalytics_Schema::is_current() ? SEOProAnalytics_Search_Import::imports($source, 5) : array(),
        );
    }

    /**
     * How far a source's import is: the days from the oldest it imports
     * (its months back, or the first day it has, when later) through the
     * newest imported, how many of them are done, and whether all are.
     *
     * @param string              $class Source class.
     * @param array<string,mixed> $state Import state.
     * @return array{total:int,done:int,complete:bool}
     */
    private static function progress($class, array $state) {
        $today   = $class::today();
        $oldest  = (new DateTimeImmutable($today, new DateTimeZone('UTC')))->modify('-' . SEOProAnalytics_Search_Import::months($class) . ' months')->format('Y-m-d');
        $first   = max(self::text($state, 'first'), $oldest);
        $back    = self::text($state, 'back');
        $through = self::text($state, 'through');
        return array(
            'total'    => self::days_between($first, $through),
            'done'     => $back !== '' ? self::days_between(max($back, $first), $through) : 0,
            'complete' => $through !== '' && $back !== '' && $back <= $first,
        );
    }

    /**
     * Pages whose queries are still to import: 0 when no page-by-page run
     * is due, null when one is due without a queue.
     *
     * @param array<string,mixed> $state Import state.
     * @return int|null
     */
    private static function pages_left(array $state) {
        if (empty($state['pairs_from'])) {
            return 0;
        }
        return isset($state['pairs_queue']) && is_array($state['pairs_queue']) ? count($state['pairs_queue']) : null;
    }

    /**
     * A stored field as text, or the default when it is not set.
     *
     * @param array<string,mixed> $fields  Stored fields (get() makes settings and state arrays).
     * @param string              $key     Field.
     * @param string              $default Default.
     * @return string
     */
    private static function text(array $fields, $key, $default = '') {
        return isset($fields[$key]) ? (string) $fields[$key] : $default;
    }

    /**
     * A stored field as a whole number, or 0 when it is not set.
     *
     * @param array<string,mixed> $fields Stored fields.
     * @param string              $key    Field.
     * @return int
     */
    private static function number(array $fields, $key) {
        return isset($fields[$key]) ? (int) $fields[$key] : 0;
    }

    /**
     * Days from one day to another, both included; 0 when either is empty
     * or the second is earlier.
     *
     * @param string $from Y-m-d.
     * @param string $to   Y-m-d.
     * @return int
     */
    private static function days_between($from, $to) {
        if ($from === '' || $to === '' || $to < $from) {
            return 0;
        }
        $utc = new DateTimeZone('UTC');
        return (int) (new DateTimeImmutable($from, $utc))->diff(new DateTimeImmutable($to, $utc))->days + 1;
    }

    /**
     * Every stored connection, as stored (credentials still encrypted).
     *
     * @return array<string,array<string,mixed>>
     */
    private static function all() {
        $all = get_option(self::OPTION, array());
        return is_array($all) ? $all : array();
    }

    /**
     * Every stored connection, read again from the database, for a write.
     * An import runs in its own request for a minute or so; if the source
     * is disconnected meanwhile, that request's cached copy still holds the
     * connection, and writing its state back from the copy would bring the
     * connection (and revoked credentials) back.
     *
     * @return array<string,array<string,mixed>>
     */
    private static function all_fresh() {
        wp_cache_delete(self::OPTION, 'options');
        wp_cache_delete('notoptions', 'options');
        return self::all();
    }

    /**
     * Whether a source is still connected, from the database (a running
     * import checks between days, so a disconnect stops it).
     *
     * @param string $source Source key.
     * @return bool
     */
    public static function still_connected($source) {
        $all = self::all_fresh();
        return isset($all[$source]) && is_array($all[$source]);
    }

    /**
     * The connected sources' keys, of the registered ones (a connection
     * whose source is not registered stays stored, unread, until it is).
     *
     * @return string[]
     */
    public static function connected() {
        return array_values(array_intersect(array_keys(self::all()), array_keys(self::sources() + self::providers())));
    }

    /**
     * One connection without its credentials: settings (such as the
     * property), state (last run, errors) and when it was made.
     *
     * @param string $source Source key.
     * @return array{settings:array<string,mixed>,state:array<string,mixed>,connected:int}|null Null when not connected.
     */
    public static function get($source) {
        $all = self::all();
        if (!isset($all[$source]) || !is_array($all[$source])) {
            return null;
        }
        $row = $all[$source];
        return array(
            'settings'  => isset($row['settings']) && is_array($row['settings']) ? $row['settings'] : array(),
            'state'     => isset($row['state']) && is_array($row['state']) ? $row['state'] : array(),
            'connected' => isset($row['connected']) ? (int) $row['connected'] : 0,
        );
    }

    /**
     * A connection's credentials, decrypted.
     *
     * @param string $source Source key.
     * @return array<string,mixed>|WP_Error
     */
    public static function credentials($source) {
        $all = self::all();
        if (!isset($all[$source]['secret'])) {
            return new WP_Error('seoproanalytics_not_connected', __('This source is not connected.', 'seoproanalytics'));
        }
        $json = self::decrypt((string) $all[$source]['secret']);
        $data = $json !== false ? json_decode($json, true) : null;
        if (!is_array($data)) {
            return new WP_Error('seoproanalytics_credentials_unreadable', __('The saved credentials cannot be read, perhaps because the site\'s security keys changed. Connect again.', 'seoproanalytics'));
        }
        return $data;
    }

    /**
     * Save a connection: credentials (encrypted) and settings. Keeps the
     * state of an existing connection.
     *
     * @param string              $source      Source key.
     * @param array<string,mixed> $credentials Credentials.
     * @param array<string,mixed> $settings    Settings.
     * @return true|WP_Error
     */
    public static function save($source, array $credentials, array $settings) {
        $secret = self::encrypt((string) wp_json_encode($credentials));
        if (is_wp_error($secret)) {
            return $secret;
        }
        $all          = self::all_fresh();
        $before       = isset($all[$source]) && is_array($all[$source]) ? $all[$source] : array();
        $all[$source] = array(
            'secret'    => $secret,
            'settings'  => $settings,
            'state'     => isset($before['state']) && is_array($before['state']) ? $before['state'] : array(),
            'connected' => isset($before['connected']) ? (int) $before['connected'] : time(),
        );
        update_option(self::OPTION, $all, false);
        return true;
    }

    /**
     * Merge values into a connection's state (null removes a key).
     *
     * @param string              $source Source key.
     * @param array<string,mixed> $values Values.
     */
    public static function update_state($source, array $values) {
        $all = self::all_fresh();
        if (!isset($all[$source]) || !is_array($all[$source])) {
            return;
        }
        $state = isset($all[$source]['state']) && is_array($all[$source]['state']) ? $all[$source]['state'] : array();
        foreach ($values as $key => $value) {
            if ($value === null) {
                unset($state[$key]);
            } else {
                $state[$key] = $value;
            }
        }
        $all[$source]['state'] = $state;
        update_option(self::OPTION, $all, false);
    }

    /**
     * Forget a connection and its credentials.
     *
     * @param string $source Source key.
     */
    public static function remove($source) {
        $all = self::all_fresh();
        unset($all[$source]);
        if ($all) {
            update_option(self::OPTION, $all, false);
        } else {
            delete_option(self::OPTION);
        }
    }

    /**
     * Forget every connection (uninstall).
     */
    public static function forget() {
        delete_option(self::OPTION);
    }

    /**
     * Encrypt a text: version, then base64 of nonce and ciphertext.
     *
     * @param string $plain Text.
     * @return string|WP_Error
     */
    public static function encrypt($plain) {
        if (!function_exists('sodium_crypto_secretbox')) {
            return new WP_Error('seoproanalytics_no_sodium', __('PHP\'s sodium functions, which WordPress normally provides, are missing, so credentials cannot be stored safely.', 'seoproanalytics'));
        }
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        return self::CIPHER . base64_encode($nonce . sodium_crypto_secretbox($plain, $nonce, self::key())); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- stores binary ciphertext as text.
    }

    /**
     * Decrypt a text made by encrypt().
     *
     * @param string      $stored Stored value.
     * @param string|null $key    Key from key(); this plugin's own when null.
     * @return string|false False when it cannot be read (another key, or damaged).
     */
    public static function decrypt($stored, $key = null) {
        if (!function_exists('sodium_crypto_secretbox_open') || strpos($stored, self::CIPHER) !== 0) {
            return false;
        }
        $raw = base64_decode(substr($stored, strlen(self::CIPHER)), true); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- reads what encrypt() stored.
        if ($raw === false || strlen($raw) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            return false;
        }
        try {
            return sodium_crypto_secretbox_open(substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), $key === null ? self::key() : $key);
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * The 32-byte key: from SEOPROANALYTICS_ENCRYPTION_KEY when wp-config.php
     * defines it, else from the site's AUTH_KEY and AUTH_SALT. Keys for
     * another context (SEOProAnalytics_Rename reads values stored under the
     * plugin's old name) come from the same secret.
     *
     * @param string $context What the key is for.
     * @return string
     */
    public static function key($context = 'seoproanalytics-connections') {
        $secret = defined('SEOPROANALYTICS_ENCRYPTION_KEY') && is_string(SEOPROANALYTICS_ENCRYPTION_KEY) && SEOPROANALYTICS_ENCRYPTION_KEY !== '' ? SEOPROANALYTICS_ENCRYPTION_KEY : wp_salt('auth');
        return hash_hmac('sha256', (string) $context, $secret, true);
    }
}

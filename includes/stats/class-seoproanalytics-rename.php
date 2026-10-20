<?php
/**
 * Move what a site stored under the plugin's old name (SEO Pro Stats,
 * seoprostats) to its name now (SEO Pro Analytics, seoproanalytics):
 * tables of every data set, options, network options, user, post, term,
 * comment and WooCommerce order meta, scheduled jobs, capabilities, the
 * collector folder, A/B test blocks saved in posts, and the encrypted
 * connection credentials (re-encrypted under the new name's key).
 *
 * While a site has data under the old name and none under the new, the
 * plugin stays off on that site, so nothing is written under the new name
 * first. The move runs on the site's next admin request (not AJAX) or
 * scheduled-jobs run, or with `wp seoproanalytics migrate-name`. Each step
 * moves only what is still under the old name, so a move that stopped half
 * way finishes on the next run. Nothing is deleted except caches
 * (transients, rewrite rules) and the emptied old folder; a credential
 * that cannot be read is kept as it is and reported. Design and the
 * install move: docs/architecture.md → Renamed from SEO Pro Stats.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 * Additional terms (GPL-3.0 section 7(b)): ATTRIBUTION.txt
 * Provenance: wpas-c8662019b99fb07b. Copies, even of part, keep these lines.
 *
 * @package SEOProAnalytics
 * @since 1.19.0
 */

if (!defined('ABSPATH')) {
    exit;
}

final class SEOProAnalytics_Rename { // NOSONAR: one-off move of every kind of stored data; one small method per kind.

    /** The plugin's old prefix (options, tables, meta keys, hooks). */
    const OLD_PREFIX = 'seoprostats_';

    /** Its prefix now. */
    const NEW_PREFIX = 'seoproanalytics_';

    /** The old settings version option: present on every site the old plugin ran on. */
    const OLD_VERSION = 'seoprostats_db_version';

    /** The settings version option now (SEOProAnalytics_Settings::DB_VERSION_OPTION; autoloaded). */
    const NEW_VERSION = 'seoproanalytics_db_version';

    /** The old plugin's basename. */
    const OLD_PLUGIN = 'seoprostats/seoprostats.php';

    /** The old and new slugs, in box IDs (screen_state()). */
    const OLD_SLUG = 'seoprostats';
    const NEW_SLUG = 'seoproanalytics';

    /** The old capability name part, in roles and users' own capabilities. */
    const OLD_CAP = 'seoprostats';

    /** Block names in saved post content. */
    const OLD_BLOCKS = 'wp:seoprostats/';
    const NEW_BLOCKS = 'wp:seoproanalytics/';

    /** What the old name's encryption key was made for (SEOProAnalytics_Connections::key()). */
    const OLD_KEY_CONTEXT = 'seoprostats-connections';

    /** wp-config.php constants with the old prefix, without it. */
    const CONSTANTS = array('ENCRYPTION_KEY', 'CORE_ONLY', 'GOOGLE_RELAY', 'CONNECT_URL', 'CRAWLER_ALLOW_PRIVATE');

    /** The old name's collector folder under wp-content (the new one: SEOProAnalytics_Collection::dir()). */
    const OLD_FOLDER = 'seoprostats';

    /** A run in progress on a site (autoload off). */
    const LOCK = 'seoproanalytics_rename_lock';

    /** The last run's problems on a site, shown to administrators until dismissed. */
    const REPORT = 'seoproanalytics_rename_report';

    /** admin-post action that dismisses the report. */
    const DISMISS = 'seoproanalytics_rename_dismiss';

    /** Seconds a lock holds, should a run die holding it. */
    const LOCK_TTL = 600;

    /** @var array<int,array<string,mixed>> Rows of the current run. */
    private static $rows = array();

    /** @var array<int,array{code:string,args:array<int,string>,site:int}> Problems of the current run (item()). */
    private static $problems = array();

    /** @var bool Whether a step found something it must not overwrite (the move does not finish). */
    private static $conflict = false;

    /** @var bool Whether this run holds the site's lock. */
    private static $locked = false;

    /** @var bool Whether another request held a site's lock during this run (cli() waits for it). */
    private static $busy = false;

    /** Seconds `wp seoproanalytics migrate-name` waits for another request's move. */
    const CLI_WAIT = 60;

    /**
     * Whether the plugin may load now, as its main file is included. While
     * the current site's data is still under the old name, it is moved
     * first on admin requests and scheduled-jobs runs; on other requests
     * the plugin stays off (WP-CLI gets `wp seoproanalytics migrate-name`
     * only). The move needs WordPress's pluggable functions (wp_salt(), for
     * the credentials' key), which exist only once every plugin is
     * included: it runs first thing on plugins_loaded, and the plugin then
     * loads on the same request (load_after_move()).
     *
     * @return bool
     */
    public static function ready() {
        if (is_admin()) {
            add_action('admin_notices', array(__CLASS__, 'notice'));
            add_action('network_admin_notices', array(__CLASS__, 'notice'));
            add_action('admin_post_' . self::DISMISS, array(__CLASS__, 'dismiss'));
        }
        if (!self::pending()) {
            return true;
        }
        if (defined('WP_CLI') && WP_CLI) {
            WP_CLI::add_command('seoproanalytics', 'SEOProAnalytics_Rename_CLI');
            return false;
        }
        if (!((is_admin() && !wp_doing_ajax()) || wp_doing_cron()) || self::blockers()) {
            return false;
        }
        if (did_action('plugins_loaded')) {
            // Included after WordPress loaded (activation's sandbox).
            return self::move_here();
        }
        add_action('plugins_loaded', array(__CLASS__, 'load_after_move'), PHP_INT_MIN);
        return false;
    }

    /**
     * On plugins_loaded, first: move the site's data, then load the plugin
     * on this request, so a cron run has the moved jobs' callbacks.
     */
    public static function load_after_move() {
        if (self::move_here()) {
            SEOProAnalytics::load();
        }
    }

    /**
     * Move the current site's and the network's data on this request.
     *
     * @return bool Whether the move finished (the plugin may load).
     */
    private static function move_here() {
        if (self::pending()) {
            self::run_site(false);
            self::network(false);
            self::finish(false);
        }
        return !self::pending();
    }

    /**
     * Whether the current site has data under the old name and none under
     * the new. No query once moved: the new version option is autoloaded.
     * Changes as the move runs.
     *
     * @phpstan-impure
     * @return bool
     */
    public static function pending() {
        return false === get_option(self::NEW_VERSION, false) && false !== get_option(self::OLD_VERSION, false);
    }

    /**
     * Reasons the move must wait, for the current site: the old plugin is
     * still on, or wp-config.php still names the old encryption key (the
     * credentials would be re-encrypted under a key the site then stops
     * using). Problems as problem() stores them: translated when shown,
     * as this runs before translations may load.
     *
     * @return array<int,array{code:string,args:array<int,string>,site:int}>
     */
    public static function blockers() {
        $found    = array();
        $active   = (array) get_option('active_plugins', array());
        $sitewide = is_multisite() ? (array) get_site_option('active_sitewide_plugins', array()) : array();
        if (in_array(self::OLD_PLUGIN, $active, true) || isset($sitewide[self::OLD_PLUGIN])) {
            $found[] = self::item('old_active');
        }
        if (defined('SEOPROSTATS_ENCRYPTION_KEY') && !defined('SEOPROANALYTICS_ENCRYPTION_KEY')) {
            $found[] = self::item('old_key');
        }
        return $found;
    }

    /**
     * Every site (multisite) and the network's data, for WP-CLI.
     *
     * @param bool $dry   Only list what would move.
     * @param bool $again Add to the previous run's rows and problems (cli() running again after waiting).
     * @return array{rows:array<int,array<string,mixed>>,problems:string[],blockers:string[]}
     */
    public static function run_all($dry, $again = false) {
        if (!$again) {
            self::$rows     = array();
            self::$problems = array();
        }
        self::$busy = false;
        $blockers   = array();
        $sites          = is_multisite() ? get_sites(array('fields' => 'ids', 'number' => 0)) : array(get_current_blog_id());
        foreach ($sites as $site) {
            if (is_multisite()) {
                switch_to_blog((int) $site);
            }
            try {
                if (self::pending()) {
                    $here = self::blockers();
                    if ($here) {
                        $blockers = array_merge($blockers, $here);
                    } else {
                        self::run_site($dry);
                        self::finish($dry);
                    }
                }
            } finally {
                if (is_multisite()) {
                    restore_current_blog();
                }
            }
        }
        if (!$blockers) {
            self::network($dry);
        }
        if (!$dry) {
            self::flush_caches();
        }
        return array(
            'rows'     => self::$rows,
            'problems' => self::messages(self::$problems),
            'blockers' => self::messages($blockers),
        );
    }

    /**
     * Move the current site's data. The settings version moves last
     * (finish()), so a run that stops part way runs again.
     *
     * @param bool $dry Only list what would move.
     */
    private static function run_site($dry) {
        self::$conflict = false;
        self::$locked   = false;
        if (!$dry && !add_option(self::LOCK, time(), '', false)) {
            if ((int) get_option(self::LOCK, 0) > time() - self::LOCK_TTL) {
                self::problem('locked');
                self::$conflict = true;
                self::$busy     = true;
                return;
            }
            update_option(self::LOCK, time(), false);
        }
        self::$locked = !$dry;
        if (!self::tables($dry)) {
            // Keep everything under the old name until the tables can move.
            self::$conflict = true;
            return;
        }
        self::options($dry);
        self::secrets($dry);
        self::site_meta_tables($dry);
        self::capabilities($dry);
        self::cron($dry);
        self::blocks($dry);
        self::constants();
    }

    /**
     * Move the settings version, so the plugin loads on the site, unless a
     * step found something it must not overwrite; keep the site's problems
     * for the admin notice either way.
     *
     * @param bool $dry Only list what would move.
     */
    private static function finish($dry) {
        global $wpdb;
        if ($dry || !self::$locked) {
            // Without the lock, another request is moving the site: it
            // writes the report and finishes.
            return;
        }
        $site = get_current_blog_id();
        $here = array_values(array_filter(self::$problems, static function ($item) use ($site) {
            return $item['site'] === $site;
        }));
        if ($here) {
            // Autoloaded, so notice() costs no query on sites without one.
            update_option(self::REPORT, array('time' => time(), 'problems' => $here), true);
        } else {
            delete_option(self::REPORT);
        }
        if (self::$conflict) {
            delete_option(self::LOCK);
            return;
        }
        if (false === get_option(self::NEW_VERSION, false)) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- renaming our own option row keeps its value and autoload.
            $wpdb->query($wpdb->prepare("UPDATE {$wpdb->options} SET option_name = %s WHERE option_name = %s", self::NEW_VERSION, self::OLD_VERSION));
        }
        self::forget_options(array(self::OLD_VERSION, self::NEW_VERSION));
        // The tracker's address changed: purge page caches on the next admin request (SEOProAnalytics_Page_Cache::check_build()).
        $cache = get_option('seoproanalytics_page_cache');
        if (is_array($cache)) {
            $cache['build'] = '';
            update_option('seoproanalytics_page_cache', $cache, true);
        }
        delete_option('rewrite_rules');
        delete_option(self::LOCK);
        self::flush_caches();
    }

    /**
     * Rename every table of the site with the old prefix (every data set),
     * in one statement, or none when one cannot move.
     *
     * @param bool $dry Only list what would move.
     * @return bool Whether every table moved (or would).
     */
    private static function tables($dry) {
        global $wpdb;
        $old = $wpdb->prefix . self::OLD_PREFIX;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- listing our own tables once.
        $tables = (array) $wpdb->get_col($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($old) . '%'));
        $pairs  = array();
        $ok     = true;
        foreach ($tables as $table) {
            $to = $wpdb->prefix . self::NEW_PREFIX . substr((string) $table, strlen($old));
            if (!preg_match('/^\w+$/', (string) $table) || !preg_match('/^\w+$/', $to)) {
                continue;
            }
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- checking our own table name once.
            if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($to))) === $to) {
                self::problem('table_exists', array($table, $to));
                $ok = false;
                continue;
            }
            $pairs[] = '`' . $table . '` TO `' . $to . '`';
        }
        if (!$ok) {
            self::row('tables', 0, $dry);
            return false;
        }
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.NotPrepared -- one atomic RENAME of our own tables; names are listed above and checked as \w+.
        if ($pairs && !$dry && false === $wpdb->query('RENAME TABLE ' . implode(', ', $pairs))) {
            self::problem('tables_failed', array($wpdb->last_error));
            return false;
        }
        self::row('tables', count($pairs), $dry);
        return true;
    }

    /**
     * Rename the site's options (all but the settings version, moved last)
     * and delete its transients, which are caches.
     *
     * @param bool $dry Only list what would move.
     */
    private static function options($dry) {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- our own option names, by the option_name index, once.
        $names = (array) $wpdb->get_col($wpdb->prepare("SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s AND option_name <> %s", $wpdb->esc_like(self::OLD_PREFIX) . '%', self::OLD_VERSION));
        $moved = 0;
        foreach ($names as $name) {
            $to = self::NEW_PREFIX . substr((string) $name, strlen(self::OLD_PREFIX));
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- checking one option row by its unique name.
            if ($wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name = %s", $to))) {
                self::problem('option_exists', array($name, $to));
                self::$conflict = true;
                continue;
            }
            if (!$dry) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- renaming our own option row keeps its value and autoload.
                $wpdb->query($wpdb->prepare("UPDATE {$wpdb->options} SET option_name = %s WHERE option_name = %s", $to, $name));
                self::forget_options(array($name, $to));
            }
            ++$moved;
        }
        self::row('options', $moved, $dry);

        $caches = 0;
        foreach (array('_transient_', '_transient_timeout_', '_site_transient_', '_site_transient_timeout_') as $kind) {
            $like = $wpdb->esc_like($kind . self::OLD_PREFIX) . '%';
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- our own transients, once.
            $caches += $dry ? (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE %s", $like)) : (int) $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $like));
        }
        self::row('transients (caches, deleted)', $caches, $dry);
    }

    /**
     * Re-encrypt what is stored under the connections key with the new
     * name's key: connection credentials and state (such as the crawler's
     * session), and shared reports' link and password copies. A value
     * neither key reads is kept as it is and reported.
     *
     * @param bool $dry Only list what would move.
     */
    private static function secrets($dry) {
        require_once SEOPROANALYTICS_DIR . 'includes/stats/class-seoproanalytics-connections.php';
        foreach (array('connections' => 'connection credentials', 'shares' => 'shared report links and passwords') as $store => $label) {
            $name  = ($dry ? self::OLD_PREFIX : self::NEW_PREFIX) . $store;
            $all   = get_option($name, null);
            $count = 0;
            if (is_array($all)) {
                foreach ($all as $key => $value) {
                    $all[$key] = self::reencrypt($value, $store, (string) $key, $count);
                }
                if (!$dry && $count) {
                    update_option($name, $all, false);
                }
            }
            self::row($label . ' (re-encrypted)', $count, $dry);
        }
    }

    /**
     * A stored value with every encrypted text re-encrypted.
     *
     * @param mixed  $value Value.
     * @param string $store Option it is in, without the prefix: connections or shares.
     * @param string $key   Connection or share, for the report.
     * @param int    $count Texts re-encrypted so far.
     * @return mixed
     */
    private static function reencrypt($value, $store, $key, &$count) {
        if (is_array($value)) {
            foreach ($value as $part => $item) {
                $value[$part] = self::reencrypt($item, $store, $key, $count);
            }
            return $value;
        }
        if (!is_string($value) || strpos($value, SEOProAnalytics_Connections::CIPHER) !== 0) {
            return $value;
        }
        $plain = false;
        foreach (self::old_keys() as $old) {
            $plain = SEOProAnalytics_Connections::decrypt($value, $old);
            if (false !== $plain) {
                break;
            }
        }
        if (false === $plain) {
            if (false === SEOProAnalytics_Connections::decrypt($value)) {
                self::problem($store === 'shares' ? 'share_kept' : 'secret_unreadable', array($key));
            }
            return $value;
        }
        $new = function_exists('sodium_crypto_secretbox') ? SEOProAnalytics_Connections::encrypt($plain) : false;
        if (!is_string($new)) {
            self::problem($store === 'shares' ? 'share_kept' : 'no_sodium', array($key));
            return $value;
        }
        ++$count;
        return $new;
    }

    /**
     * Keys the old name's values may be encrypted with: the old context
     * under the site's secret, and under the old constant while it is set.
     *
     * @return string[]
     */
    private static function old_keys() {
        $keys = array(SEOProAnalytics_Connections::key(self::OLD_KEY_CONTEXT));
        $old  = defined('SEOPROSTATS_ENCRYPTION_KEY') ? constant('SEOPROSTATS_ENCRYPTION_KEY') : '';
        if (is_string($old) && $old !== '') {
            $keys[] = hash_hmac('sha256', self::OLD_KEY_CONTEXT, $old, true);
        }
        return $keys;
    }

    /**
     * Meta keys in the site's post, term, comment and WooCommerce order
     * meta tables.
     *
     * @param bool $dry Only list what would move.
     */
    private static function site_meta_tables($dry) {
        global $wpdb;
        $tables = array($wpdb->postmeta, $wpdb->termmeta, $wpdb->commentmeta);
        $orders = $wpdb->prefix . 'wc_orders_meta';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- whether WooCommerce's order meta table exists, once.
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($orders))) === $orders) {
            $tables[] = $orders;
        }
        foreach ($tables as $table) {
            self::meta_keys($table, $dry);
        }
    }

    /**
     * Rename the meta keys with the old prefix (also with a leading
     * underscore) in one meta table, by the meta_key index.
     *
     * @param string $table Meta table.
     * @param bool   $dry   Only list what would move.
     */
    private static function meta_keys($table, $dry) {
        global $wpdb;
        $moved = 0;
        foreach (array('', '_') as $lead) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- our own meta keys by the meta_key index, once.
            $keys = (array) $wpdb->get_results($wpdb->prepare('SELECT meta_key, COUNT(*) AS n FROM %i WHERE meta_key LIKE %s GROUP BY meta_key', $table, $wpdb->esc_like($lead . self::OLD_PREFIX) . '%'));
            foreach ($keys as $key) {
                $to = $lead . self::NEW_PREFIX . substr((string) $key->meta_key, strlen($lead . self::OLD_PREFIX));
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- checking one meta key by the meta_key index.
                if ((int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i WHERE meta_key = %s', $table, $to))) {
                    self::problem('meta_exists', array((string) $key->meta_key, $table));
                    continue;
                }
                if (!$dry) {
                    // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- renaming our own meta key in place.
                    $wpdb->query($wpdb->prepare('UPDATE %i SET meta_key = %s WHERE meta_key = %s', $table, $to, $key->meta_key));
                }
                $moved += (int) $key->n;
            }
        }
        $label = substr($table, strlen($wpdb->prefix));
        if ($table === $wpdb->usermeta || $table === $wpdb->sitemeta) {
            $label = substr($table, strlen($wpdb->base_prefix));
        }
        self::row($label, $moved, $dry);
    }

    /**
     * The old capability in the site's roles and in users' own capabilities.
     *
     * @param bool $dry Only list what would move.
     */
    private static function capabilities($dry) {
        global $wpdb;
        $prefix = $wpdb->get_blog_prefix();
        $moved  = 0;

        $roles = get_option($prefix . 'user_roles');
        if (is_array($roles)) {
            foreach ($roles as $role => $data) {
                if (isset($data['capabilities']) && is_array($data['capabilities'])) {
                    $renamed = self::renamed_caps($data['capabilities']);
                    if ($renamed !== $data['capabilities']) {
                        $roles[$role]['capabilities'] = $renamed;
                        ++$moved;
                    }
                }
            }
            if (!$dry && $moved) {
                update_option($prefix . 'user_roles', $roles);
                if (isset($GLOBALS['wp_roles']) && $GLOBALS['wp_roles'] instanceof WP_Roles) {
                    // This request already read the roles; read them again.
                    $GLOBALS['wp_roles']->for_site();
                }
            }
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- users' own capabilities naming ours, once, by the meta_key index.
        $users = (array) $wpdb->get_results($wpdb->prepare("SELECT umeta_id, user_id, meta_value FROM {$wpdb->usermeta} WHERE meta_key = %s AND meta_value LIKE %s", $prefix . 'capabilities', '%' . $wpdb->esc_like(self::OLD_CAP) . '%'));
        foreach ($users as $user) {
            $caps = maybe_unserialize($user->meta_value);
            if (!is_array($caps)) {
                continue;
            }
            $renamed = self::renamed_caps($caps);
            if ($renamed === $caps) {
                continue;
            }
            if (!$dry) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- one user's capabilities row, by its id.
                $wpdb->query($wpdb->prepare("UPDATE {$wpdb->usermeta} SET meta_value = %s WHERE umeta_id = %d", maybe_serialize($renamed), (int) $user->umeta_id));
                clean_user_cache((int) $user->user_id);
            }
            ++$moved;
        }
        self::row('roles and users with the capability', $moved, $dry);
    }

    /**
     * Capabilities with the old name renamed, in their order.
     *
     * @param array<string,mixed> $caps Capability => grant.
     * @return array<string,mixed>
     */
    private static function renamed_caps(array $caps) {
        $out = array();
        foreach ($caps as $cap => $grant) {
            $out[str_replace(self::OLD_CAP, rtrim(self::NEW_PREFIX, '_'), (string) $cap)] = $grant;
        }
        return $out;
    }

    /**
     * Scheduled jobs: hooks and custom schedules with the old prefix, at
     * the same times with the same arguments.
     *
     * @param bool $dry Only list what would move.
     */
    private static function cron($dry) {
        $crons = _get_cron_array();
        if (!is_array($crons)) {
            self::row('scheduled jobs', 0, $dry);
            return;
        }
        $moved = 0;
        foreach ($crons as $time => $hooks) {
            if (!is_array($hooks)) {
                continue;
            }
            $renamed = array();
            foreach ($hooks as $hook => $events) {
                $to = self::renamed((string) $hook);
                if ($to !== $hook && is_array($events)) {
                    foreach ($events as $key => $event) {
                        if (is_array($event) && isset($event['schedule']) && is_string($event['schedule'])) {
                            $event['schedule'] = self::renamed($event['schedule']);
                        }
                        $renamed[$to][$key] = $event;
                        ++$moved;
                    }
                    continue;
                }
                $renamed[$hook] = isset($renamed[$hook]) && is_array($events) ? array_merge($events, $renamed[$hook]) : $events;
            }
            $crons[$time] = $renamed;
        }
        if (!$dry && $moved) {
            _set_cron_array($crons);
        }
        self::row('scheduled jobs', $moved, $dry);
    }

    /**
     * A name with the old prefix given the new one; others as they are.
     *
     * @param string $name Name.
     * @return string
     */
    private static function renamed($name) {
        return strpos($name, self::OLD_PREFIX) === 0 ? self::NEW_PREFIX . substr($name, strlen(self::OLD_PREFIX)) : $name;
    }

    /**
     * A/B test blocks saved in posts, revisions, templates and patterns:
     * their saved markup is the block comments only.
     *
     * @param bool $dry Only list what would move.
     */
    private static function blocks($dry) {
        global $wpdb;
        $like = '%' . $wpdb->esc_like(self::OLD_BLOCKS) . '%';
        if ($dry) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- counting once for the dry run.
            self::row('posts with A/B test blocks', (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_content LIKE %s", $like)), true);
            return;
        }
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- the posts to clear from the cache afterwards.
        $ids = array_map('intval', (array) $wpdb->get_col($wpdb->prepare("SELECT ID FROM {$wpdb->posts} WHERE post_content LIKE %s", $like)));
        if ($ids) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- one REPLACE of our block names, once.
            $wpdb->query($wpdb->prepare("UPDATE {$wpdb->posts} SET post_content = REPLACE(post_content, %s, %s) WHERE post_content LIKE %s", self::OLD_BLOCKS, self::NEW_BLOCKS, $like));
            foreach ($ids as $id) {
                clean_post_cache($id);
            }
        }
        self::row('posts with A/B test blocks', count($ids), false);
    }

    /**
     * Report wp-config.php constants still under the old name (only the
     * encryption key stops the move: blockers()).
     */
    private static function constants() {
        foreach (self::CONSTANTS as $name) {
            if (defined('SEOPROSTATS_' . $name) && !defined('SEOPROANALYTICS_' . $name)) {
                self::problem('constant', array('SEOPROSTATS_' . $name, 'SEOPROANALYTICS_' . $name));
            }
        }
    }

    /**
     * What belongs to no single site: user meta, network options and
     * site transients (multisite), and the collector folder.
     *
     * @param bool $dry Only list what would move.
     */
    private static function network($dry) {
        global $wpdb;
        self::meta_keys($wpdb->usermeta, $dry);
        self::screen_state($dry);
        if (is_multisite()) {
            self::meta_keys($wpdb->sitemeta, $dry);
            $caches = 0;
            foreach (array('_site_transient_', '_site_transient_timeout_') as $kind) {
                $like = $wpdb->esc_like($kind . self::OLD_PREFIX) . '%';
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- our own site transients, once.
                $caches += $dry ? (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->sitemeta} WHERE meta_key LIKE %s", $like)) : (int) $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->sitemeta} WHERE meta_key LIKE %s", $like));
            }
            self::row('network transients (caches, deleted)', $caches, $dry);
        }
        self::folder($dry);
    }

    /**
     * Users' screen state naming our boxes (the dashboard widget, the
     * editor's Search queries box): hidden, closed and their order.
     *
     * @param bool $dry Only list what would move.
     */
    private static function screen_state($dry) {
        global $wpdb;
        $moved = 0;
        foreach (array('metaboxhidden_', 'closedpostboxes_', 'meta-box-order_') as $kind) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- by the meta_key index, once.
            $rows = (array) $wpdb->get_results($wpdb->prepare("SELECT umeta_id, user_id, meta_value FROM {$wpdb->usermeta} WHERE meta_key LIKE %s AND meta_value LIKE %s", $wpdb->esc_like($kind) . '%', '%' . $wpdb->esc_like(self::OLD_SLUG) . '%'));
            foreach ($rows as $row) {
                $value = self::renamed_boxes(maybe_unserialize($row->meta_value));
                if (!$dry) {
                    // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- one row, by its id.
                    $wpdb->query($wpdb->prepare("UPDATE {$wpdb->usermeta} SET meta_value = %s WHERE umeta_id = %d", maybe_serialize($value), (int) $row->umeta_id));
                    wp_cache_delete((int) $row->user_id, 'user_meta');
                }
                ++$moved;
            }
        }
        self::row('users\' hidden, closed and ordered boxes', $moved, $dry);
    }

    /**
     * Box IDs with the old name given the new one (lists of IDs, or
     * comma-separated IDs per column).
     *
     * @param mixed $value Value.
     * @return mixed
     */
    private static function renamed_boxes($value) {
        if (is_array($value)) {
            return array_map(array(__CLASS__, 'renamed_boxes'), $value);
        }
        return is_string($value) ? str_replace(self::OLD_SLUG, self::NEW_SLUG, $value) : $value;
    }

    /**
     * Move wp-content/seoprostats (every site's collector config and
     * unprocessed hits) to wp-content/seoproanalytics; site folders already
     * there stay, and are reported.
     *
     * @param bool $dry Only list what would move.
     */
    private static function folder($dry) {
        // The collector's folder has one definition; the old one is beside it.
        require_once SEOPROANALYTICS_DIR . 'includes/stats/class-seoproanalytics-collection.php';
        $new = dirname(SEOProAnalytics_Collection::dir());
        $old = dirname($new) . '/' . self::OLD_FOLDER;
        if (!is_dir($old)) {
            return;
        }
        $sites = glob($old . '/site-*', GLOB_ONLYDIR);
        $sites = is_array($sites) ? $sites : array();
        if ($dry) {
            self::row('collector folders', count($sites), true);
            return;
        }
        // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename, WordPress.PHP.NoSilencedErrors.Discouraged -- moving our own folder (WP_Filesystem has no rename and may ask for FTP details in cron); a failure moves each site folder below.
        if (!file_exists($new) && @rename($old, $new)) {
            self::row('collector folders', count($sites), false);
            return;
        }
        wp_mkdir_p($new);
        $moved = 0;
        foreach ($sites as $dir) {
            $to = $new . '/' . basename($dir);
            // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename, WordPress.PHP.NoSilencedErrors.Discouraged -- as above; a failure is reported.
            if (file_exists($to) || !@rename($dir, $to)) {
                self::problem('folder', array($dir, $to));
                continue;
            }
            ++$moved;
        }
        // The guard files go with the emptied folder; the new folder gets its own (SEOProAnalytics_Collection::write_config()).
        foreach (array('index.php', '.htaccess') as $guard) {
            if (is_file($old . '/' . $guard)) {
                wp_delete_file($old . '/' . $guard);
            }
        }
        @rmdir($old); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- only once empty; anything left stays.
        self::row('collector folders', $moved, false);
    }

    /**
     * Forget cached option values after renaming rows directly.
     *
     * @param string[] $names Option names.
     */
    private static function forget_options(array $names) {
        foreach ($names as $name) {
            wp_cache_delete($name, 'options');
        }
        wp_cache_delete('alloptions', 'options');
        wp_cache_delete('notoptions', 'options');
    }

    /**
     * Forget cached values after renaming rows directly: a persistent
     * object cache keeps them across requests.
     */
    private static function flush_caches() {
        wp_cache_delete('alloptions', 'options');
        wp_cache_delete('notoptions', 'options');
        if (wp_using_ext_object_cache()) {
            wp_cache_flush();
        }
    }

    /**
     * Add a report row.
     *
     * @param string $what  What.
     * @param int    $count How many.
     * @param bool   $dry   Dry run.
     */
    private static function row($what, $count, $dry) {
        self::$rows[] = array(
            'site'   => get_current_blog_id(),
            'what'   => $what,
            'count'  => (int) $count,
            'status' => $count ? ($dry ? 'would move' : 'moved') : 'none',
        );
    }

    /**
     * Add a problem to the report.
     *
     * @param string   $code Problem code (messages()).
     * @param string[] $args Its values.
     */
    private static function problem($code, array $args = array()) {
        self::$problems[] = self::item($code, $args);
    }

    /**
     * A problem as stored: translated only when shown (messages()), as the
     * move can run before translations may load.
     *
     * @param string   $code Problem code.
     * @param string[] $args Its values.
     * @return array{code:string,args:array<int,string>,site:int}
     */
    private static function item($code, array $args = array()) {
        return array(
            'code' => $code,
            'args' => array_map('strval', array_values($args)),
            'site' => get_current_blog_id(),
        );
    }

    /**
     * Problems as text, each once.
     *
     * @param array<int,array{code:string,args:array<int,string>,site:int}> $items Problems.
     * @return string[]
     */
    private static function messages(array $items) {
        $texts = array(
            'old_active'        => __('SEO Pro Stats, the plugin\'s old name, is still active. Deactivate it (do not delete its data): SEO Pro Analytics then moves its data.', 'seoproanalytics'),
            'old_key'           => __('wp-config.php defines SEOPROSTATS_ENCRYPTION_KEY. Rename it to SEOPROANALYTICS_ENCRYPTION_KEY first, so the stored connections are re-encrypted with the key the site keeps using.', 'seoproanalytics'),
            'locked'            => __('Another request is moving this site\'s data; try again in a few minutes.', 'seoproanalytics'),
            /* translators: 1: old table name, 2: new table name */
            'table_exists'      => __('Table %1$s was not renamed: %2$s already exists, so nothing on this site was moved. Compare the two, keep one, then run wp seoproanalytics migrate-name again.', 'seoproanalytics'),
            /* translators: %s: database error */
            'tables_failed'     => __('The tables could not be renamed: %s', 'seoproanalytics'),
            /* translators: 1: old option name, 2: new option name */
            'option_exists'     => __('Option %1$s was not renamed: %2$s already exists. Compare the two, keep one, then run wp seoproanalytics migrate-name again.', 'seoproanalytics'),
            /* translators: %s: a connection, such as bing */
            'secret_unreadable' => __('A stored credential of the %s connection could not be read, so it is kept as it was. If the connection fails, connect it again in Settings → Connections.', 'seoproanalytics'),
            /* translators: %s: a connection, such as bing */
            'no_sodium'         => __('A stored credential of the %s connection could not be re-encrypted: PHP\'s sodium functions are missing. It is kept as it was.', 'seoproanalytics'),
            /* translators: %s: a shared report's ID */
            'share_kept'        => __('Shared report %s: its saved link or password copy could not be re-encrypted, so it is kept as it was and cannot be copied. Renew the link to copy it again.', 'seoproanalytics'),
            /* translators: 1: meta key, 2: database table */
            'meta_exists'       => __('Meta key %1$s in %2$s was not renamed: the new key is already in use there.', 'seoproanalytics'),
            /* translators: 1: old constant, 2: new constant */
            'constant'          => __('wp-config.php defines %1$s, which SEO Pro Analytics no longer reads: rename it to %2$s.', 'seoproanalytics'),
            /* translators: 1: old folder, 2: new folder */
            'folder'            => __('Collector folder %1$s was not moved to %2$s. Hits in it that were not processed yet are not counted; move its files by hand.', 'seoproanalytics'),
        );
        $out = array();
        foreach ($items as $item) {
            if (!is_array($item) || !isset($item['code'], $texts[$item['code']])) {
                continue;
            }
            $text = vsprintf($texts[$item['code']], isset($item['args']) ? (array) $item['args'] : array());
            if (is_multisite()) {
                /* translators: 1: site ID, 2: a problem */
                $text = sprintf(__('Site %1$d: %2$s', 'seoproanalytics'), isset($item['site']) ? (int) $item['site'] : 0, $text);
            }
            $out[] = $text;
        }
        return array_values(array_unique($out));
    }

    /**
     * The admin notice: what stops the move, or what the move could not
     * do, for people who can manage options.
     */
    public static function notice() {
        if (!current_user_can('manage_options')) {
            return;
        }
        $pending = self::pending();
        // Only an autoloaded report (finish()): no query for the usual none.
        $loaded  = wp_load_alloptions();
        $report  = isset($loaded[self::REPORT]) ? get_option(self::REPORT) : false;
        $items   = is_array($report) && !empty($report['problems']) ? (array) $report['problems'] : array();
        if ($pending) {
            $items = array_merge(self::blockers(), $items);
        }
        $problems = self::messages($items);
        if ($pending && !$problems) {
            $problems[] = __('It is moved on the next admin page that is not an AJAX request, or with wp seoproanalytics migrate-name.', 'seoproanalytics');
        }
        if (!$problems) {
            return;
        }
        echo '<div class="notice notice-' . ($pending ? 'error' : 'warning') . '"><p><strong>';
        echo esc_html($pending ? __('SEO Pro Analytics is off on this site until the data stored under its old name, SEO Pro Stats, is moved.', 'seoproanalytics') : __('SEO Pro Analytics moved the data stored under its old name, SEO Pro Stats, with these notes:', 'seoproanalytics'));
        echo '</strong></p><ul>';
        foreach ($problems as $problem) {
            echo '<li>' . esc_html($problem) . '</li>';
        }
        echo '</ul>';
        if (!$pending) {
            $url = wp_nonce_url(admin_url('admin-post.php?action=' . self::DISMISS), self::DISMISS);
            echo '<p><a href="' . esc_url($url) . '">' . esc_html__('Dismiss', 'seoproanalytics') . '</a></p>';
        }
        echo '</div>';
    }

    /**
     * Dismiss the move's report.
     */
    public static function dismiss() {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You cannot change this.', 'seoproanalytics'), '', array('response' => 403));
        }
        check_admin_referer(self::DISMISS);
        delete_option(self::REPORT);
        wp_safe_redirect(wp_get_referer() ? wp_get_referer() : admin_url());
        exit;
    }

    /**
     * `wp seoproanalytics migrate-name`.
     *
     * @param array<string,string> $assoc Options.
     */
    public static function cli(array $assoc) {
        $dry    = !empty($assoc['dry-run']);
        $format = isset($assoc['format']) && in_array($assoc['format'], array('table', 'json', 'csv', 'yaml'), true) ? $assoc['format'] : 'table';
        $result = self::run_all($dry);
        // An admin page or a cron run is moving a site's data: wait for it,
        // so what follows (such as scripts/move-install.sh's row counts)
        // sees the move done, then run again for what is left.
        for ($waited = 0; !$dry && self::$busy && $waited < self::CLI_WAIT; $waited += 3) {
            sleep(3);
            self::$problems = array_values(array_filter(self::$problems, static function ($item) {
                return $item['code'] !== 'locked';
            }));
            $result = self::run_all(false, true);
        }
        $rows = array_values(array_filter($result['rows'], static function ($row) {
            return $row['count'] > 0;
        }));
        if ($rows) {
            WP_CLI\Utils\format_items($format, $rows, array('site', 'what', 'count', 'status'));
        }
        foreach ($result['problems'] as $problem) {
            WP_CLI::warning($problem);
        }
        if ($result['blockers']) {
            WP_CLI::error(implode("\n", $result['blockers']));
        }
        if (!$rows) {
            WP_CLI::success(__('Nothing is stored under the old name.', 'seoproanalytics'));
            return;
        }
        WP_CLI::success($dry ? __('Dry run: nothing was changed.', 'seoproanalytics') : __('Moved. Run it again to check: it then finds nothing.', 'seoproanalytics'));
    }
}

/**
 * `wp seoproanalytics` while the site's data is still under the old name:
 * only the move.
 */
final class SEOProAnalytics_Rename_CLI {

    /**
     * Move what the plugin stored under its old name, SEO Pro Stats, to
     * its name now: tables, options, meta, scheduled jobs, capabilities,
     * the collector folder, A/B test blocks in posts and the encrypted
     * connection credentials. Every site of a network. Safe to run again.
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
}

<?php
/**
 * Owners: who is responsible for the SEO of a page, a search target, a
 * Plan item or an experiment. Responsibility only: a page's author and
 * last editor stay facts of their own.
 *
 * People who can own have a role chosen in Settings → Data → Owners
 * (owner_roles; only roles that can edit posts, ROLES by default), then
 * filter seoproanalytics_owner_roles. Without an owner set, each place has a
 * default (owner_default_{kind}): a page's is its post's author when that
 * person can own; a target's, its page's; a Plan item's, its page's; an
 * experiment's, its (first) page's, else whoever recorded it. Each place
 * may instead give everything to one person, or to nobody. Defaults are
 * worked out when read; only owners set by a person or agent are stored
 * (`owners` table), and "nobody" set on purpose is stored too (user 0), so
 * it overrides the default.
 *
 * Administrators (manage_options) set anyone. A person who can own may
 * take an object nobody else was given, and give back one given to them.
 *
 * Names are shown to people who can edit posts (and WP-CLI), never in
 * shared reports. Reads use the table's primary key (kind, ref) or its
 * user_kind key; page authors come from page_facts (post_id key) and the
 * posts' primary key or post_author key. Admin, REST and WP-CLI requests
 * only. Design: docs/seo-loop.md → Owners.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 * Additional terms (GPL-3.0 section 7(b)): ATTRIBUTION.txt
 * Provenance: wpas-c8662019b99fb07b. Copies, even of part, keep these lines.
 *
 * @package SEOProAnalytics
 * @since 1.13.0
 */

if (!defined('ABSPATH')) {
    exit;
}

final class SEOProAnalytics_Owners {

    /** What can have an owner: code (the kind column) => name. Codes never change meaning. */
    const KINDS = array(
        1 => 'page',
        2 => 'target',
        3 => 'item',
        4 => 'experiment',
    );

    /** Roles whose people can own until Settings → Data → Owners says otherwise. */
    const ROLES = array('administrator', 'editor', 'shop_manager', 'author');

    /** The setting with the roles that can own; its card holds the default owners too. */
    const SETTING = 'owner_roles';

    /** Default owner settings: owner_default_{kind}. */
    const DEFAULT_SETTING = 'owner_default_';

    /** Defaults that come from the object: a page's author, an object's page, an experiment's recorder. */
    const AUTHOR   = 'author';
    const PAGE     = 'page';
    const RECORDER = 'recorder';

    /** Where an owner comes from: set by someone, the default, or none. */
    const SOURCES = array('assigned', 'default', 'none');

    /** Values the owner filter takes besides a user id. */
    const ME   = 'me';
    const NONE = 'none';

    /** What assign takes besides a user id: me, none (nobody, on purpose) or default (forget the owner set). */
    const DEFAULT = 'default';

    /** Objects one assign changes at most. */
    const MAX_IDS = 200;

    /** People listed at most. */
    const MAX_PEOPLE = 500;

    /** Ids per read. */
    const CHUNK = 500;

    /** Changed at every write (autoload off): reports whose cache depends on owners key on it. */
    const OPTION = 'seoproanalytics_owners';

    /** @var bool Whether owners are left out of this request's answers (shared reports). */
    private static $hidden = false;

    /** @var array<int,string>|null The people who can own: id => name, for this request. */
    private static $people = null;

    // ------------------------------------------------------------------
    // Who.

    /**
     * Leave owners out of this request's answers: a shared report never
     * shows them, whoever opens it.
     */
    public static function hide() {
        self::$hidden = true;
    }

    /**
     * Whether this request's answers show owners: to people who can edit
     * posts, and WP-CLI; never in a shared report.
     *
     * @return bool
     */
    public static function shown() {
        return !self::$hidden && ((defined('WP_CLI') && WP_CLI) || current_user_can('edit_posts'));
    }

    /**
     * The roles whose people can own: those chosen in Settings → Data →
     * Owners that can edit posts, then the filter.
     *
     * @return string[]
     */
    public static function roles() {
        $chosen = SEOProAnalytics_Settings::get(self::SETTING);
        $chosen = array_map('strval', is_array($chosen) ? $chosen : self::ROLES);
        $roles  = array_values(array_intersect($chosen, array_map('strval', array_keys(self::role_options()))));
        /**
         * Filters the roles whose people can own SEO work in SEO Pro Analytics.
         *
         * @param string[] $roles The roles chosen in Settings → Data → Owners that can edit posts
         *                        (by default administrator, editor, shop_manager and author).
         */
        $roles = apply_filters('seoproanalytics_owner_roles', $roles);
        return is_array($roles) ? array_values(array_filter(array_map('strval', $roles))) : self::ROLES;
    }

    // ------------------------------------------------------------------
    // Settings.

    /**
     * Settings → Data → Owners: the roles that can own, and each place's
     * default owner. Per site: roles and people differ between sites.
     *
     * @return array<string,array>
     */
    public static function settings() {
        $place = __('Used when nobody chose an owner. A person chosen who can no longer own counts as nobody.', 'seoproanalytics');
        return array(
            self::SETTING                           => array(
                'type'        => 'multi',
                'network'     => false,
                'default'     => self::ROLES,
                'tab'         => 'data',
                // The roles are drawn at the top of the panel (panel()), above each place's default.
                'panel'       => true,
                'options'     => array(__CLASS__, 'role_options'),
                'label'       => __('Owners', 'seoproanalytics'),
                'description' => __('Who can own the SEO of pages, search targets, Plan items and experiments, and who owns them when nobody chose. Content, Plan, Targets and Experiments show each owner to people who can edit posts.', 'seoproanalytics'),
            ),
            self::DEFAULT_SETTING . 'page'       => array(
                'type'        => 'select',
                'network'     => false,
                'default'     => self::AUTHOR,
                'parent'      => self::SETTING,
                'options'     => array(__CLASS__, 'page_options'),
                'label'       => __('Pages', 'seoproanalytics'),
                'description' => __('The post\'s author, when they can own; pages that are not posts have nobody.', 'seoproanalytics') . ' ' . $place,
            ),
            self::DEFAULT_SETTING . 'item'       => array(
                'type'        => 'select',
                'network'     => false,
                'default'     => self::PAGE,
                'parent'      => self::SETTING,
                'options'     => array(__CLASS__, 'item_options'),
                'label'       => __('Plan items', 'seoproanalytics'),
                'description' => $place,
            ),
            self::DEFAULT_SETTING . 'target'     => array(
                'type'        => 'select',
                'network'     => false,
                'default'     => self::PAGE,
                'parent'      => self::SETTING,
                'options'     => array(__CLASS__, 'target_options'),
                'label'       => __('Search targets', 'seoproanalytics'),
                'description' => $place,
            ),
            self::DEFAULT_SETTING . 'experiment' => array(
                'type'        => 'select',
                'network'     => false,
                'default'     => self::PAGE,
                'parent'      => self::SETTING,
                'options'     => array(__CLASS__, 'experiment_options'),
                'label'       => __('Experiments', 'seoproanalytics'),
                'description' => $place,
            ),
        );
    }

    /**
     * Roles that can own: those that can edit posts, so Subscriber,
     * Customer and other roles that only read are never offered.
     *
     * @return array<string,string> Role => name.
     */
    public static function role_options() {
        $out = array();
        foreach (wp_roles()->roles as $role => $data) {
            if (!empty($data['capabilities']['edit_posts'])) {
                $out[(string) $role] = translate_user_role($data['name']);
            }
        }
        return $out;
    }

    /** @return array<int|string,string> Choices for pages' default owner. */
    public static function page_options() {
        return self::place_options('page', array(self::AUTHOR => __('The post\'s author', 'seoproanalytics')));
    }

    /** @return array<int|string,string> Choices for Plan items' default owner. */
    public static function item_options() {
        return self::place_options('item', array(self::PAGE => __('The page\'s owner', 'seoproanalytics')));
    }

    /** @return array<int|string,string> Choices for search targets' default owner. */
    public static function target_options() {
        return self::place_options('target', array(self::PAGE => __('The page\'s owner', 'seoproanalytics')));
    }

    /** @return array<int|string,string> Choices for experiments' default owner. */
    public static function experiment_options() {
        return self::place_options('experiment', array(
            self::PAGE     => __('The first page\'s owner, else whoever recorded it', 'seoproanalytics'),
            self::RECORDER => __('Whoever recorded it', 'seoproanalytics'),
        ));
    }

    /**
     * A place's choices: what the object gives, nobody, then each person
     * who can own; a person chosen before who can no longer own stays
     * listed, so the screen shows what is saved.
     *
     * @param string               $kind  Kind name.
     * @param array<string,string> $first The object's own defaults.
     * @return array<int|string,string> Value => name (people by user id, which PHP keeps as int keys).
     */
    private static function place_options($kind, array $first) {
        $out = $first + array(self::NONE => __('Nobody', 'seoproanalytics'));
        foreach (self::people() as $id => $name) {
            $out[(string) $id] = $name;
        }
        $saved = self::rule($kind);
        if (ctype_digit($saved) && !isset($out[$saved])) {
            $user = get_userdata((int) $saved);
            /* translators: %s: person's name */
            $out[$saved] = sprintf(__('%s (can no longer own: nobody)', 'seoproanalytics'), $user ? $user->display_name : '#' . $saved);
        }
        return $out;
    }

    /**
     * Register the Owners card's panel (admin requests only).
     */
    public static function admin_init() {
        add_action('seoproanalytics_setting_panel', array(__CLASS__, 'panel'), 10, 2);
    }

    /**
     * Draw the roles at the top of the Owners card's panel: the card's own
     * value, so it has no switch.
     *
     * @param string $key   Setting key whose panel is drawn.
     * @param array  $field Its schema entry.
     */
    public static function panel($key, $field = array()) {
        if ($key !== self::SETTING || !is_array($field) || !class_exists('SEOProAnalytics_Settings_Manager')) {
            return;
        }
        // The card already shows the description (and owns its id).
        SEOProAnalytics_Settings_Manager::render_field($key, array('label' => __('Roles that can own', 'seoproanalytics'), 'description' => '') + $field);
    }

    /**
     * After settings change: a change to who can own or to a default
     * changes owners, so reports whose cache keys on version() refresh.
     *
     * @param array $old Settings before.
     * @param array $new Settings after.
     */
    public static function settings_changed($old, $new) {
        foreach (array_keys(self::settings()) as $key) {
            $before = isset($old[$key]) ? $old[$key] : null;
            $after  = isset($new[$key]) ? $new[$key] : null;
            // The roles are a list: the same roles in another order are no change.
            if (is_array($before) && is_array($after)) {
                sort($before);
                sort($after);
            }
            if ($before != $after) { // phpcs:ignore Universal.Operators.StrictComparisons -- lists may differ in type, not in value.
                update_option(SEOProAnalytics_Schema::option(self::OPTION), self::version() + 1, false);
                return;
            }
        }
    }

    /**
     * A place's default owner setting as saved: author, page, recorder,
     * none, or a user id; '' when unknown.
     *
     * @param string $kind Kind name.
     * @return string
     */
    private static function rule($kind) {
        $value = SEOProAnalytics_Settings::get(self::DEFAULT_SETTING . $kind);
        return is_scalar($value) ? (string) $value : '';
    }

    /**
     * The default owner of everything in a place when its setting names a
     * person (nobody when they can no longer own) or nobody; null when the
     * default comes from each object (its author, page or recorder).
     *
     * @param string $kind Kind name.
     * @return array{id:int|null,source:string}|null
     */
    public static function fixed($kind) {
        $rule = self::rule($kind);
        if ($rule === self::NONE) {
            return self::nobody();
        }
        if (ctype_digit($rule)) {
            return self::can_own((int) $rule) ? self::owner((int) $rule, 'default') : self::nobody();
        }
        return null;
    }

    /**
     * Whether a place's default is whoever recorded the object (experiments).
     *
     * @param string $kind Kind name.
     * @return bool
     */
    public static function by_recorder($kind) {
        return self::rule($kind) === self::RECORDER;
    }

    /**
     * Default owners of objects on pages (targets, Plan items): their
     * page's owner, or the person or nobody the place's setting names.
     *
     * @param string               $kind  target or item.
     * @param array<int,int>       $paths Ref => path id (0: no page).
     * @return array<int,array{id:int|null,source:string}> Ref => owner.
     */
    public static function on_pages($kind, array $paths) {
        $fixed = self::fixed($kind);
        $pages = $fixed === null ? self::pages(array_values($paths)) : array();
        $out   = array();
        foreach ($paths as $ref => $path_id) {
            if ($fixed !== null) {
                $out[$ref] = $fixed;
            } else {
                $out[$ref] = isset($pages[(int) $path_id]) ? $pages[(int) $path_id] : self::nobody();
            }
        }
        return $out;
    }

    /**
     * The people who can own on this site: id => display name, by name.
     *
     * @return array<int,string>
     */
    public static function people() {
        if (self::$people !== null) {
            return self::$people;
        }
        self::$people = array();
        $roles        = self::roles();
        if (!$roles) {
            return self::$people;
        }
        $users = get_users(array(
            'role__in' => $roles,
            'fields'   => array('ID', 'display_name'),
            'orderby'  => 'display_name',
            'order'    => 'ASC',
            'number'   => self::MAX_PEOPLE,
        ));
        foreach ($users as $user) {
            self::$people[(int) $user->ID] = (string) $user->display_name;
        }
        return self::$people;
    }

    /**
     * Whether the person asking gives anyone work: administrators
     * (manage_options), and WP-CLI, which has the server's trust.
     *
     * @return bool
     */
    public static function admin() {
        return (defined('WP_CLI') && WP_CLI) || current_user_can('manage_options');
    }

    /**
     * Whether a person can own.
     *
     * @param int $user_id User.
     * @return bool
     */
    public static function can_own($user_id) {
        return $user_id > 0 && isset(self::people()[(int) $user_id]);
    }

    /**
     * The version reports that depend on owners key their cache on.
     *
     * @return int
     */
    public static function version() {
        return (int) get_option(SEOProAnalytics_Schema::option(self::OPTION), 0);
    }

    // ------------------------------------------------------------------
    // Reading.

    /**
     * Owners set for objects of a kind, by primary key (kind, ref).
     *
     * @param int   $kind Kind code.
     * @param int[] $refs Refs.
     * @return array<int,int> Ref => user id (0: nobody, on purpose).
     */
    public static function stored($kind, array $refs) {
        global $wpdb;
        $refs = array_values(array_unique(array_map('intval', $refs)));
        $out  = array();
        if (!$refs || !SEOProAnalytics_Schema::is_current()) {
            return $out;
        }
        foreach (array_chunk($refs, self::CHUNK) as $chunk) {
            $holders = implode(', ', array_fill(0, count($chunk), '%d'));
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- our own table, by its primary key; $holders holds only placeholders.
            $rows = (array) $wpdb->get_results($wpdb->prepare("SELECT ref, user_id FROM %i WHERE kind = %d AND ref IN ($holders)", array_merge(array(SEOProAnalytics_Schema::table('owners'), (int) $kind), $chunk)), ARRAY_A);
            foreach ($rows as $row) {
                $out[(int) $row['ref']] = (int) $row['user_id'];
            }
        }
        return $out;
    }

    /**
     * The authors of pages that are posts, when they can own: through
     * page_facts (key path_id) and the posts' primary key.
     *
     * @param int[] $path_ids Path ids.
     * @return array<int,int> Path id => author user id.
     */
    public static function authors(array $path_ids) {
        $posts = self::posts($path_ids);
        if (!$posts) {
            return array();
        }
        _prime_post_caches(array_values(array_unique($posts)), false, false);
        $out = array();
        foreach ($posts as $path_id => $post_id) {
            $post = get_post($post_id);
            if ($post && self::can_own((int) $post->post_author)) {
                $out[$path_id] = (int) $post->post_author;
            }
        }
        return $out;
    }

    /**
     * The posts of pages that are posts, through page_facts (primary key).
     *
     * @param int[] $path_ids Path ids.
     * @return array<int,int> Path id => post id.
     */
    private static function posts(array $path_ids) {
        global $wpdb;
        $path_ids = array_values(array_unique(array_filter(array_map('intval', $path_ids))));
        $posts    = array();
        if (!$path_ids || !SEOProAnalytics_Schema::is_current()) {
            return $posts;
        }
        foreach (array_chunk($path_ids, self::CHUNK) as $chunk) {
            $holders = implode(', ', array_fill(0, count($chunk), '%d'));
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- our own table, by its primary key; $holders holds only placeholders.
            $rows = (array) $wpdb->get_results($wpdb->prepare("SELECT path_id, post_id FROM %i WHERE path_id IN ($holders) AND post_id > 0", array_merge(array(SEOProAnalytics_Schema::table('page_facts')), $chunk)), ARRAY_A);
            foreach ($rows as $row) {
                $posts[(int) $row['path_id']] = (int) $row['post_id'];
            }
        }
        return $posts;
    }

    /**
     * Pages' owners: the one set, else the default (Settings → Data →
     * Owners): the post's author when they can own, or the person or
     * nobody chosen for every page.
     *
     * @param int[] $path_ids Path ids.
     * @return array<int,array{id:int|null,source:string}> Path id => owner.
     */
    public static function pages(array $path_ids) {
        $path_ids = array_values(array_unique(array_filter(array_map('intval', $path_ids))));
        $set      = self::stored(1, $path_ids);
        $fixed    = self::fixed('page');
        // Pages set to someone who can no longer own fall back to the default too.
        $unset   = array_values(array_filter($path_ids, static function ($path_id) use ($set) {
            return !isset($set[$path_id]) || ($set[$path_id] > 0 && !self::can_own($set[$path_id]));
        }));
        $authors = $fixed === null ? self::authors($unset) : array();
        $out      = array();
        foreach ($path_ids as $path_id) {
            if ($fixed !== null) {
                $fallback = $fixed;
            } else {
                $fallback = isset($authors[$path_id]) ? self::owner($authors[$path_id], 'default') : self::nobody();
            }
            $out[$path_id] = self::pick(isset($set[$path_id]) ? $set[$path_id] : null, $fallback);
        }
        return $out;
    }

    /**
     * Objects' owners: the one set for each, else its fallback.
     *
     * @param string                                         $kind      Kind name (KINDS).
     * @param array<int,array{id:int|null,source:string}>    $fallbacks Ref => the owner when none is set.
     * @return array<int,array{id:int|null,source:string}> Ref => owner.
     */
    public static function of($kind, array $fallbacks) {
        $set = self::stored(self::code($kind), array_keys($fallbacks));
        $out = array();
        foreach ($fallbacks as $ref => $fallback) {
            $out[$ref] = self::pick(isset($set[$ref]) ? $set[$ref] : null, $fallback);
        }
        return $out;
    }

    /**
     * The owner set, else the fallback. A person set who can no longer own
     * counts as none set.
     *
     * @param int|null                         $set      User id set (0: nobody), or null.
     * @param array{id:int|null,source:string} $fallback The default.
     * @return array{id:int|null,source:string}
     */
    public static function pick($set, array $fallback) {
        if ($set === null || ($set > 0 && !self::can_own($set))) {
            return $fallback;
        }
        return $set > 0 ? self::owner($set, 'assigned') : array('id' => null, 'source' => 'assigned');
    }

    /**
     * An owner.
     *
     * @param int    $user_id User.
     * @param string $source  assigned or default.
     * @return array{id:int|null,source:string}
     */
    public static function owner($user_id, $source) {
        return $user_id > 0 ? array('id' => (int) $user_id, 'source' => (string) $source) : self::nobody();
    }

    /**
     * No owner, by default.
     *
     * @return array{id:int|null,source:string}
     */
    public static function nobody() {
        return array('id' => null, 'source' => 'none');
    }

    /**
     * An owner as answers give it: id, name and source; null when owners
     * are not shown in this request.
     *
     * @param array{id:int|null,source:string} $owner Owner.
     * @return array{id:int|null,name:string|null,source:string}|null
     */
    public static function out(array $owner) {
        if (!self::shown()) {
            return null;
        }
        $people = self::people();
        $id     = $owner['id'];
        return array(
            'id'     => $id,
            'name'   => $id !== null && isset($people[$id]) ? $people[$id] : null,
            'source' => $owner['source'],
        );
    }

    /**
     * A Plan item's ref: its key's 8 bytes as a signed 64-bit number.
     *
     * @param string $key Item key (16 hex characters).
     * @return int
     */
    public static function item_ref($key) {
        $bytes = hex2bin(str_pad(substr((string) $key, 0, 16), 16, '0', STR_PAD_LEFT));
        $ref   = $bytes !== false ? unpack('J', $bytes) : false;
        return is_array($ref) ? (int) $ref[1] : 0;
    }

    // ------------------------------------------------------------------
    // Filtering.

    /**
     * The owner a filter asks for: null for any (no filter), 0 for none,
     * else a user id. me is the person asking.
     *
     * @param mixed $raw '' (any), me, none or a user id.
     * @return int|null|WP_Error
     */
    public static function filter($raw) {
        $raw = is_scalar($raw) ? strtolower(trim((string) $raw)) : '';
        if ($raw === '') {
            return null;
        }
        if (!self::shown()) {
            return self::error('seoproanalytics_owner_hidden', __('Owners are shown to people who can edit posts.', 'seoproanalytics'), 403);
        }
        if ($raw === self::NONE) {
            return 0;
        }
        if ($raw === self::ME) {
            $id = get_current_user_id();
        } else {
            $id = ctype_digit($raw) ? (int) $raw : -1;
        }
        if ($id <= 0) {
            return self::error('seoproanalytics_owner', __('The owner is me, none, or the id of a person who can own.', 'seoproanalytics'));
        }
        return $id;
    }

    /**
     * Whether an owner is the one a filter asks for.
     *
     * @param array{id:int|null,source:string} $owner  Owner.
     * @param int|null                         $filter From filter().
     * @return bool
     */
    public static function matches(array $owner, $filter) {
        if ($filter === null) {
            return true;
        }
        return $filter === 0 ? $owner['id'] === null : $owner['id'] === $filter;
    }

    /**
     * The pages an owner filter selects, for reports that page through
     * pages: only (the pages that match) or except (every page matches
     * but these). With pages defaulting to their authors: a person's are
     * those set to them and those they wrote that nobody else was given;
     * none is every page but those someone owns. Reads owners by its keys
     * and the posts by post_author.
     *
     * @param int $filter From filter(): 0 or a user id.
     * @return array{only:int[]|null,except:int[]}
     */
    public static function page_scope($filter) {
        global $wpdb;
        $filter = (int) $filter;
        if (!SEOProAnalytics_Schema::is_current()) {
            return $filter ? array('only' => array(), 'except' => array()) : array('only' => null, 'except' => array());
        }
        $set   = self::assigned_pages();
        $fixed = self::fixed('page');
        if ($fixed !== null) {
            return self::fixed_scope($set, $fixed['id'] === null ? 0 : (int) $fixed['id'], $filter);
        }
        $authors = $filter ? array($filter) : array_keys(self::people());
        $written = array();
        if ($authors && (!$filter || self::can_own($filter))) {
            $holders = implode(', ', array_fill(0, count($authors), '%d'));
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- posts by post_author; $holders holds only placeholders.
            $written = array_map('intval', (array) $wpdb->get_col($wpdb->prepare("SELECT f.path_id FROM %i p FORCE INDEX (`post_author`) JOIN %i f ON f.post_id = p.ID WHERE p.post_author IN ($holders)", array_merge(array($wpdb->posts, SEOProAnalytics_Schema::table('page_facts')), $authors))));
        }
        // $written holds every page the person (or, for none, anyone who
        // can own) wrote, so a page outside it has no default owner here.
        $written = array_fill_keys($written, true);
        $ids     = array();
        foreach (array_keys($written + $set) as $path_id) {
            $set_to = isset($set[$path_id]) ? $set[$path_id] : null;
            if ($filter) {
                $match = $set_to !== null ? $set_to === $filter : isset($written[$path_id]);
            } else {
                $match = $set_to !== null ? $set_to > 0 : isset($written[$path_id]);
            }
            if ($match) {
                $ids[] = (int) $path_id;
            }
        }
        sort($ids);
        return $filter ? array('only' => $ids, 'except' => array()) : array('only' => null, 'except' => $ids);
    }

    /**
     * page_scope() when every page without an owner set goes to one person
     * or to nobody: when that is who the filter asks for, every page but
     * those set to someone else; otherwise only those set to them.
     *
     * @param array<int,int> $set     From assigned_pages().
     * @param int            $default The default's user id, 0 for nobody.
     * @param int            $filter  0 or a user id.
     * @return array{only:int[]|null,except:int[]}
     */
    private static function fixed_scope(array $set, $default, $filter) {
        $everyone = $default === $filter;
        $ids      = array();
        foreach ($set as $path_id => $set_to) {
            if ($everyone ? $set_to !== $filter : $set_to === $filter) {
                $ids[] = (int) $path_id;
            }
        }
        sort($ids);
        return $everyone ? array('only' => null, 'except' => $ids) : array('only' => $ids, 'except' => array());
    }

    /**
     * Pages with an owner set that still counts (nobody, or a person who
     * can own), by the owners primary key's kind prefix.
     *
     * @return array<int,int> Path id => user id (0: nobody).
     */
    private static function assigned_pages() {
        global $wpdb;
        $set = array();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- our own table, by its primary key's kind prefix.
        foreach ((array) $wpdb->get_results($wpdb->prepare('SELECT ref, user_id FROM %i WHERE kind = 1', SEOProAnalytics_Schema::table('owners')), ARRAY_A) as $row) {
            $user_id = (int) $row['user_id'];
            if ($user_id === 0 || self::can_own($user_id)) {
                $set[(int) $row['ref']] = $user_id;
            }
        }
        return $set;
    }

    // ------------------------------------------------------------------
    // Setting.

    /**
     * Set the owner of objects of one kind.
     *
     * @param string   $kind page, target, item or experiment.
     * @param string[] $ids  Pages (paths or addresses on this site), target searches, item keys or experiment ids.
     * @param mixed    $user A user id, me, none (nobody, on purpose) or default (forget the owner set).
     * @return array<string,mixed>|WP_Error What was set, and what was skipped and why.
     */
    public static function assign($kind, array $ids, $user) {
        $kind = (string) $kind;
        if (!in_array($kind, self::KINDS, true)) {
            /* translators: %s: list of kinds */
            return self::error('seoproanalytics_owner_kind', sprintf(__('The kind is one of: %s.', 'seoproanalytics'), implode(', ', self::KINDS)));
        }
        $ids = array_values(array_unique(array_filter(array_map(static function ($id) {
            return is_scalar($id) ? trim((string) $id) : '';
        }, $ids), static function ($id) {
            return $id !== '';
        })));
        if (!$ids || count($ids) > self::MAX_IDS) {
            /* translators: %d: most objects */
            return self::error('seoproanalytics_owner_ids', sprintf(__('Give 1 to %d objects.', 'seoproanalytics'), self::MAX_IDS));
        }
        $to = self::assignee($user);
        if (is_wp_error($to)) {
            return $to;
        }
        if (!SEOProAnalytics_Schema::maybe_upgrade()) {
            return self::error('seoproanalytics_owner_failed', __('The owner could not be saved.', 'seoproanalytics'), 500);
        }
        $found   = self::refs($kind, $ids);
        $set     = self::stored(self::code($kind), array_values($found['refs']));
        $done    = array();
        $skipped = $found['skipped'];
        foreach ($found['refs'] as $id => $ref) {
            $why = self::cannot($to, isset($set[$ref]) ? $set[$ref] : null);
            if ($why !== '') {
                $skipped[] = array('id' => (string) $id, 'reason' => $why);
                continue;
            }
            self::save(self::code($kind), $ref, $to);
            $done[] = (string) $id;
        }
        if ($done) {
            update_option(SEOProAnalytics_Schema::option(self::OPTION), self::version() + 1, false);
        }
        return array(
            'kind'    => $kind,
            'owner'   => $to === self::DEFAULT ? null : self::out(is_int($to) && $to > 0 ? self::owner($to, 'assigned') : array('id' => null, 'source' => 'assigned')),
            'default' => $to === self::DEFAULT,
            'set'     => $done,
            'skipped' => $skipped,
        );
    }

    /**
     * Who an assign gives objects to, if the person asking may.
     *
     * @param mixed $user A user id, me, none or default.
     * @return int|string|WP_Error A user id, 0 for nobody, or default.
     */
    private static function assignee($user) {
        $user  = is_scalar($user) ? strtolower(trim((string) $user)) : '';
        $me    = get_current_user_id();
        $admin = self::admin();
        // Someone who can neither give work nor own it may not set owners at all.
        if (!$admin && !self::can_own($me)) {
            return self::error('seoproanalytics_owner_denied', __('Only administrators and people who can own (their roles are chosen in Settings → Data → Owners) set owners.', 'seoproanalytics'), 403);
        }
        if ($user === self::DEFAULT) {
            return self::DEFAULT;
        }
        if ($user === self::NONE) {
            $to = 0;
        } elseif ($user === self::ME) {
            // Without a signed-in person (WP-CLI without --user), me is no one.
            $to = $me > 0 ? $me : -1;
        } else {
            $to = ctype_digit($user) ? (int) $user : -1;
        }
        if ($to < 0 || ($to > 0 && !self::can_own($to))) {
            return self::error('seoproanalytics_owner', __('The owner is me, none, default, or the id of a person who can own (with a role chosen in Settings → Data → Owners).', 'seoproanalytics'));
        }
        if (!$admin && $to !== $me) {
            return self::error('seoproanalytics_owner_denied', __('Only administrators give work to others. People who can own may take work for themselves (me) or give it back (default).', 'seoproanalytics'), 403);
        }
        return $to;
    }

    /**
     * Why the person asking may not set this owner on an object; '' when
     * they may. Administrators may always; others take objects nobody
     * else was given, and give back their own.
     *
     * @param int|string $to  From assignee().
     * @param int|null   $set The user id set now, or null.
     * @return string
     */
    private static function cannot($to, $set) {
        if (self::admin()) {
            return '';
        }
        $me = get_current_user_id();
        if ($to === self::DEFAULT) {
            return $set === $me ? '' : __('Only work given to you can be given back.', 'seoproanalytics');
        }
        return $set === null || $set === 0 || $set === $me || !self::can_own($set) ? '' : __('Someone else was given it: ask an administrator.', 'seoproanalytics');
    }

    /**
     * The refs of the objects named, and those skipped and why.
     *
     * @param string   $kind Kind name.
     * @param string[] $ids  As assign() takes them.
     * @return array{refs:array<array-key,int>,skipped:array<int,array{id:string,reason:string}>} Refs by id (PHP keeps numeric ids as int keys).
     */
    private static function refs($kind, array $ids) {
        if ($kind === 'item') {
            return self::item_refs($ids);
        }
        if ($kind === 'experiment') {
            return self::experiment_refs($ids);
        }
        require_once __DIR__ . '/class-seoproanalytics-dict.php';
        $texts = array();
        foreach ($ids as $id) {
            $texts[$id] = $kind === 'page' ? self::page_path($id) : self::target_query($id);
        }
        $dict  = $kind === 'page' ? SEOProAnalytics_Schema::DICT_PATH : SEOProAnalytics_Schema::DICT_QUERY;
        $named = array_values(array_filter($texts, static function ($text) {
            return $text !== '';
        }));
        $known = array_flip(array_map('strval', SEOProAnalytics_Query::texts(SEOProAnalytics_Dict::find($dict, $named))));
        $targets = $kind === 'target' ? self::target_ids() : array();
        $out   = array('refs' => array(), 'skipped' => array());
        foreach ($texts as $id => $text) {
            $ref = $text !== '' && isset($known[$text]) ? (int) $known[$text] : 0;
            if ($ref && ($kind === 'page' || isset($targets[$ref]))) {
                $out['refs'][(string) $id] = $ref;
            } else {
                $out['skipped'][] = array('id' => (string) $id, 'reason' => $kind === 'page' ? __('Not a page the statistics know.', 'seoproanalytics') : __('Not a search target.', 'seoproanalytics'));
            }
        }
        return $out;
    }

    /**
     * A page's path from a path or an address on this site; '' for none.
     *
     * @param string $id Path or address.
     * @return string
     */
    private static function page_path($id) {
        require_once __DIR__ . '/class-seoproanalytics-targets.php';
        $path = SEOProAnalytics_Targets::page_path($id);
        return is_string($path) ? $path : '';
    }

    /**
     * A target's query as stored (lower case); '' for none.
     *
     * @param string $id Search.
     * @return string
     */
    private static function target_query($id) {
        require_once __DIR__ . '/class-seoproanalytics-targets.php';
        return SEOProAnalytics_Targets::query_text($id);
    }

    /**
     * The query ids of every target, by the targets' primary key.
     *
     * @return array<int,true>
     */
    private static function target_ids() {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- our own small table, by its primary key.
        return array_fill_keys(array_map('intval', (array) $wpdb->get_col($wpdb->prepare('SELECT query_id FROM %i', SEOProAnalytics_Schema::table('targets')))), true);
    }

    /**
     * Plan items' refs from their keys.
     *
     * @param string[] $ids Item keys.
     * @return array{refs:array<array-key,int>,skipped:array<int,array{id:string,reason:string}>} Refs by id (PHP keeps numeric ids as int keys).
     */
    private static function item_refs(array $ids) {
        $out = array('refs' => array(), 'skipped' => array());
        foreach ($ids as $id) {
            $key = strtolower($id);
            if (preg_match('/^[0-9a-f]{16}$/', $key)) {
                $out['refs'][$key] = self::item_ref($key);
            } else {
                $out['skipped'][] = array('id' => $id, 'reason' => __('An item\'s key is 16 hexadecimal characters, as the plan gives it.', 'seoproanalytics'));
            }
        }
        return $out;
    }

    /**
     * Experiments' refs from their ids, those that exist (primary key).
     *
     * @param string[] $ids Experiment ids.
     * @return array{refs:array<array-key,int>,skipped:array<int,array{id:string,reason:string}>} Refs by id (PHP keeps numeric ids as int keys).
     */
    private static function experiment_refs(array $ids) {
        global $wpdb;
        $numbers = array_values(array_filter(array_map('intval', array_filter($ids, 'ctype_digit'))));
        $exists  = array();
        if ($numbers) {
            $holders = implode(', ', array_fill(0, count($numbers), '%d'));
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- our own table, by its primary key; $holders holds only placeholders.
            $exists = array_fill_keys(array_map('intval', (array) $wpdb->get_col($wpdb->prepare("SELECT id FROM %i WHERE id IN ($holders)", array_merge(array(SEOProAnalytics_Schema::table('experiments')), $numbers)))), true);
        }
        $out = array('refs' => array(), 'skipped' => array());
        foreach ($ids as $id) {
            if (ctype_digit($id) && isset($exists[(int) $id])) {
                $out['refs'][$id] = (int) $id;
            } else {
                $out['skipped'][] = array('id' => $id, 'reason' => __('There is no such experiment.', 'seoproanalytics'));
            }
        }
        return $out;
    }

    /**
     * Store an owner, or forget it (default).
     *
     * @param int        $kind Kind code.
     * @param int        $ref  Ref.
     * @param int|string $to   A user id, 0 for nobody, or default.
     */
    private static function save($kind, $ref, $to) {
        global $wpdb;
        $table = SEOProAnalytics_Schema::table('owners');
        // phpcs:disable WordPress.DB.DirectDatabaseQuery -- writing our own table, by its primary key.
        if ($to === self::DEFAULT) {
            $wpdb->delete($table, array('kind' => (int) $kind, 'ref' => (int) $ref), array('%d', '%d'));
        } else {
            $wpdb->query($wpdb->prepare('INSERT INTO %i (kind, ref, user_id, assigned, assigned_by) VALUES (%d, %d, %d, %d, %d) ON DUPLICATE KEY UPDATE user_id = VALUES(user_id), assigned = VALUES(assigned), assigned_by = VALUES(assigned_by)', $table, (int) $kind, (int) $ref, (int) $to, time(), get_current_user_id()));
        }
        // phpcs:enable
    }

    /**
     * Give an object the owner set for another, if one was set (a done
     * Plan item's experiment).
     *
     * @param string $from    Kind name of the object with an owner.
     * @param int    $from_id Its ref.
     * @param string $to      Kind name of the object to give it to.
     * @param int    $to_id   Its ref.
     */
    public static function carry($from, $from_id, $to, $to_id) {
        $set = self::stored(self::code($from), array((int) $from_id));
        if (isset($set[(int) $from_id])) {
            self::save(self::code($to), (int) $to_id, $set[(int) $from_id]);
            update_option(SEOProAnalytics_Schema::option(self::OPTION), self::version() + 1, false);
        }
    }

    /**
     * Forget the owners of objects that are gone (an experiment deleted).
     *
     * @param string $kind Kind name.
     * @param int[]  $refs Refs.
     */
    public static function forget($kind, array $refs) {
        global $wpdb;
        if (!$refs || !SEOProAnalytics_Schema::is_current()) {
            return;
        }
        foreach ($refs as $ref) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- our own table, by its primary key.
            $wpdb->delete(SEOProAnalytics_Schema::table('owners'), array('kind' => self::code($kind), 'ref' => (int) $ref), array('%d', '%d'));
        }
    }

    // ------------------------------------------------------------------
    // Summary.

    /**
     * Each person who can own, with what they own: pages, open Plan items
     * (and their potential clicks per 28 days), open search targets, and
     * running and due experiments; then what nobody owns.
     *
     * @param array<string,mixed> $req    From SEOProAnalytics_Query::request(), the Plan's period.
     * @param string              $engine google or bing.
     * @param string              $goal   Goal id for the Plan's value.
     * @return array<string,mixed>|WP_Error
     */
    public static function summary(array $req, $engine = 'google', $goal = '') {
        if (!self::shown()) {
            return self::error('seoproanalytics_owner_hidden', __('Owners are shown to people who can edit posts.', 'seoproanalytics'), 403);
        }
        require_once __DIR__ . '/class-seoproanalytics-queue.php';
        require_once __DIR__ . '/class-seoproanalytics-targets.php';
        require_once __DIR__ . '/class-seoproanalytics-experiments.php';
        $blank = array('pages' => 0, 'items' => 0, 'clicks' => 0.0, 'targets' => 0, 'running' => 0, 'due' => 0);
        $by    = array(0 => $blank);
        foreach (array_keys(self::people()) as $id) {
            $by[$id] = $blank;
        }
        foreach (self::page_counts() as $id => $count) {
            $by[$id]['pages'] = $count;
        }
        $plan = SEOProAnalytics_Queue::owner_counts($req, $engine, $goal);
        if (is_wp_error($plan)) {
            return $plan;
        }
        foreach ($plan['owners'] as $id => $sums) {
            if (isset($by[$id])) {
                $by[$id]['items']  = $sums['items'];
                $by[$id]['clicks'] = round($sums['clicks'], 1);
            }
        }
        foreach (SEOProAnalytics_Targets::owner_counts() as $id => $count) {
            if (isset($by[$id])) {
                $by[$id]['targets'] = $count;
            }
        }
        foreach (SEOProAnalytics_Experiments::owner_counts() as $id => $counts) {
            if (isset($by[$id])) {
                $by[$id]['running'] = $counts['running'];
                $by[$id]['due']     = $counts['due'];
            }
        }
        $people = array();
        foreach (self::people() as $id => $name) {
            $people[] = array('id' => $id, 'name' => $name, 'me' => $id === get_current_user_id()) + $by[$id];
        }
        unset($by[0]['pages']);
        return array(
            'roles'      => self::roles(),
            'people'     => $people,
            'nobody'     => $by[0],
            'plan'       => array_diff_key($plan, array('owners' => true)),
            'can_assign' => self::admin(),
            'can_take'   => self::can_own(get_current_user_id()),
            'me'         => get_current_user_id(),
        );
    }

    /**
     * Pages per person: the pages that are posts their default gives them
     * (those they wrote, or every one when all pages go to one person),
     * moved to whoever was set where an owner was set. Reads posts by
     * post_author, page_facts by post_id and owners by its primary key's
     * kind prefix.
     *
     * @return array<int,int> User id => pages.
     */
    private static function page_counts() {
        global $wpdb;
        $people = array_keys(self::people());
        $out    = array();
        if (!$people || !SEOProAnalytics_Schema::is_current()) {
            return $out;
        }
        $set   = self::assigned_pages();
        $fixed = self::fixed('page');
        if ($fixed === null) {
            $holders = implode(', ', array_fill(0, count($people), '%d'));
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- posts by post_author and page_facts by post_id; $holders holds only placeholders.
            foreach ((array) $wpdb->get_results($wpdb->prepare("SELECT p.post_author AS u, COUNT(*) AS n FROM %i p FORCE INDEX (`post_author`) JOIN %i f ON f.post_id = p.ID WHERE p.post_author IN ($holders) GROUP BY p.post_author", array_merge(array($wpdb->posts, SEOProAnalytics_Schema::table('page_facts')), $people)), ARRAY_A) as $row) {
                $out[(int) $row['u']] = (int) $row['n'];
            }
            $defaults = self::authors(array_keys($set));
        } elseif ($fixed['id'] !== null) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- our own table, by its post_id key.
            $out[(int) $fixed['id']] = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i WHERE post_id > 0', SEOProAnalytics_Schema::table('page_facts')));
            $defaults = array_fill_keys(array_keys(self::posts(array_keys($set))), (int) $fixed['id']);
        } else {
            $defaults = array();
        }
        foreach ($set as $path_id => $user_id) {
            if (isset($defaults[$path_id], $out[$defaults[$path_id]])) {
                --$out[$defaults[$path_id]];
            }
            if ($user_id > 0) {
                $out[$user_id] = (isset($out[$user_id]) ? $out[$user_id] : 0) + 1;
            }
        }
        return $out;
    }

    // ------------------------------------------------------------------
    // Helpers.

    /**
     * A kind's code.
     *
     * @param string $kind Kind name.
     * @return int
     */
    private static function code($kind) {
        return (int) array_search((string) $kind, self::KINDS, true);
    }

    /**
     * An error for a request.
     *
     * @param string $code    Code.
     * @param string $message Message.
     * @param int    $status  HTTP status.
     * @return WP_Error
     */
    private static function error($code, $message, $status = 400) {
        return new WP_Error($code, $message, array('status' => (int) $status));
    }
}

<?php
/**
 * Backlinks triage (Search → Backlinks → Links and Lost links): the
 * owner's classes of each link (links.classes bits), and the filters,
 * search and sort of the links lists.
 *
 * Filtering, search and sorting run on the report's cached list (at most
 * SEOProAnalytics_Backlinks::MAX_ROWS rows), so they add no database work; the
 * totals stay those of the whole list and `total` is the filtered count.
 * classify() sets classes by the links' row ids (owner only) and tells the
 * report its links changed. Layout: docs/architecture.md → Backlinks.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 * Additional terms (GPL-3.0 section 7(b)): ATTRIBUTION.txt
 * Provenance: wpas-c8662019b99fb07b. Copies, even of part, keep these lines.
 *
 * @package SEOProAnalytics
 * @since 1.8.0
 */

if (!defined('ABSPATH')) {
    exit;
}

final class SEOProAnalytics_Backlinks_Triage {

    /**
     * The built-in classes of a link: name => bit, A to Z by English name,
     * then Ignore and Spam (packages/core BACKLINK_CLASSES). Bits never
     * change meaning; those from 2^32 need the bigint columns (schema v33)
     * and 64-bit PHP. 2^36 to 2^42 are kept for later built-in classes; the
     * site's own take 2^62 down to 2^43 (OWN_TOP, MAX_OWN). classes() gives
     * the ones in use: these less those turned off, plus the site's own.
     */
    const CLASSES = array(
        'brand_citation'  => 8589934592,
        'nap_citation'    => 17179869184,
        'cross_site'      => 1048576,
        'documentation'   => 67108864,
        'forum'           => 2097152,
        'general'         => 8192,
        'guest_post'      => 16,
        'guide'           => 34359738368,
        'home_page'       => 32,
        'integration'     => 134217728,
        'link_swap'       => 536870912,
        'listicle'        => 32768,
        'logo_linked'     => 4294967296,
        'monitor'         => 2,
        'needs_updating'  => 4,
        'niche_edit'      => 8388608,
        'organic_mention' => 2048,
        'paid_link'       => 268435456,
        'partner'         => 1024,
        'press_organic'   => 256,
        'press_release'   => 128,
        'private_blog'    => 16777216,
        'profile_page'    => 512,
        'blog'            => 16384,
        'quote'           => 131072,
        'redirect'        => 524288,
        'site_footer'     => 64,
        'social_media'    => 262144,
        'statistics'      => 65536,
        'topical'         => 4096,
        'url_linked'      => 1073741824,
        'url_unlinked'    => 2147483648,
        'video'           => 4194304,
        'wiki'            => 33554432,
        'ignore'          => 1,
        'spam'            => 8,
    );

    /** In the classes filter: links with no class. */
    const NONE = 'none';

    /** Lists the triage applies to. */
    const KINDS = array('links', 'lost');

    /** Lists only the classes filter applies to (their rows have classes, not rel, dates or metrics). */
    const CLASS_KINDS = array('domains', 'reported');

    /** Settings → Backlinks → Classes: the site's own classes, one "key | Name" a line. */
    const OWN_KEY = 'backlink_classes';

    /** Classes turned off, one key a line: not offered, shown, listed or valued; links keep them. */
    const OFF_KEY = 'backlink_classes_off';

    /** Classes hidden until ticked in Classes, after the line, one key a line. */
    const HIDDEN_KEY = 'backlink_classes_hidden';

    /** Hidden until ticked, until the owner changes it. */
    const HIDDEN_DEFAULT = array('ignore', 'spam');

    /** The site's own classes: at most this many, with the bits 2^OWN_TOP down. */
    const MAX_OWN = 20;
    const OWN_TOP = 62;

    /** Longest own class name and key. */
    const MAX_OWN_NAME = 60;
    const MAX_OWN_KEY  = 40;

    /**
     * Option: each own class's key => its bit's power of two, so a class
     * keeps its bit when renamed or listed in another order, and its links
     * keep it while it is turned off. Not a setting: never in exports.
     */
    const BITS_OPTION = 'seoproanalytics_backlink_class_bits';

    /**
     * Classes in use this request (registry()).
     *
     * @var array{classes:array<string,int>,labels:array<string,string>,own:array<string,string>,off:string[],hidden:string[]}|null
     */
    private static $registry = null;

    /**
     * The classes in use: the built-in ones less those turned off, then
     * the site's own (each with its bit), their names, and which are
     * hidden until ticked. Read from the stored option, not
     * SEOProAnalytics_Settings::get(), as the settings schema itself
     * lists the classes (SEOProAnalytics_Backlink_Lists::settings()); the
     * three settings are each site's own (network: false).
     *
     * @return array{classes:array<string,int>,labels:array<string,string>,own:array<string,string>,off:string[],hidden:string[]}
     */
    public static function registry() {
        if (self::$registry !== null) {
            return self::$registry;
        }
        $stored = get_option(SEOProAnalytics_Settings::OPTION, array());
        $stored = is_array($stored) ? $stored : array();
        $off    = self::keys_in(isset($stored[self::OFF_KEY]) ? $stored[self::OFF_KEY] : '');
        $hidden = isset($stored[self::HIDDEN_KEY]) ? self::keys_in($stored[self::HIDDEN_KEY]) : self::HIDDEN_DEFAULT;
        $own    = self::parse_own(isset($stored[self::OWN_KEY]) ? $stored[self::OWN_KEY] : '');
        $bits   = self::own_bits(array_keys($own));

        $classes = array();
        $labels  = array();
        foreach (self::built_in_labels() as $name => $label) {
            if (!in_array($name, $off, true)) {
                $classes[$name] = self::CLASSES[$name];
                $labels[$name]  = $label;
            }
        }
        foreach ($own as $name => $label) {
            // A bit an integer cannot hold (32-bit PHP) leaves the class out.
            if (isset($bits[$name]) && PHP_INT_SIZE >= 8 && !in_array($name, $off, true)) {
                $classes[$name] = 1 << $bits[$name];
                $labels[$name]  = $label;
            }
        }
        self::$registry = array(
            'classes' => $classes,
            'labels'  => $labels,
            'own'     => $own,
            'off'     => $off,
            'hidden'  => array_values(array_intersect($hidden, array_keys($classes))),
        );
        return self::$registry;
    }

    /**
     * Settings → Backlinks → Classes: the site's own classes at the top of
     * the card, and the class manager (admin requests only).
     */
    public static function admin_init() {
        add_action('seoproanalytics_setting_panel', array(__CLASS__, 'panel'), 10, 2);
        add_action('seoproanalytics_admin_enqueue', array(__CLASS__, 'enqueue'));
    }

    /**
     * The site's own classes, the card's own value (it has no switch);
     * the turned-off and hidden lists follow as its children.
     * admin/js/seoproanalytics-backlink-classes.js draws the three as one
     * list of classes with ticks; without it these are the form.
     *
     * @param string $key   Setting key whose panel is drawn.
     * @param array  $field Its schema entry.
     */
    public static function panel($key, $field = array()) {
        if ($key !== self::OWN_KEY || !is_array($field) || !class_exists('SEOProAnalytics_Settings_Manager')) {
            return;
        }
        SEOProAnalytics_Settings_Manager::render_field($key, array(
            'label'       => __('The site\'s own classes', 'seoproanalytics'),
            /* translators: %d: most own classes */
            'description' => sprintf(__('One a line, key | Name, such as press_kit | Press Kit. Up to %d. Removing one takes it off every link.', 'seoproanalytics'), self::MAX_OWN),
            'placeholder' => 'press_kit | Press Kit',
        ) + $field);
    }

    /**
     * The class manager's script and the classes it lists; once, for
     * whichever settings tab is drawn first (the card can also show in
     * search results).
     */
    public static function enqueue() {
        static $done = false;
        if ($done) {
            return;
        }
        $done     = true;
        $file     = 'admin/js/seoproanalytics-backlink-classes.js';
        $version  = file_exists(SEOPROANALYTICS_DIR . $file) ? (string) filemtime(SEOPROANALYTICS_DIR . $file) : SEOPROANALYTICS_VERSION;
        $builtins = array();
        foreach (self::built_in_labels() as $name => $label) {
            $builtins[] = array(
                'name'     => $name,
                'label'    => $label,
                'hint'     => self::hint($name),
                'pageOnly' => in_array($name, SEOProAnalytics_Backlink_Lists::PAGE_ONLY, true),
            );
        }
        wp_enqueue_script('seoproanalytics-backlink-classes', SEOPROANALYTICS_URL . $file, array('jquery', 'seoproanalytics-admin', 'wp-i18n', 'wp-a11y'), $version, true);
        wp_set_script_translations('seoproanalytics-backlink-classes', 'seoproanalytics');
        wp_add_inline_script('seoproanalytics-backlink-classes', 'window.seoproanBacklinkClasses=' . wp_json_encode(array(
            'own'      => self::OWN_KEY,
            'off'      => self::OFF_KEY,
            'hidden'   => self::HIDDEN_KEY,
            'builtins' => $builtins,
            'reserved' => array_merge(array_keys(self::CLASSES), array(self::NONE)),
            'max'      => self::MAX_OWN,
            'maxName'  => self::MAX_OWN_NAME,
            'maxKey'   => self::MAX_OWN_KEY,
        )) . ';', 'before');
        wp_add_inline_style('seoproanalytics-admin', '.seoproan-classes{display:grid;gap:12px;margin-bottom:4px}.seoproan-classes p{margin:0}.seoproan-classes [hidden]{display:none}'
            . '.seoproan-classes__table{width:100%;border-collapse:collapse}.seoproan-classes__table th,.seoproan-classes__table td{padding:6px 8px;text-align:left;vertical-align:middle;border-bottom:1px solid var(--seoproan-border,#dcdcde)}'
            . '.seoproan-classes__table thead th{font-weight:600}.seoproan-classes__table td:first-child,.seoproan-classes__table th:first-child{width:1%;white-space:nowrap}'
            . '.seoproan-classes__divider td{padding:2px 8px;border-bottom:2px solid var(--seoproan-border,#c3c4c7);color:var(--seoproan-muted,#646970);font-size:12px}'
            . '.seoproan-classes__row.is-off .seoproan-classes__name{color:var(--seoproan-muted,#646970)}.seoproan-classes__key{color:var(--seoproan-muted,#646970);font-size:12px}'
            . '.seoproan-classes__add{display:flex;flex-wrap:wrap;gap:8px 12px;align-items:end}.seoproan-classes__add label{display:grid;gap:4px}.seoproan-classes__add input[type=text]{margin:0}');
    }

    /** Forget this request's classes, after their settings change. */
    public static function reset() {
        self::$registry = null;
    }

    /**
     * The classes in use: name => bit.
     *
     * @return array<string,int>
     */
    public static function classes() {
        return self::registry()['classes'];
    }

    /**
     * The classes hidden until ticked in Classes (after the line).
     *
     * @return string[]
     */
    public static function hidden() {
        return self::registry()['hidden'];
    }

    /**
     * Class keys, one a line.
     *
     * @param mixed $text Stored lines.
     * @return string[]
     */
    private static function keys_in($text) {
        $out = array();
        foreach (preg_split('/[\r\n]+/', is_string($text) ? $text : '') ?: array() as $line) {
            $key = sanitize_key(trim($line));
            if ($key !== '') {
                $out[$key] = $key;
            }
        }
        return array_values($out);
    }

    /**
     * The site's own classes from their lines, "key | Name" or just
     * "Name" (its key made from the name): key => name, at most MAX_OWN.
     * A key that is a built-in class's, none, or already used is made
     * anew from the name.
     *
     * @param mixed $text Stored lines.
     * @return array<string,string>
     */
    public static function parse_own($text) {
        $out = array();
        foreach (preg_split('/[\r\n]+/', is_string($text) ? $text : '') ?: array() as $line) {
            $parts = array_map('trim', explode('|', $line, 2));
            $name  = isset($parts[1]) ? $parts[1] : $parts[0];
            $name  = trim(mb_substr((string) preg_replace('/\s+/', ' ', $name), 0, self::MAX_OWN_NAME));
            if ($name === '' || count($out) >= self::MAX_OWN) {
                continue;
            }
            $key = isset($parts[1]) ? self::key_of($parts[0]) : '';
            if ($key === '' || isset($out[$key]) || isset(self::CLASSES[$key]) || $key === self::NONE) {
                $key = self::unique_key(self::key_of($name), $out);
            }
            $out[$key] = $name;
        }
        return $out;
    }

    /**
     * A class key made from text: lower case a–z, 0–9 and _.
     *
     * @param string $text Text.
     * @return string
     */
    private static function key_of($text) {
        $key = strtolower(remove_accents((string) $text));
        $key = trim((string) preg_replace('/[^a-z0-9]+/', '_', $key), '_');
        // Cut to the longest key, without a trailing _.
        return rtrim((string) preg_replace('/^(.{' . self::MAX_OWN_KEY . '}).+$/', '$1', $key), '_');
    }

    /**
     * A key not used yet by a built-in class or another own one.
     *
     * @param string               $key  Key wanted ('' for none).
     * @param array<string,string> $used Own classes so far.
     * @return string
     */
    private static function unique_key($key, array $used) {
        $key  = $key === '' ? 'class' : $key;
        $base = $key;
        for ($n = 2; isset($used[$key]) || isset(self::CLASSES[$key]) || $key === self::NONE; $n++) {
            $key = substr($base, 0, self::MAX_OWN_KEY - strlen((string) $n) - 1) . '_' . $n;
        }
        return $key;
    }

    /**
     * Each own class's bit's power of two; a new class takes the highest
     * free one (stored at once, so it never changes). Freed only by
     * sync_own() when the class is removed.
     *
     * @param string[] $keys Own class keys.
     * @return array<string,int>
     */
    private static function own_bits(array $keys) {
        $map = get_option(self::BITS_OPTION, array());
        $map = is_array($map) ? array_map('intval', $map) : array();
        $new = array_diff($keys, array_keys($map));
        if ($new) {
            $free = array_diff(range(self::OWN_TOP, self::OWN_TOP - self::MAX_OWN + 1), $map);
            foreach ($new as $key) {
                $power = array_shift($free);
                if ($power === null) {
                    break;
                }
                $map[$key] = (int) $power;
            }
            update_option(self::BITS_OPTION, $map, false);
        }
        return $map;
    }

    /**
     * After the classes' settings change: own classes removed give back
     * their bits, taken off every link first (live and demo data) so a
     * class added later does not inherit them; and the reports refresh.
     * Admin, REST and WP-CLI saves only, never a visitor page.
     *
     * @param array<string,mixed> $old Settings before.
     * @param array<string,mixed> $new Settings after.
     */
    public static function settings_changed($old, $new) {
        $keys = array(self::OWN_KEY, self::OFF_KEY, self::HIDDEN_KEY);
        $changed = false;
        foreach ($keys as $key) {
            $changed = $changed || !SEOProAnalytics_Settings::same(isset($old[$key]) ? $old[$key] : null, isset($new[$key]) ? $new[$key] : null);
        }
        if (!$changed) {
            return;
        }
        self::reset();
        $own = self::parse_own(isset($new[self::OWN_KEY]) ? $new[self::OWN_KEY] : '');
        $map = get_option(self::BITS_OPTION, array());
        $map = is_array($map) ? array_map('intval', $map) : array();
        $gone = array_diff_key($map, $own);
        if ($gone && PHP_INT_SIZE >= 8) {
            $mask = 0;
            foreach ($gone as $power) {
                $mask |= 1 << $power;
            }
            self::clear_bits($mask);
            update_option(self::BITS_OPTION, array_diff_key($map, $gone), false);
        }
        self::own_bits(array_keys($own));
        self::reset();
        SEOProAnalytics_Backlinks::changed();
    }

    /**
     * Take bits off every link and referring page row, in the live data
     * and the demo data when made: one UPDATE each, by an admin's change.
     *
     * @param int $mask Bits.
     */
    private static function clear_bits($mask) {
        $clear = static function () use ($mask) {
            global $wpdb;
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- our own table; a removed class's bit, once, by an admin's change.
            $wpdb->query($wpdb->prepare('UPDATE %i SET classes = classes & ~%d, unclasses = unclasses & ~%d WHERE (classes | unclasses) & %d', SEOProAnalytics_Schema::table('links'), $mask, $mask, $mask));
            SEOProAnalytics_Backlinks::changed();
        };
        $clear();
        require_once SEOPROANALYTICS_DIR . 'includes/stats/class-seoproanalytics-demo.php';
        if (SEOProAnalytics_Demo::status()['status'] !== 'none') {
            SEOProAnalytics_Demo::run($clear);
        }
    }

    /**
     * Each class in use for the dashboard, A to Z is left to the reader's
     * language: name, label, hidden until ticked, and a page's class only.
     *
     * @return array<int,array{name:string,label:string,hidden:bool,pageOnly:bool,own:bool}>
     */
    public static function boot() {
        $registry = self::registry();
        $out      = array();
        foreach ($registry['labels'] as $name => $label) {
            $out[] = array(
                'name'     => $name,
                'label'    => $label,
                'hidden'   => in_array($name, $registry['hidden'], true),
                'pageOnly' => in_array($name, SEOProAnalytics_Backlink_Lists::PAGE_ONLY, true),
                'own'      => isset($registry['own'][$name]),
            );
        }
        return $out;
    }

    /**
     * The classes in use, with their names.
     *
     * @return array<string,string>
     */
    public static function labels() {
        return self::registry()['labels'];
    }

    /**
     * Every built-in class's name, turned off or not, as the dashboard
     * shows it.
     *
     * @return array<string,string>
     */
    public static function built_in_labels() {
        return array(
            'brand_citation'  => __('Citation of Brand Name', 'seoproanalytics'),
            'nap_citation'    => __('Citation of Name, Address, Phone (NAP)', 'seoproanalytics'),
            'cross_site'      => __('Cross-Site', 'seoproanalytics'),
            'documentation'   => __('Documentation', 'seoproanalytics'),
            'forum'           => __('Forum', 'seoproanalytics'),
            'general'         => __('General', 'seoproanalytics'),
            'guest_post'      => __('Guest Post', 'seoproanalytics'),
            'guide'           => __('Guide', 'seoproanalytics'),
            'home_page'       => __('Home Page', 'seoproanalytics'),
            'integration'     => __('Integration', 'seoproanalytics'),
            'link_swap'       => __('Link Swap', 'seoproanalytics'),
            'listicle'        => __('Listicle', 'seoproanalytics'),
            'logo_linked'     => __('Logo Linked', 'seoproanalytics'),
            'monitor'         => __('Monitor', 'seoproanalytics'),
            'needs_updating'  => __('Needs Updating', 'seoproanalytics'),
            'niche_edit'      => __('Niche Edit', 'seoproanalytics'),
            'organic_mention' => __('Organic Mention', 'seoproanalytics'),
            'paid_link'       => __('Paid Link', 'seoproanalytics'),
            'partner'         => __('Partner', 'seoproanalytics'),
            'press_organic'   => __('Press Organic', 'seoproanalytics'),
            'press_release'   => __('Press Release', 'seoproanalytics'),
            'private_blog'    => __('Private Blog (PBN)', 'seoproanalytics'),
            'profile_page'    => __('Profile Page', 'seoproanalytics'),
            'blog'            => __('Public Blog', 'seoproanalytics'),
            'quote'           => __('Quote', 'seoproanalytics'),
            'redirect'        => __('Redirect', 'seoproanalytics'),
            'site_footer'     => __('Site Footer', 'seoproanalytics'),
            'social_media'    => __('Social Media', 'seoproanalytics'),
            'statistics'      => __('Statistics', 'seoproanalytics'),
            'topical'         => __('Topical', 'seoproanalytics'),
            'url_linked'      => __('URL Linked', 'seoproanalytics'),
            'url_unlinked'    => __('URL Unlinked', 'seoproanalytics'),
            'video'           => __('Video', 'seoproanalytics'),
            'wiki'            => __('Wiki', 'seoproanalytics'),
            'ignore'          => __('Ignore', 'seoproanalytics'),
            'spam'            => __('Spam', 'seoproanalytics'),
        );
    }

    /**
     * What a class is for, under its list in Settings → Backlinks.
     *
     * @param string $name Class name.
     * @return string
     */
    public static function hint($name) {
        $hints = array(
            'ignore'          => __('Links to leave out, such as those from the site\'s own copies.', 'seoproanalytics'),
            'spam'            => __('Links made for attention, not readers, such as pages a machine makes for every domain.', 'seoproanalytics'),
            'guide'           => __('How-to guides and tutorials.', 'seoproanalytics'),
            'monitor'         => __('Links to keep an eye on.', 'seoproanalytics'),
            'needs_updating'  => __('Links whose text or page linked to should change.', 'seoproanalytics'),
            'topical'         => __('Sites and pages on the same subject as this site: the links that count most for its rankings.', 'seoproanalytics'),
            'general'         => __('Sites on many subjects, such as directories, news and magazines.', 'seoproanalytics'),
            'home_page'       => __('Links on a site\'s home page.', 'seoproanalytics'),
            'site_footer'     => __('Links in a footer or sidebar on every page of a site.', 'seoproanalytics'),
            'blog'            => __('Posts on a blog anyone can read.', 'seoproanalytics'),
            'redirect'        => __('Links that reach this site through a redirect, such as an old address or a moved domain.', 'seoproanalytics'),
            'cross_site'      => __('Links from the owner\'s other sites.', 'seoproanalytics'),
            'forum'           => __('Forum threads and replies.', 'seoproanalytics'),
            'video'           => __('Video pages and their descriptions.', 'seoproanalytics'),
            'niche_edit'      => __('Links added to an existing article after it was published, usually for payment.', 'seoproanalytics'),
            'private_blog'    => __('A private blog network: sites made to link, not to be read. Counts for little and can harm rankings.', 'seoproanalytics'),
            'wiki'            => __('Wikis anyone can edit.', 'seoproanalytics'),
            'documentation'   => __('Documentation and help pages.', 'seoproanalytics'),
            'integration'     => __('Integration and app directory pages, such as a plugin or app listing.', 'seoproanalytics'),
            'paid_link'       => __('Links paid for. Without rel sponsored, search engines can count them against a site.', 'seoproanalytics'),
            'link_swap'       => __('Links given in exchange for a link back.', 'seoproanalytics'),
            'url_linked'      => __('Links whose text is this site\'s address.', 'seoproanalytics'),
            'url_unlinked'    => __('Pages that write this site\'s address without linking it: worth asking for the link.', 'seoproanalytics'),
            'logo_linked'     => __('Links on a logo or image.', 'seoproanalytics'),
            'brand_citation'  => __('Pages that name the brand.', 'seoproanalytics'),
            'nap_citation'    => __('Listings of the business\'s name, address and phone number, such as directories and maps.', 'seoproanalytics'),
            'listicle'        => __('List posts, such as “the 10 best…”.', 'seoproanalytics'),
            'statistics'      => __('Pages of statistics and facts that cite this site as their source.', 'seoproanalytics'),
            'quote'           => __('Pages that quote this site or its people.', 'seoproanalytics'),
            'guest_post'      => __('Posts written for another site.', 'seoproanalytics'),
            'press_release'   => __('Press releases and the sites that copy them.', 'seoproanalytics'),
            'press_organic'   => __('Press coverage that was not paid for.', 'seoproanalytics'),
            'organic_mention' => __('Mentions nobody asked for.', 'seoproanalytics'),
            'profile_page'    => __('Profiles and link-in-bio pages.', 'seoproanalytics'),
            'social_media'    => __('Social networks.', 'seoproanalytics'),
            'partner'         => __('Partners, clients and suppliers.', 'seoproanalytics'),
        );
        return isset($hints[$name]) ? $hints[$name] : '';
    }

    /**
     * Every class in use's bits together.
     *
     * @return int
     */
    public static function all_bits() {
        return self::bits(array_keys(self::classes()));
    }

    /**
     * A class's bit: 0 for a class not in use (unknown or turned off), and
     * on 32-bit PHP for a bit an integer cannot hold (from 2^31), so those
     * classes do nothing there.
     *
     * @param string $name Class name.
     * @return int
     */
    public static function bit($name) {
        $classes = self::classes();
        $bit     = isset($classes[$name]) ? $classes[$name] : 0;
        return PHP_INT_SIZE >= 8 || $bit < 2147483648 ? $bit : 0;
    }

    /**
     * The bits of class names.
     *
     * @param string[] $names Class names (unknown ones count nothing).
     * @return int
     */
    public static function bits(array $names) {
        $bits = 0;
        foreach ($names as $name) {
            $bits |= self::bit((string) $name);
        }
        return $bits;
    }

    /**
     * A row's classes for the report: its own, and those its referring
     * page and the lists give it, less those the owner took off the row or
     * its page (unclasses); and, for each class it has only from the page
     * or a list, where from, so the dashboard can say so.
     *
     * @param int                  $own     The row's own classes bits.
     * @param int                  $page    Its referring page's classes bits (0 for none).
     * @param array<string,string> $listed  From SEOProAnalytics_Backlink_Lists::match().
     * @param int                  $own_un  Classes taken off the row.
     * @param int                  $page_un Classes taken off its referring page (its links lose them too).
     * @return array{classes:string[],inherited:object}
     */
    public static function effective($own, $page, array $listed, $own_un = 0, $page_un = 0) {
        $own     = (int) $own;
        $blocked = (int) $own_un | (int) $page_un;
        $page    = (int) $page & ~$blocked & ~$own;
        $inherited = array();
        foreach (self::names($page) as $name) {
            $inherited[$name] = array('from' => 'page');
        }
        foreach ($listed as $name => $line) {
            $bit = self::bit((string) $name);
            if (!isset($inherited[$name]) && $bit && !($bit & ($blocked | $own))) {
                $inherited[$name] = array('from' => 'list', 'line' => (string) $line);
            }
        }
        return array(
            'classes'   => self::names($own | $page | self::bits(array_keys($inherited))),
            // An object, so an empty one is {} in JSON.
            'inherited' => (object) $inherited,
        );
    }

    /**
     * The asked-for filters with only the classes kept (domains and
     * reported lists).
     *
     * @param array<string,mixed> $t From params().
     * @return array<string,mixed>
     */
    public static function only_classes(array $t) {
        return array_merge($t, array('rel' => null, 'search' => '', 'date_from' => '', 'date_to' => '', 'ranges' => array(), 'sort' => ''));
    }

    /** rel filter values: followed is a link without nofollow, sponsored or ugc. */
    const RELS = array('followed', 'nofollow', 'sponsored', 'ugc');

    /** Metrics of the linking site and page, filtered by ranges. */
    const METRICS = array('site_dr', 'site_ld', 'page_dr', 'page_ld');

    /** Sorts of the links lists. */
    const SORTS = array('source', 'page', 'anchor', 'first_seen', 'last_seen', 'lost', 'site_dr', 'site_ld', 'page_dr', 'page_ld', 'value');

    /** Dates a range filter applies to. */
    const DATES = array('first_seen', 'last_seen', 'lost');

    /** Fields searched. */
    const SEARCH_IN = array('source', 'page', 'anchor');

    /** Links classified per request at most; longest search. */
    const MAX_IDS    = 500;
    const MAX_SEARCH = 200;

    /** Text sorts: ascending by default; the rest most first. */
    const TEXT_SORTS = array('source', 'page', 'anchor');

    /**
     * Names of a link's classes.
     *
     * @param int $bits classes bits.
     * @return string[]
     */
    public static function names($bits) {
        $out = array();
        foreach (array_keys(self::classes()) as $name) {
            $bit = self::bit($name);
            if ($bit && ((int) $bits & $bit)) {
                $out[] = $name;
            }
        }
        return $out;
    }

    /**
     * The filters, search and sort asked for, checked.
     *
     * @param array<string,mixed> $params Request parameters: classes, rel, date, date_from, date_to, search, search_in, site_dr_min … page_ld_max, sort, order.
     * @return array<string,mixed>|WP_Error
     */
    public static function params(array $params) {
        $out = array(
            'classes'   => self::list_param($params, 'classes', array_merge(array_keys(self::classes()), array(self::NONE))),
            'rel'       => self::list_param($params, 'rel', self::RELS),
            'search_in' => self::list_param($params, 'search_in', self::SEARCH_IN),
            'search'    => trim((string) ($params['search'] ?? '')),
            'date'      => (string) ($params['date'] ?? '') !== '' ? (string) $params['date'] : 'first_seen',
            'date_from' => (string) ($params['date_from'] ?? ''),
            'date_to'   => (string) ($params['date_to'] ?? ''),
            'sort'      => (string) ($params['sort'] ?? ''),
            'order'     => (string) ($params['order'] ?? ''),
            'ranges'    => array(),
        );
        foreach (array('classes', 'rel', 'search_in') as $key) {
            if (is_wp_error($out[$key])) {
                return $out[$key];
            }
        }
        if (strlen($out['search']) > self::MAX_SEARCH) {
            return self::error(__('The search is too long.', 'seoproanalytics'));
        }
        if (!in_array($out['date'], self::DATES, true) || !self::is_day($out['date_from']) || !self::is_day($out['date_to'])) {
            return self::error(__('Dates are YYYY-MM-DD, and the date is first_seen, last_seen or lost.', 'seoproanalytics'));
        }
        if (($out['sort'] !== '' && !in_array($out['sort'], self::SORTS, true)) || !in_array($out['order'], array('', 'asc', 'desc'), true)) {
            /* translators: %s: list of sorts */
            return self::error(sprintf(__('The sort is one of: %s; the order asc or desc.', 'seoproanalytics'), implode(', ', self::SORTS)));
        }
        foreach (self::METRICS as $metric) {
            $range = array(self::bound($params, $metric . '_min'), self::bound($params, $metric . '_max'));
            if (is_wp_error($range[0]) || is_wp_error($range[1])) {
                return self::error(__('Metric bounds are whole numbers of 0 or more.', 'seoproanalytics'));
            }
            if ($range[0] !== null || $range[1] !== null) {
                $out['ranges'][$metric] = $range;
            }
        }
        return $out;
    }

    /**
     * A comma-separated list parameter: null when left out (no filter).
     *
     * @param array<string,mixed> $params  Parameters.
     * @param string              $key     Name.
     * @param string[]            $allowed Values allowed.
     * @return string[]|null|WP_Error
     */
    private static function list_param(array $params, $key, array $allowed) {
        if (!isset($params[$key])) {
            return null;
        }
        $raw = is_array($params[$key]) ? implode(',', array_map('strval', $params[$key])) : (string) $params[$key];
        if (trim($raw) === '') {
            return null;
        }
        $values = array_values(array_unique(array_filter(array_map('trim', explode(',', $raw)), static function ($value) {
            return $value !== '';
        })));
        $bad    = array_diff($values, $allowed);
        if ($bad) {
            /* translators: 1: parameter name, 2: list of values */
            return self::error(sprintf(__('%1$s takes: %2$s.', 'seoproanalytics'), $key, implode(', ', $allowed)));
        }
        return $values;
    }

    /**
     * A metric bound: null when left out.
     *
     * @param array<string,mixed> $params Parameters.
     * @param string              $key    Name.
     * @return int|null|WP_Error
     */
    private static function bound(array $params, $key) {
        if (!isset($params[$key]) || $params[$key] === '') {
            return null;
        }
        $value = $params[$key];
        if (!is_numeric($value) || (int) $value < 0 || (string) (int) $value !== trim((string) $value)) {
            return new WP_Error('seoproanalytics_backlinks_filter', '');
        }
        return (int) $value;
    }

    /**
     * Whether a date is '' or YYYY-MM-DD.
     *
     * @param string $day Date.
     * @return bool
     */
    private static function is_day($day) {
        return $day === '' || (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', $day);
    }

    /**
     * A 400 error.
     *
     * @param string $message Message.
     * @return WP_Error
     */
    private static function error($message) {
        return new WP_Error('seoproanalytics_backlinks_filter', $message, array('status' => 400));
    }

    /**
     * Whether any filter, search or sort is asked for.
     *
     * @param array<string,mixed> $t From params().
     * @return bool
     */
    public static function active(array $t) {
        return $t['classes'] !== null || $t['rel'] !== null || $t['search'] !== '' || $t['date_from'] !== '' || $t['date_to'] !== '' || $t['ranges'] || $t['sort'] !== '';
    }

    /**
     * Filter, search and sort a links list.
     *
     * @param array<int,array<string,mixed>> $rows Link rows (SEOProAnalytics_Backlinks link rows).
     * @param array<string,mixed>            $t    From params().
     * @return array<int,array<string,mixed>>
     */
    public static function apply(array $rows, array $t) {
        $rows = array_values(array_filter($rows, static function ($row) use ($t) {
            return self::keep($row, $t);
        }));
        if ($t['sort'] !== '') {
            $rows = self::sorted($rows, $t['sort'], $t['order']);
        }
        return $rows;
    }

    /**
     * Whether a link passes every filter and the search.
     *
     * @param array<string,mixed> $row Link row.
     * @param array<string,mixed> $t   From params().
     * @return bool
     */
    private static function keep(array $row, array $t) {
        if ($t['classes'] !== null) {
            $classes = (array) $row['classes'];
            // Hidden when any of its classes is not chosen; no class goes by none.
            if (!$classes ? !in_array(self::NONE, $t['classes'], true) : (bool) array_diff($classes, $t['classes'])) {
                return false;
            }
        }
        if ($t['rel'] !== null) {
            $rel = $row['rel'] ? (array) $row['rel'] : array('followed');
            if (!array_intersect($rel, $t['rel'])) {
                return false;
            }
        }
        foreach ($t['ranges'] as $metric => $range) {
            $value = isset($row[$metric]) ? $row[$metric] : null;
            if ($value === null || ($range[0] !== null && $value < $range[0]) || ($range[1] !== null && $value > $range[1])) {
                return false;
            }
        }
        if ($t['date_from'] !== '' || $t['date_to'] !== '') {
            $day = isset($row[$t['date']]) && is_string($row[$t['date']]) ? substr($row[$t['date']], 0, 10) : '';
            if ($day === '' || ($t['date_from'] !== '' && $day < $t['date_from']) || ($t['date_to'] !== '' && $day > $t['date_to'])) {
                return false;
            }
        }
        return $t['search'] === '' || self::matches($row, $t['search'], $t['search_in'] !== null ? $t['search_in'] : self::SEARCH_IN);
    }

    /**
     * Whether the search is in one of the fields (any case).
     *
     * @param array<string,mixed> $row    Link row.
     * @param string              $search Text.
     * @param string[]            $fields Fields.
     * @return bool
     */
    private static function matches(array $row, $search, array $fields) {
        foreach ($fields as $field) {
            $text = isset($row[$field]) ? (string) $row[$field] : '';
            if ($text !== '' && (function_exists('mb_stripos') ? mb_stripos($text, $search, 0, 'UTF-8') : stripos($text, $search)) !== false) {
                return true;
            }
        }
        return false;
    }

    /**
     * Rows sorted by one column; links without the value last.
     *
     * @param array<int,array<string,mixed>> $rows  Link rows.
     * @param string                         $sort  One of SORTS.
     * @param string                         $order asc, desc, or '' for the column's natural order.
     * @return array<int,array<string,mixed>>
     */
    private static function sorted(array $rows, $sort, $order) {
        $text = in_array($sort, self::TEXT_SORTS, true);
        $asc  = $order === '' ? $text : $order === 'asc';
        $keys = array();
        foreach ($rows as $i => $row) {
            $value    = isset($row[$sort]) ? $row[$sort] : null;
            $keys[$i] = $value === null || $value === '' ? null : ($text ? strtolower((string) $value) : $value);
        }
        uksort($rows, static function ($a, $b) use ($keys, $asc) {
            if ($keys[$a] === null || $keys[$b] === null) {
                return ($keys[$a] === null) <=> ($keys[$b] === null) ?: $a <=> $b;
            }
            $cmp = $keys[$a] <=> $keys[$b];
            return ($asc ? $cmp : -$cmp) ?: $a <=> $b;
        });
        return array_values($rows);
    }

    /**
     * Facts about the whole list for the filters: links of each class (and
     * without one), and the highest figure of each metric.
     *
     * @param array<int,array<string,mixed>> $rows Link rows, unfiltered.
     * @return array{classes:array<string,int>,max:array<string,int>}
     */
    public static function facets(array $rows) {
        $classes = array_fill_keys(array_merge(array_keys(self::classes()), array(self::NONE)), 0);
        $max     = array_fill_keys(self::METRICS, 0);
        foreach ($rows as $row) {
            foreach ($row['classes'] ? (array) $row['classes'] : array(self::NONE) as $name) {
                ++$classes[$name];
            }
            foreach (self::METRICS as $metric) {
                if (isset($row[$metric]) && $row[$metric] > $max[$metric]) {
                    $max[$metric] = (int) $row[$metric];
                }
            }
        }
        return array('classes' => $classes, 'max' => $max);
    }

    /**
     * Set, add or remove the owner's classes: of links by their row ids;
     * of referring pages (Reported pages) by their own rows' ids, whose
     * classes every link on the page has, found now or later; or of sites
     * and pages by putting them on
     * the classes' lists (SEOProAnalytics_Backlink_Lists), so every link from
     * them has the classes.
     *
     * @param array<string,mixed> $params One of ids, pages (lists or comma-separated) or sites (hosts or addresses, list or comma- or line-separated); classes (names, comma-separated or a list; empty with set clears); mode (set, add or remove; set when left out).
     * @return array<string,mixed>|WP_Error
     */
    public static function classify(array $params) {
        $mode = isset($params['mode']) && (string) $params['mode'] !== '' ? (string) $params['mode'] : 'set';
        if (!in_array($mode, array('set', 'add', 'remove'), true)) {
            return self::error(__('The mode is set, add or remove.', 'seoproanalytics'));
        }
        $names = self::list_param($params, 'classes', array_keys(self::classes()));
        if (is_wp_error($names)) {
            return $names;
        }
        $names = $names === null ? array() : $names;
        if (!$names && $mode !== 'set') {
            return self::error(__('Give the classes to add or remove.', 'seoproanalytics'));
        }
        $sites = self::raw_list($params, 'sites', '/[\r\n,]+/');
        $pages = array_values(array_unique(array_filter(array_map('intval', self::raw_list($params, 'pages', '/,/')))));
        $ids   = array_values(array_unique(array_filter(array_map('intval', self::raw_list($params, 'ids', '/,/')))));
        // One scope per request, so none is dropped without a word.
        if (count(array_filter(array($sites, $pages, $ids))) > 1) {
            return self::error(__('Give one of ids, pages or sites.', 'seoproanalytics'));
        }
        if ($sites) {
            require_once __DIR__ . '/class-seoproanalytics-backlink-lists.php';
            $done = SEOProAnalytics_Backlink_Lists::change($sites, $names, $mode);
            return is_wp_error($done) ? $done : $done + array('scope' => 'sites');
        }
        if ($pages) {
            return self::classify_rows($pages, true, $names, $mode);
        }
        return self::classify_rows($ids, false, $names, $mode);
    }

    /**
     * A list parameter's values: a list, or text split by the separators.
     *
     * @param array<string,mixed> $params Parameters.
     * @param string              $key    Name.
     * @param string              $split  Separators, as a pattern.
     * @return string[]
     */
    private static function raw_list(array $params, $key, $split) {
        if (!isset($params[$key])) {
            return array();
        }
        $raw = is_array($params[$key]) ? array_map('strval', $params[$key]) : (preg_split($split, (string) $params[$key]) ?: array());
        return array_values(array_filter(array_map('trim', $raw), static function ($value) {
            return $value !== '';
        }));
    }

    /**
     * Write classes to rows of the links table: links by id, or referring
     * pages by their page row's id (the page's row and every link on it,
     * by the source_url key).
     *
     * @param int[]    $ids   Row ids.
     * @param bool     $pages Whether the ids are referring pages' rows.
     * @param string[] $names Class names.
     * @param string   $mode  set, add or remove.
     * @return array<string,mixed>|WP_Error
     */
    private static function classify_rows(array $ids, $pages, array $names, $mode) {
        global $wpdb;
        if (!$ids || count($ids) > self::MAX_IDS) {
            return self::error(
                $pages
                    /* translators: %d: most pages per request */
                    ? sprintf(__('Give from 1 to %d page ids.', 'seoproanalytics'), self::MAX_IDS)
                    /* translators: %d: most links per request */
                    : sprintf(__('Give from 1 to %d link ids.', 'seoproanalytics'), self::MAX_IDS)
            );
        }
        $bits = self::bits($names);
        // A class added is the row's own and no longer taken off; one
        // removed is taken off, so the row's page or a list cannot give
        // it back (ticking it again adds it). Set leaves other removals,
        // and the bits of classes turned off, which come back with them.
        $set    = array(
            'set'    => 'classes = (classes & ~%d) | %d, unclasses = unclasses & ~%d',
            'add'    => 'classes = classes | %d, unclasses = unclasses & ~%d',
            'remove' => 'classes = classes & ~%d, unclasses = unclasses | %d',
        );
        $values = $mode === 'set' ? array(self::all_bits(), $bits, $bits) : array($bits, $bits);
        $table  = SEOProAnalytics_Schema::table('links');
        // A referring page's classes live on its own row (path_id 0); the
        // report gives them to every link on the page, found now or later.
        $rows    = $pages ? 'path_id = 0' : 'path_id > 0';
        $holders = implode(', ', array_fill(0, count($ids), '%d'));
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- our own table by its primary key; the SET clause is one of three fixed strings, $rows one of two, $holders holds only placeholders.
        $updated = $wpdb->query($wpdb->prepare("UPDATE %i SET {$set[$mode]} WHERE id IN ($holders) AND {$rows}", array_merge(array($table), $values, $ids)));
        if ($updated === false) {
            return new WP_Error('seoproanalytics_backlinks_classify', __('The classes could not be saved.', 'seoproanalytics'), array('status' => 500));
        }
        SEOProAnalytics_Backlinks::changed();
        return array('updated' => (int) $updated, ($pages ? 'pages' : 'ids') => $ids, 'classes' => $names, 'mode' => $mode, 'scope' => $pages ? 'pages' : 'links');
    }
}

<?php
/**
 * Business profiles (Settings → Data): the site's listings on Google
 * Business Profile, Bing Places, Apple Business Connect and other
 * directories. Each gets a tagged website link to paste as the profile's
 * website, so visits from it count in the Business profiles channel
 * (SEOProAnalytics_Channels::BUSINESS_PROFILE) and on the profile's own row,
 * by campaign, with its conversions and revenue. Adding, changing or
 * removing a profile is a change on the timeline.
 *
 * No outside request: the list is the owner's. A connection added later
 * (such as Google Business Profile's own figures) attaches to these
 * entries by their campaign.
 *
 * Stored as lines of the business_profiles_list setting:
 * platform | name | profile address | page.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 * Additional terms (GPL-3.0 section 7(b)): ATTRIBUTION.txt
 * Provenance: wpas-c8662019b99fb07b. Copies, even of part, keep these lines.
 *
 * @package SEOProAnalytics
 * @since 1.12.0
 */

if (!defined('ABSPATH')) {
    exit;
}

final class SEOProAnalytics_Business_Profiles {

    /** The switch (a Settings → Data card). */
    const KEY = 'business_profiles';

    /** The list: one profile per line. */
    const LIST_KEY = 'business_profiles_list';

    /** utm_medium of a profile's tagged link (SEOProAnalytics_Channels::PROFILE_MEDIUMS). */
    const MEDIUM = 'business_profile';

    /** Kind code of a profile added, changed or removed (SEOProAnalytics_Changes::KINDS). */
    const CHANGE = 53;

    /** Most profiles read from the list. */
    const MAX = 50;

    /** Longest campaign name made from a profile's name. */
    const MAX_CAMPAIGN = 60;

    /** The demo data's profile (SEOProAnalytics_Demo::SOURCES sends its visits). */
    const DEMO = 'google | Head office | | /';

    /** Platforms: what a line may start with => the platform. */
    const ALIASES = array(
        'google'                  => 'google',
        'gbp'                     => 'google',
        'google business profile' => 'google',
        'google maps'             => 'google',
        'bing'                    => 'bing',
        'bing places'             => 'bing',
        'apple'                   => 'apple',
        'apple maps'              => 'apple',
        'apple business connect'  => 'apple',
        'other'                   => 'other',
    );

    /**
     * Platform labels by platform.
     *
     * @return array<string,string>
     */
    public static function platforms() {
        return array(
            'google' => __('Google Business Profile', 'seoproanalytics'),
            'bing'   => __('Bing Places', 'seoproanalytics'),
            'apple'  => __('Apple Business Connect', 'seoproanalytics'),
            'other'  => __('Other', 'seoproanalytics'),
        );
    }

    /**
     * The profiles reports use: the demo's on the demo data; else the
     * list, while the switch is on.
     *
     * @return array<int,array<string,string>>
     */
    public static function profiles() {
        if (SEOProAnalytics_Schema::set() === 'demo') {
            return self::parse(self::DEMO);
        }
        return SEOProAnalytics_Settings::get(self::KEY) ? self::listed() : array();
    }

    /**
     * The profiles listed in the setting, with the switch on or off.
     *
     * @return array<int,array<string,string>>
     */
    public static function listed() {
        return self::parse((string) SEOProAnalytics_Settings::get(self::LIST_KEY));
    }

    /**
     * Read profile lines: platform | name | profile address | page. A line
     * without a name is skipped; an unknown platform is other; the page
     * is the home page when left out. Each gets a campaign from its name,
     * unique in the list, and its tagged link.
     *
     * @param mixed $text Lines.
     * @return array<int,array<string,string>> Each: platform, name, url, page, campaign, source, link.
     */
    public static function parse($text) {
        $out   = array();
        $taken = array();
        foreach (preg_split('/[\r\n]+/', is_scalar($text) ? (string) $text : '') ?: array() as $line) {
            $parts = array_map('trim', explode('|', $line));
            $name  = isset($parts[1]) ? sanitize_text_field($parts[1]) : '';
            if ($name === '') {
                continue;
            }
            $platform = self::platform($parts[0]);
            $url      = self::profile_url(isset($parts[2]) ? $parts[2] : '');
            $campaign = self::campaign($name, $platform, count($out) + 1, $taken);

            $taken[$campaign] = true;
            $profile          = array(
                'platform' => $platform,
                'name'     => $name,
                'url'      => $url,
                'page'     => self::page(isset($parts[3]) ? $parts[3] : ''),
                'campaign' => $campaign,
                'source'   => self::source($platform, $url),
            );
            $profile['link'] = self::link($profile);
            $out[]           = $profile;
            if (count($out) >= self::MAX) {
                break;
            }
        }
        return $out;
    }

    /**
     * The platform a line names; other when it names none of them.
     *
     * @param string $text Text.
     * @return string
     */
    private static function platform($text) {
        $text = strtolower(trim((string) preg_replace('/\s+/', ' ', $text)));
        return isset(self::ALIASES[$text]) ? self::ALIASES[$text] : 'other';
    }

    /**
     * A profile's public address: http or https, else ''.
     *
     * @param string $text Text.
     * @return string
     */
    private static function profile_url($text) {
        $url = esc_url_raw($text, array('http', 'https'));
        return preg_match('~^https?://[^/\s]+~i', $url) ? $url : '';
    }

    /**
     * The page a profile represents, as a path of this site ('/' for the
     * home page): a path, or an address on this site.
     *
     * @param string $text Text.
     * @return string
     */
    private static function page($text) {
        $text = trim($text);
        if ($text === '') {
            return '/';
        }
        if (preg_match('~^https?://~i', $text)) {
            if (self::host($text) !== self::host(home_url('/'))) {
                return '/';
            }
            $text = (string) wp_parse_url($text, PHP_URL_PATH);
            // A site in a folder: its pages are paths below home_url().
            $base = rtrim((string) wp_parse_url(home_url('/'), PHP_URL_PATH), '/');
            if ($base !== '' && strpos($text, $base . '/') === 0) {
                $text = substr($text, strlen($base));
            }
        }
        $text = (string) preg_replace('~[?#].*$~', '', $text);
        $text = (string) preg_replace('#[^A-Za-z0-9/_.~%-]#', '', $text);
        return '/' . ltrim($text, '/');
    }

    /**
     * An address's host, lower case, without www.
     *
     * @param string $url Address.
     * @return string
     */
    private static function host($url) {
        $host = strtolower((string) wp_parse_url($url, PHP_URL_HOST));
        // On PHP 7, substr() of "www." alone is false, not ''.
        return strpos($host, 'www.') === 0 ? (string) substr($host, 4) : $host;
    }

    /**
     * A campaign name from a profile's name: lower case, hyphens, unique
     * in the list; the platform and place when the name has no Latin
     * letters or digits.
     *
     * @param string              $name     Name.
     * @param string              $platform Platform.
     * @param int                 $place    Place in the list, from 1.
     * @param array<string,bool>  $taken    Campaigns used.
     * @return string
     */
    private static function campaign($name, $platform, $place, array $taken) {
        $base = sanitize_title(remove_accents($name));
        if ($base === '' || strpos($base, '%') !== false) {
            $base = $platform . '-' . $place;
        }
        $base = trim(substr($base, 0, self::MAX_CAMPAIGN), '-');
        $slug = $base;
        for ($n = 2; isset($taken[$slug]); $n++) {
            $slug = $base . '-' . $n;
        }
        return $slug;
    }

    /**
     * utm_source of a profile's link: the platform; for other, the
     * profile address's site (yelp for www.yelp.com), else other.
     *
     * @param string $platform Platform.
     * @param string $url      Profile address.
     * @return string
     */
    private static function source($platform, $url) {
        if ($platform !== 'other' || $url === '') {
            return $platform;
        }
        $parts = explode('.', self::host($url));
        $name  = count($parts) > 1 ? $parts[count($parts) - 2] : $parts[0];
        $name  = (string) preg_replace('/[^a-z0-9-]/', '', $name);
        return $name !== '' ? $name : 'other';
    }

    /**
     * The tagged website link of a profile.
     *
     * @param array<string,string> $profile Profile (page, source, campaign).
     * @return string
     */
    public static function link(array $profile) {
        return add_query_arg(
            array(
                'utm_source'   => rawurlencode($profile['source']),
                'utm_medium'   => self::MEDIUM,
                'utm_campaign' => rawurlencode($profile['campaign']),
            ),
            home_url($profile['page'])
        );
    }

    // ------------------------------------------------------------------
    // Reports.

    /**
     * Visits, conversions and revenue of the Business profiles channel and
     * of each profile (its tagged link's visits: the channel and its
     * campaign), with the request's own filters.
     *
     * @param array<string,mixed> $req From SEOProAnalytics_Query::request().
     * @return array<string,mixed>
     */
    public static function report(array $req) {
        $profiles = self::profiles();
        return SEOProAnalytics_Query::cached('business_profiles', $req + array('defs' => $profiles), static function () use ($req, $profiles) {
            $range     = SEOProAnalytics_Query::range($req);
            $channel   = array('dimension' => 'channel', 'op' => 'is', 'values' => array('business_profile'));
            $platforms = self::platforms();
            $rows      = array();
            foreach ($profiles as $profile) {
                $campaign = array('dimension' => 'utm_campaign', 'op' => 'is', 'values' => array($profile['campaign']));
                $rows[]   = $profile + array('platform_label' => $platforms[$profile['platform']]) + self::figures($req, $range, array($channel, $campaign));
            }
            return array(
                'range'    => SEOProAnalytics_Query::range_out($range),
                'channel'  => self::figures($req, $range, array($channel)),
                'profiles' => $rows,
            );
        });
    }

    /**
     * Visitors, visits, goal completions (each goal's and their sum) and
     * revenue of the visits that match the request's filters and some more.
     *
     * @param array<string,mixed>            $req   From SEOProAnalytics_Query::request().
     * @param array<string,mixed>            $range From SEOProAnalytics_Query::range().
     * @param array<int,array<string,mixed>> $more  More filters ({dimension, op, values}).
     * @return array<string,mixed>
     */
    private static function figures(array $req, array $range, array $more) {
        $extra    = SEOProAnalytics_Query::parse_filters($more);
        $filters  = array_merge($req['filters'], is_array($extra) ? $extra : array());
        $compiled = SEOProAnalytics_Query::compile($filters, $range);
        $totals   = SEOProAnalytics_Query::totals($range, $compiled);
        $goals    = SEOProAnalytics_Conversions::goals(array('filters' => $filters, 'compare' => 'none') + $req);
        $list     = array();
        $sum      = 0;
        foreach ($goals['goals'] as $goal) {
            $sum   += (int) $goal['completions'];
            $list[] = array(
                'id'          => $goal['id'],
                'name'        => $goal['name'],
                'visits'      => (int) $goal['visits'],
                'completions' => (int) $goal['completions'],
            );
        }
        return array(
            'visitors'    => isset($totals['visitors']) ? (int) $totals['visitors'] : 0,
            'visits'      => isset($totals['visits']) ? (int) $totals['visits'] : 0,
            'conversions' => $sum,
            'goals'       => $list,
            'revenue'     => self::revenue($range, $compiled),
        );
    }

    /**
     * Revenue of the matching visits, by currency: every event with an
     * amount (Purchase and others), refunds taken off. Counted from the
     * events, not per goal, so an amount two goals count is counted once.
     *
     * @param array<string,mixed> $range    From SEOProAnalytics_Query::range().
     * @param array<string,mixed> $compiled From SEOProAnalytics_Query::compile().
     * @return array<int,array{currency:string,amount:float,count:int}>
     */
    private static function revenue(array $range, array $compiled) {
        global $wpdb;
        $refunds = SEOProAnalytics_Dict::find(SEOProAnalytics_Schema::DICT_EVENT, array(SEOProAnalytics_Purchases::REFUND));
        $refund  = $refunds ? (int) $refunds[0] : -1;
        $where   = $compiled['where'];
        $args    = array_merge(
            array($refund, SEOProAnalytics_Schema::table('sessions'), SEOProAnalytics_Schema::table('events')),
            SEOProAnalytics_Query::fact_window($range),
            array($range['from'], $range['to']),
            $compiled['args']
        );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, PluginCheck.Security.DirectDB.UnescapedDBParameter -- our own tables by index `ts` and the visit's key; $where holds the compiled filters' placeholders.
        $rows = $wpdb->get_results($wpdb->prepare("SELECT f.currency AS c, SUM(CASE WHEN f.name_id = %d THEN -f.revenue ELSE f.revenue END) AS r, COUNT(*) AS n FROM %i s INNER JOIN %i f ON f.session_id = s.id WHERE f.ts >= %d AND f.ts < %d AND f.revenue <> 0 AND s.started >= %d AND s.started < %d$where GROUP BY f.currency ORDER BY r DESC", $args), ARRAY_A);
        return SEOProAnalytics_Conversions::money((array) $rows);
    }

    // ------------------------------------------------------------------
    // The timeline.

    /**
     * After settings change (seoproanalytics_settings_changed): each profile
     * added, changed or removed is a change on the timeline, made by
     * whoever saved it. Only while the switch is on.
     *
     * @param mixed $old Settings before.
     * @param mixed $new Settings after.
     */
    public static function settings_changed($old, $new) {
        if (!is_array($old) || !is_array($new) || empty($new[self::KEY])) {
            return;
        }
        $was = self::by_campaign(self::parse(isset($old[self::LIST_KEY]) ? $old[self::LIST_KEY] : ''));
        $now = self::by_campaign(self::parse(isset($new[self::LIST_KEY]) ? $new[self::LIST_KEY] : ''));
        if ($was === $now) {
            return;
        }
        require_once __DIR__ . '/class-seoproanalytics-changes.php';
        foreach ($now as $campaign => $profile) {
            if (!isset($was[$campaign])) {
                self::record('added', $profile, array(), null);
                continue;
            }
            $fields = self::changed_fields($was[$campaign], $profile);
            if ($fields) {
                self::record('changed', $profile, $fields, $was[$campaign]);
            }
        }
        foreach (array_diff_key($was, $now) as $profile) {
            self::record('removed', $profile, array(), $profile);
        }
    }

    /**
     * Profiles by campaign.
     *
     * @param array<int,array<string,string>> $profiles Profiles.
     * @return array<string,array<string,string>>
     */
    private static function by_campaign(array $profiles) {
        $out = array();
        foreach ($profiles as $profile) {
            $out[$profile['campaign']] = $profile;
        }
        return $out;
    }

    /**
     * What changed in a profile: platform, url (its address), page.
     *
     * @param array<string,string> $was Before.
     * @param array<string,string> $now After.
     * @return string[]
     */
    private static function changed_fields(array $was, array $now) {
        $out = array();
        foreach (array('platform', 'url', 'page') as $field) {
            if ($was[$field] !== $now[$field]) {
                $out[] = $field;
            }
        }
        return $out;
    }

    /**
     * Record one profile's change: old and new are its platform, address
     * and page; meta its name, action, platform, campaign and the fields
     * that changed.
     *
     * @param string                    $action  added, changed or removed.
     * @param array<string,string>      $profile The profile (as it is now, or was when removed).
     * @param string[]                  $fields  Fields that changed.
     * @param array<string,string>|null $before  The profile before; null when added.
     */
    private static function record($action, array $profile, array $fields, $before) {
        SEOProAnalytics_Changes::record(self::CHANGE, array(
            'path'        => $profile['page'],
            'object_type' => 'business_profile',
            'old'         => $before ? self::summary($before) : '',
            'new'         => $action === 'removed' ? '' : self::summary($profile),
            'meta'        => array(
                'name'     => $profile['name'],
                'action'   => $action,
                'platform' => $profile['platform'],
                'campaign' => $profile['campaign'],
                'fields'   => $fields,
            ),
        ));
    }

    /**
     * A profile in one line: platform | address | page.
     *
     * @param array<string,string> $profile Profile.
     * @return string
     */
    private static function summary(array $profile) {
        return implode(' | ', array($profile['platform'], $profile['url'], $profile['page']));
    }

    // ------------------------------------------------------------------
    // Settings → Data.

    /**
     * Register the panel's hooks (admin requests only).
     */
    public static function admin_init() {
        add_action('seoproanalytics_setting_panel', array(__CLASS__, 'panel'), 10, 1);
        add_action('seoproanalytics_admin_enqueue', array(__CLASS__, 'enqueue'));
    }

    /**
     * Draw each listed profile's tagged link at the top of the Business
     * profiles panel (seoproanalytics_setting_panel).
     *
     * @param string $key Setting key whose panel is drawn.
     */
    public static function panel($key) {
        if ($key !== self::KEY) {
            return;
        }
        $profiles  = self::listed();
        $platforms = self::platforms();
        echo '<div class="seoproan-panel-note seoproan-profiles">';
        if (!$profiles) {
            echo '<p>' . esc_html__('List each profile below. Its tagged website link shows here after the page is reloaded.', 'seoproanalytics') . '</p></div>';
            return;
        }
        echo '<p>' . esc_html__('Paste each link as the profile\'s website. Visits from it show in the Business profiles channel and, by campaign, on the profile\'s own row with its conversions and revenue.', 'seoproanalytics') . '</p>';
        foreach ($profiles as $i => $profile) {
            $id = 'seoproan-profile-link-' . $i;
            ?>
            <div class="seoproan-profile">
                <p>
                    <label for="<?php echo esc_attr($id); ?>"><strong><?php echo esc_html($profile['name']); ?></strong></label>
                    <?php
                    /* translators: 1: platform, such as Google Business Profile, 2: page of this site, 3: campaign */
                    echo esc_html(sprintf(__('%1$s · page %2$s · campaign %3$s', 'seoproanalytics'), $platforms[$profile['platform']], $profile['page'], $profile['campaign']));
                    if ($profile['url'] !== '') {
                        echo ' · <a href="' . esc_url($profile['url']) . '" target="_blank" rel="noopener noreferrer">' . esc_html__('Profile', 'seoproanalytics') . '<span class="screen-reader-text"> ' . esc_html__('(opens in a new tab)', 'seoproanalytics') . '</span></a>';
                    }
                    ?>
                </p>
                <input type="text" class="large-text code" id="<?php echo esc_attr($id); ?>" value="<?php echo esc_attr($profile['link']); ?>" readonly />
                <div class="seoproan-profile__actions">
                    <button type="button" class="button" data-seoproan-copy="<?php echo esc_attr($id); ?>"><?php esc_html_e('Copy', 'seoproanalytics'); ?></button>
                    <a class="button" href="<?php echo esc_url($profile['link']); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e('Open in a new window', 'seoproanalytics'); ?></a>
                </div>
            </div>
            <?php
        }
        echo '</div>';
    }

    /**
     * The panel's spacing, its Copy buttons and the profile rows (the
     * list as four fields a profile, admin/js/seoproanalytics-business-profiles.js);
     * once, for whichever settings tab is drawn first (the card can also
     * show in search results).
     */
    public static function enqueue() {
        static $done = false;
        if ($done) {
            return;
        }
        $done    = true;
        $file    = 'admin/js/seoproanalytics-business-profiles.js';
        $version = file_exists(SEOPROANALYTICS_DIR . $file) ? (string) filemtime(SEOPROANALYTICS_DIR . $file) : SEOPROANALYTICS_VERSION;
        wp_enqueue_script('seoproanalytics-business-profiles', SEOPROANALYTICS_URL . $file, array('jquery', 'seoproanalytics-admin', 'wp-i18n', 'wp-a11y'), $version, true);
        wp_set_script_translations('seoproanalytics-business-profiles', 'seoproanalytics');
        wp_add_inline_script('seoproanalytics-business-profiles', 'window.seoproanBusinessProfiles=' . wp_json_encode(array(
            'key'       => self::LIST_KEY,
            'max'       => self::MAX,
            'platforms' => self::platforms(),
            'aliases'   => self::ALIASES,
        )) . ';', 'before');
        wp_add_inline_style('seoproanalytics-admin', '.seoproan-profiles{display:grid;gap:16px}.seoproan-profiles p{margin:0}.seoproan-profile{display:grid;gap:8px}.seoproan-profile__actions{display:flex;flex-wrap:wrap;gap:8px}'
            . '.seoproan-repeater{display:grid;gap:12px;margin-bottom:4px}.seoproan-repeater p{margin:0}.seoproan-repeater__rows{display:grid;gap:12px}.seoproan-repeater__rows[hidden],.seoproan-repeater [hidden]{display:none}'
            . '.seoproan-repeater__row{display:grid;gap:8px;min-width:0;margin:0;padding:12px;border:1px solid var(--seoproan-border,#dcdcde);border-radius:4px}.seoproan-repeater__row legend{float:left;width:100%;padding:0;font-weight:600}'
            . '.seoproan-repeater__fields{display:grid;gap:8px 12px;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));align-items:start}.seoproan-repeater__field{display:grid;gap:4px;min-width:0}'
            . '.seoproan-repeater__field input,.seoproan-repeater__field select{width:100%;max-width:none;margin:0}.seoproan-repeater__remove{justify-self:start}.seoproan-repeater__actions{display:flex;flex-wrap:wrap;gap:8px 16px;align-items:center}');
    }
}

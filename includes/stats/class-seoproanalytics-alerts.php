<?php
/**
 * Collection health, checked only by cron, admin and an explicit CLI run.
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

final class SEOProAnalytics_Alerts {
    const OPTION = 'seoproanalytics_alerts';
    const ADMIN_OPTION = 'seoproanalytics_alerts_checked';
    const KINDS = array('hits_stopped', 'jobs_late', 'summaries_behind', 'collector_broken', 'source_failing', 'email_failing');

    /**
     * Open and recently resolved alerts, without running checks or sending.
     *
     * @return array
     */
    public static function open() {
        $state = get_option(self::OPTION, array());
        $out = array();
        foreach (is_array($state) ? $state : array() as $key => $alert) {
            if (is_array($alert) && (empty($alert['resolved']) || $alert['resolved'] > time() - 7 * DAY_IN_SECONDS)) {
                $out[] = array('key' => $alert['key'] ?? $key) + $alert;
            }
        }
        return $out;
    }

    /**
     * Serialize state and delivery on this site's existing database connection.
     * An abandoned connection releases its lock, with no stale lock option.
     *
     * @param bool $admin Apply the hourly admin-check throttle under the lock.
     */
    public static function run($admin = false) {
        global $wpdb;
        $lock = 'seoproan_alerts_' . hash('sha256', DB_NAME . ':' . $wpdb->options);
        $lock = substr($lock, 0, 64);
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- connection-scoped advisory lock, not cached data.
        if ((int) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 0)', $lock)) !== 1) {
            return;
        }
        try {
            wp_cache_delete('notoptions', 'options');
            if ($admin) {
                // A concurrent admin request may have checked while this one waited.
                wp_cache_delete(self::ADMIN_OPTION, 'options');
                if ((int) get_option(self::ADMIN_OPTION, 0) > time() - HOUR_IN_SECONDS) {
                    return;
                }
                update_option(self::ADMIN_OPTION, time(), false);
            }
            wp_cache_delete(self::OPTION, 'options');
            self::check_locked();
        } finally {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- release this connection's advisory lock.
            $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock));
        }
    }

    /** Merge successful checks only: an unavailable signal cannot resolve an alert. */
    private static function check_locked() {
        $now = time();
        $state = get_option(self::OPTION, array());
        $state = is_array($state) ? $state : array();
        foreach (self::KINDS as $kind) {
            try {
                $current = self::condition($kind);
            } catch (\Throwable $error) {
                continue; // Keep prior state; never email arbitrary exception text.
            }
            if ($current === null) {
                continue;
            }
            self::merge($state, $current, $kind, $now);
        }
        foreach ($state as $key => $alert) {
            if (is_array($alert) && !empty($alert['resolved']) && $alert['resolved'] <= $now - 7 * DAY_IN_SECONDS) {
                unset($state[$key]);
            }
        }
        self::deliver($state, $now);
        update_option(self::OPTION, $state, false);
    }

    /**
     * Merge one successfully read signal, preserving unchanged incidents.
     *
     * @param array  $state Stored state.
     * @param array  $current Current incidents.
     * @param string $kind Signal kind.
     * @param int    $now Unix time.
     */
    private static function merge(array &$state, array $current, $kind, $now) {
        foreach ($state as $key => &$alert) {
            if (is_array($alert) && $alert['kind'] === $kind && !isset($current[$key]) && empty($alert['resolved'])) {
                $alert['resolved'] = $now;
            }
        }
        unset($alert);
        foreach ($current as $key => $alert) {
            $old = $state[$key] ?? array();
            if (!empty($old['resolved']) && !empty($old['sent']) && empty($old['resolved_sent'])) {
                // A recurrence must not erase a recovery waiting for the hourly
                // allowance or a successful transport. Keep its original key.
                $state[$key . ':resolved:' . $old['resolved']] = array('key' => $key) + $old;
            }
            $state[$key] = self::incident($alert, $old, $kind, $now);
        }
    }

    /**
     * Update an unchanged incident or start a new one after recovery/error change.
     *
     * @param array  $alert Current signal.
     * @param array  $old Previous incident, if any.
     * @param string $kind Signal kind.
     * @param int    $now Unix time.
     * @return array
     */
    private static function incident(array $alert, array $old, $kind, $now) {
        $same = $old && empty($old['resolved']) && ($old['fingerprint'] ?? '') === ($alert['fingerprint'] ?? '');
        return $alert + array(
            'kind' => $kind,
            'since' => $same ? $old['since'] : ($alert['observed'] ?? $now),
            'sent' => $same ? $old['sent'] : 0,
            'resolved' => 0,
            'resolved_sent' => 0,
        );
    }

    /**
     * A signal returns keyed alerts, [] when healthy, null when unknown.
     *
     * @param string $kind Signal name.
     * @return array|null
     */
    private static function condition($kind) {
        switch ($kind) {
            case 'hits_stopped':
                return self::hits();
            case 'jobs_late':
                $late = SEOProAnalytics_Collection::jobs_late();
                return $late === null || $late > SEOProAnalytics_Collection::JOBS_MISSED ? array($kind => array(
                    'message' => __('Scheduled statistics jobs are not running on time.', 'seoproanalytics'),
                    'action' => __('Check WordPress scheduled jobs and your server cron configuration.', 'seoproanalytics'),
                    'delay' => HOUR_IN_SECONDS,
                )) : array();
            case 'summaries_behind':
                // The early admin check can precede a new subsite's table setup.
                if (!SEOProAnalytics_Schema::is_current()) {
                    return null;
                }
                require_once __DIR__ . '/class-seoproanalytics-rollup.php';
                $through = SEOProAnalytics_Rollup::through();
                $behind = $through !== '' ? $through < wp_date('Y-m-d', time() - 2 * DAY_IN_SECONDS) : SEOProAnalytics_Rollup::due() !== null;
                return $behind ? array($kind => array(
                    'message' => __('Daily statistics summaries are behind.', 'seoproanalytics'),
                    'action' => __('Check scheduled jobs and run wp seoproanalytics doctor to inspect daily summaries.', 'seoproanalytics'),
                    'delay' => 0,
                )) : array();
            case 'collector_broken':
                $dir = SEOProAnalytics_Collection::dir();
                return !is_dir($dir) || !wp_is_writable($dir) || !is_file($dir . '/config.php') ? array($kind => array(
                    'message' => __('The statistics collector folder or configuration is unavailable.', 'seoproanalytics'),
                    'action' => __('Check the collector folder permissions with wp seoproanalytics doctor, then open wp-admin to refresh the configuration.', 'seoproanalytics'),
                    'delay' => HOUR_IN_SECONDS,
                )) : array();
            case 'source_failing':
                return self::sources();
            case 'email_failing':
                return self::report_emails();
            default:
                return null;
        }
    }

    /**
     * Seven calendar days ending at the last summary, including quiet days.
     *
     * @return array|null
     */
    private static function hits() {
        global $wpdb;
        if (!SEOProAnalytics_Statistics::enabled()) {
            return array();
        }
        $processed = get_option(SEOProAnalytics_Collection::PROCESS_OPTION, array());
        if ((int) ($processed['last'] ?? 0) > time() - DAY_IN_SECONDS) {
            return array();
        }
        require_once __DIR__ . '/class-seoproanalytics-rollup.php';
        $through = SEOProAnalytics_Rollup::through();
        if ($through === '' || !SEOProAnalytics_Schema::is_current()) {
            return null;
        }
        $from = (new DateTimeImmutable($through))->modify('-6 days')->format('Y-m-d');
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- bounded daily primary-key range, hourly health check.
        $views = $wpdb->get_var($wpdb->prepare('SELECT SUM(pageviews) FROM %i WHERE day >= %s AND day <= %s AND dim = 0 AND val = 0', SEOProAnalytics_Schema::table('daily'), $from, $through));
        if ($wpdb->last_error !== '') {
            return null;
        }
        return (int) $views >= 140 ? array('hits_stopped' => array(
            'message' => __('No new visits have been processed for at least 24 hours on a previously active site.', 'seoproanalytics'),
            'action' => __('Check the tracker, collector and processing jobs with wp seoproanalytics doctor.', 'seoproanalytics'),
            'delay' => 0,
        )) : array();
    }

    /**
     * Remember the first observation of the same error across repeated retries.
     * Store only its fingerprint, never provider error text or credentials.
     *
     * @return array
     */
    private static function sources() {
        require_once __DIR__ . '/class-seoproanalytics-connections.php';
        $out = array();
        foreach (SEOProAnalytics_Connections::statuses() as $source) {
            if (empty($source['connected']) || empty($source['error'])) {
                continue;
            }
            $key = 'source_failing:' . $source['source'];
            $out[$key] = array(
                /* translators: %s: connected source's name. */
                'message' => sprintf(__('The connected source %s keeps failing.', 'seoproanalytics'), $source['name']),
                'action' => __('Open Settings → Connections and check the source connection and its last error.', 'seoproanalytics'),
                'fingerprint' => hash('sha256', (string) $source['error']),
                'delay' => 3 * DAY_IN_SECONDS,
                'observed' => min(time(), (int) ($source['error_at'] ?? 0) ?: time()),
            );
        }
        return $out;
    }

    /**
     * Shares without the optional scheduled-mail fields simply have no error.
     * Never expose the report, its token or arbitrary mail transport errors.
     *
     * @return array
     */
    private static function report_emails() {
        require_once __DIR__ . '/class-seoproanalytics-shares.php';
        foreach (SEOProAnalytics_Shares::all() as $share) {
            if (!empty($share['email']['last_error'])) {
                return array('email_failing' => array(
                    'message' => __('A scheduled statistics report email failed.', 'seoproanalytics'),
                    'action' => __('Check the report email settings and WordPress mail delivery, then send a test email.', 'seoproanalytics'),
                    'delay' => 0,
                ));
            }
        }
        return array();
    }

    /**
     * Recipients and rejected lines. Only a truly empty setting falls back.
     *
     * @return array{valid:array,invalid:array}
     */
    public static function recipients() {
        $raw = trim((string) SEOProAnalytics_Settings::get('alerts_to'));
        $out = array('valid' => array(), 'invalid' => array());
        foreach (explode("\n", $raw === '' ? (string) get_option('admin_email') : $raw) as $line) {
            $line = trim($line);
            if ($line !== '') {
                $out[is_email($line) ? 'valid' : 'invalid'][] = $line;
            }
        }
        $out['valid'] = array_values(array_unique($out['valid']));
        return $out;
    }

    /**
     * One combined message per hour, including recoveries and weekly reminders.
     * Reserve before calling the transport: failed and partial sends retry hourly.
     *
     * @param array $state Stored alert states, updated on success.
     * @param int   $now Unix time.
     */
    private static function deliver(array &$state, $now) {
        if (!SEOProAnalytics_Settings::get('alerts') || (int) ($state['_last_email'] ?? 0) > $now - HOUR_IN_SECONDS) {
            return;
        }
        $kinds = (array) SEOProAnalytics_Settings::get('alerts_kinds');
        $due = array();
        $blocks = array();
        $all_resolved = true;
        foreach ($state as $key => $alert) {
            if (!is_array($alert) || !in_array($alert['kind'], $kinds, true) || !self::due($alert, $now)) {
                continue;
            }
            $resolved = !empty($alert['resolved']);
            $due[$key] = $resolved;
            $all_resolved = $all_resolved && $resolved;
            $blocks = array_merge($blocks, self::blocks($key, $alert, $resolved));
        }
        $to = self::recipients()['valid'];
        if (!$due || !$to) {
            return;
        }
        $state['_last_email'] = $now;
        update_option(self::OPTION, $state, false);
        require_once __DIR__ . '/class-seoproanalytics-mail.php';
        $result = SEOProAnalytics_Mail::send($to, $all_resolved ? __('SEO Pro Analytics: Resolved', 'seoproanalytics') : __('SEO Pro Analytics: statistics alerts', 'seoproanalytics'), $blocks, array(), 'alert', (string) array_key_first($due));
        if (is_wp_error($result)) {
            return;
        }
        foreach ($due as $key => $resolved) {
            $state[$key][$resolved ? 'resolved_sent' : 'sent'] = $now;
        }
    }

    /**
     * Whether an incident is ready for initial, reminder or recovery email.
     *
     * @param array $alert Stored incident.
     * @param int   $now Unix time.
     * @return bool
     */
    private static function due(array $alert, $now) {
        if (!empty($alert['resolved'])) {
            return !empty($alert['sent']) && empty($alert['resolved_sent']);
        }
        return $now - $alert['since'] >= $alert['delay'] && (!$alert['sent'] || $now - $alert['sent'] >= 7 * DAY_IN_SECONDS);
    }

    /**
     * Safe typed mail content, with the existing scheduler's site commands.
     *
     * @param string $key Incident key.
     * @param array  $alert Stored incident.
     * @param bool   $resolved Whether this is a recovery.
     * @return array
     */
    private static function blocks($key, array $alert, $resolved) {
        $blocks = array(
            array('type' => 'heading', 'text' => ($resolved ? __('Resolved', 'seoproanalytics') . ': ' : '') . $alert['message']),
            /* translators: %s: first observation's date and time. */
            array('type' => 'small', 'text' => sprintf(__('First seen: %s', 'seoproanalytics'), wp_date('Y-m-d H:i:s T', $alert['since']))),
            array('type' => 'text', 'text' => $resolved ? __('Statistics health has recovered.', 'seoproanalytics') : $alert['action']),
        );
        if (!$resolved && $key === 'jobs_late' && SEOProAnalytics_Collection::wp_cron_off()) {
            require_once SEOPROANALYTICS_DIR . 'includes/admin/class-seoproanalytics-schedule-notice.php';
            $blocks[] = array('type' => 'text', 'text' => __('Run one of these server cron commands every five minutes or more often:', 'seoproanalytics') . "\n" . implode("\n", SEOProAnalytics_Schedule_Notice::commands()));
        }
        return $blocks;
    }
}

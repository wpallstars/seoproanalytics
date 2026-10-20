<?php
/**
 * Scheduled shared-report snapshots; no reader quota or visitor-page work.
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

final class SEOProAnalytics_Email_Reports {
    const DAYS = array('daily' => 1, 'weekdays' => 3, 'weekly' => 7, 'monthly' => 31, 'quarterly' => 92);

    /**
     * Validate owner fields, never trusting owner-supplied delivery state.
     *
     * @param mixed $raw Schedule.
     * @param int   $max_days Share boundary.
     * @return array|WP_Error
     */
    public static function valid_schedule($raw, $max_days = 0) {
        if (!is_array($raw)) {
            return self::error(__('Check the email schedule.', 'seoproanalytics'));
        }
        $interval = $raw['interval'] ?? 'weekly';
        $hour = $raw['hour'] ?? 8;
        $intro = $raw['intro'] ?? '';
        $enabled = $raw['enabled'] ?? false;
        $events = $raw['events'] ?? true;
        $notes = $raw['notes'] ?? false;
        if (!in_array($events, array(true, false, 0, 1), true) || !in_array($notes, array(true, false, 0, 1), true)) {
            return self::error(__('Check the changes and notes options.', 'seoproanalytics'));
        }
        if (!is_string($interval) || !isset(self::DAYS[$interval]) || !in_array($enabled, array(true, false, 0, 1), true) || filter_var($hour, FILTER_VALIDATE_INT) === false || $hour < 0 || $hour > 23 || !is_string($intro) || mb_strlen($intro) > 2000) {
            return self::error(__('Choose a valid interval, an hour from 0 to 23 and a message up to 2,000 characters.', 'seoproanalytics'));
        }
        $recipients = self::recipients($raw['recipients'] ?? array());
        if (is_wp_error($recipients)) {
            return $recipients;
        }
        if (count($recipients) > 20 || ($enabled && !$recipients)) {
            return self::error(__('Use one to twenty recipients when emails are enabled.', 'seoproanalytics'));
        }
        if ($enabled && $max_days && $max_days < self::DAYS[$interval]) {
            return self::error(__('The email period must fit inside the days readers can look back.', 'seoproanalytics'));
        }
        return array('enabled' => (bool) $enabled, 'recipients' => $recipients, 'interval' => $interval, 'hour' => (int) $hour, 'intro' => sanitize_textarea_field($intro), 'events' => (bool) $events, 'notes' => (bool) $notes);
    }

    /**
     * Checked, case-insensitively distinct recipients.
     *
     * @param mixed $to Lines or an array.
     * @return array|WP_Error
     */
    private static function recipients($to) {
        if (is_string($to)) {
            $to = preg_split('/\R/', $to) ?: array();
        }
        if (!is_array($to)) {
            return self::error(__('Use up to twenty valid email addresses, one per line.', 'seoproanalytics'));
        }
        $recipients = array();
        foreach ($to as $address) {
            if (!is_string($address) || (trim($address) !== '' && !is_email(trim($address)))) {
                return self::error(__('Use up to twenty valid email addresses, one per line.', 'seoproanalytics'));
            }
            if (trim($address) !== '') {
                $recipients[strtolower(trim($address))] = trim($address);
            }
        }
        return array_values($recipients);
    }

    /**
     * Latest ended period, anchored to its most recent send day, also on
     * missed send days. Calendar arithmetic preserves local DST boundaries.
     *
     * @param string            $interval Interval.
     * @param DateTimeImmutable $now Site-local time.
     * @return array|null
     */
    public static function period($interval, DateTimeImmutable $now) {
        $day = $now->setTime(0, 0);
        switch ($interval) {
            case 'daily':
                $from = $day->modify('-1 day');
                break;
            case 'weekdays':
                if ((int) $day->format('N') >= 6) {
                    $day = $day->modify('last friday');
                }
                $from = $day->modify((int) $day->format('N') === 1 ? '-3 days' : '-1 day');
                break;
            case 'weekly':
                $offset = ((int) $day->format('w') - (int) get_option('start_of_week', 1) + 7) % 7;
                $day = $day->modify('-' . $offset . ' days');
                $from = $day->modify('-7 days');
                break;
            case 'monthly':
                $day = $day->modify('first day of this month');
                $from = $day->modify('-1 month');
                break;
            case 'quarterly':
                $month = 1 + 3 * (int) floor(((int) $day->format('n') - 1) / 3);
                $day = $day->setDate((int) $day->format('Y'), $month, 1);
                $from = $day->modify('-3 months');
                break;
            default:
                return null;
        }
        return array('from' => $from->format('Y-m-d'), 'to' => $day->modify('-1 day')->format('Y-m-d'), 'send_day' => $day->format('Y-m-d'));
    }

    /**
     * Whether this latest period may be claimed.
     *
     * @param array             $email Schedule and state.
     * @param array             $period Period.
     * @param DateTimeImmutable $now Site-local now.
     * @return bool
     */
    public static function due(array $email, array $period, DateTimeImmutable $now) {
        $key = self::key($email, $period);
        return !empty($email['enabled']) && ($email['last_period'] ?? '') !== $key
            && !(($email['failure_period'] ?? '') === $key && ($email['failures'] ?? 0) >= 3)
            && ($period['send_day'] < $now->format('Y-m-d') || (int) $now->format('G') >= $email['hour'])
            && (empty($email['sending']) || ($email['sending']['time'] ?? 0) <= time() - HOUR_IN_SECONDS);
    }

    /** Hourly work, bounded by the existing fifty-share limit. */
    public static function run() {
        require_once __DIR__ . '/class-seoproanalytics-shares.php';
        foreach (SEOProAnalytics_Shares::all() as $id => $share) {
            if (!empty($share['email']['enabled'])) {
                self::send($id, false, '', true);
            }
        }
    }

    /**
     * Send the latest period, claiming before delivery and merging state
     * into the fresh share afterwards. Tests never mutate scheduling state.
     *
     * @param string $id Share ID.
     * @param bool   $test Test to the current owner only.
     * @param string $to Optional CLI test address.
     * @param bool   $scheduled Whether the send hour and enabled switch apply.
     * @return true|WP_Error
     */
    public static function send($id, $test = false, $to = '', $scheduled = false) {
        require_once __DIR__ . '/class-seoproanalytics-shares.php';
        require_once __DIR__ . '/class-seoproanalytics-mail.php';
        $now = new DateTimeImmutable('now', wp_timezone());
        $claim = SEOProAnalytics_Shares::mutate(static function () use ($id, $test, $now, $scheduled) {
            $all = SEOProAnalytics_Shares::all();
            $share = $all[$id] ?? null;
            if (!$share) {
                return self::error(__('Shared report not found.', 'seoproanalytics'));
            }
            $email = $share['email'] ?? array('interval' => 'weekly', 'hour' => 8, 'intro' => '', 'recipients' => array());
            $period = self::period($email['interval'], $now);
            $due = $scheduled ? $email : array_merge($email, array('enabled' => true, 'hour' => 0));
            if (!$period || (!$test && !self::due($due, $period, $now))) {
                return self::error(__('This period is not due or has already been sent.', 'seoproanalytics'));
            }
            require_once __DIR__ . '/class-seoproanalytics-rollup.php';
            if (SEOProAnalytics_Rollup::summary_through() < $period['to']) {
                return self::error(__('Waiting for daily summaries to cover this period.', 'seoproanalytics'));
            }
            $ticket = wp_generate_uuid4();
            if (!$test) {
                $email['sending'] = array('period' => self::key($email, $period), 'time' => time(), 'ticket' => $ticket);
                $all[$id]['email'] = $email;
                update_option(SEOProAnalytics_Shares::OPTION, $all, false);
            }
            $share['email'] = $email;
            return array($share, $period, $ticket);
        });
        if (is_wp_error($claim)) {
            return $claim;
        }
        list($share, $period, $ticket) = $claim;
        $blocks = self::build($share, $period);
        $result = is_wp_error($blocks) ? $blocks : self::deliver($share, $blocks, $test ? array($to ?: wp_get_current_user()->user_email) : $share['email']['recipients']);
        if (is_wp_error($blocks)) {
            self::log_skip($id, $blocks->get_error_message());
        }
        if (!$test) {
            self::finish($id, $share, $period, $ticket, $result);
        }
        return $result;
    }

    /**
     * Record only state from our own claim, retaining concurrent owner edits.
     *
     * @param string        $id Share ID.
     * @param array         $share Claimed share.
     * @param array         $period Period.
     * @param string        $ticket Claim identity.
     * @param true|WP_Error $result Result.
     */
    private static function finish($id, array $share, array $period, $ticket, $result) {
        SEOProAnalytics_Shares::mutate(static function () use ($id, $share, $period, $ticket, $result) {
            $all = SEOProAnalytics_Shares::all();
            if (($all[$id]['email']['sending']['ticket'] ?? '') !== $ticket) {
                return;
            }
            $email = &$all[$id]['email'];
            $key = self::key($share['email'], $period);
            unset($email['sending']);
            if (is_wp_error($result)) {
                $email['failures'] = ($email['failure_period'] ?? '') === $key ? (int) ($email['failures'] ?? 0) + 1 : 1;
                $email['failure_period'] = $key;
                // Never persist arbitrary transport text: it may contain secrets.
                $email['last_error'] = $result->get_error_code() === 'seoproanalytics_email_report' ? $result->get_error_message() : __('Email delivery failed. Check the email log and delivery settings.', 'seoproanalytics');
            } else {
                $email['last_period'] = $key;
                $email['last_sent'] = time();
                $email['last_error'] = '';
                $email['failures'] = 0;
            }
            update_option(SEOProAnalytics_Shares::OPTION, $all, false);
        });
    }

    /**
     * Deliver individually so partial success never resends to successful
     * recipients. The mail transport logs each individual delivery.
     *
     * @param array $share Share.
     * @param array $blocks Content.
     * @param array $to Recipients.
     * @return true|WP_Error
     */
    private static function deliver(array $share, array $blocks, array $to) {
        $accepted = false;
        $result = self::error(__('Every recipient failed.', 'seoproanalytics'));
        foreach ($to as $address) {
            $sent = SEOProAnalytics_Mail::send(array($address), $share['branding']['title'] ?: get_bloginfo('name'), $blocks, $share['branding'], 'report', $share['id']);
            $accepted = $accepted || !is_wp_error($sent);
            $result = $sent;
        }
        return $accepted ? true : $result;
    }

    /**
     * Default intro shared by the message and the editor's placeholder.
     *
     * @param string $interval Interval.
     * @param array  $period Period.
     * @return string
     */
    public static function intro($interval, array $period) {
        $labels = array('daily' => __('daily', 'seoproanalytics'), 'weekdays' => __('weekday', 'seoproanalytics'), 'weekly' => __('weekly', 'seoproanalytics'), 'monthly' => __('monthly', 'seoproanalytics'), 'quarterly' => __('quarterly', 'seoproanalytics'));
        /* translators: 1: interval, 2: site name, 3: first date, 4: last date. */
        return sprintf(__('Here is the %1$s report for %2$s, %3$s–%4$s.', 'seoproanalytics'), $labels[$interval], get_bloginfo('name'), $period['from'], $period['to']);
    }

    /**
     * Build only from the public read pipeline. An unavailable comparison
     * remains unavailable rather than widening the reader's look-back window.
     *
     * @param array $share Stored share.
     * @param array $period Period.
     * @return array|WP_Error
     */
    public static function build(array $share, array $period) {
        if ($share['revoked']) {
            return self::error(__('This shared report has been stopped.', 'seoproanalytics'));
        }
        if ($share['expires'] && $share['expires'] <= time()) {
            return self::error(__('This shared report has expired.', 'seoproanalytics'));
        }
        if (empty($share['token_enc'])) {
            return self::error(__('Make a new link to email it.', 'seoproanalytics'));
        }
        $secrets = SEOProAnalytics_Shares::secrets($share);
        if (!$secrets['url']) {
            return self::error(__('The link cannot be decrypted. Make a new link to email it.', 'seoproanalytics'));
        }
        require_once __DIR__ . '/class-seoproanalytics-api.php';
        // CLI and cron have no rest_api_init until the server is initialized.
        rest_get_server();
        $blocks = array(array('type' => 'text', 'text' => $share['email']['intro'] ?: self::intro($share['email']['interval'], $period)));
        foreach ($share['views'] as $view) {
            $next = self::section($share, $view, $period);
            if (is_wp_error($next)) {
                return $next;
            }
            $blocks = array_merge($blocks, $next);
        }
        $blocks = array_merge($blocks, self::change_blocks($share, $period));
        $blocks[] = array('type' => 'button', 'label' => __('Open the full report', 'seoproanalytics'), 'url' => $secrets['url']);
        if ($secrets['password'] !== '') {
            /* translators: %s: report password, included only with owner opt-in. */
            $blocks[] = array('type' => 'text', 'text' => sprintf(__('Password: %s', 'seoproanalytics'), $secrets['password']));
        }
        $blocks[] = array('type' => 'small', 'text' => __('You get this email because the site’s owner added you to this report’s emails. Reply to them to stop.', 'seoproanalytics'));
        return $blocks;
    }

    /**
     * The newest twenty permitted changes, grouped with period counts.
     * Shared block rendering keeps HTML and plain text on the same rows.
     *
     * @param array $share Share.
     * @param array $period Period.
     * @return array
     */
    private static function change_blocks(array $share, array $period) {
        $events = $share['email']['events'] ?? true;
        $notes = $share['email']['notes'] ?? false;
        if (!$events && !$notes) {
            return array();
        }
        $changes = SEOProAnalytics_API::share_period_changes($share, array('range' => 'custom', 'from' => $period['from'], 'to' => $period['to']), (bool) $notes);
        if ($changes === null || is_wp_error($changes)) {
            return array();
        }
        $changes = array_values(array_filter($changes, static function ($row) use ($events) { return $events || $row['group'] === 'note'; }));
        if (!$changes && !$events) {
            return array();
        }
        $blocks = array(array('type' => 'heading', 'text' => __('What changed on the site', 'seoproanalytics')));
        if (!$changes) {
            $blocks[] = array('type' => 'text', 'text' => __('No changes recorded in this period.', 'seoproanalytics'));
            return $blocks;
        }
        $labels = array('content' => __('Content', 'seoproanalytics'), 'seo' => __('SEO', 'seoproanalytics'), 'product' => __('Products', 'seoproanalytics'), 'site' => __('Site', 'seoproanalytics'), 'search' => __('Search engines', 'seoproanalytics'), 'note' => __('Notes', 'seoproanalytics'));
        $counts = array_count_values(array_column($changes, 'group'));
        $visible = array_slice($changes, 0, 20);
        foreach (SEOProAnalytics_Changes::GROUPS as $group) {
            if (empty($counts[$group])) {
                continue;
            }
            $blocks[] = array('type' => 'heading', 'text' => $labels[$group] . ' (' . number_format_i18n($counts[$group]) . ')');
            $rows = array();
            foreach ($visible as $row) {
                if ($row['group'] === $group) {
                    $rows[] = array(substr($row['t'], 0, 10), $row['label'], trim($row['title'] . ($row['path'] ? ' — ' . $row['path'] : '')));
                }
            }
            if ($rows) {
                $blocks[] = array('type' => 'table', 'header' => array(__('Date', 'seoproanalytics'), __('Change', 'seoproanalytics'), __('Title or page', 'seoproanalytics')), 'rows' => $rows);
            }
        }
        if (count($changes) > 20) {
            /* translators: %s: number of additional changes. */
            $blocks[] = array('type' => 'text', 'text' => sprintf(__('and %s more', 'seoproanalytics'), number_format_i18n(count($changes) - 20)));
            if (in_array('changes', array_column($share['views'], 'view'), true)) {
                $blocks[] = array('type' => 'text', 'text' => __('See all changes in the full report', 'seoproanalytics'));
            }
        }
        return $blocks;
    }

    /**
     * One section's permitted headline figures and small top lists.
     *
     * @param array $share Share.
     * @param array $view Section.
     * @param array $period Period.
     * @return array|WP_Error
     */
    private static function section(array $share, array $view, array $period) {
        $section = $view['view'];
        if (!in_array($section, array('overview', 'goals', 'search'), true) || ($section === 'search' && ($view['engine'] ?? 'google') !== 'google')) {
            return array();
        }
        $report = array('overview' => 'stats', 'goals' => 'goals', 'search' => 'search')[$section];
        $params = array('range' => 'custom', 'from' => $period['from'], 'to' => $period['to'], 'compare' => 'prev', 'limit' => 5, 'engine' => 'google');
        $answer = SEOProAnalytics_API::share_snapshot($share, $section, $report, $params);
        if (is_wp_error($answer) && $share['max_days']) {
            $params['compare'] = 'none';
            $answer = SEOProAnalytics_API::share_snapshot($share, $section, $report, $params);
        }
        if (is_wp_error($answer)) {
            return self::error(__('The email period is outside this report’s available window.', 'seoproanalytics'));
        }
        // Search sources lag behind visitor summaries. Do not present missing
        // or truncated source data as zero or as a complete scheduled period.
        if ($section === 'search' && (($answer['through'] ?? '') < $period['to'] || ($answer['first'] ?? '') > $period['from'])) {
            return array(
                array('type' => 'heading', 'text' => __('Google Search', 'seoproanalytics')),
                array('type' => 'text', 'text' => __('Google Search data for this period is not yet complete. Open the full report for the latest available dates.', 'seoproanalytics')),
            );
        }
        if ($section === 'goals') {
            return self::goal_blocks($answer['goals']);
        }
        $blocks = self::metric_blocks($section, $answer);
        if ($section === 'overview') {
            $lists = self::top_lists($share, $params);
            return is_wp_error($lists) ? $lists : array_merge($blocks, $lists);
        }
        return $blocks;
    }

    /**
     * Highest converting goals, including each goal's relative change.
     *
     * @param array $goals Public goal rows.
     * @return array
     */
    private static function goal_blocks(array $goals) {
        usort($goals, static function ($a, $b) { return $b['completions'] <=> $a['completions']; });
        $rows = array();
        foreach (array_slice($goals, 0, 5) as $goal) {
            $rows[] = self::metric($goal['name'], $goal['completions'], $goal['change']['completions'] ?? null);
        }
        return array(array('type' => 'heading', 'text' => __('Goals', 'seoproanalytics')), array('type' => 'metrics', 'rows' => $rows));
    }

    /**
     * Only the permitted headline metrics, not arbitrary report fields.
     *
     * @param string $section Overview or Search.
     * @param array  $answer Public answer.
     * @return array
     */
    private static function metric_blocks($section, array $answer) {
        $labels = $section === 'overview' ? array('visitors' => __('Visitors', 'seoproanalytics'), 'visits' => __('Visits', 'seoproanalytics'), 'pageviews' => __('Pageviews', 'seoproanalytics'), 'bounce_rate' => __('Bounce rate', 'seoproanalytics'), 'visit_duration' => __('Visit duration (seconds)', 'seoproanalytics')) : array('clicks' => __('Clicks', 'seoproanalytics'), 'impressions' => __('Impressions', 'seoproanalytics'), 'ctr' => __('CTR', 'seoproanalytics'), 'position' => __('Average position', 'seoproanalytics'));
        $rows = array();
        foreach ($labels as $key => $label) {
            $value = ($answer['metrics'] ?? $answer['totals'])[$key] ?? 0;
            $percent = in_array($key, array('bounce_rate', 'ctr'), true);
            $rows[] = self::metric($label, $value, $answer['compare']['change'][$key] ?? null, $percent);
        }
        return array(array('type' => 'heading', 'text' => $section === 'overview' ? __('Visitors', 'seoproanalytics') : __('Google Search', 'seoproanalytics')), array('type' => 'metrics', 'rows' => $rows));
    }

    /**
     * Overview's top pages and channels, with the same locked filters.
     *
     * @param array $share Share.
     * @param array $params Checked period parameters.
     * @return array|WP_Error
     */
    private static function top_lists(array $share, array $params) {
        $blocks = array();
        foreach (array('page' => __('Top pages', 'seoproanalytics'), 'channel' => __('Top channels', 'seoproanalytics')) as $dimension => $title) {
            $data = SEOProAnalytics_API::share_snapshot($share, 'overview', 'breakdown', $params + array('dimension' => $dimension));
            if (is_wp_error($data)) {
                return $data;
            }
            $items = array();
            foreach ($data['rows'] as $row) {
                $items[] = array($row['label'] ?: $row['value'], number_format_i18n($row['visitors']));
            }
            $blocks[] = array('type' => 'heading', 'text' => $title);
            $blocks[] = array('type' => 'table', 'header' => array(__('Name', 'seoproanalytics'), __('Visitors', 'seoproanalytics')), 'rows' => $items);
        }
        return $blocks;
    }

    /**
     * Plain formatted metric with a written direction and relative change.
     *
     * @param string     $label Label.
     * @param float|int  $value Value.
     * @param float|null $change Relative change.
     * @param bool       $percent Ratio value.
     * @return array
     */
    private static function metric($label, $value, $change, $percent = false) {
        return array('label' => $label, 'value' => number_format_i18n($percent ? $value * 100 : $value, $percent || is_float($value) ? 1 : 0) . ($percent ? '%' : ''), 'change' => $change === null ? __('Not available', 'seoproanalytics') : ($change > 0 ? '+' : '') . number_format_i18n($change * 100, 1) . '%', 'direction' => $change === null || $change == 0 ? 'flat' : ($change > 0 ? 'up' : 'down'));
    }

    /**
     * A period identity includes the interval, so changing it starts anew.
     *
     * @param array $email Schedule.
     * @param array $period Period.
     * @return string
     */
    private static function key(array $email, array $period) {
        return $email['interval'] . ':' . $period['from'] . ':' . $period['to'];
    }

    /**
     * Pre-transport failures share the existing bounded, content-free log.
     * Only fixed messages from this builder are passed here.
     *
     * @param string $id Share ID.
     * @param string $reason Safe reason.
     */
    private static function log_skip($id, $reason) {
        $entries = SEOProAnalytics_Mail::log_entries();
        array_unshift($entries, array('t' => time(), 'kind' => 'report', 'ref' => $id, 'recipients' => 0, 'result' => 'failed', 'error' => $reason));
        update_option(SEOProAnalytics_Mail::LOG_OPTION, array_slice($entries, 0, SEOProAnalytics_Mail::LOG_SIZE), false);
    }

    /**
     * Safe report failure.
     *
     * @param string $message Fixed translated reason.
     * @return WP_Error
     */
    private static function error($message) {
        return new WP_Error('seoproanalytics_email_report', $message, array('status' => 400));
    }
}

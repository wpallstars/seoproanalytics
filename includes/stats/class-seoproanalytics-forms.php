<?php
/**
 * Accepted form submissions, never typed field values or submit attempts.
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

final class SEOProAnalytics_Forms {

    const EVENT = 'Form sent';

    /** @var array<string,bool> Accepted entries already buffered in this request. */
    private static $done = array();

    /** Register only hooks, without querying form plugins on visitor pages. */
    public static function init() {
        add_action('init', array(__CLASS__, 'shop_hooks'), 1);
    }

    /** Add only verified success hooks, when recording is enabled. */
    public static function shop_hooks() {
        if (!self::enabled()) {
            return;
        }
        add_action('fluentform/submission_inserted', array(__CLASS__, 'fluentforms'), 10, 3);
        add_action('wpcf7_mail_sent', array(__CLASS__, 'contactform7'), 10, 1);
        add_action('wpforms_process_complete', array(__CLASS__, 'wpforms'), 10, 4);
    }

    /** @return bool Whether accepted forms are recorded. */
    private static function enabled() {
        return SEOProAnalytics_Statistics::collecting() && (bool) SEOProAnalytics_Settings::get('forms');
    }

    /**
     * Fluent Forms also processes some spam entries: read only their status.
     * SubmissionHandlerService::processSubmissionData supplies id, data, form.
     *
     * @param int                 $entry_id  Stored entry ID.
     * @param array<string,mixed> $form_data Submitted values, deliberately unused.
     * @param object              $form      Form definition, not submitted values.
     */
    public static function fluentforms($entry_id, $form_data, $form) { // NOSONAR: the verified hook passes submitted data before the form definition; never read it.
        if (!self::enabled() || !is_object($form) || empty($form->id) || (int) $entry_id <= 0) {
            return;
        }
        $visit = SEOProAnalytics_Purchases::current_visit();
        if (!$visit) {
            return;
        }
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- read-only lookup by the form plugin's submission primary key; no field values selected.
        $status = $wpdb->get_var($wpdb->prepare('SELECT status FROM %i WHERE id = %d AND form_id = %d', $wpdb->prefix . 'fluentform_submissions', (int) $entry_id, (int) $form->id));
        if (!is_string($status) || $status === 'spam') {
            return;
        }
        self::record($visit, 'fluentforms', (int) $form->id, isset($form->title) ? (string) $form->title : '', 'fluentforms:' . (int) $entry_id);
    }

    /**
     * Contact Form 7 calls this only after successful mail delivery.
     * It has no stored entry ID: the submission object identifies this request.
     *
     * @param object $form Contact form definition.
     */
    public static function contactform7($form) {
        if (!self::enabled() || !is_object($form) || !method_exists($form, 'id') || !method_exists($form, 'title')) {
            return;
        }
        $visit = SEOProAnalytics_Purchases::current_visit();
        if ($visit) {
            self::record($visit, 'contactform7', (int) $form->id(), (string) $form->title(), 'contactform7:' . spl_object_hash($form));
        }
    }

    /**
     * WPForms_Process skips process_complete for entries marked as spam.
     * Lite has no stored entry ID, so zero is a request-local form completion.
     *
     * @param array<string,mixed> $fields    Submitted values, deliberately unused.
     * @param array<string,mixed> $entry     Submitted values, deliberately unused.
     * @param array<string,mixed> $form_data Form definition.
     * @param int                 $entry_id Stored entry ID, or zero in Lite.
     */
    public static function wpforms($fields, $entry, $form_data, $entry_id) { // NOSONAR: the verified hook passes field values before the definition; never read them.
        if (!self::enabled() || !is_array($form_data) || empty($form_data['id']) || !empty($form_data['spam_reason'])) {
            return;
        }
        $visit = SEOProAnalytics_Purchases::current_visit();
        if ($visit) {
            $id = (int) $form_data['id'];
            $title = isset($form_data['settings']['form_title']) ? (string) $form_data['settings']['form_title'] : '';
            self::record($visit, 'wpforms', $id, $title, 'wpforms:' . $id . ':' . (int) $entry_id);
        }
    }

    /**
     * Buffer an accepted entry once. Only form-definition metadata is allowed.
     * No entry IDs, field values or revenue go into statistics.
     *
     * @param array<string,mixed> $visit  Counted submitter's visit.
     * @param string              $plugin Adapter name.
     * @param int                 $id     Form ID.
     * @param string              $title  Form title.
     * @param string              $key    Request-local deduplication key.
     */
    private static function record(array $visit, $plugin, $id, $title, $key) {
        if ($id <= 0 || isset(self::$done[$key])) {
            return;
        }
        $props = array(
            'form'    => mb_substr(sanitize_text_field($title), 0, 60),
            'form_id' => $plugin . ':' . $id,
            'plugin'  => $plugin,
        );
        if (SEOProAnalytics_Purchases::record_event($visit, self::EVENT, $props)) {
            self::$done[$key] = true;
        }
    }
}

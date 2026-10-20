<?php
/**
 * Visitor requests use the network values last applied to this site.
 * The starter records them in an autoloaded option on admin, cron and CLI
 * requests; this keeps the tracker and its collector config in step without
 * adding a network-option query to every visitor page.
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

final class SEOProAnalytics_Network_Snapshot {

    /** Register before settings are first read, on multisite only. */
    public static function init() {
        if (is_multisite()) {
            add_filter('pre_site_option_seoproanalytics_network', array(__CLASS__, 'read'));
        }
    }

    /**
     * Keep saving requests on live values. Site AJAX requests, like
     * visitors, use the applied snapshot: the starter does not synchronize
     * sites during AJAX. Network AJAX actions still need live network data.
     *
     * @param mixed $pre Existing short-circuit value.
     * @return mixed
     */
    public static function read($pre) {
        if (false !== $pre || (is_admin() && !wp_doing_ajax()) || wp_doing_cron() || (defined('WP_CLI') && WP_CLI)) {
            return $pre;
        }
        // This selects a data source, not permission: the network handlers
        // separately verify their capability and nonce before any change.
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- no mutation; handlers verify their own nonce.
        $action = isset($_REQUEST['action']) && is_string($_REQUEST['action']) ? sanitize_key(wp_unslash($_REQUEST['action'])) : '';
        if (wp_doing_ajax() && in_array($action, array('seoproanalytics_network_save', 'seoproanalytics_network_apply', 'seoproanalytics_network_copy'), true)) {
            return $pre;
        }
        $options = wp_load_alloptions();
        // A site's first settings start from the network's saved values
        // (SEOProAnalytics_Network_Sites::seed()), whichever request runs the
        // one-off migration: read them live until it has recorded its version.
        $version = isset($options[SEOProAnalytics_Settings::DB_VERSION_OPTION]) ? (int) $options[SEOProAnalytics_Settings::DB_VERSION_OPTION] : 0;
        if ($version < SEOProAnalytics_Settings::DB_VERSION) {
            return $pre;
        }
        $seen = isset($options[SEOProAnalytics_Network_Sites::SEEN]) ? maybe_unserialize($options[SEOProAnalytics_Network_Sites::SEEN]) : array();
        $seen = is_array($seen) ? $seen : array();
        return array(
            'enforced' => isset($seen['enforced']) && is_array($seen['enforced']) ? $seen['enforced'] : array(),
            'version' => (int) ($seen['version'] ?? 0),
        );
    }
}

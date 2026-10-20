<?php
/**
 * Dashboard-only colours and the existing statistics-viewer mode control.
 * The starter's shared theme owns settings, network settings and the header.
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

final class SEOProAnalytics_Dashboard_Theme {

    const ACTION = 'seoproanalytics_dashboard_theme';
    const CSS_FILE = 'admin/css/seoproanalytics-dashboard-theme.css';

    /** Register after the shared theme so its assets are present. */
    public static function init() {
        add_action('admin_enqueue_scripts', array(__CLASS__, 'enqueue'), 21);
        add_action('wp_ajax_' . self::ACTION, array(__CLASS__, 'save'));
        add_filter('wpallstars_admin_theme_participants', array(__CLASS__, 'participants'));
    }

    /**
     * Keep statistics viewers in the shared colour-mode controls.
     *
     * @param mixed $participants Participating plugins.
     * @return array
     */
    public static function participants($participants) {
        $participants = is_array($participants) ? $participants : array();
        if (!is_network_admin() && current_user_can(SEOProAnalytics_API::CAP)) {
            $participants['seoproanalytics'] = true;
        }
        return $participants;
    }

    /**
     * Add the app and modal palette on Statistics only.
     *
     * @param string $hook Current admin screen.
     */
    public static function enqueue($hook) {
        if (SEOProAnalytics_Dashboard::HOOK !== $hook || is_network_admin()) {
            return;
        }
        $time = filemtime(SEOPROANALYTICS_DIR . self::CSS_FILE);
        wp_enqueue_style('seoproanalytics-dashboard-theme', SEOPROANALYTICS_URL . self::CSS_FILE, array('seoproanalytics-theme'), false === $time ? SEOPROANALYTICS_VERSION : (string) $time);
        // Charts listen to the plugin's event, while the shared theme owns
        // the root class. Observe optimistic saves, rollbacks and System
        // changes too, not only the shared event after a successful save.
        wp_add_inline_script('seoproanalytics-theme', '(function(r){var dark=r.classList.contains("seoproan-dark");'
            . 'new MutationObserver(function(){var next=r.classList.contains("seoproan-dark");'
            . 'if(next!==dark){dark=next;document.dispatchEvent(new CustomEvent("seoproan-themechange"));}})'
            . '.observe(r,{attributes:true,attributeFilter:["class"]});'
            . 'requestAnimationFrame(function(){r.classList.add("seoproan-theme-ready");});'
            . '})(document.documentElement);');
        // Settings managers use the shared save handler. Read-only viewers
        // may save only their own preference, not settings or network values.
        if (!current_user_can('manage_options') && current_user_can(SEOProAnalytics_API::CAP)) {
            wp_add_inline_script('seoproanalytics-theme', 'Object.assign(window.seoproanalyticsTheme, ' . wp_json_encode(array(
                'action' => self::ACTION,
                'nonce' => wp_create_nonce(self::ACTION),
            )) . ');', 'before');
        }
    }

    /** Save only this viewer's colour preference, with a dedicated nonce. */
    public static function save() {
        check_ajax_referer(self::ACTION, 'nonce');
        if (!current_user_can(SEOProAnalytics_API::CAP)) {
            wp_send_json_error(array('message' => __('You cannot change this.', 'seoproanalytics')), 403);
        }
        $mode = isset($_POST['mode']) && is_string($_POST['mode']) ? sanitize_key(wp_unslash($_POST['mode'])) : '';
        if (!in_array($mode, SEOProAnalytics_Admin_Theme::MODES, true)) {
            wp_send_json_error(array('message' => __('Unknown colour mode.', 'seoproanalytics')), 400);
        }
        $user_id  = get_current_user_id();
        $previous = array();
        foreach (array(SEOProAnalytics_Admin_Theme::SHARED_META, SEOProAnalytics_Admin_Theme::META) as $key) {
            $previous[$key] = get_user_meta($user_id, $key, false);
        }
        foreach (array_keys($previous) as $key) {
            update_user_meta($user_id, $key, $mode);
            if (get_user_meta($user_id, $key, true) !== $mode) {
                self::restore($user_id, $previous, $mode);
                wp_send_json_error(array('message' => __('The colour mode could not be saved.', 'seoproanalytics')), 500);
            }
        }
        wp_send_json_success(array('mode' => $mode));
    }

    /**
     * Undo a partial save before reporting failure, as the shared save
     * handler does: only this mode's writes, not a newer choice in another tab.
     *
     * @param int                 $user_id  Current user.
     * @param array<string,array> $previous Previous rows, empty for absent keys.
     * @param string              $mode     Mode this save attempted.
     */
    private static function restore($user_id, array $previous, $mode) {
        foreach ($previous as $key => $values) {
            if (!$values) {
                delete_user_meta($user_id, $key, $mode);
            } else {
                update_user_meta($user_id, $key, $values[0], $mode);
            }
        }
    }
}

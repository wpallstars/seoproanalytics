<?php
/**
 * Per-user tracker diagnostics and short-lived automation tokens. No hits leave the browser.
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

final class SEOProAnalytics_Test_Mode {

    const USER_META = 'seoproanalytics_test_mode';
    const PARAM = 'seoproanalytics_test';
    const ACTION = 'seoproanalytics_test_mode';
    const LIFETIME = 900;

    /** @var bool Once accepted, this response must never revert to live collection. */
    private static $active = false;

    /** Register hooks only; ordinary visitors never read user meta. */
    public static function init() {
        add_action('init', array(__CLASS__, 'no_cache'), 0);
        add_action('template_redirect', array(__CLASS__, 'no_cache'), 0);
        add_action('admin_bar_menu', array(__CLASS__, 'admin_bar'), 100);
        add_action('admin_post_' . self::ACTION, array(__CLASS__, 'toggle'));
    }

    /** @return bool Whether this request is explicitly testing. */
    public static function active() {
        if (self::$active) {
            return true;
        }
        // A token grants diagnostics only, not access to statistics or account actions.
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- signed, time-limited read-only diagnostics token.
        $token = isset($_GET[self::PARAM]) && is_string($_GET[self::PARAM]) ? sanitize_text_field(wp_unslash($_GET[self::PARAM])) : '';
        if ($token !== '' && self::valid_token($token)) {
            self::$active = true;
            return true;
        }
        self::$active = is_user_logged_in() && SEOProAnalytics_API::can_read()
            && get_user_meta(get_current_user_id(), self::USER_META, true) === '1';
        return self::$active;
    }

    /** Prevent a test response being stored as a visitor's page. */
    public static function no_cache() {
        if (!self::active()) {
            return;
        }
        if (!defined('DONOTCACHEPAGE')) {
            // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- cache plugins share this established signal.
            define('DONOTCACHEPAGE', true);
        }
        nocache_headers();
        // Do not leak an automation token to destinations or third-party resources.
        if (!headers_sent()) {
            header('Referrer-Policy: no-referrer');
        }
    }

    /**
     * @param WP_Admin_Bar $bar WordPress toolbar.
     */
    public static function admin_bar($bar) {
        if (!SEOProAnalytics_API::can_read()) {
            return;
        }
        $on = get_user_meta(get_current_user_id(), self::USER_META, true) === '1';
        $bar->add_node(array(
            'id' => self::ACTION,
            'title' => $on ? __('Stats test mode: on', 'seoproanalytics') : __('Stats test mode: off', 'seoproanalytics'),
            'href' => wp_nonce_url(add_query_arg(array('action' => self::ACTION, 'on' => $on ? '0' : '1'), admin_url('admin-post.php')), self::ACTION),
        ));
    }

    /** Nonce-checked, capability-checked change to the current user only. */
    public static function toggle() {
        if (!SEOProAnalytics_API::can_read()) {
            wp_die(esc_html__('You cannot view statistics.', 'seoproanalytics'), '', array('response' => 403));
        }
        check_admin_referer(self::ACTION);
        if (isset($_GET['on']) && $_GET['on'] === '1') {
            update_user_meta(get_current_user_id(), self::USER_META, '1');
        } else {
            delete_user_meta(get_current_user_id(), self::USER_META);
        }
        wp_safe_redirect(wp_get_referer() ?: home_url('/'));
        exit;
    }

    /** @return string A site-bound token, valid for fifteen minutes. */
    public static function token() {
        $payload = (time() + self::LIFETIME) . '.' . wp_generate_password(20, false, false);
        return $payload . '.' . self::signature($payload);
    }

    /**
     * @param string $token Untrusted query value.
     * @return bool
     */
    public static function valid_token($token) {
        if (!preg_match('/^([0-9]{10})\.([A-Za-z0-9]{20})\.([a-f0-9]{64})$/D', $token, $parts)) {
            return false;
        }
        $expires = (int) $parts[1];
        return $expires > time() && $expires <= time() + self::LIFETIME
            && hash_equals(self::signature($parts[1] . '.' . $parts[2]), $parts[3]);
    }

    /**
     * @param string $payload Expiry and random nonce.
     * @return string
     */
    private static function signature($payload) {
        return hash_hmac('sha256', get_current_blog_id() . '|' . home_url('/') . '|' . $payload, wp_salt('auth'));
    }
}

<?php
/**
 * Admin fallback health check and notices on the plugin's own screens.
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

final class SEOProAnalytics_Alerts_Notice {
    /** Register only on admin requests; check before missing jobs are rescheduled. */
    public static function init() {
        add_action('admin_init', array(__CLASS__, 'check'), 5);
        add_action('admin_notices', array(__CLASS__, 'notice'));
        add_action('seoproanalytics_setting_panel', array(__CLASS__, 'recipients'));
    }

    /** An admin request still checks when WordPress cron is dead. */
    public static function check() {
        require_once SEOPROANALYTICS_DIR . 'includes/stats/class-seoproanalytics-alerts.php';
        if ((int) get_option(SEOProAnalytics_Alerts::ADMIN_OPTION, 0) > time() - HOUR_IN_SECONDS) {
            return;
        }
        SEOProAnalytics_Alerts::run(true);
    }

    /** Open alerts, without duplicating the existing scheduler notice. */
    public static function notice() {
        global $plugin_page;
        if (!in_array((string) $plugin_page, array(SEOProAnalytics_Setup::MENU_PARENT, SEOProAnalytics_Admin_Manager::PAGE), true) || !current_user_can('manage_options')) {
            return;
        }
        require_once SEOPROANALYTICS_DIR . 'includes/stats/class-seoproanalytics-alerts.php';
        $late = SEOProAnalytics_Collection::jobs_late();
        $schedule_shown = SEOProAnalytics_Collection::wp_cron_off() && $late !== null && $late > SEOProAnalytics_Collection::JOBS_LATE && apply_filters('seoproanalytics_schedule_notice', true, $late);
        foreach (SEOProAnalytics_Alerts::open() as $alert) {
            if ($alert['resolved'] || ($alert['key'] === 'jobs_late' && $schedule_shown)) {
                continue;
            }
            echo '<div class="notice notice-warning"><p><strong>' . esc_html__('SEO Pro Analytics:', 'seoproanalytics') . ' ' . esc_html($alert['message']) . '</strong> ' . esc_html($alert['action']) . '</p></div>';
        }
    }

    /**
     * Invalid saved recipients stay visible, rather than silently disappearing.
     *
     * @param string $key Parent setting.
     */
    public static function recipients($key) {
        if ($key !== 'alerts') {
            return;
        }
        require_once SEOPROANALYTICS_DIR . 'includes/stats/class-seoproanalytics-alerts.php';
        $invalid = SEOProAnalytics_Alerts::recipients()['invalid'];
        if ($invalid) {
            echo '<p class="seoproan-panel-note" id="seoproan-alerts-invalid">' . esc_html__('These invalid email addresses are ignored:', 'seoproanalytics') . ' ' . esc_html(implode(', ', $invalid)) . '</p>';
            // The starter's panel hook precedes its child fields. Move the note
            // under its field once those exist; without JS it remains readable.
            wp_add_inline_script('seoproanalytics-admin', '(function () {
                var field = document.getElementById("seoproan-alerts_to");
                var note = document.getElementById("seoproan-alerts-invalid");
                if (field && note) {
                    field.parentElement.appendChild(note);
                    field.setAttribute("aria-describedby", field.getAttribute("aria-describedby") + " seoproan-alerts-invalid");
                }
            }());');
        }
    }
}

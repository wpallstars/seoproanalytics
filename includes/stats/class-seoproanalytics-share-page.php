<?php
/**
 * A standalone, untracked report shell, without the theme or wp-admin.
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

final class SEOProAnalytics_Share_Page {

    /** Register only hooks; no options or queries on ordinary pages. */
    public static function init() {
        add_action('template_redirect', array(__CLASS__, 'render'), -100);
    }

    /** Print only our dependencies; never run theme head/footer or tracker hooks. */
    public static function render() {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a bearer link, not an administrative action.
        if (!isset($_GET['seoproanalytics_share'])) {
            return;
        }
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- the REST API checks the bearer and optional password.
        $token = is_string($_GET['seoproanalytics_share']) ? sanitize_text_field(wp_unslash($_GET['seoproanalytics_share'])) : '';
        $token = preg_match('/^[a-f0-9]{32}$/D', $token) ? $token : str_repeat('0', 32);
        header('X-Robots-Tag: noindex, nofollow');
        header('Referrer-Policy: no-referrer');
        header('Cache-Control: private, no-store');
        header('Content-Type: text/html; charset=' . get_bloginfo('charset'));
        status_header(200);
        require_once SEOPROANALYTICS_DIR . 'includes/admin/class-seoproanalytics-dashboard.php';
        SEOProAnalytics_Dashboard::enqueue_entry('share', array(
            'locale' => get_locale(), 'timezone' => wp_timezone_string(), 'startOfWeek' => (int) get_option('start_of_week', 1), 'canManage' => false, 'data' => 'live',
        ));
        wp_add_inline_script('seoproanalytics-share', 'window.seoproanalyticsShare = ' . wp_json_encode(array(
            'token' => $token, 'root' => rest_url('seoproanalytics/v1/'),
        )) . ';', 'before');
        ?>
        <!doctype html>
        <html <?php language_attributes(); ?>>
        <head>
            <meta charset="<?php echo esc_attr(get_bloginfo('charset')); ?>">
            <meta name="viewport" content="width=device-width, initial-scale=1">
            <meta name="robots" content="noindex,nofollow">
            <title><?php esc_html_e('Shared report', 'seoproanalytics'); ?></title>
            <?php wp_styles()->do_items(array('seoproanalytics-share')); ?>
        </head>
        <body class="seoproan-share">
            <main id="seoproan-share"><noscript><?php esc_html_e('This report needs JavaScript.', 'seoproanalytics'); ?></noscript></main>
            <?php wp_scripts()->do_items(array('seoproanalytics-share'), 1); ?>
        </body>
        </html>
        <?php
        exit;
    }
}

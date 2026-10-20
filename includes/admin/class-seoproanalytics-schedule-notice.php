<?php
/**
 * When WP-Cron is turned off (DISABLE_WP_CRON) and no server cron job runs
 * WordPress's scheduled jobs, hits wait in the buffer uncounted and the
 * daily jobs wait too. A plugin cannot add the server's cron job (that is
 * the host's control panel or crontab), so SEO Pro Analytics says so on its own
 * screens, with the command for this site, once its minute job is more
 * than 15 minutes late (an error past an hour: Site Health's thresholds).
 * It goes away by itself when the jobs run again.
 *
 * With WP-Cron on, a late job only means nobody opened a page for a while,
 * and this screen's own load starts the jobs, so nothing is shown; a site
 * that cannot reach itself is Site Health's "loopback request" test.
 *
 * The seoproanalytics_schedule_notice filter (false to hide) lets a plugin that
 * shows the same advice for the whole site avoid saying it twice.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 * Additional terms (GPL-3.0 section 7(b)): ATTRIBUTION.txt
 * Provenance: wpas-c8662019b99fb07b. Copies, even of part, keep these lines.
 *
 * @package SEOProAnalytics
 * @since 1.4.2
 */

if (!defined('ABSPATH')) {
    exit;
}

final class SEOProAnalytics_Schedule_Notice {

    /**
     * Register hooks (admin requests only).
     */
    public static function init() {
        add_action('admin_notices', array(__CLASS__, 'notice'));
    }

    /**
     * The notice, on SEO Pro Analytics's statistics and settings screens.
     */
    public static function notice() {
        global $plugin_page;
        $pages = array(SEOProAnalytics_Setup::MENU_PARENT, SEOProAnalytics_Admin_Manager::PAGE);
        if (!in_array((string) $plugin_page, $pages, true) || !current_user_can('manage_options') || !SEOProAnalytics_Collection::wp_cron_off()) {
            return;
        }
        $late = SEOProAnalytics_Collection::jobs_late();
        if (null === $late || $late <= SEOProAnalytics_Collection::JOBS_LATE) {
            return;
        }
        /**
         * Whether to show SEO Pro Analytics's notice about scheduled jobs not running.
         *
         * @param bool $show Show it.
         * @param int  $late How late the minute job is, in seconds.
         */
        if (!apply_filters('seoproanalytics_schedule_notice', true, $late)) {
            return;
        }
        $type = $late > SEOProAnalytics_Collection::JOBS_MISSED ? 'error' : 'warning';
        ?>
        <div class="notice notice-<?php echo esc_attr($type); ?> seoproan-schedule-notice">
            <p><strong><?php esc_html_e('SEO Pro Analytics: new visits are waiting to be counted.', 'seoproanalytics'); ?></strong>
                <?php
                echo esc_html(sprintf(
                    /* translators: 1: DISABLE_WP_CRON, 2: how late, such as "2 hours". */
                    __('WP-Cron is turned off on this site (%1$s in wp-config.php), so a cron job on the server must run WordPress\'s scheduled jobs, and they are %2$s late. Visits are kept and counted when the jobs run; search data imports and daily summaries wait too.', 'seoproanalytics'),
                    'DISABLE_WP_CRON',
                    human_time_diff(time() - $late)
                ));
                ?>
            </p>
            <p><?php esc_html_e('Add a cron job in your hosting control panel that runs every 5 minutes or more often, with one of these commands:', 'seoproanalytics'); ?></p>
            <ul class="ul-disc">
                <?php foreach (self::commands() as $command) : ?>
                    <li><code><?php echo esc_html($command); ?></code></li>
                <?php endforeach; ?>
            </ul>
            <p>
                <?php
                echo esc_html(sprintf(
                    /* translators: %s: the site's wp-cron.php address. */
                    __('Where the host asks for an address to open instead: %s', 'seoproanalytics'),
                    site_url('wp-cron.php')
                ));
                ?>
            </p>
        </div>
        <?php
    }

    /**
     * Commands that run this site's scheduled jobs. PHP on wp-cron.php
     * runs the main site's jobs only, so a network's other sites get the
     * WP-CLI command with their address.
     *
     * @return string[]
     */
    public static function commands() {
        $path     = untrailingslashit(ABSPATH);
        $commands = array();
        if (!is_multisite() || is_main_site()) {
            $commands[] = 'php ' . $path . '/wp-cron.php';
        }
        $commands[] = 'wp cron event run --due-now --path=' . $path . (is_multisite() ? ' --url=' . home_url('/') : '');
        return $commands;
    }
}

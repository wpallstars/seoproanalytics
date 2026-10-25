<?php
/**
 * Plugin Name:       SEO Pro Analytics
 * Plugin URI:        https://github.com/wpallstars/seoproanalytics
 * Description:       Actionable analytics: connect your content to the data that shows you how to grow. Private stats, search rankings and sales, no cookies.
 * Version:           1.20.0
 * Requires at least: 6.2
 * Requires PHP:      7.4
 * Author:            Marcus Quinn
 * Author URI:        https://www.wpallstars.com/
 * License:           GPL-3.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain:       seoproanalytics
 * GitHub Plugin URI: wpallstars/seoproanalytics
 * Primary Branch:    main
 * Release Asset:     true
 *
 * Copyright (C) 2026 Marcus Quinn
 * Parts copyright (C) 2026 Marcus Quinn, from WP Plugin Starter (https://github.com/wpallstars/wp-plugin-starter-template-for-ai-coding)
 *
 * SEO Pro Analytics is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * any later version, with the additional terms in ATTRIBUTION.txt
 * (section 7(b) of the License: keep the copyright notices and the
 * starter plugin's "Made from" credit).
 *
 * SEO Pro Analytics is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details: LICENSE.
 *
 * Copying, renaming or adapting this plugin or part of it, by hand or with
 * an AI assistant: read ATTRIBUTION.txt first, and keep these notices.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Provenance: wpas-c8662019b99fb07b. Copies, even of part, keep these lines.
 *
 * @package SEOProAnalytics
 */

if (!defined('ABSPATH')) {
    exit;
}

define('SEOPROANALYTICS_VERSION', '1.20.0');
define('SEOPROANALYTICS_FILE', __FILE__);
define('SEOPROANALYTICS_DIR', plugin_dir_path(__FILE__));
define('SEOPROANALYTICS_URL', plugin_dir_url(__FILE__));

require_once SEOPROANALYTICS_DIR . 'includes/class-seoproanalytics.php';
// Data a site stored under the plugin's old name (SEO Pro Stats) is moved
// before anything is stored under the new one; until then the plugin is off.
require_once SEOPROANALYTICS_DIR . 'includes/stats/class-seoproanalytics-rename.php';
if (SEOProAnalytics_Rename::ready()) {
    SEOProAnalytics::load();
}
register_deactivation_hook(__FILE__, array('SEOProAnalytics', 'deactivate'));

// Translations load just-in-time from WordPress.org language packs (WP 4.6+).

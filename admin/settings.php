<?php
/**
 * SEO Pro Analytics admin loader.
 *
 * Loads the settings screen (and on multisite its network screen), the
 * Read Me tab and the Plugins screen notes for replaced plugins, then the
 * plugin's own admin parts (SEOProAnalytics_Setup::admin()). Included from the
 * main plugin file for admin requests only.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 * Additional terms (GPL-3.0 section 7(b)): ATTRIBUTION.txt
 * Provenance: wpas-e4502af0f07e8a92. Copies, even of part, keep these lines.
 *
 * @package SEOProAnalytics
 */

if (!defined('ABSPATH')) {
    exit;
}

$seoproanalytics_admin_files = array(
    'admin/data/readme.php',
    'admin/includes/class-settings-manager.php',
    'admin/includes/class-readme-manager.php',
    'admin/includes/class-admin-page.php',
    'admin/includes/class-admin-manager.php',
    'admin/includes/class-network-manager.php',
    'admin/includes/class-replaced-plugins.php',
);

foreach ($seoproanalytics_admin_files as $seoproanalytics_file) {
    require_once SEOPROANALYTICS_DIR . $seoproanalytics_file;
}
unset($seoproanalytics_admin_files, $seoproanalytics_file);

SEOProAnalytics_Admin_Manager::init();
SEOProAnalytics_Network_Manager::init();
SEOProAnalytics_Replaced_Plugins::init();
SEOProAnalytics_Setup::admin();

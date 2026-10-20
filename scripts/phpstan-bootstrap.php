<?php
/**
 * Constants for PHPStan (phpstan.neon.dist). WordPress's functions and
 * classes come from szepeviktor/phpstan-wordpress; this adds the constants
 * WordPress defines while it loads (wp-settings.php, wp-config.php) and
 * those seoproanalytics.php defines.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 * Additional terms (GPL-3.0 section 7(b)): ATTRIBUTION.txt
 * Provenance: wpas-e4502af0f07e8a92. Copies, even of part, keep these lines.
 *
 * @package SEOProAnalytics
 */

define('WPINC', 'wp-includes');
define('COOKIEPATH', '/');
define('SITECOOKIEPATH', '/');
define('DB_NAME', 'wordpress');
define('SEOPROANALYTICS_VERSION', '0.0.0');
define('SEOPROANALYTICS_FILE', dirname(__DIR__) . '/seoproanalytics.php');
define('SEOPROANALYTICS_DIR', dirname(__DIR__) . '/');
define('SEOPROANALYTICS_URL', 'https://example.com/wp-content/plugins/seoproanalytics/');

<?php
/**
 * Fast collector endpoint: receives the tracker's hits without loading
 * WordPress, so a visit costs about a millisecond of PHP.
 *
 * It reads the site's collector config from
 * wp-content/seoproanalytics/site-{s}/config.php (written by WordPress) and
 * hands the request to SEOProAnalytics_Collector, the same code the REST route
 * runs. The tracker uses this file only after WordPress's loopback test
 * found it working; otherwise it posts to the REST route. Design:
 * docs/architecture.md → Collection → Collector.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 * Additional terms (GPL-3.0 section 7(b)): ATTRIBUTION.txt
 * Provenance: wpas-c8662019b99fb07b. Copies, even of part, keep these lines.
 *
 * @package SEOProAnalytics
 * @since 0.2.0
 */

header('Cache-Control: no-store');
header('X-Robots-Tag: noindex');

// Runs without WordPress (no nonces or sanitising functions); each value is checked where it is used.
$seoproanalytics_get    = $_GET; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- public endpoint without WordPress.
$seoproanalytics_server = $_SERVER;
$seoproanalytics_site   = isset($seoproanalytics_get['s']) && is_string($seoproanalytics_get['s']) ? $seoproanalytics_get['s'] : '1';
if (!ctype_digit($seoproanalytics_site) || strlen($seoproanalytics_site) > 9) {
    http_response_code(400);
    exit;
}

// This file is wp-content/plugins/seoproanalytics/collect.php.
$seoproanalytics_dir    = dirname(__DIR__, 2) . '/seoproanalytics/site-' . (int) $seoproanalytics_site;
$seoproanalytics_config = is_file($seoproanalytics_dir . '/config.php') ? include $seoproanalytics_dir . '/config.php' : null; // nosemgrep: php.lang.security.injection.tainted-filename.tainted-filename -- fixed local plugin-relative path; site ID is digit-validated, length-bounded and cast to int. NOSONAR: the file returns the config; include_once returns true if it was loaded before.
if (!is_array($seoproanalytics_config)) {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/includes/stats/class-seoproanalytics-collector.php';

$seoproanalytics_method = isset($seoproanalytics_server['REQUEST_METHOD']) ? (string) $seoproanalytics_server['REQUEST_METHOD'] : '';

// Loopback test from WordPress: GET ?s=1&ping=<random>.
if ($seoproanalytics_method === 'GET' && isset($seoproanalytics_get['ping']) && is_string($seoproanalytics_get['ping'])) {
    header('Content-Type: text/plain; charset=utf-8');
    echo SEOProAnalytics_Collector::ping($seoproanalytics_config, substr($seoproanalytics_get['ping'], 0, 64)); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- a hex HMAC.
    exit;
}

if ($seoproanalytics_method !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit;
}

$seoproanalytics_body = file_get_contents('php://input', false, null, 0, SEOProAnalytics_Collector::MAX_BODY + 1); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- the request body, without WordPress.
http_response_code(SEOProAnalytics_Collector::handle(
    $seoproanalytics_config,
    $seoproanalytics_dir,
    is_string($seoproanalytics_body) ? $seoproanalytics_body : '',
    $seoproanalytics_server,
    time()
));

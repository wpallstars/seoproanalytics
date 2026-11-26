<?php
/**
 * The site's own business details (name, phone number, postcode), read
 * from the active SEO plugin's organisation or local business settings,
 * for checking a Google Business Profile against them
 * (SEOProAnalytics_GBP finding gbp_details).
 *
 * Read from options only, never a page fetch, and only when findings are
 * built (admin, REST, WP-CLI or cron), never on a visitor page. The first
 * plugin with a value for a field wins, in the order Yoast SEO, Rank Math,
 * SEOPress, The SEO Framework. A field no plugin sets is absent: nothing
 * is compared against an empty value.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 * Additional terms (GPL-3.0 section 7(b)): ATTRIBUTION.txt
 * Provenance: wpas-c8662019b99fb07b. Copies, even of part, keep these lines.
 *
 * @package SEOProAnalytics
 * @since 1.40.0
 */

if (!defined('ABSPATH')) {
    exit;
}

final class SEOProAnalytics_Site_Details {

    /** The details compared. */
    const FIELDS = array('name', 'phone', 'postcode');

    /** @var array<string,string>|null Read once per request. */
    private static $cache = null;

    /**
     * The details the SEO plugins hold.
     *
     * @return array<string,string> Field (name, phone, postcode) => value; only fields with a value.
     */
    public static function get() {
        if (self::$cache !== null) {
            return self::$cache;
        }
        $found = array();
        foreach (array(self::from_yoast(), self::from_rank_math(), self::from_seopress(), self::from_framework()) as $source) {
            foreach ($source as $field => $value) {
                if (!is_scalar($value)) {
                    continue;
                }
                $value = trim(wp_strip_all_tags((string) $value));
                if ($value !== '' && !isset($found[$field])) {
                    $found[$field] = $value;
                }
            }
        }
        self::$cache = $found;
        return $found;
    }

    /** Forget the details read (the next get() reads again). */
    public static function reset() {
        self::$cache = null;
    }

    /**
     * Whether a profile value and the site's value are the same detail.
     * Name: case-insensitive, spaces and punctuation collapsed, either
     * containing the other. Phone: the last 9 digits. Postcode: uppercase
     * without spaces.
     *
     * @param string $field Name, phone or postcode.
     * @param string $site  The site's value.
     * @param string $other The profile's value.
     * @return bool
     */
    public static function same($field, $site, $other) {
        if ($field === 'name') {
            $a = self::squash($site);
            $b = self::squash($other);
            return $a === '' || $b === '' || strpos($a, $b) !== false || strpos($b, $a) !== false;
        }
        if ($field === 'phone') {
            $a = substr((string) preg_replace('/\D+/', '', $site), -9);
            $b = substr((string) preg_replace('/\D+/', '', $other), -9);
            return $a === '' || $b === '' || $a === $b;
        }
        $a = strtoupper((string) preg_replace('/\s+/', '', $site));
        $b = strtoupper((string) preg_replace('/\s+/', '', $other));
        return $a === '' || $b === '' || $a === $b;
    }

    /**
     * Lowercase letters and digits only.
     *
     * @param string $value Text.
     * @return string
     */
    private static function squash($value) {
        return (string) preg_replace('/[^\p{L}\p{N}]+/u', '', function_exists('mb_strtolower') ? mb_strtolower($value) : strtolower($value));
    }

    /**
     * An option as an array.
     *
     * @param string $name Option.
     * @return array<string,mixed>
     */
    private static function option($name) {
        $value = get_option($name, array());
        return is_array($value) ? $value : array();
    }

    /**
     * Yoast SEO (and Local SEO's wpseo_local, when present).
     *
     * @return array<string,mixed>
     */
    private static function from_yoast() {
        $titles = self::option('wpseo_titles');
        $local  = self::option('wpseo_local');
        return array(
            'name'     => $titles['company_name'] ?? '',
            'phone'    => $local['location_phone'] ?? '',
            'postcode' => $local['location_zipcode'] ?? '',
        );
    }

    /**
     * Rank Math.
     *
     * @return array<string,mixed>
     */
    private static function from_rank_math() {
        $titles = self::option('rank-math-options-titles');
        $phone  = $titles['phone'] ?? '';
        if (!is_string($phone) || $phone === '') {
            $phone = '';
            foreach ((array) ($titles['phone_numbers'] ?? array()) as $entry) {
                if (is_array($entry) && !empty($entry['number'])) {
                    $phone = (string) $entry['number'];
                    break;
                }
            }
        }
        $address = $titles['local_address'] ?? array();
        return array(
            'name'     => $titles['knowledgegraph_name'] ?? '',
            'phone'    => $phone,
            'postcode' => is_array($address) ? ($address['postalCode'] ?? '') : '',
        );
    }

    /**
     * SEOPress.
     *
     * @return array<string,mixed>
     */
    private static function from_seopress() {
        $social = self::option('seopress_social_option_name');
        return array(
            'name'  => $social['seopress_social_knowledge_name'] ?? '',
            'phone' => $social['seopress_social_knowledge_phone'] ?? '',
        );
    }

    /**
     * The SEO Framework.
     *
     * @return array<string,mixed>
     */
    private static function from_framework() {
        $site = self::option('autodescription-site-settings');
        return array('name' => $site['knowledge_name'] ?? '');
    }
}

<?php
/**
 * Backlink export upserts never change verification state.
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

final class SEOProAnalytics_Backlinks_Store {
    /** Keep the referring page and exact target. @param array<string,mixed> $link Link. @param string $source Provider. @return bool */
    public static function write(array $link, $source) {
        $url = $link['url'];
        $host = strtolower((string) wp_parse_url($url, PHP_URL_HOST));
        $url_id = self::dict_id(SEOProAnalytics_Schema::DICT_URL, $url);
        $host_id = self::dict_id(SEOProAnalytics_Schema::DICT_HOST, $host);
        $path_id = self::dict_id(SEOProAnalytics_Schema::DICT_PATH, $link['path']);
        $anchor_id = self::dict_id(SEOProAnalytics_Schema::DICT_LABEL, self::anchor($link['anchor']));
        if (!$url_id || !$host_id || ($link['path'] !== '' && !$path_id)) {
            return false;
        }
        foreach (array_unique(array(0, $path_id)) as $path) {
            if (!self::put($link, $source, $path, $host_id, $url_id, $anchor_id)) {
                return false;
            }
        }
        return true;
    }

    /**
     * A provider's word that a known link is lost: kept in its facts (when
     * its crawler last saw it, not an exact removal time). The link's own
     * status stays the backlinks check's to decide; unknown links are not
     * added.
     *
     * @param array<string,mixed> $link   Link (url, path).
     * @param string              $source Provider.
     * @param int                 $when   When the provider lost it.
     * @return bool Whether a known link was marked.
     */
    public static function provider_lost(array $link, $source, $when) {
        global $wpdb;
        $path_id = self::dict_id(SEOProAnalytics_Schema::DICT_PATH, $link['path']);
        if (!$path_id) {
            return false;
        }
        $key       = SEOProAnalytics_Dict::hash($link['url'] . "\t" . $path_id);
        $providers = self::providers($key);
        if (!isset($providers[$source]) || !is_array($providers[$source])) {
            return false;
        }
        $providers[$source]['lost'] = (int) $when;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- our table, unique lkey update of provider facts only.
        return $wpdb->query($wpdb->prepare('UPDATE %i SET providers = %s WHERE lkey = UNHEX(%s)', SEOProAnalytics_Schema::table('links'), (string) wp_json_encode($providers), $key)) !== false;
    }

    /**
     * Delete what one source alone found (disconnecting a provider with its
     * data): links no other source or check found go; the others lose its
     * bit and its facts. By the primary key, in chunks.
     *
     * @param string $source One of SEOProAnalytics_Backlinks::FOUND.
     * @return int Rows deleted.
     */
    public static function delete_found($source) {
        global $wpdb;
        $table   = SEOProAnalytics_Schema::table('links');
        $bit     = SEOProAnalytics_Backlinks::FOUND[$source];
        $deleted = 0;
        $last    = 0;
        do {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- explicitly confirmed deletion, our own table by its primary key in chunks.
            $rows = (array) $wpdb->get_results($wpdb->prepare('SELECT id, found, providers FROM %i WHERE id > %d AND found & %d ORDER BY id LIMIT %d', $table, $last, $bit, 500), ARRAY_A);
            foreach ($rows as $row) {
                $last = (int) $row['id'];
                if ((int) $row['found'] === $bit) {
                    // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- our own table by its primary key.
                    $deleted += (int) $wpdb->delete($table, array('id' => $last), array('%d'));
                    continue;
                }
                $providers = json_decode((string) $row['providers'], true);
                $providers = is_array($providers) ? $providers : array();
                unset($providers[$source]);
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- our own table by its primary key.
                $wpdb->update($table, array('found' => (int) $row['found'] & ~$bit, 'providers' => (string) wp_json_encode($providers)), array('id' => $last), array('%d', '%s'), array('%d'));
            }
        } while (count($rows) === 500);
        return $deleted;
    }

    /** Bounded plain anchor text. @param string $text Anchor. @return string */
    private static function anchor($text) {
        $text = wp_strip_all_tags($text);
        return function_exists('mb_substr') ? mb_substr($text, 0, SEOProAnalytics_Backlinks::MAX_ANCHOR) : substr($text, 0, SEOProAnalytics_Backlinks::MAX_ANCHOR);
    }

    /** Existing provider facts by the unique key. @param string $key Key. @return array */
    private static function providers($key) {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- our table, unique lkey lookup.
        $known = $wpdb->get_row($wpdb->prepare('SELECT providers FROM %i WHERE lkey = UNHEX(%s)', SEOProAnalytics_Schema::table('links'), $key), ARRAY_A);
        $providers = is_array($known) ? json_decode((string) $known['providers'], true) : array();
        return is_array($providers) ? $providers : array();
    }

    /** Merge one provider without overwriting another. @param array $providers Known. @param array<string,mixed> $link Link. @param string $source Provider. @param int $path Target. @return array */
    private static function merge(array $providers, array $link, $source, $path) {
        $old = isset($providers[$source]) ? $providers[$source] : array();
        $authority = $link['authority'];
        if ($authority === null) {
            $authority = isset($old['authority']) ? $old['authority'] : null;
        }
        $last = isset($old['last_seen']) ? (int) $old['last_seen'] : 0;
        $providers[$source] = array('authority' => $authority, 'last_seen' => max($link['last'], $last));
        if (!$path && ($link['path'] === '' || !empty($old['candidate']))) {
            $providers[$source]['candidate'] = true;
        }
        return $providers;
    }

    /** Unique-key upsert without lost/checked/misses/status resets. @param array<string,mixed> $link Link. @param string $source Provider. @param int $path Target. @param int $host_id Host. @param int $url_id URL. @param int $anchor_id Anchor. @return bool */
    private static function put(array $link, $source, $path, $host_id, $url_id, $anchor_id) {
        global $wpdb;
        $key = SEOProAnalytics_Dict::hash($link['url'] . "\t" . $path);
        $providers = self::merge(self::providers($key), $link, $source, $path);
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- our table, unique lkey upsert; exports never reset verified loss.
        $ok = $wpdb->query($wpdb->prepare(
            'INSERT INTO %i (lkey, source_host_id, source_url_id, path_id, anchor_id, rel, found, status, first_seen, last_seen, providers) VALUES (UNHEX(%s), %d, %d, %d, %d, %d, %d, %d, %d, %d, %s) ON DUPLICATE KEY UPDATE found = found | VALUES(found), first_seen = IF(first_seen = 0, VALUES(first_seen), IF(VALUES(first_seen) = 0, first_seen, LEAST(first_seen, VALUES(first_seen)))), last_seen = GREATEST(last_seen, VALUES(last_seen)), providers = VALUES(providers)',
            SEOProAnalytics_Schema::table('links'), $key, $host_id, $url_id, $path, $path ? $anchor_id : 0, $path ? $link['rel'] : 0, SEOProAnalytics_Backlinks::FOUND[$source], $path ? SEOProAnalytics_Backlinks::LINK_LIVE : SEOProAnalytics_Backlinks::PAGE_NONE, $link['first'], $link['last'], (string) wp_json_encode($providers)
        ));
        return $ok !== false;
    }

    /** One dictionary id. @param int $kind Kind. @param string $value Text. @return int */
    private static function dict_id($kind, $value) {
        $ids = SEOProAnalytics_Dict::ids($kind, array($value));
        return isset($ids[SEOProAnalytics_Dict::clean($value)]) ? (int) $ids[SEOProAnalytics_Dict::clean($value)] : 0;
    }
}

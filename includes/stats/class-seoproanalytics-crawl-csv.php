<?php
/**
 * Crawl export headers and rows (SEOProAnalytics_Crawl_Import), without
 * storage or remote requests: which file holds pages and which links, the
 * crawler that made it, and each row as the import stages it.
 *
 * Every address loses its query and fragment (they can carry tokens).
 * Pages are kept only on the site's own hosts; link targets anywhere.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 * Additional terms (GPL-3.0 section 7(b)): ATTRIBUTION.txt
 * Provenance: wpas-c8662019b99fb07b. Copies, even of part, keep these lines.
 *
 * @package SEOProAnalytics
 * @since 1.11.0
 */

if (!defined('ABSPATH')) {
    exit;
}

final class SEOProAnalytics_Crawl_CSV {

    /** Server export fields consumed by HEADERS below, excluding page bodies and visitor data. */
    const LIBRECRAWL_FIELDS = array(
        'urls' => array('url', 'status_code', 'title', 'canonical_url', 'robots', 'depth', 'response_time', 'size', 'redirects', 'redirected_to', 'content_type'),
        'links' => array('source_url', 'target_url', 'anchor_text', 'is_internal', 'target_status', 'placement'),
    );

    /**
     * Column names by field, as header() makes them (lower case letters
     * and digits). One place, so a crawler renaming a column is a
     * one-line change. LibreCrawl: url, status_code, title, canonical_url,
     * robots, depth, response_time (ms), size, redirects, redirected_to,
     * content_type; links: source_url, target_url, anchor_text,
     * is_internal, target_status, placement. aidevops site crawler:
     * crawl-data.csv (url or URL, status_code, canonical, meta_robots,
     * crawl_depth, response_time_ms), broken-links.csv (url or Broken URL,
     * status_code, source or Source URL) and redirects.csv (original_url,
     * status_code, redirect_url).
     */
    const HEADERS = array(
        'url'           => array('url', 'address', 'pageurl', 'originalurl', 'requestedurl'),
        'status'        => array('statuscode', 'status', 'httpstatus', 'httpstatuscode', 'responsecode'),
        'title'         => array('title', 'title1', 'pagetitle'),
        'description'   => array('metadescription', 'metadescription1', 'description'),
        'h1'            => array('h1', 'h11'),
        'h1_count'      => array('h1count', 'h11occurrences'),
        'canonical'     => array('canonicalurl', 'canonical', 'canonicallinkelement1', 'canonicallinkelement'),
        'robots'        => array('robots', 'metarobots', 'metarobots1', 'xrobotstag', 'xrobotstag1'),
        'indexability'  => array('indexabilitystatus', 'indexability'),
        'depth'         => array('depth', 'crawldepth', 'level'),
        'ms'            => array('responsetimems', 'responsetime'),
        'bytes'         => array('size', 'filesizebytes', 'sizebytes', 'contentlength', 'filesize'),
        'redirect'      => array('redirectedto', 'redirecturl', 'redirecturi', 'finalurl', 'redirecttarget'),
        'hops'          => array('redirects', 'redirectcount', 'redirectchain', 'numberofredirects', 'hops'),
        'type'          => array('contenttype'),
        'source'        => array('sourceurl', 'source', 'sourcepage', 'fromurl', 'from', 'foundon', 'linkingpage'),
        'target'        => array('targeturl', 'destination', 'destinationurl', 'brokenurl', 'tourl', 'to', 'linkurl', 'href'),
        'target_status' => array('targetstatus', 'destinationstatus', 'statuscode', 'status'),
        'anchor'        => array('anchortext', 'anchor', 'linktext', 'alttext', 'alt'),
        'placement'     => array('placement', 'linkposition', 'position'),
    );

    /** Columns only one crawler writes: format => columns. */
    const SIGNATURES = array(
        'aidevops'   => array('responsetimems', 'crawldepth', 'brokenurl', 'metarobots', 'redirecturl'),
        'librecrawl' => array('canonicalurl', 'targetstatus', 'isinternal', 'targetdomain', 'redirectedto'),
    );

    /** Unknown status (an empty cell): the import looks it up, or leaves the link out. */
    const UNKNOWN = -1;

    /**
     * A status cell for a target the crawler never requested, lower case.
     * LibreCrawl 05a1372de833 exports a link with no status this way
     * (main.py, the links CSV and JSON exports): no answer was observed, so
     * it is UNKNOWN, not a failure.
     */
    const NOT_CRAWLED = 'not crawled';

    /** @var array<string,int>|null The site's own hosts, within a request. */
    private static $hosts = null;

    /**
     * A header as compared: lower case letters and digits (a UTF-8 BOM
     * and spaces go).
     *
     * @param mixed $value Header.
     * @return string
     */
    public static function header($value) {
        return strtolower((string) preg_replace('/[^a-zA-Z0-9]/', '', (string) $value));
    }

    /**
     * What a file holds by its headers: links (a source page and a target
     * column), pages (an address column), or '' when neither.
     *
     * @param string[] $headers Headers, from header().
     * @return string links, pages or ''.
     */
    public static function kind(array $headers) {
        if (array_intersect($headers, self::HEADERS['source']) && (array_intersect($headers, self::HEADERS['target']) || in_array('url', $headers, true))) {
            return 'links';
        }
        return array_intersect($headers, self::HEADERS['url']) ? 'pages' : '';
    }

    /**
     * The crawler that made a file, by the columns only it writes.
     *
     * @param string[] $headers Headers, from header().
     * @return string One of SEOProAnalytics_Crawl_Import::FORMATS.
     */
    public static function detect(array $headers) {
        foreach (self::SIGNATURES as $format => $signature) {
            if (array_intersect($headers, $signature)) {
                return $format;
            }
        }
        return 'csv';
    }

    /**
     * Cells by header(): scalars as text, lists kept (LibreCrawl's JSON
     * redirects), anything else dropped.
     *
     * @param array<string|int,mixed> $row Row.
     * @return array<string,mixed>
     */
    public static function cells(array $row) {
        $out = array();
        foreach ($row as $key => $value) {
            if (is_scalar($value) || $value === null) {
                $out[self::header((string) $key)] = trim((string) $value);
            } elseif (is_array($value)) {
                $out[self::header((string) $key)] = $value;
            }
        }
        return $out;
    }

    /**
     * One page row as staged, or null to leave it out (not on the site, not
     * a web page, no address, or a query variant: queried()).
     *
     * @param array<string,mixed> $row    From cells().
     * @param string              $format One of SEOProAnalytics_Crawl_Import::FORMATS.
     * @return array<int,mixed>|null p, path, status, flags, depth, ms, bytes, hops, redirect target, served metadata.
     */
    public static function page(array $row, $format) {
        $address = self::value($row, 'url');
        $url     = self::url($address);
        if ($url === '' || self::queried($address) || !self::own($url)) {
            return null;
        }
        $type = strtolower(self::value($row, 'type'));
        if ($type !== '' && strpos($type, 'html') === false) {
            return null;
        }
        $status   = self::status(self::value($row, 'status'));
        $redirect = self::url(self::value($row, 'redirect'), $url);
        if ($redirect === $url) {
            // A final address column filled in for every page, redirected or not.
            $redirect = '';
        }
        $hops     = self::hops($row, $status, $redirect);
        return array(
            'p',
            self::path($url),
            max(0, $status),
            self::flags($row, $url, $status, $hops),
            self::number(self::value($row, 'depth'), 65535),
            self::ms($row, $format),
            self::number(self::value($row, 'bytes'), 4294967295),
            $hops,
            $redirect !== '' ? self::target($redirect) : '',
            array(
                'title' => self::present($row, 'title') ? self::value($row, 'title') : null,
                'description' => self::present($row, 'description') ? self::value($row, 'description') : null,
                'h1' => self::headings($row),
            ),
        );
    }

    /**
     * Whether an export observes a field (an absent column is not empty text).
     *
     * @param array<string,mixed> $row Cells.
     * @param string $field Field.
     * @return bool
     */
    private static function present(array $row, $field) {
        return (bool) array_intersect(self::HEADERS[$field], array_keys($row));
    }

    /**
     * Observed H1s, capped at 255; null when not exported. LibreCrawl at
     * 05a1372de833 keeps only the first H1 as a string (seo_extractor.py),
     * so it can establish presence, not detect additional headings.
     * Lists from JSON/CSV and generic H1-1, H1-2 columns also work.
     *
     * @param array<string,mixed> $row Cells.
     * @return int|null
     */
    private static function headings(array $row) {
        if (self::present($row, 'h1_count')) {
            return self::number(self::value($row, 'h1_count'), 255);
        }
        $count = null;
        foreach ($row as $name => $value) {
            if ($name !== 'h1' && !preg_match('/^h1[1-9][0-9]*$/', $name)) {
                continue;
            }
            $count = $count === null ? 0 : $count;
            if (is_string($value) && substr($value, 0, 1) === '[') {
                $list = json_decode($value, true);
                $value = is_array($list) ? $list : $value;
            }
            $count += is_array($value) ? count(array_filter($value, array('SEOProAnalytics_Crawl_Import', 'filled'))) : (is_string($value) && trim($value) !== '' ? 1 : 0);
        }
        return $count === null ? null : min(255, $count);
    }

    /**
     * One link row as staged, or null to leave it out (from another site or
     * a query variant of a page, to an address that is not a web address).
     * Successful internal links are staged for counting, then discarded
     * before permanent storage.
     *
     * @param array<string,mixed>                 $row  From cells().
     * @param array<string,array{0:int,1:int}>    $seen Pages of this import that did not answer 2xx straight away: path => status, hops.
     * @param bool                                $all  Whether this import has pages: a page on the site it does not name answered 2xx.
     * @return array{0:string,1:string,2:string,3:int,4:int,5:int,6:int,7:string}|null l, from path, target, internal, status, hops, placement, anchor.
     */
    public static function link(array $row, array $seen, $all) {
        $source = self::value($row, 'source');
        $from   = self::url($source);
        if ($from === '' || self::queried($source) || !self::own($from)) {
            return null;
        }
        $to = self::value($row, 'target');
        $to = self::url($to !== '' ? $to : self::value($row, 'url'), $from);
        if ($to === '') {
            return null;
        }
        $internal = self::own($to);
        $status   = self::status(self::value($row, 'target_status'));
        $redirect = self::url(self::value($row, 'redirect'), $to);
        $hops     = self::hops($row, $status, $redirect);
        $path     = $internal ? self::path($to) : '';
        if ($internal && isset($seen[$path])) {
            // The page's own row knows its redirects (crawlers give a link the status at the end).
            list($page_status, $hops) = $seen[$path];
            $status = $status === self::UNKNOWN || $hops > 0 ? $page_status : $status;
        } elseif ($internal && $status === self::UNKNOWN && $all) {
            $status = 200;
        }
        // An external row needs an HTTP observation; never invent a failed
        // answer from missing status, even if it names a redirect address.
        if ($status === self::UNKNOWN && !$internal) {
            return null;
        }
        return array('l', self::path($from), self::target($to), $internal ? 1 : 0, $status, $hops, self::placement(self::value($row, 'placement')), substr(self::value($row, 'anchor'), 0, 120), $redirect !== '' ? self::target($redirect) : '');
    }

    // ------------------------------------------------------------------
    // Fields.

    /**
     * One field's text by its column names.
     *
     * @param array<string,mixed> $row   From cells().
     * @param string              $field Key of HEADERS.
     * @return string
     */
    private static function value(array $row, $field) {
        foreach (self::HEADERS[$field] as $name) {
            if (isset($row[$name]) && is_string($row[$name])) {
                return $row[$name];
            }
        }
        return '';
    }

    /**
     * An HTTP status: the number, 0 for no answer (a crawler's error or
     * time-out text), UNKNOWN for an empty cell or a target the crawler
     * never requested (NOT_CRAWLED).
     *
     * @param string $value Cell.
     * @return int
     */
    private static function status($value) {
        if ($value === '' || strtolower($value) === self::NOT_CRAWLED) {
            return self::UNKNOWN;
        }
        if (preg_match('/^\d{1,3}/', $value, $m)) {
            return (int) $m[0];
        }
        return 0;
    }

    /**
     * Redirects followed: LibreCrawl's list (or its text in a CSV), a
     * count, or one for an address answering 3xx itself.
     *
     * @param array<string,mixed> $row      From cells().
     * @param int                 $status   Status.
     * @param string              $redirect Where it led, or ''.
     * @return int 0–255.
     */
    private static function hops(array $row, $status, $redirect) {
        $hops = 0;
        foreach (self::HEADERS['hops'] as $name) {
            if (!isset($row[$name])) {
                continue;
            }
            $value = $row[$name];
            if (is_array($value)) {
                $hops = count($value);
            } elseif (is_numeric($value)) {
                $hops = (int) $value;
            } elseif (is_string($value) && $value !== '' && $value[0] === '[') {
                $hops = preg_match_all('/[\'"]url[\'"]\s*:/', $value);
            }
            break;
        }
        if ($hops === 0 && $status >= 300 && $status < 400) {
            $hops = 1;
        }
        if ($hops === 0 && $redirect !== '' && $status !== 0) {
            $hops = 1;
        }
        return max(0, min(255, (int) $hops));
    }

    /**
     * Served flags (SEOProAnalytics_Crawl::FLAGS): noindex and nofollow from
     * the robots rule, a canonical address elsewhere, and no title on a
     * page that answered 2xx (when the export has titles).
     *
     * @param array<string,mixed> $row    From cells().
     * @param string              $url    The page's address.
     * @param int                 $status Status.
     * @param int                 $hops   Redirects.
     * @return int
     */
    private static function flags(array $row, $url, $status, $hops) {
        $flags  = 0;
        $robots = strtolower(self::value($row, 'robots'));
        if (preg_match('/\b(?:noindex|none)\b/', $robots) || preg_match('/\bnoindex\b/i', self::value($row, 'indexability'))) {
            $flags |= SEOProAnalytics_Crawl::FLAGS['noindex'];
        }
        if (preg_match('/\b(?:nofollow|none)\b/', $robots)) {
            $flags |= SEOProAnalytics_Crawl::FLAGS['nofollow'];
        }
        $canonical = self::url(self::value($row, 'canonical'), $url);
        if ($canonical !== '' && self::same($canonical) !== self::same($url)) {
            $flags |= SEOProAnalytics_Crawl::FLAGS['canonical'];
        }
        $titled = false;
        foreach (self::HEADERS['title'] as $name) {
            $titled = $titled || array_key_exists($name, $row);
        }
        if ($titled && $status >= 200 && $status < 300 && $hops === 0 && self::value($row, 'title') === '') {
            $flags |= SEOProAnalytics_Crawl::FLAGS['title_missing'];
        }
        return $flags;
    }

    /**
     * Response time in milliseconds. LibreCrawl and aidevops give
     * milliseconds; another CSV's response time under 30 is taken as
     * seconds.
     *
     * @param array<string,mixed> $row    From cells().
     * @param string              $format Format.
     * @return int
     */
    private static function ms(array $row, $format) {
        if (isset($row['responsetimems']) && is_string($row['responsetimems'])) {
            return self::number($row['responsetimems'], 4294967295);
        }
        $value = isset($row['responsetime']) && is_string($row['responsetime']) ? $row['responsetime'] : '';
        if (!is_numeric($value)) {
            return 0;
        }
        $ms = (float) $value;
        if ($format === 'csv' && $ms < 30) {
            $ms *= 1000;
        }
        return (int) max(0, min(4294967295, round($ms)));
    }

    /**
     * A whole number from 0 to most.
     *
     * @param string $value Cell.
     * @param int    $most  Most.
     * @return int
     */
    private static function number($value, $most) {
        return is_numeric($value) ? (int) max(0, min($most, round((float) $value))) : 0;
    }

    /**
     * Where a link sits: SEOProAnalytics_Crawl::PLACEMENTS code.
     *
     * @param string $value Cell.
     * @return int
     */
    private static function placement($value) {
        $value = strtolower($value);
        $words = array('nav' => 1, 'menu' => 1, 'header' => 1, 'footer' => 2, 'body' => 3, 'content' => 3, 'main' => 3, 'text' => 3, 'image' => 4, 'img' => 4, 'sidebar' => 5, 'aside' => 5, 'widget' => 5);
        foreach ($words as $word => $code) {
            if (strpos($value, $word) !== false) {
                return $code;
            }
        }
        return 0;
    }

    // ------------------------------------------------------------------
    // Addresses.

    /**
     * An http or https address without credentials, query or fragment,
     * relative ones resolved against base; '' when it is not one.
     *
     * @param string $url  Address.
     * @param string $base Address it is relative to, or ''.
     * @return string
     */
    public static function url($url, $base = '') {
        $url = trim((string) $url);
        if ($url === '') {
            return '';
        }
        if ($base !== '' && strpos($url, '//') === 0) {
            $url = (string) wp_parse_url($base, PHP_URL_SCHEME) . ':' . $url;
        } elseif ($base !== '' && $url[0] === '/') {
            $port = wp_parse_url($base, PHP_URL_PORT);
            $url  = (string) wp_parse_url($base, PHP_URL_SCHEME) . '://' . (string) wp_parse_url($base, PHP_URL_HOST) . ($port ? ':' . (int) $port : '') . $url;
        }
        $url = (string) strtok($url, '?#');
        $parts = wp_parse_url($url);
        if (!is_array($parts) || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])) {
            return '';
        }
        $scheme = strtolower(isset($parts['scheme']) ? $parts['scheme'] : '');
        if (!in_array($scheme, array('http', 'https'), true)) {
            return '';
        }
        return $scheme . '://' . strtolower($parts['host']) . (isset($parts['port']) ? ':' . (int) $parts['port'] : '') . (isset($parts['path']) && $parts['path'] !== '' ? $parts['path'] : '/');
    }

    /**
     * Whether an export's address asks for a query (?replytocom=, ?page_id=,
     * ?share=). Pages are stored by path, so a query variant would replace
     * its page's row and add its links to the page's own: WordPress serves
     * ?replytocom= with noindex and redirects ?page_id= to the permalink,
     * which made sound pages look noindexed or redirected.
     *
     * @param string $url Address as the export gives it.
     * @return bool
     */
    private static function queried($url) {
        $url = (string) $url;
        return strpos(substr($url, 0, strcspn($url, '#')), '?') !== false;
    }

    /**
     * Whether an address is on the site's own hosts.
     *
     * @param string $url Address from url().
     * @return bool
     */
    public static function own($url) {
        if (self::$hosts === null) {
            require_once __DIR__ . '/class-seoproanalytics-collection.php';
            self::$hosts = array_flip(SEOProAnalytics_Collection::hosts());
        }
        return isset(self::$hosts[strtolower((string) wp_parse_url($url, PHP_URL_HOST))]);
    }

    /**
     * An address's path, as the site's pages are stored (DICT_PATH).
     *
     * @param string $url Address from url().
     * @return string
     */
    public static function path($url) {
        return substr('/' . ltrim(rawurldecode((string) wp_parse_url($url, PHP_URL_PATH)), '/'), 0, 300);
    }

    /**
     * An address as clicks name it (DICT_TARGET): a path on the site,
     * origin and path elsewhere, the path as the browser gives it
     * (percent-encoded, so the same destination meets its clicks).
     *
     * @param string $url Address from url().
     * @return string
     */
    public static function target($url) {
        $path = (string) wp_parse_url($url, PHP_URL_PATH);
        $path = (string) preg_replace_callback('/[^\x21-\x7E]/', static function ($m) {
            return rawurlencode($m[0]);
        }, $path === '' ? '/' : $path);
        if (self::own($url)) {
            return substr($path, 0, 300);
        }
        $port = wp_parse_url($url, PHP_URL_PORT);
        return substr(strtolower((string) wp_parse_url($url, PHP_URL_SCHEME)) . '://' . strtolower((string) wp_parse_url($url, PHP_URL_HOST)) . ($port ? ':' . (int) $port : '') . $path, 0, 300);
    }

    /**
     * An address compared for canonical: host without www, path without
     * its last slash.
     *
     * @param string $url Address from url().
     * @return string
     */
    private static function same($url) {
        $host = (string) preg_replace('/^www\./', '', strtolower((string) wp_parse_url($url, PHP_URL_HOST)));
        return $host . untrailingslashit(rawurldecode((string) wp_parse_url($url, PHP_URL_PATH)));
    }
}

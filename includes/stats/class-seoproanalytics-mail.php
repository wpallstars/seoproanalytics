<?php
/**
 * Branded, image-optional multipart mail through WordPress. Loaded only by
 * explicit callers. No retries: a partial send is a failure for the caller.
 * The diagnostic option uses read-modify-write; simultaneous sends may lose
 * a log entry. It never contains addresses or message content.
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

final class SEOProAnalytics_Mail {
    const LOG_OPTION = 'seoproanalytics_mail_log';
    const MAX_RECIPIENTS = 20;
    const LOG_SIZE = 50;

    /**
     * Send one message per address; all addresses are checked before sending.
     * Blocks: heading/text/small use text; metrics use rows of label, value,
     * change, direction; table uses header and rows; list uses items of text
     * and optional url; button uses label and url. Strings are always plain.
     *
     * @param array  $to       Recipients, never persisted.
     * @param string $subject  Subject.
     * @param array  $blocks   Typed content blocks.
     * @param array  $branding Shared-report branding overrides.
     * @param string $kind     test, report or alert.
     * @param string $ref      Share ID or alert key (not message content).
     * @return true|WP_Error
     */
    public static function send(array $to, $subject, array $blocks, array $branding = array(), $kind = 'test', $ref = '') {
        $count = count($to);
        if (!$count || $count > self::MAX_RECIPIENTS || count(array_filter($to, static function ($address) {
            return is_string($address) && is_email($address);
        })) !== $count) {
            $error = new WP_Error('seoproanalytics_mail_recipients', __('Use one to twenty valid email addresses.', 'seoproanalytics'), array('status' => 400));
            self::log($kind, $ref, $count, $error);
            return $error;
        }
        require_once __DIR__ . '/class-seoproanalytics-shares.php';
        $brand = SEOProAnalytics_Shares::branding($branding);
        $brand['title'] = $brand['title'] ?: get_bloginfo('name');
        $brand['logo'] = $brand['logo'] ?: (int) get_theme_mod('custom_logo', 0);
        $html = self::html($blocks, $brand);
        $text = self::text($blocks, $brand);
        $failed = 0;
        $reason = '';
        foreach (array_unique($to) as $address) {
            $failure = null;
            $instance = null;
            $previous = '';
            $alt = static function ($mailer) use ($text, &$alt, &$instance, &$previous) {
                $instance = $mailer;
                $previous = $mailer->AltBody;
                $mailer->AltBody = $text;
                remove_action('phpmailer_init', $alt, PHP_INT_MAX);
            };
            $capture = static function ($error) use (&$failure) {
                $failure = $error;
            };
            add_action('phpmailer_init', $alt, PHP_INT_MAX);
            add_action('wp_mail_failed', $capture);
            try {
                $sent = wp_mail($address, $subject, $html, array('Content-Type: text/html; charset=UTF-8'));
            } catch (\Exception $exception) {
                $sent = false;
                $failure = new WP_Error('seoproanalytics_mail_exception', $exception->getMessage());
            } finally {
                remove_action('phpmailer_init', $alt, PHP_INT_MAX);
                remove_action('wp_mail_failed', $capture);
                if ($instance) {
                    $instance->AltBody = $previous;
                }
            }
            if (!$sent || is_wp_error($failure)) {
                ++$failed;
                $reason = is_wp_error($failure) ? $failure->get_error_message() : __('WordPress did not accept the email.', 'seoproanalytics');
            }
        }
        /* translators: 1: failed recipients, 2: delivery error. */
        $result = $failed ? new WP_Error('seoproanalytics_mail_failed', sprintf(__('%1$d recipients failed: %2$s', 'seoproanalytics'), $failed, $reason), array('status' => 500)) : true;
        self::log($kind, $ref, count(array_unique($to)), $result);
        return $result;
    }

    /**
     * Sample content is deliberately not live statistics.
     *
     * @param string $to Optional address; defaults to the signed-in owner.
     * @return true|WP_Error
     */
    public static function test($to = '') {
        return self::send(array($to ?: wp_get_current_user()->user_email), __('SEO Pro Analytics test email', 'seoproanalytics'), array(
            array('type' => 'heading', 'text' => __('Your site at a glance', 'seoproanalytics')),
            array('type' => 'text', 'text' => __("This is a test email with sample figures, not live statistics.\n\nYour WordPress mail settings handle delivery.", 'seoproanalytics')),
            array('type' => 'metrics', 'rows' => array(array('label' => __('Visits', 'seoproanalytics'), 'value' => '1,234', 'change' => '+12%', 'direction' => 'up'), array('label' => __('Clicks', 'seoproanalytics'), 'value' => '321', 'change' => '-3%', 'direction' => 'down'), array('label' => __('Conversions', 'seoproanalytics'), 'value' => '20', 'change' => '0%', 'direction' => 'flat'))),
            array('type' => 'table', 'header' => array(__('Page', 'seoproanalytics'), __('Visits', 'seoproanalytics')), 'rows' => array(array('/', '123'), array('/sample/', '45'))),
            array('type' => 'list', 'items' => array(array('text' => __('Sample site link', 'seoproanalytics'), 'url' => home_url('/')), array('text' => __('No tracking pixels or tracked links.', 'seoproanalytics')))),
            array('type' => 'button', 'label' => __('Visit site', 'seoproanalytics'), 'url' => home_url('/')),
            array('type' => 'small', 'text' => __('Sent only because an administrator requested a test.', 'seoproanalytics')),
        ));
    }

    /**
     * Newest-first diagnostic entries.
     *
     * @return array
     */
    public static function log_entries() {
        $entries = get_option(self::LOG_OPTION, array());
        return is_array($entries) ? array_slice($entries, 0, self::LOG_SIZE) : array();
    }

    /**
     * Mail plugins may include recipients, credentials or content in errors.
     * Return the reason to the caller, but persist only a generic safe category.
     *
     * @param string        $kind   Message kind.
     * @param string        $ref    Opaque reference.
     * @param int           $count  Recipients.
     * @param true|WP_Error $result Outcome.
     */
    private static function log($kind, $ref, $count, $result) {
        $entries = self::log_entries();
        array_unshift($entries, array(
            't' => time(),
            'kind' => in_array($kind, array('test', 'report', 'alert'), true) ? $kind : 'test',
            'ref' => preg_match('/^[a-zA-Z0-9_-]{1,100}$/D', $ref) ? $ref : '',
            'recipients' => $count,
            'result' => is_wp_error($result) ? 'failed' : 'sent',
            'error' => self::safe_error($result),
        ));
        update_option(self::LOG_OPTION, array_slice($entries, 0, self::LOG_SIZE), false);
    }

    /**
     * A diagnostic reason without arbitrary mail-plugin text or error data.
     * The full error is returned immediately, never persisted.
     *
     * @param true|WP_Error $result Send result.
     * @return string
     */
    private static function safe_error($result) {
        if (!is_wp_error($result)) {
            return '';
        }
        if ($result->get_error_code() === 'seoproanalytics_mail_recipients') {
            return __('Use one to twenty valid email addresses.', 'seoproanalytics');
        }
        $message = $result->get_error_message();
        foreach (array('authenticate' => 'SMTP authentication failed.', 'connect' => 'Mail transport connection failed.', 'WordPress did not accept' => 'WordPress did not accept the email.') as $needle => $reason) {
            if (stripos($message, $needle) !== false) {
                return $reason;
            }
        }
        return __('Mail transport failed. Check delivery settings; the test response contains the full error.', 'seoproanalytics');
    }

    /**
     * Plain-text block content, also used for non-link HTML blocks.
     *
     * @param array $block One typed block.
     * @return string
     */
    private static function block_text(array $block) {
        switch ($block['type'] ?? '') {
            case 'heading':
            case 'text':
            case 'small':
                return (string) ($block['text'] ?? '');
            case 'button':
                return ($block['label'] ?? '') . ': ' . esc_url_raw($block['url'] ?? '', array('http', 'https'));
            case 'metrics':
                $lines = array();
                foreach ($block['rows'] ?? array() as $row) {
                    $direction = in_array($row['direction'] ?? '', array('up', 'down', 'flat'), true) ? $row['direction'] : 'flat';
                    $lines[] = ($row['label'] ?? '') . ': ' . ($row['value'] ?? '') . ' (' . ($row['change'] ?? '') . ', ' . $direction . ')';
                }
                return implode("\n", $lines);
            case 'table':
                $lines = array(implode(' | ', $block['header'] ?? array()));
                foreach ($block['rows'] ?? array() as $row) {
                    $lines[] = implode(' | ', $row);
                }
                return implode("\n", $lines);
            case 'list':
                $lines = array();
                foreach ($block['items'] ?? array() as $item) {
                    $url = esc_url_raw($item['url'] ?? '', array('http', 'https'));
                    $lines[] = '- ' . ($item['text'] ?? '') . ($url ? ' (' . $url . ')' : '');
                }
                return implode("\n", $lines);
            default:
                return '';
        }
    }

    /**
     * Plain-text alternative from the exact same blocks and branding.
     *
     * @param array $blocks Content.
     * @param array $brand  Checked branding.
     * @return string
     */
    private static function text(array $blocks, array $brand) {
        $parts = array($brand['title']);
        foreach ($blocks as $block) {
            $parts[] = self::block_text($block);
        }
        $parts[] = self::footer($brand);
        return implode("\n\n", array_filter($parts));
    }

    /**
     * Footer is useful even when images are blocked.
     *
     * @param array $brand Checked branding.
     * @return string
     */
    private static function footer(array $brand) {
        return implode("\n", array_filter(array($brand['agency'], $brand['website'], $brand['byline'], $brand['credit'] ? 'SEO Pro Analytics' : '')));
    }

    /**
     * Table-based, light, inline-styled layout with no remote assets.
     *
     * @param array $blocks Content.
     * @param array $brand  Checked branding.
     * @return string
     */
    private static function html(array $blocks, array $brand) {
        $html = '<!doctype html><html><head><meta charset="UTF-8"></head><body style="margin:0;background:#f0f0f1;color:#1d2327;font:16px Arial,sans-serif"><table role="presentation" width="100%" cellspacing="0" cellpadding="0"><tr><td align="center" style="padding:24px 0"><table role="presentation" width="600" cellspacing="0" cellpadding="0" style="width:100%;max-width:600px;background:#ffffff;border-top:4px solid ' . esc_attr($brand['accent']) . '"><tr><td style="padding:24px;overflow-wrap:anywhere">';
        foreach (array('logo', 'agency_logo') as $key) {
            $url = SEOProAnalytics_Shares::local_logo((int) $brand[$key]);
            if ($url) {
                $html .= '<p><img src="' . esc_url($url, array('http', 'https')) . '" alt="' . esc_attr($key === 'logo' ? $brand['title'] : $brand['agency']) . '" width="120" style="max-width:120px;height:auto"></p>';
            }
        }
        $html .= '<h1 style="font-size:24px;margin:0 0 24px">' . esc_html($brand['title']) . '</h1>';
        foreach ($blocks as $block) {
            $html .= self::block_html($block, $brand['accent']);
        }
        return $html . '<p style="font-size:12px;color:#50575e;margin:24px 0 0">' . nl2br(esc_html(self::footer($brand))) . '</p></td></tr></table></td></tr></table></body></html>';
    }

    /**
     * Escape every caller-controlled string at its output context.
     *
     * @param array  $block  Typed content.
     * @param string $accent Checked hex color.
     * @return string
     */
    private static function block_html(array $block, $accent) {
        $type = $block['type'] ?? '';
        if ($type === 'heading') {
            return '<h2 style="font-size:20px;margin:24px 0 12px">' . esc_html($block['text'] ?? '') . '</h2>';
        }
        if ($type === 'button') {
            return '<p style="margin:24px 0"><a style="display:inline-block;padding:12px 16px;border:2px solid ' . esc_attr($accent) . ';color:#1d2327;background:#ffffff" href="' . esc_url($block['url'] ?? '', array('http', 'https')) . '">' . esc_html($block['label'] ?? '') . '</a></p>';
        }
        if ($type === 'list') {
            $html = '<ul style="padding-left:24px;margin:16px 0">';
            foreach ($block['items'] ?? array() as $item) {
                $text = esc_html($item['text'] ?? '');
                $url = esc_url($item['url'] ?? '', array('http', 'https'));
                $html .= '<li style="margin:8px 0">' . ($url ? '<a style="color:#2271b1" href="' . $url . '">' . $text . '</a>' : $text) . '</li>';
            }
            return $html . '</ul>';
        }
        if ($type === 'table' || $type === 'metrics') {
            return self::table_html($block);
        }
        $style = $type === 'small' ? 'font-size:12px;color:#50575e' : 'font-size:16px;line-height:1.5';
        $html = '';
        foreach (preg_split('/\R\s*\R/', self::block_text($block)) ?: array() as $paragraph) {
            $html .= '<p style="margin:16px 0;' . esc_attr($style) . '">' . nl2br(esc_html($paragraph)) . '</p>';
        }
        return $html;
    }

    /**
     * Data tables and metrics; direction is written, not conveyed by color.
     *
     * @param array $block Table or metrics block.
     * @return string
     */
    private static function table_html(array $block) {
        $html = '<table width="100%" cellspacing="0" cellpadding="8" style="border-collapse:collapse;margin:16px 0;text-align:left">';
        if (!empty($block['header'])) {
            $html .= '<tr>';
            foreach ($block['header'] as $cell) {
                $html .= '<th scope="col" style="border-bottom:1px solid #dcdcde">' . esc_html($cell) . '</th>';
            }
            $html .= '</tr>';
        }
        foreach ($block['rows'] ?? array() as $row) {
            if ($block['type'] === 'metrics') {
                $direction = in_array($row['direction'] ?? '', array('up', 'down', 'flat'), true) ? $row['direction'] : 'flat';
                $row = array($row['label'] ?? '', $row['value'] ?? '', ($row['change'] ?? '') . ' (' . $direction . ')');
            }
            $html .= '<tr>';
            foreach ($row as $cell) {
                $html .= '<td style="border-bottom:1px solid #dcdcde">' . esc_html($cell) . '</td>';
            }
            $html .= '</tr>';
        }
        return $html . '</table>';
    }
}

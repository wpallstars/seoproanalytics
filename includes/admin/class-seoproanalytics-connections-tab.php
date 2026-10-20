<?php
/**
 * Settings → Connections: connect outside data sources (Bing Webmaster
 * Tools, and those the SEO Pro Analytics Connect add-on registers), see how far
 * each import is, import now, undo an import, and disconnect. The add-on's
 * connections not registered show as "(subscription needed)" cards linking
 * to its page. The screen is drawn here from each source's status (an
 * add-on's adapter with render_card() draws its own card's body); its
 * buttons call the REST routes (SEOProAnalytics_API, /connections) through
 * admin/js/seoproanalytics-connections.js. Credentials are never printed.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 * Additional terms (GPL-3.0 section 7(b)): ATTRIBUTION.txt
 * Provenance: wpas-c8662019b99fb07b. Copies, even of part, keep these lines.
 *
 * @package SEOProAnalytics
 * @since 0.8.0
 */

if (!defined('ABSPATH')) {
    exit;
}

final class SEOProAnalytics_Connections_Tab {

    /** Tab slug. */
    const TAB = 'connections';

    /** The tab's script. */
    const JS_FILE = 'admin/js/seoproanalytics-connections.js';

    /** The connections class's file, loaded only where needed. */
    const CONNECTIONS_FILE = 'includes/stats/class-seoproanalytics-connections.php';

    /** Property field's id suffix, shared by its input and label. */
    private const PROPERTY_SUFFIX = '-property';

    /** Bing's setup pages, as Microsoft's own documentation links them. */
    const LINKS = array(
        'bing'     => 'https://www.bing.com/webmasters/',
        'bing_api' => 'https://learn.microsoft.com/en-us/bingwebmaster/getting-access',
    );

    /**
     * Register the tab and its script (admin requests only).
     */
    public static function init() {
        add_filter('seoproanalytics_admin_tabs', array(__CLASS__, 'tabs'));
        add_action('seoproanalytics_admin_enqueue', array(__CLASS__, 'enqueue'));
    }

    /**
     * Add the tab after Data.
     *
     * @param array<string,array<string,mixed>> $tabs Tabs.
     * @return array<string,array<string,mixed>>
     */
    public static function tabs($tabs) {
        $tab = array(
            'label'      => __('Connections', 'seoproanalytics'),
            'group'      => 'settings',
            'capability' => 'manage_options',
            // Drawn with the other settings tabs, so switching to it is
            // instant: it reads only stored statuses, and its script
            // listens on the document.
            'preload'    => true,
            'render'     => array(__CLASS__, 'render'),
        );
        $out = array();
        foreach ((array) $tabs as $slug => $value) {
            $out[$slug] = $value;
            if ($slug === 'data') {
                $out[self::TAB] = $tab;
            }
        }
        if (!isset($out[self::TAB])) {
            $out[self::TAB] = $tab;
        }
        return $out;
    }

    /**
     * Enqueue the tab's script when the tab is drawn on the page (shown,
     * or ready to switch to).
     *
     * @param string $tab A tab drawn on the page.
     */
    public static function enqueue($tab) {
        if ($tab !== self::TAB) {
            return;
        }
        $version = file_exists(SEOPROANALYTICS_DIR . self::JS_FILE) ? (string) filemtime(SEOPROANALYTICS_DIR . self::JS_FILE) : SEOPROANALYTICS_VERSION;
        wp_enqueue_script('seoproanalytics-connections', SEOPROANALYTICS_URL . self::JS_FILE, array('seoproanalytics-admin', 'wp-api-fetch', 'wp-i18n', 'wp-a11y'), $version, true);
        wp_set_script_translations('seoproanalytics-connections', 'seoproanalytics');
        wp_add_inline_style('seoproanalytics-admin', '.seoproan-connection{padding:16px 20px;min-width:0}.seoproan-connection h3{margin:0 0 4px;font-size:14px}.seoproan-connection__steps{margin:8px 0 12px 1.5em}.seoproan-connection__steps li{margin-bottom:8px;max-width:72em}.seoproan-connection textarea{width:100%;font-family:monospace;font-size:12px}.seoproan-connection .form-table th{width:180px;padding-block:8px}.seoproan-connection .form-table td{padding-block:8px}.seoproan-connection__actions{display:flex;flex-wrap:wrap;gap:8px;align-items:center;margin-top:12px}.seoproan-connection__message:empty{display:none}.seoproan-connection code{overflow-wrap:anywhere}.seoproan-connection__scroll{margin-top:12px;overflow-x:auto}.seoproan-connection details{margin-top:12px}');
    }

    /**
     * Draw the tab.
     */
    public static function render() {
        require_once SEOPROANALYTICS_DIR . self::CONNECTIONS_FILE;
        require_once SEOPROANALYTICS_DIR . 'includes/stats/class-seoproanalytics-search-import.php';
        // Puts back the hourly job if it went missing (a cron reset).
        SEOProAnalytics_Search_Import::schedule();
        ?>
        <div class="seoproan-section">
            <div class="seoproan-section__intro">
                <h2 class="seoproan-section__title"><?php esc_html_e('Connections', 'seoproanalytics'); ?></h2>
                <p class="seoproan-section__desc"><?php esc_html_e('Add data from services you use to the statistics. Each stays off until you connect it. The site then asks the service from WP-Cron, never while a visitor loads a page, and stores what it gets in its own tables. Keys are stored encrypted and never shown again.', 'seoproanalytics'); ?></p>
            </div>
            <div class="seoproan-cards">
                <?php
                // IndexNow first: free, no account, and on with one switch.
                self::render_indexnow();
                foreach (SEOProAnalytics_Connections::statuses() as $status) {
                    self::render_source($status);
                }
                foreach (SEOProAnalytics_Connections::placeholders() as $source => $addon) {
                    self::render_placeholder($source, $addon);
                }
                ?>
            </div>
        </div>
        <?php
    }

    /**
     * A connection the SEO Pro Analytics Connect add-on adds, while it is not
     * registered: its name, what it adds, and where to get it. No form, and
     * no request to any service.
     *
     * @param string                                     $source Source key.
     * @param array{name:string,kind:string,what:string} $addon  SEOProAnalytics_Connections::placeholders() entry.
     */
    private static function render_placeholder($source, array $addon) {
        $kept = SEOProAnalytics_Connections::kept($source);
        // A kept connection's card takes the Disconnect button (seoproanalytics-connections.js).
        $attrs = $kept ? ' data-seoproan-connection="' . esc_attr($source) . '"' : '';
        ?>
        <section class="seoproan-card seoproan-connection" id="<?php echo esc_attr('seoproan-connection-' . $source); ?>" data-seoproan-placeholder="<?php echo esc_attr($source); ?>"<?php echo $attrs; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above. ?>>
            <h3>
                <?php echo esc_html($addon['name']); ?>
                <span class="seoproan-badge"><?php esc_html_e('(subscription needed)', 'seoproanalytics'); ?></span>
            </h3>
            <?php if ($addon['what'] !== '') : ?>
                <p class="seoproan-setting__desc"><?php echo esc_html($addon['what']); ?></p>
            <?php endif; ?>
            <p><?php esc_html_e('Comes with the SEO Pro Analytics Connect add-on. Your own statistics, Bing Webmaster Tools and IndexNow stay free.', 'seoproanalytics'); ?></p>
            <?php if ($kept) : ?>
                <div class="seoproan-connection__message" data-seoproan-message role="alert"></div>
                <p><?php esc_html_e('Its connection is still saved here, encrypted, but nothing is read until the add-on is active. Disconnect to forget the saved key.', 'seoproanalytics'); ?></p>
            <?php endif; ?>
            <div class="seoproan-connection__actions">
                <a class="button" href="<?php echo esc_url(SEOProAnalytics_Connections::connect_url()); ?>" target="_blank" rel="noopener"><?php esc_html_e('Get SEO Pro Analytics Connect', 'seoproanalytics'); ?><span class="screen-reader-text"> <?php esc_html_e('(opens in a new tab)', 'seoproanalytics'); ?></span></a>
                <?php if ($kept && $source === 'search-console') : ?>
                    <label><input type="checkbox" data-seoproan-field="delete_data"> <?php esc_html_e('Delete imported search data', 'seoproanalytics'); ?></label>
                <?php endif; ?>
                <?php if ($kept) : ?>
                    <button type="button" class="button button-link-delete" data-seoproan-action="disconnect"><?php esc_html_e('Disconnect', 'seoproanalytics'); ?></button>
                <?php endif; ?>
            </div>
        </section>
        <?php
    }

    /** Draw the stored notification status; no service request. */
    private static function render_indexnow() {
        require_once SEOPROANALYTICS_DIR . 'includes/stats/class-seoproanalytics-indexnow.php';
        $status = SEOProAnalytics_IndexNow::status();
        ?>
        <section class="seoproan-card seoproan-connection" id="seoproan-connection-indexnow">
            <h3><?php esc_html_e('IndexNow', 'seoproanalytics'); ?></h3>
            <p><?php esc_html_e('Changed page addresses are sent to api.indexnow.org and shared by participating engines, including Bing, not Google. Received does not mean indexed.', 'seoproanalytics'); ?></p>
            <p><a href="<?php echo esc_url(SEOProAnalytics_Admin_Manager::tab_url('data')); ?>"><?php echo esc_html($status['enabled'] ? __('On — change under Settings → Data', 'seoproanalytics') : __('Off — enable under Settings → Data', 'seoproanalytics')); ?></a></p>
            <p><?php echo esc_html(sprintf(/* translators: %d: queued page count. */ __('Queued pages: %d', 'seoproanalytics'), $status['queued'])); ?></p>
            <?php if ($status['key_url']) : ?>
                <p><a href="<?php echo esc_url($status['key_url']); ?>"><?php esc_html_e('Public verification key', 'seoproanalytics'); ?></a></p>
            <?php endif; ?>
            <?php if ($status['last']) : ?>
                <p><?php echo esc_html(sprintf(/* translators: 1: date, 2: page count, 3: HTTP code, 4: what the code means. */ __('Last submission: %1$s · %2$d pages · HTTP %3$d: %4$s', 'seoproanalytics'), wp_date('Y-m-d H:i', $status['last']['time']), $status['last']['count'], $status['last']['code'], $status['meaning'])); ?></p>
            <?php endif; ?>
            <?php if ($status['key_check']) : ?>
                <p><?php echo esc_html(sprintf(/* translators: 1: date, 2: result. */ __('Key address checked: %1$s · %2$s', 'seoproanalytics'), wp_date('Y-m-d H:i', $status['key_check']['time']), $status['key_check']['ok'] ? __('reachable', 'seoproanalytics') : __('not reachable', 'seoproanalytics'))); ?></p>
            <?php endif; ?>
            <?php if ($status['error']) : ?>
                <p role="alert"><?php echo esc_html($status['error']); ?></p>
            <?php endif; ?>
        </section>
        <?php
    }

    /**
     * Draw one source's card.
     *
     * @param array<string,mixed> $status SEOProAnalytics_Connections::status().
     */
    private static function render_source(array $status) {
        $source = (string) $status['source'];
        $id     = 'seoproan-connection-' . $source;
        // An add-on's adapter draws its own card's body (render_card()).
        $class  = SEOProAnalytics_Connections::source_class($source);
        ?>
        <section class="seoproan-card seoproan-connection" id="<?php echo esc_attr($id); ?>" data-seoproan-connection="<?php echo esc_attr($source); ?>">
            <h3>
                <?php echo esc_html((string) $status['name']); ?>
                <?php if (!empty($status['connected'])) : ?>
                    <span class="seoproan-badge seoproan-badge--success"><?php esc_html_e('Connected', 'seoproanalytics'); ?></span>
                <?php endif; ?>
            </h3>
            <div class="seoproan-connection__message" data-seoproan-message role="alert"></div>
            <?php
            if ($class && method_exists($class, 'render_card')) {
                $class::render_card($status, $id);
            } elseif (empty($status['connected'])) {
                self::render_connect($id);
            } else {
                self::render_connected($status, $id);
            }
            ?>
        </section>
        <?php
    }

    /**
     * The form to connect Bing Webmaster Tools, the free plugin's own
     * source.
     *
     * @param string $id Card id.
     */
    private static function render_connect($id) {
        ?>
        <p class="seoproan-setting__desc"><?php esc_html_e('Bing\'s clicks and impressions by day, and its top pages and search queries by week with their average position, next to Google\'s. On connecting, the 16 months Bing keeps are imported; after that each week is added once Bing has it, about a week later.', 'seoproanalytics'); ?></p>
        <ol class="seoproan-connection__steps">
            <?php
            foreach (self::bing_steps() as $step) {
                printf(
                    '<li><strong>%1$s</strong> %2$s</li>',
                    esc_html($step[0]),
                    wp_kses($step[1], array(
                        'a'    => array('href' => true, 'target' => true, 'rel' => true),
                        'span' => array('class' => true),
                    ))
                );
            }
            ?>
        </ol>
        <p>
            <label for="<?php echo esc_attr($id . '-key'); ?>"><strong><?php esc_html_e('API key', 'seoproanalytics'); ?></strong></label><br>
            <input type="password" class="regular-text" id="<?php echo esc_attr($id . '-key'); ?>" data-seoproan-field="key" autocomplete="off" spellcheck="false">
        </p>
        <p>
            <label for="<?php echo esc_attr($id . self::PROPERTY_SUFFIX); ?>"><strong><?php esc_html_e('Site', 'seoproanalytics'); ?></strong></label><br>
            <input type="text" class="regular-text" id="<?php echo esc_attr($id . self::PROPERTY_SUFFIX); ?>" data-seoproan-field="property" autocomplete="off" placeholder="<?php esc_attr_e('Found from the site\'s address', 'seoproanalytics'); ?>">
            <span class="description"><?php esc_html_e('Leave empty to use this site.', 'seoproanalytics'); ?></span>
        </p>
        <div class="seoproan-connection__actions">
            <button type="button" class="button button-primary" data-seoproan-action="connect"><?php esc_html_e('Connect', 'seoproanalytics'); ?></button>
        </div>
        <?php
    }

    /**
     * A link that opens in a new tab.
     *
     * @param string $key  LINKS key.
     * @param string $text Link text.
     * @return string HTML.
     */
    private static function link($key, $text) {
        return sprintf(
            '<a href="%1$s" target="_blank" rel="noopener noreferrer">%2$s<span class="screen-reader-text"> %3$s</span></a>',
            esc_url(self::LINKS[$key]),
            esc_html($text),
            esc_html__('(opens in a new tab)', 'seoproanalytics')
        );
    }

    /**
     * Bing's setup steps: a title and the step, with its links (HTML made
     * here; translations hold only plain text and %s for the links).
     *
     * @return array<int,array{0:string,1:string}>
     */
    private static function bing_steps() {
        return array(
            array(
                __('Add this site to Bing Webmaster Tools.', 'seoproanalytics'),
                /* translators: %s: link "Open Bing Webmaster Tools" */
                vsprintf(esc_html__('%s and sign in. If the site is not there yet, add it; importing it from Google Search Console verifies it at once.', 'seoproanalytics'), array(self::link('bing', __('Open Bing Webmaster Tools', 'seoproanalytics')))),
            ),
            array(
                __('Copy the API key.', 'seoproanalytics'),
                /* translators: %s: link "Help" */
                vsprintf(esc_html__('Choose Settings (the gear at the top right) → API access → API key, generate one if there is none, and copy it (%s).', 'seoproanalytics'), array(self::link('bing_api', __('Help', 'seoproanalytics')))),
            ),
            array(
                __('Connect.', 'seoproanalytics'),
                esc_html__('Paste the key below and choose Connect. It is stored encrypted. One key reads every site of the account.', 'seoproanalytics'),
            ),
        );
    }

    /**
     * A connected source the free plugin draws (Bing): its state, imports
     * and actions.
     *
     * @param array<string,mixed> $status SEOProAnalytics_Connections::status().
     * @param string              $id     Card id.
     */
    private static function render_connected(array $status, $id) {
        $imported = $status['imported'];
        $format   = get_option('date_format');
        if ($imported['from'] !== '') {
            /* translators: 1: first day, 2: last day */
            $range = sprintf(__('%1$s to %2$s', 'seoproanalytics'), self::day($imported['from'], $format), self::day($imported['to'], $format));
        } else {
            $range = __('Nothing yet: the first import starts within a minute or two.', 'seoproanalytics');
        }
        if ($imported['complete']) {
            /* translators: %s: source name */
            $history = sprintf(__('All of %s\'s history is in.', 'seoproanalytics'), (string) $status['name']);
        } else {
            /* translators: 1: days imported, 2: days in all */
            $history = sprintf(__('%1$d of %2$d days, newest first; it carries on in the background a minute apart.', 'seoproanalytics'), $imported['days'], $imported['of']);
        }
        if (!empty($status['pages_left'])) {
            /* translators: %d: number of pages */
            $history .= ' ' . sprintf(_n('The search queries of %d page are still to import.', 'The search queries of %d pages are still to import.', (int) $status['pages_left'], 'seoproanalytics'), (int) $status['pages_left']);
        } elseif ($status['pages_left'] === null) {
            $history .= ' ' . __('The search queries of each page come next.', 'seoproanalytics');
        }
        ?>
        <?php if ((string) $status['error'] !== '') : ?>
            <div class="notice notice-error inline">
                <p>
                    <?php
                    /* translators: 1: time since, 2: error message */
                    echo esc_html(sprintf(__('The last import failed (%1$s): %2$s', 'seoproanalytics'), self::ago($status['error_at']), (string) $status['error']));
                    ?>
                </p>
            </div>
        <?php endif; ?>
        <?php // A table of facts with row headers, so not role="presentation" (it would hide the headers from screen readers). ?>
        <table class="form-table">
            <tbody>
                <tr>
                    <th scope="row"><?php esc_html_e('Site', 'seoproanalytics'); ?></th>
                    <td><code><?php echo esc_html((string) $status['property']); ?></code></td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e('Imported', 'seoproanalytics'); ?></th>
                    <td><?php echo esc_html($range); ?><br><span class="description"><?php echo esc_html($history); ?></span></td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e('Last import', 'seoproanalytics'); ?></th>
                    <td>
                        <?php echo esc_html(self::ago($status['last_run'])); ?>
                        <?php if ($status['next_run']) : ?>
                            <br><span class="description">
                                <?php
                                /* translators: %s: time span such as "5 mins" */
                                echo esc_html(sprintf(__('Next check in %s.', 'seoproanalytics'), human_time_diff((int) $status['next_run'])));
                                ?>
                            </span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php if (isset($status['site_data']) && is_array($status['site_data'])) : ?>
                    <?php self::render_bing_site_data($status['site_data']); ?>
                <?php endif; ?>
            </tbody>
        </table>
        <div class="seoproan-connection__actions">
            <button type="button" class="button button-primary" data-seoproan-action="import"><?php esc_html_e('Import now', 'seoproanalytics'); ?></button>
        </div>

        <?php
        self::render_connected_imports($status['imports'], $format);
        self::render_connected_details($status, $id);
    }

    /**
     * Bing's site data rows: the crawl facts' last read, the URL submission
     * quota and the weekly links read into Backlinks.
     *
     * @param array<string,mixed> $site SEOProAnalytics_Bing_Site_Data::status().
     */
    private static function render_bing_site_data(array $site) {
        $read  = $site['read'] !== null ? (int) strtotime((string) $site['read']) : 0;
        $links = $site['links'];
        if ($links['running']) {
            /* translators: %d: links read so far */
            $line = sprintf(_n('Reading: %d link so far.', 'Reading: %d links so far.', (int) $links['links'], 'seoproanalytics'), (int) $links['links']);
        } elseif ($links['read'] !== null) {
            /* translators: 1: links read, 2: time since */
            $line = sprintf(_n('%1$d link read %2$s; read again once a week.', '%1$d links read %2$s; read again once a week.', (int) $links['links'], 'seoproanalytics'), (int) $links['links'], self::ago((int) strtotime((string) $links['read'])));
        } else {
            $line = __('Not read yet; Bing’s inbound links go into Backlinks once a week.', 'seoproanalytics');
        }
        ?>
        <tr>
            <th scope="row"><?php esc_html_e('Crawl issues', 'seoproanalytics'); ?></th>
            <td>
                <?php
                /* translators: 1: addresses with a crawl issue, 2: time since */
                echo esc_html($read ? sprintf(_n('%1$d address, read %2$s; read again once a day.', '%1$d addresses, read %2$s; read again once a day.', (int) $site['issues'], 'seoproanalytics'), (int) $site['issues'], self::ago($read)) : __('Not read yet; read once a day in the hourly import.', 'seoproanalytics'));
                ?>
                <?php if ($site['error'] !== null) : ?>
                    <br><span class="description">
                        <?php
                        /* translators: %s: Bing's message */
                        echo esc_html(sprintf(__('Not all could be read: %s', 'seoproanalytics'), (string) $site['error']));
                        ?>
                    </span>
                <?php endif; ?>
            </td>
        </tr>
        <?php if ($site['quota']) : ?>
            <tr>
                <th scope="row"><?php esc_html_e('URL submission quota', 'seoproanalytics'); ?></th>
                <td>
                    <?php
                    /* translators: 1: addresses a day, 2: addresses a month */
                    echo esc_html(sprintf(__('%1$d a day, %2$d a month.', 'seoproanalytics'), (int) $site['quota']['daily'], (int) $site['quota']['monthly']));
                    ?>
                </td>
            </tr>
        <?php endif; ?>
        <tr>
            <th scope="row"><?php esc_html_e('Inbound links', 'seoproanalytics'); ?></th>
            <td>
                <?php echo esc_html($line); ?>
                <?php if ($links['error'] !== null) : ?>
                    <br><span class="description">
                        <?php
                        /* translators: %s: Bing's message */
                        echo esc_html(sprintf(__('The read stopped: %s It carries on in the next hourly import.', 'seoproanalytics'), (string) $links['error']));
                        ?>
                    </span>
                <?php endif; ?>
            </td>
        </tr>
        <?php
    }

    /**
     * A connected source's recent imports, each finished one with Undo.
     *
     * @param array<int,array<string,mixed>> $imports SEOProAnalytics_Connections::status() imports.
     * @param mixed                          $format  The date_format option.
     */
    private static function render_connected_imports(array $imports, $format) {
        if (!$imports) {
            return;
        }
        ?>
            <div class="seoproan-connection__scroll">
            <table class="widefat striped seoproan-connection__imports">
                <caption class="screen-reader-text"><?php esc_html_e('Recent imports', 'seoproanalytics'); ?></caption>
                <thead>
                    <tr>
                        <th scope="col"><?php esc_html_e('Import', 'seoproanalytics'); ?></th>
                        <th scope="col"><?php esc_html_e('Days', 'seoproanalytics'); ?></th>
                        <th scope="col"><?php esc_html_e('Rows', 'seoproanalytics'); ?></th>
                        <th scope="col"><?php esc_html_e('Status', 'seoproanalytics'); ?></th>
                        <th scope="col"><span class="screen-reader-text"><?php esc_html_e('Actions', 'seoproanalytics'); ?></span></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($imports as $import) : ?>
                        <tr>
                            <td>#<?php echo esc_html((string) $import['id']); ?> · <?php echo esc_html(self::ago($import['started'])); ?></td>
                            <td>
                                <?php
                                echo esc_html($import['from'] === $import['to'] ? self::day($import['from'], $format) : self::day($import['from'], $format) . ' – ' . self::day($import['to'], $format));
                                ?>
                            </td>
                            <td><?php echo esc_html(number_format_i18n((int) $import['rows'])); ?></td>
                            <td><?php echo esc_html(self::status_label($import['status'])); ?><?php echo $import['error'] !== '' ? ': ' . esc_html($import['error']) : ''; ?></td>
                            <td>
                                <?php if ($import['status'] === 'done' && $import['rows'] > 0) : ?>
                                    <button type="button" class="button button-small" data-seoproan-action="undo" data-seoproan-import="<?php echo esc_attr((string) $import['id']); ?>"><?php esc_html_e('Undo', 'seoproanalytics'); ?></button>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            </div>
        <?php
    }

    /**
     * A connected source's forms: change the site or the key, and
     * disconnect.
     *
     * @param array<string,mixed> $status SEOProAnalytics_Connections::status().
     * @param string              $id     Card id.
     */
    private static function render_connected_details(array $status, $id) {
        ?>

        <details>
            <summary><?php esc_html_e('Change the site or key', 'seoproanalytics'); ?></summary>
            <p>
                <label for="<?php echo esc_attr($id . self::PROPERTY_SUFFIX); ?>"><strong><?php esc_html_e('Site', 'seoproanalytics'); ?></strong></label><br>
                <input type="text" class="regular-text" id="<?php echo esc_attr($id . self::PROPERTY_SUFFIX); ?>" data-seoproan-field="property" autocomplete="off" value="<?php echo esc_attr((string) $status['property']); ?>">
                <span class="description"><?php esc_html_e('Another site starts the import again from the beginning.', 'seoproanalytics'); ?></span>
            </p>
            <p>
                <label for="<?php echo esc_attr($id . '-key'); ?>"><strong><?php esc_html_e('New API key', 'seoproanalytics'); ?></strong></label><br>
                <input type="password" class="regular-text" id="<?php echo esc_attr($id . '-key'); ?>" data-seoproan-field="key" autocomplete="off" spellcheck="false" placeholder="<?php esc_attr_e('Leave empty to keep the saved key.', 'seoproanalytics'); ?>">
            </p>
            <div class="seoproan-connection__actions">
                <button type="button" class="button" data-seoproan-action="connect"><?php esc_html_e('Save', 'seoproanalytics'); ?></button>
            </div>
        </details>

        <details>
            <summary><?php esc_html_e('Disconnect', 'seoproanalytics'); ?></summary>
            <p><?php esc_html_e('Forgets the key: nothing more is imported. The search data already imported stays, unless you delete it too.', 'seoproanalytics'); ?></p>
            <p>
                <label>
                    <input type="checkbox" data-seoproan-field="delete_data">
                    <?php esc_html_e('Also delete the imported search data', 'seoproanalytics'); ?>
                </label>
            </p>
            <div class="seoproan-connection__actions">
                <button type="button" class="button button-link-delete" data-seoproan-action="disconnect"><?php esc_html_e('Disconnect', 'seoproanalytics'); ?></button>
            </div>
        </details>
        <?php
    }

    /**
     * An import status, in words.
     *
     * @param string $status running, done, failed or undone.
     * @return string
     */
    private static function status_label($status) {
        $labels = array(
            'running' => __('Running', 'seoproanalytics'),
            'done'    => __('Done', 'seoproanalytics'),
            'failed'  => __('Failed', 'seoproanalytics'),
            'undone'  => __('Undone', 'seoproanalytics'),
        );
        return isset($labels[$status]) ? $labels[$status] : $status;
    }

    /**
     * A day in the site's date format ('' for none).
     *
     * @param string $ymd    Y-m-d, or ''.
     * @param mixed  $format The date_format option.
     * @return string
     */
    private static function day($ymd, $format) {
        $time = strtotime($ymd . ' 12:00:00 UTC');
        $text = $ymd !== '' && $time ? wp_date($format, $time, new DateTimeZone('UTC')) : '';
        return is_string($text) ? $text : $ymd;
    }

    /**
     * Time since, in words.
     *
     * @param int $time Unix time; 0 for never.
     * @return string
     */
    private static function ago($time) {
        /* translators: %s: time span such as "5 mins" */
        return $time ? sprintf(__('%s ago', 'seoproanalytics'), human_time_diff((int) $time)) : __('never', 'seoproanalytics');
    }
}

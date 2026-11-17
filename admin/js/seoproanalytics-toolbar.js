/**
 * The toolbar's Analytics menu (SEOProAnalytics_Toolbar): the current
 * page's last 30 days, fetched from GET /page-summary the first time the
 * menu is opened (hover, focus or touch), never on page load.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 * Additional terms (GPL-3.0 section 7(b)): ATTRIBUTION.txt
 * Provenance: wpas-c8662019b99fb07b. Copies, even of part, keep these lines.
 */
(function () {
	'use strict';

	var cfg = window.seoproanalyticsToolbar;
	if (!cfg || !window.fetch) {
		return;
	}
	var t = cfg.i18n;
	var loaded = false;

	function el(tag, text, className) {
		var node = document.createElement(tag);
		node.textContent = text === undefined || text === null ? '' : String(text);
		node.className = className || '';
		return node;
	}

	function number(value) {
		return Number(value || 0).toLocaleString();
	}

	function duration(seconds) {
		seconds = Math.round(Number(seconds) || 0);
		var m = Math.floor(seconds / 60);
		var s = seconds % 60;
		return m ? m + 'm ' + (s < 10 ? '0' : '') + s + 's' : s + 's';
	}

	function percent(value) {
		return Math.round((Number(value) || 0) * 100) + '%';
	}

	/** A change as +12% / −5%, or ''. */
	function change(value) {
		if (value === null || value === undefined || !isFinite(value)) {
			return '';
		}
		var rounded = Math.round(value * 100);
		return ' (' + (rounded > 0 ? '+' : rounded < 0 ? '−' : '±') + Math.abs(rounded) + '%)';
	}

	function rating(value, limits) {
		if (value === null || value === undefined || !limits) {
			return '';
		}
		if (value <= limits[0]) {
			return 'is-good';
		}
		return value > limits[1] ? 'is-poor' : 'is-ni';
	}

	function row(table, label, value, className) {
		var tr = el('tr');
		tr.appendChild(el('th', label));
		tr.appendChild(el('td', value, className));
		table.appendChild(tr);
	}

	function draw(box, data) {
		box.textContent = '';
		var m = data.metrics || {};
		var ch = data.change || {};
		if (!m.visits) {
			box.appendChild(el('span', t.none));
			return;
		}
		var table = el('table');
		row(table, t.visitors, number(m.visitors) + change(ch.visitors));
		row(table, t.pageviews, number(m.pageviews) + change(ch.pageviews));
		row(table, t.time, duration(m.visit_duration));
		row(table, t.bounce, percent(m.bounce_rate));
		row(table, t.scroll, (data.scroll || 0) + '%');
		row(table, t.clicks, number(data.clicks));
		if (data.sources && data.sources.length) {
			row(table, t.sources, data.sources.map(function (s) {
				return (s.value || t.direct) + ' ' + number(s.visitors);
			}).join(', '));
		}
		var speed = data.speed || {};
		if (speed.loads) {
			var parts = [];
			['lcp', 'inp', 'cls'].forEach(function (metric) {
				if (speed[metric] !== null && speed[metric] !== undefined) {
					parts.push(metric);
				}
			});
			if (parts.length) {
				var tr = el('tr');
				tr.appendChild(el('th', t.speed));
				var td = el('td');
				parts.forEach(function (metric, i) {
					var value = speed[metric];
					var text = metric.toUpperCase() + ' ' + (metric === 'cls' ? (value / 1000).toFixed(2) : (value >= 1000 ? (value / 1000).toFixed(1) + 's' : value + 'ms'));
					td.appendChild(el('span', (i ? ' · ' : '') + text, rating(value, data.ratings && data.ratings[metric])));
				});
				tr.appendChild(td);
				table.appendChild(tr);
			}
		}
		var bots = data.crawlers || {};
		if (bots.hits) {
			row(table, t.crawlers, number(bots.hits) + ' (' + t.search + ' ' + number(bots.search) + ', ' + t.ai + ' ' + number(bots.ai) + ')');
		}
		box.appendChild(table);
		box.appendChild(el('span', t.previous));
	}

	function load() {
		if (loaded) {
			return;
		}
		loaded = true;
		var box = document.querySelector('[data-seoproan-figures]');
		if (!box) {
			return;
		}
		box.textContent = t.loading;
		var url = new URL(cfg.summaryUrl, window.location.href);
		url.searchParams.set('page', cfg.page);
		fetch(url.toString(), {
			credentials: 'same-origin',
			headers: { 'X-WP-Nonce': cfg.nonce },
		})
			.then(function (response) {
				return response.ok ? response.json() : Promise.reject(response);
			})
			.then(function (data) {
				draw(box, data);
			})
			.catch(function () {
				box.textContent = t.failed;
				loaded = false;
			});
	}

	function start() {
		var node = document.getElementById(cfg.node);
		if (!node) {
			return;
		}
		['mouseenter', 'focusin', 'touchstart'].forEach(function (type) {
			node.addEventListener(type, load, { passive: true });
		});
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', start);
	} else {
		start();
	}
})();

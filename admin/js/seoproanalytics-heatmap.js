/**
 * The click and scroll heatmap over a page (?seoproanalytics_heatmap=1,
 * for people who may read statistics; SEOProAnalytics_Toolbar): GET
 * /heatmap's click cells drawn on a canvas over the page (x: a share of
 * the window's width, y: pixels from the top), dead clicks outlined, and
 * lines where each tenth of the pageviews stopped scrolling. A panel
 * picks the period and device. Nothing is sent: the overlay only reads.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 * Additional terms (GPL-3.0 section 7(b)): ATTRIBUTION.txt
 * Provenance: wpas-c8662019b99fb07b. Copies, even of part, keep these lines.
 */
(function () {
	'use strict';

	var cfg = window.seoproanalyticsHeatmap;
	if (!cfg || !window.fetch || !document.createElement('canvas').getContext) {
		return;
	}
	var t = cfg.i18n;
	/** Canvas height limit (browsers refuse larger canvases). */
	var MAX_HEIGHT = 32000;
	var state = {
		range: '30d',
		device: window.innerWidth < 768 ? 'mobile' : window.innerWidth < 1024 ? 'tablet' : 'desktop',
		clicks: true,
		scroll: true,
		data: null,
	};
	var layer;
	var canvas;
	var lines;
	var status;
	var resizeTimer;

	function el(tag, text, className) {
		var node = document.createElement(tag);
		if (text !== undefined && text !== null) {
			node.textContent = String(text);
		}
		if (className) {
			node.className = className;
		}
		return node;
	}

	function format(text, value) {
		return String(text).replace('%s', value);
	}

	function pageHeight() {
		var root = document.documentElement;
		return Math.max(root.scrollHeight, document.body ? document.body.scrollHeight : 0);
	}

	function styles() {
		var css = '' +
			'.seoproan-hm-layer{position:absolute;top:0;left:0;right:0;pointer-events:none;z-index:99990}' +
			'.seoproan-hm-layer canvas{position:absolute;top:0;left:0}' +
			'.seoproan-hm-line{position:absolute;left:0;right:0;border-top:2px dashed rgba(34,113,177,.85)}' +
			'.seoproan-hm-line span{position:absolute;right:8px;top:2px;background:rgba(34,113,177,.92);color:#fff;font:600 11px/1.6 -apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;padding:0 6px;border-radius:2px}' +
			'.seoproan-hm-panel{position:fixed;right:16px;bottom:16px;z-index:99999;width:300px;background:#fff;color:#1d2327;border:1px solid #c3c4c7;border-radius:4px;box-shadow:0 4px 16px rgba(0,0,0,.2);font:13px/1.5 -apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;padding:12px}' +
			'.seoproan-hm-panel h2{font-size:14px;margin:0 0 8px;padding:0;color:inherit}' +
			'.seoproan-hm-panel label{display:block;margin:4px 0}' +
			'.seoproan-hm-panel select{width:100%;margin-top:2px}' +
			'.seoproan-hm-panel p{margin:6px 0;font-size:12px;color:#50575e}' +
			'.seoproan-hm-panel .seoproan-hm-actions{display:flex;justify-content:space-between;margin-top:8px}' +
			'.seoproan-hm-panel a,.seoproan-hm-panel button.button-link{color:#2271b1;background:none;border:0;padding:0;cursor:pointer;font:inherit;text-decoration:underline}';
		var style = el('style', css);
		document.head.appendChild(style);
	}

	function select(label, options, value, change) {
		var wrap = el('label', label);
		var input = el('select');
		Object.keys(options).forEach(function (key) {
			var option = el('option', options[key]);
			option.value = key;
			option.selected = key === value;
			input.appendChild(option);
		});
		input.addEventListener('change', function () {
			change(input.value);
		});
		wrap.appendChild(input);
		return wrap;
	}

	function toggle(label, checked, change) {
		var wrap = el('label');
		var input = el('input');
		input.type = 'checkbox';
		input.checked = checked;
		input.addEventListener('change', function () {
			change(input.checked);
		});
		wrap.appendChild(input);
		wrap.appendChild(document.createTextNode(' ' + label));
		return wrap;
	}

	function panel() {
		var box = el('div', null, 'seoproan-hm-panel');
		box.setAttribute('role', 'region');
		box.setAttribute('aria-label', t.title);
		box.appendChild(el('h2', t.title + ' — ' + cfg.page));
		box.appendChild(select('', cfg.ranges, state.range, function (value) {
			state.range = value;
			load();
		}));
		box.appendChild(select('', cfg.devices, state.device, function (value) {
			state.device = value;
			load();
		}));
		box.appendChild(toggle(t.clicksOn + ' (' + t.dead + ')', state.clicks, function (on) {
			state.clicks = on;
			draw();
		}));
		box.appendChild(toggle(t.scrollOn, state.scroll, function (on) {
			state.scroll = on;
			draw();
		}));
		status = el('p', t.loading);
		status.setAttribute('aria-live', 'polite');
		box.appendChild(status);
		box.appendChild(el('p', t.hint));
		var actions = el('div', null, 'seoproan-hm-actions');
		var report = el('a', t.report);
		report.href = cfg.clicksReport;
		actions.appendChild(report);
		var close = el('a', t.close);
		close.href = cfg.close;
		actions.appendChild(close);
		box.appendChild(actions);
		document.body.appendChild(box);
	}

	function draw() {
		var data = state.data;
		var width = document.documentElement.clientWidth;
		var height = Math.min(MAX_HEIGHT, pageHeight());
		layer.style.height = height + 'px';
		canvas.width = width;
		canvas.height = height;
		canvas.style.width = width + 'px';
		canvas.style.height = height + 'px';
		var ctx = canvas.getContext('2d');
		ctx.clearRect(0, 0, width, height);
		lines.textContent = '';
		if (!data) {
			return;
		}
		// The toolbar pushes the page down (html's margin); visitors' pages have no toolbar.
		var offset = parseFloat(window.getComputedStyle(document.documentElement).marginTop) || 0;
		if (state.clicks && data.cells.length) {
			var most = 1;
			data.cells.forEach(function (cell) {
				most = Math.max(most, cell[2]);
			});
			var cellY = data.cell.y;
			// Busiest last, so they draw on top.
			data.cells.slice().sort(function (a, b) {
				return a[2] - b[2];
			}).forEach(function (cell) {
				var x = ((cell[0] + 0.5) / 100) * width;
				var y = offset + (cell[1] + 0.5) * cellY;
				if (y > height) {
					return;
				}
				var heat = Math.sqrt(cell[2] / most);
				var radius = 10 + heat * 18;
				var hue = Math.round((1 - heat) * 220);
				var gradient = ctx.createRadialGradient(x, y, 0, x, y, radius);
				gradient.addColorStop(0, 'hsla(' + hue + ',100%,50%,' + (0.45 + heat * 0.45) + ')');
				gradient.addColorStop(1, 'hsla(' + hue + ',100%,50%,0)');
				ctx.fillStyle = gradient;
				ctx.beginPath();
				ctx.arc(x, y, radius, 0, Math.PI * 2);
				ctx.fill();
				if (cell[3] > 0) {
					ctx.strokeStyle = 'rgba(0,0,0,.75)';
					ctx.lineWidth = 1.5;
					ctx.beginPath();
					ctx.arc(x, y, 6, 0, Math.PI * 2);
					ctx.stroke();
				}
			});
		}
		if (state.scroll && data.scroll.views) {
			var full = pageHeight() - offset;
			data.scroll.reached.forEach(function (share, i) {
				// Every tenth but the top; 100% sits at the very bottom.
				var y = offset + Math.round(((i + 1) / 10) * full) - 2;
				if (i === 0 || y > height) {
					return;
				}
				var line = el('div', null, 'seoproan-hm-line');
				line.style.top = y + 'px';
				line.appendChild(el('span', format(t.reached, Math.round(share * 100) + '%')));
				lines.appendChild(line);
			});
		}
	}

	function load() {
		status.textContent = t.loading;
		var query = 'heatmap?page=' + encodeURIComponent(cfg.page) + '&range=' + encodeURIComponent(state.range) + '&compare=none' + (state.device ? '&device=' + encodeURIComponent(state.device) : '');
		fetch(cfg.root + query, { credentials: 'same-origin', headers: { 'X-WP-Nonce': cfg.nonce } })
			.then(function (response) {
				return response.ok ? response.json() : Promise.reject(response);
			})
			.then(function (data) {
				state.data = data;
				var text = format(t.clickCount, Number(data.clicks || 0).toLocaleString()) + ' · ' + format(t.viewCount, Number(data.scroll.views || 0).toLocaleString());
				status.textContent = data.cells.length ? text : t.noClicks + ' ' + text;
				draw();
			})
			.catch(function () {
				status.textContent = t.failed;
			});
	}

	function start() {
		styles();
		layer = el('div', null, 'seoproan-hm-layer');
		layer.setAttribute('aria-hidden', 'true');
		canvas = el('canvas');
		lines = el('div');
		layer.appendChild(canvas);
		layer.appendChild(lines);
		document.body.appendChild(layer);
		panel();
		load();
		window.addEventListener('resize', function () {
			clearTimeout(resizeTimer);
			resizeTimer = setTimeout(draw, 200);
		});
		// Late images and fonts change the page's height.
		window.addEventListener('load', draw);
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', start);
	} else {
		start();
	}
})();

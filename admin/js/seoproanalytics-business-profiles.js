/**
 * Settings → Data → Business profiles: the profile list as rows of four
 * fields (platform, name, profile address, page), with Paste a list for
 * long lists. The rows write the same lines the setting stores
 * (platform | name | profile address | page) into its textarea, which
 * saves as before (seoproanalytics-admin.js); without this script the
 * textarea is the form. Also the panel's Copy buttons
 * (SEOProAnalytics_Business_Profiles::panel()).
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 * Additional terms (GPL-3.0 section 7(b)): ATTRIBUTION.txt
 * Provenance: wpas-c8662019b99fb07b. Copies, even of part, keep these lines.
 */
(function ($, wp, cfg) {
	'use strict';

	if (!$ || !wp || !wp.i18n || !cfg) {
		return;
	}

	var __ = wp.i18n.__;
	var sprintf = wp.i18n.sprintf;

	function speak(text) {
		if (wp.a11y && wp.a11y.speak) {
			wp.a11y.speak(text);
		}
	}

	/* Copy: the label says Link copied for two seconds, and screen readers hear it. */
	$(document).on('click', '[data-seoproan-copy]', function () {
		var button = this;
		var input = document.getElementById(button.getAttribute('data-seoproan-copy'));
		var copied = __('Link copied.', 'seoproanalytics');
		if (!input) {
			return;
		}
		if (!button.dataset.label) {
			button.dataset.label = button.textContent;
		}
		var done = function () {
			button.textContent = copied;
			speak(copied);
			clearTimeout(button.seoproanTimer);
			button.seoproanTimer = setTimeout(function () {
				button.textContent = button.dataset.label;
			}, 2000);
		};
		input.select();
		if (navigator.clipboard && window.isSecureContext) {
			navigator.clipboard.writeText(input.value).then(done);
		} else if (document.execCommand('copy')) {
			done();
		}
	});

	var textarea = document.querySelector('textarea[data-seoproan-setting="' + cfg.key + '"]');
	if (!textarea) {
		return;
	}

	var FIELDS = ['platform', 'name', 'url', 'page'];
	var uid = 0;

	/** The platform a line starts with, as SEOProAnalytics_Business_Profiles::platform() reads it. */
	function platformOf(text) {
		var key = String(text || '').replace(/\s+/g, ' ').trim().toLowerCase();
		return Object.prototype.hasOwnProperty.call(cfg.aliases, key) ? cfg.aliases[key] : 'other';
	}

	/** Lines to rows. A line without a name is kept as a row, so nothing typed is lost. */
	function parse(text) {
		var rows = [];
		String(text || '').split(/[\r\n]+/).forEach(function (line) {
			if (!line.trim()) {
				return;
			}
			var parts = line.split('|').map(function (part) {
				return part.trim();
			});
			rows.push({
				platform: platformOf(parts[0]),
				name: parts[1] || '',
				url: parts[2] || '',
				page: parts.slice(3).join('|') // A page never holds |; keep what was typed.
			});
		});
		return rows;
	}

	/** Rows to lines. A row with nothing typed but its platform is left out. */
	function serialize(rows) {
		return rows.filter(function (row) {
			return row.name || row.url || row.page;
		}).map(function (row) {
			return FIELDS.map(function (field) {
				// | separates the fields, so it cannot be in one.
				return String(row[field] || '').replace(/\|/g, '/').trim();
			}).join(' | ').replace(/(\s*\|\s*)+$/, '');
		}).join('\n');
	}

	function el(tag, attrs, text) {
		var node = document.createElement(tag);
		Object.keys(attrs || {}).forEach(function (name) {
			node.setAttribute(name, attrs[name]);
		});
		if (text !== undefined) {
			node.textContent = text;
		}
		return node;
	}

	var root = el('div', { 'class': 'seoproan-repeater' });
	var list = el('div', { 'class': 'seoproan-repeater__rows' });
	var limit = el('p', { 'class': 'description' }, sprintf(
		/* translators: %d: most profiles */
		__('Up to %d profiles. Remove one to add another.', 'seoproanalytics'),
		cfg.max
	));
	var actions = el('div', { 'class': 'seoproan-repeater__actions' });
	var add = el('button', { type: 'button', 'class': 'button' }, __('Add profile', 'seoproanalytics'));
	var toggle = el('button', { type: 'button', 'class': 'button-link' });

	actions.appendChild(add);
	actions.appendChild(toggle);
	root.appendChild(list);
	root.appendChild(limit);
	root.appendChild(actions);
	textarea.parentNode.insertBefore(root, textarea);

	function field(row, name, label, control) {
		var id = 'seoproan-profile-' + row + '-' + name;
		var wrap = el('div', { 'class': 'seoproan-repeater__field' });
		control.id = id;
		control.setAttribute('data-field', name);
		wrap.appendChild(el('label', { 'for': id }, label));
		wrap.appendChild(control);
		return wrap;
	}

	function rowNode(data) {
		var n = ++uid;
		var node = el('fieldset', { 'class': 'seoproan-repeater__row' });
		var fields = el('div', { 'class': 'seoproan-repeater__fields' });
		var select = el('select');
		Object.keys(cfg.platforms).forEach(function (key) {
			var option = el('option', { value: key }, cfg.platforms[key]);
			option.selected = key === data.platform;
			select.appendChild(option);
		});
		var name = el('input', { type: 'text', 'class': 'regular-text', placeholder: __('Head office', 'seoproanalytics') });
		var url = el('input', { type: 'url', 'class': 'regular-text code', placeholder: 'https://…' });
		var page = el('input', { type: 'text', 'class': 'regular-text code', placeholder: '/' });
		name.value = data.name;
		url.value = data.url;
		page.value = data.page;

		node.appendChild(el('legend'));
		fields.appendChild(field(n, 'platform', __('Platform', 'seoproanalytics'), select));
		fields.appendChild(field(n, 'name', __('Name', 'seoproanalytics'), name));
		fields.appendChild(field(n, 'url', __('Profile address', 'seoproanalytics'), url));
		fields.appendChild(field(n, 'page', __('Page', 'seoproanalytics'), page));
		node.appendChild(fields);
		node.appendChild(el('button', { type: 'button', 'class': 'button-link button-link-delete seoproan-repeater__remove' }));
		return node;
	}

	function rows() {
		return Array.prototype.map.call(list.children, function (node) {
			var row = {};
			FIELDS.forEach(function (name) {
				var control = node.querySelector('[data-field="' + name + '"]');
				// | separates the fields in the stored lines; show what is kept.
				if (control.value.indexOf('|') !== -1) {
					control.value = control.value.replace(/\|/g, '/');
				}
				row[name] = control.value;
			});
			return row;
		});
	}

	/** Number the rows and name their Remove buttons; Add stops at the most profiles. */
	function label() {
		Array.prototype.forEach.call(list.children, function (node, i) {
			/* translators: %d: profile's number in the list */
			var title = sprintf(__('Profile %d', 'seoproanalytics'), i + 1);
			node.querySelector('legend').textContent = title;
			var remove = node.querySelector('.seoproan-repeater__remove');
			remove.textContent = __('Remove', 'seoproanalytics');
			/* translators: %s: Profile 1, Profile 2 … */
			remove.setAttribute('aria-label', sprintf(__('Remove %s', 'seoproanalytics'), title));
		});
		var full = list.children.length >= cfg.max;
		add.disabled = full;
		limit.hidden = !full;
	}

	function render(data) {
		list.textContent = '';
		if (!data.length) {
			data = [{ platform: 'google', name: '', url: '', page: '' }];
		}
		data.slice(0, cfg.max).forEach(function (row) {
			list.appendChild(rowNode(row));
		});
		label();
	}

	/** Write the rows to the textarea; its change event saves them. */
	function sync() {
		var value = serialize(rows());
		if (value !== textarea.value) {
			textarea.value = value;
			$(textarea).trigger('change');
		}
	}

	var bulk = false;

	function mode(toBulk, focus) {
		bulk = toBulk;
		if (!bulk) {
			render(parse(textarea.value));
		} else {
			textarea.rows = Math.min(12, Math.max(4, textarea.value.split('\n').length + 1));
		}
		list.hidden = bulk;
		add.hidden = bulk;
		limit.hidden = bulk || list.children.length < cfg.max;
		textarea.style.display = bulk ? '' : 'none';
		toggle.textContent = bulk ? __('Use the fields', 'seoproanalytics') : __('Paste a list', 'seoproanalytics');
		if (focus) {
			(bulk ? textarea : list.querySelector('select')).focus();
		}
	}

	$(list).on('change', 'input, select', sync);

	$(list).on('click', '.seoproan-repeater__remove', function () {
		var node = $(this).closest('.seoproan-repeater__row')[0];
		var next = node.nextElementSibling || node.previousElementSibling;
		node.parentNode.removeChild(node);
		if (!list.children.length) {
			render([]);
			next = list.firstElementChild;
		}
		label();
		sync();
		speak(__('Profile removed.', 'seoproanalytics'));
		(next ? next.querySelector('select') : add).focus();
	});

	add.addEventListener('click', function () {
		if (list.children.length >= cfg.max) {
			return;
		}
		var node = rowNode({ platform: 'google', name: '', url: '', page: '' });
		list.appendChild(node);
		label();
		node.querySelector('select').focus();
	});

	toggle.addEventListener('click', function () {
		mode(!bulk, true);
	});

	mode(false, false);
})(window.jQuery, window.wp, window.seoproanBusinessProfiles);

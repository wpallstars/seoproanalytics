/**
 * Settings → Backlinks → Classes: every class as one list, A to Z, with
 * those hidden until ticked after a line, as Search → Backlinks shows
 * them. A tick turns a class on or off (links keep it while off); Show
 * or Hide puts it above or below the line; the site's own classes can be
 * renamed, added and removed. The list writes the three settings'
 * textareas (own classes as key | Name lines, the keys turned off, the
 * keys hidden), which save as before (seoproanalytics-admin.js); without
 * this script the textareas are the form
 * (SEOProAnalytics_Backlinks_Triage::panel()).
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

	function area(key) {
		return document.querySelector('textarea[data-seoproan-setting="' + key + '"]');
	}

	var own = area(cfg.own);
	var off = area(cfg.off);
	var hidden = area(cfg.hidden);
	if (!own || !off || !hidden) {
		return;
	}

	function speak(text) {
		if (wp.a11y && wp.a11y.speak) {
			wp.a11y.speak(text);
		}
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

	function lines(text) {
		return String(text || '').split(/[\r\n]+/).map(function (line) {
			return line.trim();
		}).filter(Boolean);
	}

	/** A key from text, as SEOProAnalytics_Backlinks_Triage::key_of() makes it. */
	function keyOf(text) {
		var key = String(text || '').normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase();
		return key.replace(/[^a-z0-9]+/g, '_').replace(/^_+|_+$/g, '').slice(0, cfg.maxKey).replace(/_+$/, '');
	}

	function cleanName(text) {
		return String(text || '').replace(/\|/g, '/').replace(/\s+/g, ' ').trim().slice(0, cfg.maxName);
	}

	/** A key no built-in or other own class has (unique_key()). */
	function uniqueKey(key, used) {
		key = key || 'class';
		var base = key;
		for (var n = 2; used.indexOf(key) !== -1 || cfg.reserved.indexOf(key) !== -1; n++) {
			key = base.slice(0, cfg.maxKey - String(n).length - 1) + '_' + n;
		}
		return key;
	}

	/** Own classes from their lines, as parse_own() reads them. */
	function parseOwn(text) {
		var out = [];
		lines(text).forEach(function (line) {
			var at = line.indexOf('|');
			var name = cleanName(at === -1 ? line : line.slice(at + 1));
			if (!name || out.length >= cfg.max) {
				return;
			}
			var used = out.map(function (row) {
				return row.name;
			});
			var key = at === -1 ? '' : keyOf(line.slice(0, at));
			if (!key || used.indexOf(key) !== -1 || cfg.reserved.indexOf(key) !== -1) {
				key = uniqueKey(keyOf(name), used);
			}
			out.push({ name: key, label: name, hint: '', own: true });
		});
		return out;
	}

	var state = {
		own: parseOwn(own.value),
		off: lines(off.value),
		hidden: lines(hidden.value)
	};

	function has(list, key) {
		return list.indexOf(key) !== -1;
	}

	function toggle(list, key, on) {
		var i = list.indexOf(key);
		if (on && i === -1) {
			list.push(key);
		} else if (!on && i !== -1) {
			list.splice(i, 1);
		}
	}

	/** Write one textarea; its change event saves it. */
	function write(textarea, value) {
		if (value !== textarea.value) {
			textarea.value = value;
			$(textarea).trigger('change');
		}
	}

	function save() {
		var known = cfg.builtins.map(function (row) {
			return row.name;
		}).concat(state.own.map(function (row) {
			return row.name;
		}));
		// Keys of classes that are no more are dropped from both lists.
		state.off = state.off.filter(function (key) {
			return has(known, key);
		});
		state.hidden = state.hidden.filter(function (key) {
			return has(known, key);
		});
		write(own, state.own.map(function (row) {
			return row.name + ' | ' + row.label;
		}).join('\n'));
		write(off, state.off.join('\n'));
		write(hidden, state.hidden.join('\n'));
	}

	var root = el('div', { 'class': 'seoproan-classes' });
	var table = el('table', { 'class': 'seoproan-classes__table widefat striped' });
	var head = el('thead');
	var headRow = el('tr');
	headRow.appendChild(el('th', { scope: 'col' }, __('In use', 'seoproanalytics')));
	headRow.appendChild(el('th', { scope: 'col' }, __('Class', 'seoproanalytics')));
	headRow.appendChild(el('th', { scope: 'col' }, __('In Search → Backlinks', 'seoproanalytics')));
	var actHead = el('th', { scope: 'col' });
	actHead.appendChild(el('span', { 'class': 'screen-reader-text' }, __('Actions', 'seoproanalytics')));
	headRow.appendChild(actHead);
	head.appendChild(headRow);
	var body = el('tbody');
	table.appendChild(head);
	table.appendChild(body);

	var add = el('div', { 'class': 'seoproan-classes__add' });
	var nameLabel = el('label', { 'for': 'seoproan-class-new' }, __('New class', 'seoproanalytics'));
	var nameInput = el('input', { type: 'text', id: 'seoproan-class-new', 'class': 'regular-text', maxlength: String(cfg.maxName), placeholder: __('Press Kit', 'seoproanalytics') });
	nameLabel.appendChild(nameInput);
	var hideLabel = el('label', { 'class': 'seoproan-classes__hide' });
	var hideInput = el('input', { type: 'checkbox' });
	hideLabel.style.display = 'flex';
	hideLabel.style.gap = '6px';
	hideLabel.style.alignItems = 'center';
	hideLabel.appendChild(hideInput);
	hideLabel.appendChild(document.createTextNode(__('Hidden until ticked', 'seoproanalytics')));
	var addButton = el('button', { type: 'button', 'class': 'button' }, __('Add class', 'seoproanalytics'));
	var limit = el('p', { 'class': 'description' }, sprintf(
		/* translators: %d: most own classes */
		__('Up to %d of the site\'s own classes. Remove one to add another.', 'seoproanalytics'),
		cfg.max
	));
	add.appendChild(nameLabel);
	add.appendChild(hideLabel);
	add.appendChild(addButton);

	root.appendChild(table);
	root.appendChild(add);
	root.appendChild(limit);
	// Saving and Saved for the turned-off and hidden lists, whose own fields are out of sight.
	var statuses = el('p');
	[cfg.off, cfg.hidden].forEach(function (key) {
		statuses.appendChild(el('span', { 'class': 'seoproan-status', 'data-seoproan-status': key, 'aria-hidden': 'true' }));
	});
	root.appendChild(statuses);

	// The list goes where the own classes' textarea is; the three fields are kept, out of sight, as what is saved.
	var field = own.closest('.seoproan-field');
	field.parentNode.insertBefore(root, field);
	[own, off, hidden].forEach(function (textarea) {
		textarea.closest('.seoproan-field').style.display = 'none';
	});

	function rowsOf(isHidden) {
		var collator = typeof Intl !== 'undefined' && Intl.Collator ? new Intl.Collator(undefined, { numeric: true, sensitivity: 'base' }) : null;
		return cfg.builtins.concat(state.own).filter(function (row) {
			return has(state.hidden, row.name) === isHidden;
		}).sort(function (a, b) {
			return collator ? collator.compare(a.label, b.label) : a.label.localeCompare(b.label);
		});
	}

	function rowNode(row) {
		var isOff = has(state.off, row.name);
		var isHidden = has(state.hidden, row.name);
		var tr = el('tr', { 'class': 'seoproan-classes__row' + (isOff ? ' is-off' : ''), 'data-class': row.name });
		var tickId = 'seoproan-class-on-' + row.name;

		var tickCell = el('td');
		var tick = el('input', { type: 'checkbox', id: tickId, 'data-act': 'on' });
		tick.checked = !isOff;
		tickCell.appendChild(tick);
		tr.appendChild(tickCell);

		var nameCell = el('td');
		if (row.own) {
			var input = el('input', { type: 'text', 'class': 'regular-text', value: row.label, maxlength: String(cfg.maxName), 'data-act': 'rename' });
			/* translators: %s: class name */
			input.setAttribute('aria-label', sprintf(__('Name of %s', 'seoproanalytics'), row.label));
			tick.setAttribute('aria-label', row.label);
			nameCell.appendChild(input);
		} else {
			nameCell.appendChild(el('label', { 'for': tickId, 'class': 'seoproan-classes__name' }, row.label));
		}
		nameCell.appendChild(document.createTextNode(' '));
		nameCell.appendChild(el('code', { 'class': 'seoproan-classes__key' }, row.name));
		if (row.hint) {
			nameCell.appendChild(el('p', { 'class': 'description' }, row.hint));
		}
		tr.appendChild(nameCell);

		var showCell = el('td');
		var show = el('select', { 'data-act': 'hidden' });
		/* translators: %s: class name */
		show.setAttribute('aria-label', sprintf(__('Where %s shows', 'seoproanalytics'), row.label));
		show.appendChild(el('option', { value: '0' }, __('Shown', 'seoproanalytics')));
		show.appendChild(el('option', { value: '1' }, __('Hidden until ticked', 'seoproanalytics')));
		show.value = isHidden ? '1' : '0';
		showCell.appendChild(show);
		tr.appendChild(showCell);

		var actCell = el('td');
		if (row.own) {
			var remove = el('button', { type: 'button', 'class': 'button-link button-link-delete', 'data-act': 'remove' }, __('Remove', 'seoproanalytics'));
			/* translators: %s: class name */
			remove.setAttribute('aria-label', sprintf(__('Remove %s', 'seoproanalytics'), row.label));
			actCell.appendChild(remove);
		}
		tr.appendChild(actCell);
		return tr;
	}

	function render(focusKey, focusAct) {
		body.textContent = '';
		rowsOf(false).forEach(function (row) {
			body.appendChild(rowNode(row));
		});
		var below = rowsOf(true);
		if (below.length) {
			var divider = el('tr', { 'class': 'seoproan-classes__divider' });
			divider.appendChild(el('td', { colspan: '4' }, __('Hidden until ticked in Classes', 'seoproanalytics')));
			body.appendChild(divider);
			below.forEach(function (row) {
				body.appendChild(rowNode(row));
			});
		}
		var full = state.own.length >= cfg.max;
		addButton.disabled = full;
		nameInput.disabled = full;
		limit.hidden = !full;
		if (focusKey) {
			var target = body.querySelector('tr[data-class="' + focusKey + '"] [data-act="' + (focusAct || 'on') + '"]');
			if (target) {
				target.focus();
			}
		}
	}

	function ownRow(key) {
		for (var i = 0; i < state.own.length; i++) {
			if (state.own[i].name === key) {
				return i;
			}
		}
		return -1;
	}

	$(body).on('change', '[data-act]', function () {
		var key = $(this).closest('tr').attr('data-class');
		var act = this.getAttribute('data-act');
		if (act === 'on') {
			toggle(state.off, key, !this.checked);
		} else if (act === 'hidden') {
			toggle(state.hidden, key, this.value === '1');
		} else if (act === 'rename') {
			var i = ownRow(key);
			var name = cleanName(this.value);
			if (i === -1 || !name) {
				this.value = i === -1 ? '' : state.own[i].label;
				return;
			}
			state.own[i].label = name;
		}
		save();
		render(key, act);
	});

	$(body).on('click', '[data-act="remove"]', function () {
		var key = $(this).closest('tr').attr('data-class');
		var i = ownRow(key);
		if (i === -1) {
			return;
		}
		/* translators: %s: class name */
		if (!window.confirm(sprintf(__('Remove %s? It is taken off every link, page and site that has it, and its list in Class lists is no longer used.', 'seoproanalytics'), state.own[i].label))) {
			return;
		}
		var label = state.own[i].label;
		state.own.splice(i, 1);
		save();
		render();
		/* translators: %s: class name */
		speak(sprintf(__('%s removed.', 'seoproanalytics'), label));
		nameInput.focus();
	});

	function addClass() {
		var name = cleanName(nameInput.value);
		if (!name || state.own.length >= cfg.max) {
			nameInput.focus();
			return;
		}
		var used = state.own.map(function (row) {
			return row.name;
		});
		var key = uniqueKey(keyOf(name), used);
		state.own.push({ name: key, label: name, hint: '', own: true });
		toggle(state.hidden, key, hideInput.checked);
		nameInput.value = '';
		hideInput.checked = false;
		save();
		render(key, 'on');
		/* translators: %s: class name */
		speak(sprintf(__('%s added. Reload the page for its list in Class lists.', 'seoproanalytics'), name));
	}

	addButton.addEventListener('click', addClass);
	nameInput.addEventListener('keydown', function (event) {
		if (event.key === 'Enter') {
			event.preventDefault();
			addClass();
		}
	});

	render();
})(window.jQuery, window.wp, window.seoproanBacklinkClasses);

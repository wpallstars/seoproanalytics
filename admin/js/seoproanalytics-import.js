/**
 * Settings → Import: the dry run, import (with its progress), undo and
 * removing leftover data call the REST routes (/seoproanalytics/v1/migrate and
 * /imports), then reload the tab, which PHP draws from
 * SEOProAnalytics_Migrate::status() (SEOProAnalytics_Import_Tab).
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 * Additional terms (GPL-3.0 section 7(b)): ATTRIBUTION.txt
 * Provenance: wpas-c8662019b99fb07b. Copies, even of part, keep these lines.
 */
(function (wp) {
	'use strict';

	if (!wp || !wp.apiFetch) {
		return;
	}

	var __ = wp.i18n.__;
	var _n = wp.i18n._n;
	var sprintf = wp.i18n.sprintf;
	var base = '/seoproanalytics/v1/';
	var root = document.querySelector('[data-seoproan-import-root]');

	if (!root) {
		return;
	}

	var linksForm = root.querySelector('[data-seoproan-links-form]');
	var linksStatus = root.querySelector('[data-seoproan-links-status]');
	var linksProgress = root.querySelector('[data-seoproan-links-progress]');
	// Whether this page saw the links import run: once it ends, the tab is drawn again with the import in the history.
	var linksWatched = false;
	function linksText(job) {
		if (job.status === 'running') {
			/* translators: 1: rows done, 2: rows in all */
			return sprintf(__('Importing: %1$s of %2$s rows.', 'seoproanalytics'), number(job.done), number(job.total));
		}
		if (job.status === 'error') {
			return __('The last import stopped: a row could not be written. Import the file again; rows already written are kept once.', 'seoproanalytics');
		}
		/* translators: 1: rows kept, 2: rows skipped */
		return sprintf(__('Last import done: %1$s rows kept, %2$s skipped.', 'seoproanalytics'), number(job.accepted), number(job.skipped));
	}
	// Failed status reads in a row: a busy or updating site can answer a poll with an error page, which is not the end of the import.
	var linksFailures = 0;
	function showLinks(job) {
		linksFailures = 0;
		linksStatus.hidden = false;
		linksStatus.textContent = linksText(job);
		linksProgress.hidden = job.status !== 'running';
		linksProgress.max = Math.max(1, job.total);
		linksProgress.value = job.done;
		linksForm.querySelector('button').disabled = job.status === 'running';
		if (job.status === 'running') {
			linksWatched = true;
			// Each status read also moves the import on, so read again soon.
			window.setTimeout(pollLinks, 1500);
		} else if (linksWatched || job.history) {
			speak(linksStatus.textContent);
			window.setTimeout(function () { window.location.reload(); }, 1500);
		}
	}
	function pollLinks() {
		wp.apiFetch({ path: base + 'backlinks/import' }).then(showLinks).catch(function () {
			++linksFailures;
			if (linksFailures > 10) {
				linksStatus.textContent = __('The progress could not be read for a while. The import carries on in the background; reload this page to see it.', 'seoproanalytics');
				return;
			}
			var wait = Math.min(30, 5 * linksFailures);
			/* translators: %s: seconds until the next try */
			linksStatus.textContent = sprintf(__('Still importing, but the progress could not be read just now. Trying again in %s seconds.', 'seoproanalytics'), number(wait));
			window.setTimeout(pollLinks, wait * 1000);
		});
	}
	function uploadFailed(error) {
		var message = (error && error.message) || __('The links could not be imported.', 'seoproanalytics');
		function failed() {
			linksProgress.hidden = true;
			linksStatus.textContent = message;
			linksForm.querySelector('button').disabled = false;
		}
		// A refusal from the site (such as another import still running) is its answer. Only a lost
		// answer (an error page or a dropped connection) may hide a file the site kept: ask then.
		var code = error && error.code;
		if (code !== 'invalid_json' && code !== 'fetch_error') {
			failed();
			return;
		}
		wp.apiFetch({ path: base + 'backlinks/import' }).then(function (job) {
			if (job.status === 'running') {
				showLinks(job);
			} else {
				failed();
			}
		}).catch(failed);
	}
	if (linksForm) {
		linksForm.addEventListener('submit', function (event) {
			event.preventDefault();
			var file = linksForm.querySelector('input').files[0];
			if (!file) { return; }
			var body = new FormData();
			body.append('file', file);
			body.append('source', linksForm.querySelector('select').value);
			linksForm.querySelector('button').disabled = true;
			linksStatus.hidden = false;
			linksStatus.textContent = __('Uploading and reading the file…', 'seoproanalytics');
			// No value: the bar moves without a measure until the rows are counted.
			linksProgress.hidden = false;
			linksProgress.removeAttribute('value');
			wp.apiFetch({ path: base + 'backlinks/import', method: 'POST', body: body }).then(showLinks).catch(uploadFailed);
		});
		if (linksStatus.getAttribute('data-status') === 'running') { pollLinks(); }
	}

	// Site crawl: one crawl's exports uploaded together (POST /crawl/import), written in the background; each status read moves it on.
	var crawlForm = root.querySelector('[data-seoproan-crawl-form]');
	var crawlStatus = root.querySelector('[data-seoproan-crawl-status]');
	var crawlProgress = root.querySelector('[data-seoproan-crawl-progress]');
	var crawlWatched = false;
	var crawlFailures = 0;
	function crawlText(job) {
		if (job.status === 'running') {
			/* translators: 1: rows done, 2: rows in all */
			return sprintf(__('Importing: %1$s of %2$s rows.', 'seoproanalytics'), number(job.done), number(job.total));
		}
		if (job.status === 'error') {
			return job.error || __('The last crawl import stopped. Import the files again.', 'seoproanalytics');
		}
		/* translators: 1: pages, 2: links that fail or redirect, 3: rows skipped */
		return sprintf(__('Last crawl import done: %1$s pages, %2$s links that fail or redirect, %3$s rows skipped.', 'seoproanalytics'), number(job.pages), number(job.links), number(job.skipped));
	}
	function showCrawl(job) {
		crawlFailures = 0;
		crawlStatus.hidden = false;
		crawlStatus.textContent = crawlText(job);
		crawlProgress.hidden = job.status !== 'running';
		crawlProgress.max = Math.max(1, job.total);
		crawlProgress.value = job.done;
		crawlForm.querySelector('button').disabled = job.status === 'running';
		if (job.status === 'running') {
			crawlWatched = true;
			window.setTimeout(pollCrawl, 1500);
		} else if (crawlWatched) {
			speak(crawlStatus.textContent);
			window.setTimeout(function () { window.location.reload(); }, 1500);
		}
	}
	function pollCrawl() {
		wp.apiFetch({ path: base + 'crawl/import' }).then(showCrawl).catch(function () {
			++crawlFailures;
			if (crawlFailures > 10) {
				crawlStatus.textContent = __('The progress could not be read for a while. The import carries on in the background; reload this page to see it.', 'seoproanalytics');
				return;
			}
			var wait = Math.min(30, 5 * crawlFailures);
			/* translators: %s: seconds until the next try */
			crawlStatus.textContent = sprintf(__('Still importing, but the progress could not be read just now. Trying again in %s seconds.', 'seoproanalytics'), number(wait));
			window.setTimeout(pollCrawl, wait * 1000);
		});
	}
	if (crawlForm) {
		crawlForm.addEventListener('submit', function (event) {
			event.preventDefault();
			var files = crawlForm.querySelector('input').files;
			if (!files.length) { return; }
			var body = new FormData();
			Array.prototype.forEach.call(files, function (file) {
				body.append('files[]', file);
			});
			body.append('format', crawlForm.querySelector('select').value);
			body.append('complete', crawlForm.querySelector('#seoproan-crawl-complete').checked ? 'true' : 'false');
			crawlForm.querySelector('button').disabled = true;
			crawlStatus.hidden = false;
			crawlStatus.textContent = __('Uploading and reading the files…', 'seoproanalytics');
			crawlProgress.hidden = false;
			crawlProgress.removeAttribute('value');
			wp.apiFetch({ path: base + 'crawl/import', method: 'POST', body: body }).then(showCrawl).catch(function (error) {
				crawlProgress.hidden = true;
				crawlStatus.textContent = (error && error.message) || __('The crawl could not be imported.', 'seoproanalytics');
				crawlForm.querySelector('button').disabled = false;
				// A lost answer may hide an import the site started: look.
				if (error && (error.code === 'invalid_json' || error.code === 'fetch_error')) {
					wp.apiFetch({ path: base + 'crawl/import' }).then(function (job) {
						if (job.status === 'running') { showCrawl(job); }
					}).catch(function () {});
				}
			});
		});
		if (crawlStatus.getAttribute('data-status') === 'running') { pollCrawl(); }
	}

	function speak(message, politeness) {
		if (wp.a11y && wp.a11y.speak) {
			wp.a11y.speak(message, politeness || 'polite');
		}
	}

	function number(value) {
		return Number(value || 0).toLocaleString();
	}

	function day(ymd) {
		if (!/^\d{4}-\d{2}-\d{2}$/.test(ymd || '')) {
			return ymd || '';
		}
		return new Date(ymd + 'T12:00:00Z').toLocaleDateString(undefined, { dateStyle: 'medium', timeZone: 'UTC' });
	}

	function range(from, to) {
		return from === to ? day(from) : day(from) + ' – ' + day(to);
	}

	// An element with text (never HTML) and optional class.
	function el(tag, text, className) {
		var node = document.createElement(tag);
		if (text !== undefined && text !== null && text !== '') {
			node.textContent = text;
		}
		if (className) {
			node.className = className;
		}
		return node;
	}

	function show(box, text, kind) {
		box = box || root.querySelector('[data-seoproan-message]');
		box.textContent = '';
		if (!text) {
			return;
		}
		var notice = el('div', '', 'notice inline notice-' + (kind || 'error'));
		notice.appendChild(el('p', text));
		box.appendChild(notice);
		speak(text, kind === 'error' ? 'assertive' : 'polite');
	}

	function busy(card, button, on) {
		card.querySelectorAll('button').forEach(function (b) {
			b.disabled = on;
		});
		if (on) {
			button.setAttribute('aria-busy', 'true');
		} else {
			button.removeAttribute('aria-busy');
		}
	}

	function request(card, button, options, done) {
		busy(card, button, true);
		show(null, '', '');
		speak(__('Working…', 'seoproanalytics'));
		wp.apiFetch(options)
			.then(function (answer) {
				busy(card, button, false);
				done(answer);
			})
			.catch(function (error) {
				busy(card, button, false);
				show(null, (error && error.message) || __('That did not work. Please try again.', 'seoproanalytics'), 'error');
			});
	}

	function reload(message) {
		show(null, message, 'success');
		window.setTimeout(function () {
			window.location.reload();
		}, 800);
	}

	// A two-column table: rows of [label, value] (or [label, value, value]).
	function table(head, rows) {
		var wrap = el('div', '', 'seoproan-import__scroll');
		var t = el('table', '', 'widefat striped');
		var thead = el('thead');
		var tr = el('tr');
		head.forEach(function (label, i) {
			var th = el('th', label, i ? 'num' : '');
			th.scope = 'col';
			tr.appendChild(th);
		});
		thead.appendChild(tr);
		t.appendChild(thead);
		var tbody = el('tbody');
		rows.forEach(function (row) {
			var r = el('tr');
			row.forEach(function (cell, i) {
				var c = el(i ? 'td' : 'th', cell, i ? 'num' : '');
				if (!i) {
					c.scope = 'row';
				}
				r.appendChild(c);
			});
			tbody.appendChild(r);
		});
		t.appendChild(tbody);
		wrap.appendChild(t);
		return wrap;
	}

	var dimensions = {
		site: __('Site totals', 'seoproanalytics'),
		page: __('Pages', 'seoproanalytics'),
		entry: __('Entry pages', 'seoproanalytics'),
		exit: __('Exit pages', 'seoproanalytics'),
		landing: __('Search landing pages', 'seoproanalytics'),
		source: __('Referrers', 'seoproanalytics'),
		channel: __('Channels', 'seoproanalytics'),
		utm_source: __('Campaign sources', 'seoproanalytics'),
		utm_medium: __('Campaign media', 'seoproanalytics'),
		utm_campaign: __('Campaigns', 'seoproanalytics'),
		utm_term: __('Campaign terms', 'seoproanalytics'),
		utm_content: __('Campaign content', 'seoproanalytics'),
		country: __('Countries', 'seoproanalytics'),
		device: __('Devices', 'seoproanalytics'),
		browser: __('Browsers', 'seoproanalytics'),
		os: __('Operating systems', 'seoproanalytics')
	};

	// What a setting would be after the import: its new value when it is
	// carried over, else why it stays.
	function settingAfter(s, ticked) {
		if (!s.change) {
			return s.reason === 'same' ? __('Already the same', 'seoproanalytics') : __('Stays: you changed it', 'seoproanalytics');
		}
		/* translators: %s: the setting's value now */
		return ticked ? s.to : sprintf(__('Stays: %s', 'seoproanalytics'), s.now);
	}

	// The settings the import would carry over, a row each: a checkbox
	// (when there is an import to carry them), our setting with the
	// plugin's own name for it, its value, ours now and ours after.
	function settingsTable(plan, choose) {
		var wrap = el('div', '', 'seoproan-import__scroll');
		var t = el('table', '', 'widefat striped seoproan-import__settings');
		var thead = el('thead');
		var head = el('tr');
		if (choose) {
			var corner = el('td', '', 'check-column');
			corner.appendChild(el('span', __('Carry over', 'seoproanalytics'), 'screen-reader-text'));
			head.appendChild(corner);
		}
		[__('Setting', 'seoproanalytics'), plan.name, __('SEO Pro Analytics now', 'seoproanalytics'), __('After the import', 'seoproanalytics')].forEach(function (label) {
			var th = el('th', label);
			th.scope = 'col';
			head.appendChild(th);
		});
		thead.appendChild(head);
		t.appendChild(thead);

		var tbody = el('tbody');
		plan.settings.forEach(function (s, i) {
			var r = el('tr');
			var id = 'seoproan-setting-' + plan.source + '-' + i;
			var after = el('td', settingAfter(s, s.change));
			var name = el('td', '', 'seoproan-import__setting');
			if (choose) {
				var check = el('th', '', 'check-column');
				check.scope = 'row';
				var box = el('input');
				box.type = 'checkbox';
				box.id = id;
				box.value = s.key;
				box.checked = !!s.change;
				box.disabled = !s.change;
				box.setAttribute('data-seoproan-field', 'setting');
				box.addEventListener('change', function () {
					after.textContent = settingAfter(s, box.checked);
					r.classList.toggle('is-kept', !box.checked);
				});
				check.appendChild(box);
				r.appendChild(check);
				var label = el('label', s.label);
				label.htmlFor = id;
				name.appendChild(label);
			} else {
				name.appendChild(el('strong', s.label));
			}
			/* translators: 1: plugin name, 2: that plugin's name for the setting */
			name.appendChild(el('span', sprintf(__('%1$s: %2$s', 'seoproanalytics'), plan.name, s.theirs), 'description'));
			r.appendChild(name);
			r.appendChild(el('td', s.from));
			r.appendChild(el('td', s.now));
			r.appendChild(after);
			if (!s.change) {
				r.classList.add('is-kept');
			}
			tbody.appendChild(r);
		});
		t.appendChild(tbody);
		wrap.appendChild(t);
		return wrap;
	}

	// The settings ticked in the dry run (null: no settings table, so the
	// import carries over all it would change, which is none).
	function chosenSettings(card) {
		var box = card.querySelector('[data-seoproan-plan]');
		var boxes = box.querySelectorAll('[data-seoproan-field="setting"]');
		if (!boxes.length) {
			return null;
		}
		return Array.prototype.filter.call(boxes, function (b) {
			return b.checked && !b.disabled;
		}).map(function (b) {
			return b.value;
		});
	}

	// The dry run's answer, with the choice of plugin for shared days and
	// the Import button.
	function drawPlan(card, plan) {
		var box = card.querySelector('[data-seoproan-plan]');
		box.textContent = '';
		var days = plan.import.days;

		box.appendChild(el('h4', __('What the import would do', 'seoproanalytics')));
		var summary = el('p');
		if (days) {
			/* translators: 1: number of days, 2: the days, such as "1 Mar 2026 – 30 Sep 2026" */
			summary.textContent = sprintf(_n('Add %1$s day: %2$s.', 'Add %1$s days: %2$s.', days, 'seoproanalytics'), number(days), range(plan.import.from, plan.import.to));
		} else {
			summary.textContent = __('Nothing to add: every day with its statistics is already filled.', 'seoproanalytics');
		}
		box.appendChild(summary);

		var skipped = [];
		if (plan.skipped.own) {
			/* translators: 1: number of days, 2: a day */
			skipped.push(sprintf(_n('%1$s day skipped: SEO Pro Analytics has its own statistics from %2$s.', '%1$s days skipped: SEO Pro Analytics has its own statistics from %2$s.', plan.skipped.own, 'seoproanalytics'), number(plan.skipped.own), day(plan.own_from)));
		}
		Object.keys(plan.skipped.imported || {}).forEach(function (by) {
			var count = plan.skipped.imported[by];
			/* translators: 1: number of days, 2: plugin key */
			skipped.push(sprintf(_n('%1$s day skipped: already imported (%2$s).', '%1$s days skipped: already imported (%2$s).', count, 'seoproanalytics'), number(count), by));
		});
		if (skipped.length) {
			var list = el('ul', '', 'seoproan-import__list');
			skipped.forEach(function (line) {
				list.appendChild(el('li', line));
			});
			box.appendChild(list);
		}

		if (days) {
			box.appendChild(el('h4', plan.estimated ? __('Counts (estimated from a few days: its tables are large)', 'seoproanalytics') : __('Counts', 'seoproanalytics')));
			box.appendChild(table(
				['', plan.name],
				[
					[__('Pageviews', 'seoproanalytics'), number(plan.totals.pageviews)],
					[__('Visits', 'seoproanalytics'), number(plan.totals.visits)],
					[__('Visitors', 'seoproanalytics'), number(plan.totals.visitors)]
				]
			));
			var rows = Object.keys(plan.rows || {}).map(function (name) {
				return [dimensions[name] || name, number(plan.rows[name])];
			});
			if (rows.length) {
				box.appendChild(el('h4', __('Rows it would add (estimated)', 'seoproanalytics')));
				box.appendChild(table([__('Report', 'seoproanalytics'), __('Rows', 'seoproanalytics')], rows));
			}
		}

		// Other plugins not imported yet, with statistics on the same days.
		var choice = plan.prefer;
		(plan.overlap || []).forEach(function (other) {
			var set = el('fieldset');
			/* translators: 1: plugin name, 2: number of days, 3: the days */
			set.appendChild(el('legend', sprintf(_n('%1$s also has statistics for %2$s of these days (%3$s). Which should fill them?', '%1$s also has statistics for %2$s of these days (%3$s). Which should fill them?', other.days, 'seoproanalytics'), other.name, number(other.days), range(other.from, other.to))));
			[plan.source, other.source].forEach(function (key) {
				var label = el('label');
				var radio = el('input');
				radio.type = 'radio';
				radio.name = 'seoproan-prefer-' + plan.source + '-' + other.source;
				radio.value = key;
				radio.setAttribute('data-seoproan-field', 'prefer');
				radio.checked = key === (plan.prefer !== plan.source ? plan.prefer : other.suggested);
				if (radio.checked) {
					choice = key;
				}
				label.appendChild(radio);
				/* translators: 1: plugin name, 2: number of pageviews */
				label.appendChild(document.createTextNode(' ' + sprintf(__('%1$s (%2$s pageviews on those days)', 'seoproanalytics'), key === plan.source ? plan.name : other.name, number(other.pageviews[key]))));
				set.appendChild(label);
			});
			set.appendChild(el('p', __('The one chosen is imported first; the other then fills only the days left. To change it later, undo the import and run it again.', 'seoproanalytics'), 'description'));
			box.appendChild(set);
		});
		box.setAttribute('data-seoproan-prefer', choice);

		// Settings: only ours still at their default are filled in, and only
		// those ticked.
		if (plan.settings && plan.settings.length) {
			box.appendChild(el('h4', __('Settings', 'seoproanalytics')));
			box.appendChild(settingsTable(plan, !!days));
			box.appendChild(el('p', days
				/* translators: %s: plugin name */
				? sprintf(__('Tick the settings to carry over with the import. Only SEO Pro Analytics settings still at their default can be filled in; %s\'s own settings are never changed.', 'seoproanalytics'), plan.name)
				/* translators: %s: plugin name */
				: sprintf(__('Settings are carried over with an import; there are no days to import now. %s\'s own settings are never changed.', 'seoproanalytics'), plan.name), 'description'));
		}

		if (days) {
			var actions = el('div', '', 'seoproan-import__actions');
			var go = el('button', __('Import', 'seoproanalytics'), 'button button-primary');
			go.type = 'button';
			go.setAttribute('data-seoproan-action', 'import');
			actions.appendChild(go);
			actions.appendChild(el('span', __('Runs in the background; each day can be undone with the import.', 'seoproanalytics'), 'description'));
			box.appendChild(actions);
		}
		box.querySelector('h4').setAttribute('tabindex', '-1');
		box.querySelector('h4').focus();
	}

	function chosen(card) {
		var box = card.querySelector('[data-seoproan-plan]');
		var picked = box.querySelector('[data-seoproan-field="prefer"]:checked');
		return picked ? picked.value : (box.getAttribute('data-seoproan-prefer') || '');
	}

	var kinds = [
		['tables', __('Database tables', 'seoproanalytics')],
		['options', __('Options', 'seoproanalytics')],
		['transients', __('Transients', 'seoproanalytics')],
		['cron', __('Scheduled tasks', 'seoproanalytics')],
		['user_meta', __('User settings', 'seoproanalytics')],
		['post_meta', __('Post data', 'seoproanalytics')],
		['roles', __('User roles', 'seoproanalytics')],
		['files', __('Files and folders (in wp-content)', 'seoproanalytics')]
	];

	function drawLeftovers(card, answer) {
		var box = card.querySelector('[data-seoproan-leftovers]');
		box.textContent = '';
		var list = answer.leftovers || {};
		var any = false;
		kinds.forEach(function (kind) {
			var items = list[kind[0]] || [];
			if (!items.length) {
				return;
			}
			any = true;
			box.appendChild(el('h4', sprintf('%1$s (%2$s)', kind[1], number(items.length))));
			var ul = el('ul', '', 'seoproan-import__list');
			items.forEach(function (item) {
				var li = el('li');
				li.appendChild(el('code', item));
				ul.appendChild(li);
			});
			box.appendChild(ul);
		});
		var network = list.network || {};
		if (Object.keys(network).length) {
			box.appendChild(el('p', __('Shared by the whole network and left in place (remove them from the network, once no site uses the plugin):', 'seoproanalytics'), 'description'));
			var nl = el('ul', '', 'seoproan-import__list');
			Object.keys(network).forEach(function (kind) {
				network[kind].forEach(function (item) {
					var li = el('li');
					li.appendChild(el('code', item));
					nl.appendChild(li);
				});
			});
			box.appendChild(nl);
		}
		if (!any) {
			box.appendChild(el('p', __('Nothing is left on this site.', 'seoproanalytics')));
			return;
		}
		box.appendChild(el('p', __('This cannot be undone. Make a database backup first. The days already imported into SEO Pro Analytics stay.', 'seoproanalytics'), 'description'));
		var actions = el('div', '', 'seoproan-import__actions');
		var go = el('button', __('Remove leftover data', 'seoproanalytics'), 'button button-link-delete');
		go.type = 'button';
		go.setAttribute('data-seoproan-action', 'cleanup');
		actions.appendChild(go);
		box.appendChild(actions);
		box.querySelector('h4').setAttribute('tabindex', '-1');
		box.querySelector('h4').focus();
	}

	// While an import runs: read the status (each read moves it on) until it
	// ends, then reload.
	function poll() {
		var bar = root.querySelector('[data-seoproan-progress] progress');
		var text = root.querySelector('[data-seoproan-progress-text]');
		wp.apiFetch({ path: base + 'migrate' })
			.then(function (status) {
				var job = status.job || {};
				var done = 0;
				var total = 0;
				var waiting = '';
				(job.queue || []).forEach(function (item) {
					done += item.done;
					total += item.total;
					// A remote source (Jetpack Stats on WordPress.com) asked to wait.
					waiting = item.notice || waiting;
				});
				if (bar) {
					bar.max = Math.max(1, total);
					bar.value = done;
				}
				if (text) {
					/* translators: 1: days done, 2: days in all */
					text.textContent = sprintf(__('%1$d of %2$d days. It carries on in the background; you can leave this page.', 'seoproanalytics'), done, total) + (waiting ? ' ' + waiting : '');
				}
				if (job.status === 'running') {
					window.setTimeout(poll, 1500);
				} else {
					reload(__('Import finished.', 'seoproanalytics'));
				}
			})
			.catch(function () {
				window.setTimeout(poll, 5000);
			});
	}

	var actions = {
		refresh: function (card, button) {
			request(card, button, { path: base + 'migrate?fresh=1' }, function () {
				reload(__('Looked again.', 'seoproanalytics'));
			});
		},

		plan: function (card, button, source) {
			request(card, button, {
				path: base + 'migrate/' + source,
				method: 'POST',
				data: { dry_run: true }
			}, function (plan) {
				drawPlan(card, plan);
			});
		},

		import: function (card, button, source) {
			var data = { prefer: chosen(card) };
			var settings = chosenSettings(card);
			if (settings !== null) {
				data.settings = settings;
			}
			request(card, button, {
				path: base + 'migrate/' + source,
				method: 'POST',
				data: data
			}, function () {
				reload(__('The import has started.', 'seoproanalytics'));
			});
		},

		undo: function (card, button) {
			var id = button.getAttribute('data-seoproan-import');
			/* translators: %s: import number */
			if (!window.confirm(sprintf(__('Delete the days import #%s added?', 'seoproanalytics'), id))) {
				return;
			}
			request(card, button, {
				path: base + 'imports/' + id,
				method: 'DELETE'
			}, function (answer) {
				/* translators: %s: number of rows */
				reload(sprintf(__('Undone: %s rows deleted.', 'seoproanalytics'), number(answer.deleted)));
			});
		},

		crawlUndo: function (card, button) {
			var id = button.getAttribute('data-seoproan-import');
			/* translators: %s: import number */
			if (!window.confirm(sprintf(__('Delete the pages and links crawl import #%s added?', 'seoproanalytics'), id))) {
				return;
			}
			request(card, button, {
				path: base + 'crawl/imports/' + id,
				method: 'DELETE'
			}, function (answer) {
				/* translators: %s: number of rows */
				reload(sprintf(__('Undone: %s rows deleted.', 'seoproanalytics'), number(answer.deleted)));
			});
		},

		leftovers: function (card, button, source) {
			request(card, button, {
				path: base + 'migrate/' + source + '/cleanup',
				method: 'POST',
				data: { dry_run: true }
			}, function (answer) {
				drawLeftovers(card, answer);
			});
		},

		cleanup: function (card, button, source) {
			if (!window.confirm(__('Remove everything listed? This cannot be undone.', 'seoproanalytics'))) {
				return;
			}
			request(card, button, {
				path: base + 'migrate/' + source + '/cleanup',
				method: 'POST',
				data: { dry_run: false }
			}, function (answer) {
				var removed = 0;
				Object.keys(answer.removed || {}).forEach(function (kind) {
					removed += answer.removed[kind];
				});
				/* translators: %s: number of items */
				reload(sprintf(__('Removed %s items.', 'seoproanalytics'), number(removed)));
			});
		}
	};

	root.addEventListener('click', function (event) {
		var button = event.target.closest('[data-seoproan-action]');
		var card = button ? button.closest('[data-seoproan-source]') : null;
		var action = button ? actions[button.getAttribute('data-seoproan-action')] : null;
		if (!card || !action) {
			return;
		}
		event.preventDefault();
		action(card, button, card.getAttribute('data-seoproan-source'));
	});

	if (root.getAttribute('data-seoproan-job') === 'running') {
		poll();
	}
})(window.wp);

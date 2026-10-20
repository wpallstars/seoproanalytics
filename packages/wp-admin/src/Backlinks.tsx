/**
 * Search → Backlinks: pages of other sites that link to the site's pages,
 * found without an outside service. Once a day the site opens the pages
 * that sent visits and reads their links to it (SEOProAnalytics_Backlinks), so
 * a link from a site that never sent a visit is not here.
 *
 * Five lists: live links (newest first), the sites linking (most visits
 * first), the site's pages linked to (most sites first), the links lost
 * in the period, and the referring pages link exports named (Settings →
 * Import → Links) with their check; a reported page's link count opens
 * its links (the page linked to, text and rel). The period counts new and lost links
 * and the sites' visits; filters do not apply. Choosing one
 * of the site's pages opens it in Rankings.
 *
 * Two more join the links with search data, from the engine chosen:
 * Needs links (pages with queries in striking distance and fewer useful
 * referring domains than the site's pages in the top three, with research
 * menus for where links could come from) and Link impact (pages' search
 * before and after they gained useful referring domains, against the
 * whole site: associated, never shown as caused, with Start an
 * experiment from the change to test it).
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 */

import { Fragment, useState } from 'react';
import { addQueryArgs } from '@wordpress/url';
import { Button, Card, CardBody, CardHeader, Notice, SelectControl, TextareaControl } from '@wordpress/components';
import { __, _n, sprintf } from '@wordpress/i18n';
import { BACKLINK_METRICS, BACKLINK_SOURCES, formatChange, formatDecimal, formatNumber, formatPlaces, pageTopic, type BacklinkClassifyScope, type BacklinkDecision, type BacklinkDomainRow, type BacklinkImpactRow, type BacklinkKind, type BacklinkMixClass, type BacklinkNeedRow, type BacklinkPageRow, type BacklinkReportedRow, type BacklinkReportedState, type BacklinkRow, type BacklinkSort, type BacklinkSource, type BacklinksAnswer, type SortOrder } from '@seoproanalytics/core';
import { checkBacklinks, downloadDisavow, errorMessage, mergeDisavow, saveBacklinkDecision, scopeKey, shareAccess, useBacklinkReview, useBacklinks } from './api';
import { boot, locale } from './boot';
import { useDataSet } from './data';
import { longLabel } from './dates';
import { startFrom } from './Experiments';
import { PageCell } from './Opportunities';
import { ClassBadges, ClassesBar, ClassifyMenu, isFiltered, metricHint, metricName, toTriage, TRIAGE_DEFAULT, TriageBar, useSettled, type ClassifyItem, type TriageChoice } from './components/BacklinkTriage';
import { LoadState, Pager } from './components/Pager';
import { ResearchMenu } from './components/ResearchMenu';
import type { SearchPick, SearchReportProps } from './components/SearchSetup';
import { SortHeader } from './components/SortHeader';
import { TableScroll } from './components/TableScroll';

const PER_PAGE = 25;

/** Link impact: days compared on each side of a link (SEOProAnalytics_Backlink_Needs::WINDOW). */
const IMPACT_DAYS = 28;

const number = (value: number) => formatNumber(value, locale, false);

/** A list's name. */
export function backlinkKindName(kind: BacklinkKind): string {
	const names: Record<BacklinkKind, string> = {
		links: __('Links', 'seoproanalytics'),
		domains: __('Sites linking', 'seoproanalytics'),
		pages: __('Pages linked to', 'seoproanalytics'),
		lost: __('Lost links', 'seoproanalytics'),
		reported: __('Reported pages', 'seoproanalytics'),
		needs: __('Needs links', 'seoproanalytics'),
		impact: __('Link impact', 'seoproanalytics'),
	};
	return names[kind];
}

/** The lists that read search data too: their counts are known only once open. */
const SEARCH_KINDS: readonly BacklinkKind[] = ['needs', 'impact'];

/** A list's count, from the totals; for needs and impact, the open answer's total, else null. */
function count(kind: BacklinkKind, answer: BacklinksAnswer | undefined): number | null {
	if (kind === 'needs' || kind === 'impact') {
		return answer?.kind === kind ? answer.total : null;
	}
	return answer ? answer.totals[kind] : null;
}

/** The tiles, which pick the list: sites first, then the lists with search data. */
const TILE_ORDER: readonly BacklinkKind[] = ['domains', 'links', 'pages', 'lost', 'reported', 'needs', 'impact'];

/** Under a tile's figure: what is new in the period. */
function tileFoot(kind: BacklinkKind, totals: BacklinksAnswer['totals']): string {
	if (kind === 'needs') {
		return __('Pages at positions 4–20', 'seoproanalytics');
	}
	if (kind === 'impact') {
		return __('Search before and after', 'seoproanalytics');
	}
	if (kind === 'reported') {
		/* translators: %s: number of reported pages opened by the check. */
		return sprintf(__('%s checked', 'seoproanalytics'), number(totals.reported_checked));
	}
	if (kind === 'domains') {
		/* translators: %s: number of sites first linking in the period. */
		return sprintf(__('%s new in the period', 'seoproanalytics'), number(totals.new_domains));
	}
	if (kind === 'links') {
		/* translators: %s: number of links first found in the period. */
		return sprintf(__('%s new in the period', 'seoproanalytics'), number(totals.new));
	}
	if (kind === 'lost') {
		return __('In the period', 'seoproanalytics');
	}
	return __('Of this site', 'seoproanalytics');
}

/** An ISO time as a day, or –. */
function day(iso: string | null | undefined): string {
	return iso ? longLabel(iso.slice(0, 10), 'day') : '–';
}

type BacklinksProps = SearchReportProps & {
	open: (pick: SearchPick) => void;
};

export function Backlinks({ state, update, open }: Readonly<BacklinksProps>) {
	const [review, setReview] = useState(false);
	// Here, so the filters stay while Review is open.
	const triage = useTriage(state.backlinks ?? 'links');
	return (
		<Card className="seoproan-app-card is-wide seoproan-app-section" size="small">
			<CardHeader className="seoproan-app-card__header">
				<h2 className="seoproan-app-card__title">{__('Backlinks', 'seoproanalytics')}</h2>
				{boot.canManage && !shareAccess.token && <Button variant="secondary" aria-pressed={review} onClick={() => setReview(!review)}>{review ? __('Back to links', 'seoproanalytics') : __('Review', 'seoproanalytics')}</Button>}
			</CardHeader>
			{review ? <CardBody><BacklinkReview state={state} /></CardBody> : <BacklinkLists state={state} update={update} open={open} triage={triage} />}
		</Card>
	);
}

/**
 * Filters, search and sort of Links and Lost links (typing asks once it
 * settles), as chosen and as asked; Sites linking and Reported pages ask
 * with the classes only, Pages linked to without any.
 */
function useTriage(kind: BacklinkKind) {
	const [choice, setChoice] = useState<TriageChoice>(TRIAGE_DEFAULT);
	const triaged = kind === 'links' || kind === 'lost';
	const classed = kind === 'domains' || kind === 'reported';
	const settled = toTriage(useSettled(choice), kind);
	const triage = triaged ? settled : classed ? { classes: settled.classes } : {};
	const sorting: LinkSorting = {
		sort: (choice.sort ?? '') as BacklinkSort,
		order: choice.order ?? 'desc',
		onSort: (sort: BacklinkSort, order: SortOrder) => setChoice({ ...choice, sort, order }),
	};
	return {
		choice,
		setChoice,
		triaged,
		classed,
		triage,
		sorting,
		noClass: !!triage.classes && triage.classes.length === 0,
		filtered: triaged ? isFiltered(choice) : classed && choice.hidden.length > 0,
	};
}

type BacklinkListsProps = BacklinksProps & {
	triage: ReturnType<typeof useTriage>;
};

/** The tiles, the source and triage filters, and the chosen list. */
function BacklinkLists({ state, update, open, triage: chosen }: Readonly<BacklinkListsProps>) {
	const kind: BacklinkKind = state.backlinks ?? 'links';
	// In the address, so the Import tab's history links straight to an export's results.
	const source: BacklinkSource | '' = state.found ?? '';
	const setSource = (value: string) => update({ found: (value || undefined) as BacklinkSource | undefined });
	const { choice, setChoice, triaged, classed, triage, sorting, noClass, filtered } = chosen;
	// Back to the first rows when the period, list or filters change.
	const scope = scopeKey(state.range, state.from, state.to, kind, source, triage, SEARCH_KINDS.includes(kind) ? state.engine : '');
	const [at, setAt] = useState({ scope, offset: 0 });
	const offset = at.scope === scope ? at.offset : 0;
	const query = useBacklinks(state, kind, PER_PAGE, offset, source, triage);
	const answer = query.data;
	const [notice, setNotice] = useState('');
	// The facets only of an answer for this list.
	const facets = answer?.kind === kind ? answer.facets : undefined;

	return (
		<>
			<BacklinkTiles kind={kind} answer={answer} onPick={(k) => update({ backlinks: k === 'links' ? undefined : k })} />
			<CardBody className="seoproan-app-card__body">
				<SelectControl label={__('How found', 'seoproanalytics')} value={source} onChange={setSource} options={[{ label: __('All sources', 'seoproanalytics'), value: '' }, ...BACKLINK_SOURCES.map((value) => ({ value, label: foundLabel([value]) }))]} />
				{triaged && <TriageBar kind={kind} choice={choice} onChange={setChoice} facets={facets} />}
				{classed && <ClassesBar kind={kind} choice={choice} onChange={setChoice} facets={facets} />}
				{notice && (
					<Notice status="success" onRemove={() => setNotice('')} className="seoproan-notice">
						{notice}
					</Notice>
				)}
				<LoadState query={query} failed={__('The backlinks could not be loaded. Reload the page to try again.', 'seoproanalytics')} />
				{noClass && (
					<div className="seoproan-app-empty">
						<p>{__('No class is ticked in Classes, so nothing shows.', 'seoproanalytics')}</p>
					</div>
				)}
				{!noClass && answer && (
					<BacklinkResults
						// While another list loads, the last answer stays: its rows are drawn as its own kind.
						kind={answer.kind}
						answer={answer}
						source={source}
						open={open}
						refreshing={query.isFetching}
						filtered={filtered}
						sorting={sorting}
						onClassified={setNotice}
						onExperiment={(row) => {
							startFromImpact(row);
							update({ view: 'search', report: 'experiments', change: String(row.change_id), backlinks: undefined, found: undefined, page: undefined });
						}}
						offset={offset}
						onPage={(next) => setAt({ scope, offset: next })}
					/>
				)}
			</CardBody>
		</>
	);
}

interface BacklinkTilesProps {
	kind: BacklinkKind;
	answer: BacklinksAnswer | undefined;
	onPick: (kind: BacklinkKind) => void;
}

/** The lists to pick from, each with its count (Needs links and Link impact: once open). */
function BacklinkTiles({ kind, answer, onPick }: Readonly<BacklinkTilesProps>) {
	const totals = answer?.totals;
	return (
		<div className="seoproan-tiles" role="group" aria-label={__('Show', 'seoproanalytics')}>{/* NOSONAR: a group of buttons; a fieldset would bring its own border, padding and min-width. */}
			{TILE_ORDER.map((k) => {
				const value = count(k, answer);
				return (
					<button key={k} type="button" className={`seoproan-tile${kind === k ? ' is-selected' : ''}`} aria-pressed={kind === k} onClick={() => onPick(k)}>
						<span className="seoproan-tile__label">{backlinkKindName(k)}</span>
						<span className="seoproan-tile__value">{value === null ? '–' : number(value)}</span>
						<span className="seoproan-tile__foot">
							<span className="seoproan-muted">{totals ? tileFoot(k, totals) : ''}</span>
						</span>
					</button>
				);
			})}
		</div>
	);
}

type BacklinkResultsProps = BacklinkListProps & {
	offset: number;
	onPage: (offset: number) => void;
};

/** How many rows pass the filters, in words. */
function matching(kind: BacklinkKind, total: number): string {
	if (kind === 'domains') {
		/* translators: %s: number of sites that pass the filters. */
		return sprintf(_n('%s site matches the filters.', '%s sites match the filters.', total, 'seoproanalytics'), number(total));
	}
	if (kind === 'reported') {
		/* translators: %s: number of pages that pass the filters. */
		return sprintf(_n('%s page matches the filters.', '%s pages match the filters.', total, 'seoproanalytics'), number(total));
	}
	/* translators: %s: number of links that pass the filters. */
	return sprintf(_n('%s link matches the filters.', '%s links match the filters.', total, 'seoproanalytics'), number(total));
}

/** The totals, how many rows pass the filters, the list, the check, notes and pages. */
function BacklinkResults({ offset, onPage, ...list }: Readonly<BacklinkResultsProps>) {
	const { answer, filtered } = list;
	return (
		<>
			{SEARCH_KINDS.includes(answer.kind) ? (
				<SearchIntro answer={answer} />
			) : (
				<p className="seoproan-note seoproan-opportunities__intro">
					{intro(answer)}
					{filtered && ' ' + matching(answer.kind, answer.total)}
				</p>
			)}
			<BacklinkList {...list} />
			<CheckNow answer={answer} />
			<Notes answer={answer} />
			<Pager offset={offset} perPage={PER_PAGE} total={answer.total} more={answer.more} label={__('Pages of the backlinks list', 'seoproanalytics')} onChange={onPage} />
		</>
	);
}

/** How the links lists sort, and how to change it. */
interface LinkSorting {
	sort: BacklinkSort;
	order: SortOrder;
	onSort: (sort: BacklinkSort, order: SortOrder) => void;
}

interface BacklinkListProps {
	kind: BacklinkKind;
	answer: BacklinksAnswer;
	source: string;
	open: BacklinksProps['open'];
	refreshing: boolean;
	filtered: boolean;
	sorting: LinkSorting;
	onClassified: (message: string) => void;
	/** Link impact: start an experiment from a row's backlink_new change. */
	onExperiment: (row: BacklinkImpactRow) => void;
}

/** The chosen list's table, or why it is empty. Rows are drawn as the answer's own kind. */
function BacklinkList({ kind, answer, source, open, refreshing, filtered, sorting, onClassified, onExperiment }: Readonly<BacklinkListProps>) {
	const rows = answer.rows;
	if (!rows.length) {
		return (
			<div className="seoproan-app-empty">
				<p>{filtered && kind !== 'pages' ? __('Nothing matches the filters.', 'seoproanalytics') : empty(kind, answer, source)}</p>
			</div>
		);
	}
	switch (kind) {
		case 'domains':
			return <DomainsTable rows={rows as BacklinkDomainRow[]} refreshing={refreshing} onClassified={onClassified} />;
		case 'pages':
			return <PagesTable rows={rows as BacklinkPageRow[]} open={open} refreshing={refreshing} />;
		case 'reported':
			return <ReportedTable rows={rows as BacklinkReportedRow[]} open={open} refreshing={refreshing} onClassified={onClassified} />;
		case 'needs':
			return <NeedsTable rows={rows as BacklinkNeedRow[]} open={open} refreshing={refreshing} />;
		case 'impact':
			return <ImpactTable rows={rows as BacklinkImpactRow[]} open={open} refreshing={refreshing} onExperiment={onExperiment} />;
		default:
			return <LinksTable rows={rows as BacklinkRow[]} kind={kind} open={open} refreshing={refreshing} sorting={sorting} onClassified={onClassified} />;
	}
}

/** Owner-only local decisions. A score never selects disavow automatically. */
function BacklinkReview({ state }: Readonly<{ state: SearchReportProps['state'] }>) {
	const data = useDataSet();
	const scope = JSON.stringify([data, state.range, state.from, state.to]);
	const [at, setAt] = useState({ scope, offset: 0 });
	const offset = at.scope === scope ? at.offset : 0;
	const query = useBacklinkReview(state, offset);
	const [busy, setBusy] = useState(false);
	const [error, setError] = useState('');
	const [notice, setNotice] = useState('');
	const [text, setText] = useState('');
	async function run(action: () => Promise<unknown>) {
		setBusy(true);
		setError('');
		setNotice('');
		try { await action(); } catch (caught) { setError(errorMessage(caught, __('The action could not finish.', 'seoproanalytics'))); } finally { setBusy(false); }
	}
	function decision(row: BacklinkDecision) {
		return <SelectControl label={`${row.scope === 'domain' ? __('Site decision', 'seoproanalytics') : __('URL decision', 'seoproanalytics')}: ${row.target}`} value={row.decision} disabled={busy || query.isFetching} options={[{ value: 'undecided', label: __('Undecided', 'seoproanalytics') }, { value: 'keep', label: __('Keep — never flag again', 'seoproanalytics') }, { value: 'disavow', label: __('Disavow in local file', 'seoproanalytics') }]} onChange={(value) => { void run(async () => { await saveBacklinkDecision({ ...row, decision: value as BacklinkDecision['decision'] }, data); setNotice(__('Decision saved locally. Nothing was submitted.', 'seoproanalytics')); }); }} help={row.reviewed ? `${row.imported ? __('From prior list', 'seoproanalytics') : __('Reviewed', 'seoproanalytics')} · ${new Date(row.reviewed * 1000).toLocaleString()} · ${__('User', 'seoproanalytics')} ${row.user_id}` : undefined} />;
	}
	return <div>
		<p>{__('Most sites never need to disavow links. Google recommends it only for many spammy or artificial links that caused, or are likely to cause, a manual action. These scores are review hints, not proof or a recommendation to disavow. Language and TLD alone are weak signals.', 'seoproanalytics')}</p>
		<p>{__('Download and upload manually in Search Console’s Disavow Links tool for the matching URL-prefix property, not a Domain property. Every upload replaces the old list: merge your current file first. Bing removed its disavow tool and API in October 2023. Nothing here is sent to any engine. A domain decision covers every URL of that domain; a URL keep cannot override a disavowed domain.', 'seoproanalytics')}</p>
		<TextareaControl label={__('Current disavow list (optional)', 'seoproanalytics')} value={text} onChange={setText} disabled={busy} help={__('UTF-8 .txt; at most 100,000 lines and 2 MB including export comments; URLs up to 2,048 characters.', 'seoproanalytics')} />
		<label>{__('Or load a text file', 'seoproanalytics')} <input type="file" accept=".txt,text/plain" disabled={busy} onChange={(event) => { const file = event.target.files?.[0]; if (file) { void run(async () => { if (file.size > 2097152) { throw new Error(__('The list exceeds 2 MB.', 'seoproanalytics')); } const buffer = await file.arrayBuffer(); setText(new TextDecoder('utf-8', { fatal: true }).decode(buffer)); }); } }} /></label>
		<p><Button variant="secondary" disabled={busy || !text.trim()} onClick={() => { void run(async () => { const result = await mergeDisavow(text, data); setNotice(sprintf(/* translators: %d: entries read from a prior file. */ __('%d prior entries read. Existing explicit decisions are kept.', 'seoproanalytics'), result.entries)); setText(''); }); }}>{__('Merge prior list locally', 'seoproanalytics')}</Button> <Button variant="secondary" disabled={busy || !!text.trim()} onClick={() => { void run(() => downloadDisavow(data)); }}>{__('Download disavow.txt', 'seoproanalytics')}</Button></p>
		{(error || query.isError) && <Notice status="error" isDismissible={false}>{error || errorMessage(query.error, __('Review could not load.', 'seoproanalytics'))}</Notice>}
		{notice && <Notice status="success" isDismissible={false}>{notice}</Notice>}
		<p className="seoproan-note">{__('Sites are ordered by score. Each signal contributes its displayed weight once per site, capped at 100. Missing facts add nothing. Live and lost links use the bounded backlink sample and selected period; previous decisions remain visible even outside that sample.', 'seoproanalytics')}</p>
		{query.data?.rows.map((site) => <section key={site.host}>
			<h3>{site.host} — {site.score}/100</h3>
			{decision(site.decision)}
			<ul>{site.reasons.map((reason) => <li key={reason.signal}>{reason.reason} (+{reason.weight})</li>)}</ul>
			<details><summary>{__('Links, anchors and URL decisions', 'seoproanalytics')} ({site.links.length})</summary>
				{site.links.map((link) => <div key={`${link.source} ${link.page}`}><p><SourceLink url={link.source} /> → {link.page} · {link.anchor || '–'} · {link.score}/100</p><ul>{link.reasons.map((reason) => <li key={reason.signal}>{reason.reason} (+{reason.weight})</li>)}</ul>{decision(link.decision)}</div>)}
				{site.url_decisions.filter((row) => !site.links.some((link) => link.source === row.target)).map((row) => <div key={row.target}>{decision(row)}</div>)}
			</details>
		</section>)}
		{query.data && !query.data.rows.length && <p>{__('No backlinks or prior decisions in this sample.', 'seoproanalytics')}</p>}
		<nav aria-label={__('Review pages', 'seoproanalytics')}><Button variant="secondary" disabled={busy || query.isFetching || offset === 0} onClick={() => setAt({ scope, offset: Math.max(0, offset - 25) })}>{__('Previous', 'seoproanalytics')}</Button> <Button variant="secondary" disabled={busy || query.isFetching || !query.data?.more} onClick={() => setAt({ scope, offset: offset + 25 })}>{__('Next', 'seoproanalytics')}</Button></nav>
	</div>;
}

/** The totals in a sentence. */
function intro(answer: BacklinksAnswer): string {
	const t = answer.totals;
	return sprintf(
		/* translators: 1: live links, 2: sites linking, 3: pages of this site linked to, 4: new links in the period, 5: lost links in the period. */
		__('%1$s links from %2$s sites to %3$s of this site’s pages. In the period: %4$s new, %5$s lost.', 'seoproanalytics'),
		number(t.links),
		number(t.domains),
		number(t.pages),
		number(t.new),
		number(t.lost)
	);
}

/** Why Needs links or Link impact is empty: no search data, no benchmark, or nothing found. */
function emptySearch(kind: 'needs' | 'impact', answer: BacklinksAnswer): string {
	if (answer.connected === false) {
		return __('No search data yet: connect Google Search Console or Bing Webmaster Tools in Settings → Connections.', 'seoproanalytics');
	}
	if (kind === 'impact') {
		return __('No page gained useful referring domains in the period. Try a longer period, such as the last 12 months.', 'seoproanalytics');
	}
	const top = answer.search?.top;
	if (top && !top.pages) {
		return __('No page of this site is in the top three in the period, so there is nothing to compare links with. Try a longer period.', 'seoproanalytics');
	}
	if (top && !top.median) {
		return __('The pages of this site in the top three have no useful referring domains either: links are not what sets them apart. Look at the pages’ content and searches instead.', 'seoproanalytics');
	}
	return __('No page at positions 4–20 has fewer useful referring domains than the pages of this site in the top three. Try a longer period.', 'seoproanalytics');
}

function empty(kind: BacklinkKind, answer: BacklinksAnswer, source: string): string {
	if (kind === 'needs' || kind === 'impact') {
		return emptySearch(kind, answer);
	}
	if (kind === 'reported') {
		return source
			? __('No page an export of this source named.', 'seoproanalytics')
			: __('No link export imported yet: import one in Settings → Import → Links.', 'seoproanalytics');
	}
	if (!answer.read.enabled) {
		return __('The check is off: turn on “Check pages that send visitors for links” in Settings → Data.', 'seoproanalytics');
	}
	if (!answer.read.pages) {
		return __('No other site has sent a visit from a page that could be checked yet. Pages that send visits are checked once a day.', 'seoproanalytics');
	}
	if (kind === 'lost') {
		return __('No link was lost in the period.', 'seoproanalytics');
	}
	if (answer.read.checked < answer.read.pages) {
		return answer.read.next
			? __('No link to this site found yet; the pages a link export named are being opened, a batch a minute (see Reported pages).', 'seoproanalytics')
			: __('No link to this site found yet; the rest of the pages that sent visits are checked in the daily run.', 'seoproanalytics');
	}
	return __('The pages that sent visits do not link to this site (the visits came from a link elsewhere, or one the browser did not name).', 'seoproanalytics');
}

/** Notes under the list: what was checked, and how. */
function Notes({ answer }: Readonly<{ answer: BacklinksAnswer }>) {
	const notes: string[] = [
		sprintf(
			/* translators: 1: pages checked, 2: pages known. */
			_n('%1$s of %2$s page that sent visits checked.', '%1$s of %2$s pages that sent visits checked.', answer.read.pages, 'seoproanalytics'),
			number(answer.read.checked),
			number(answer.read.pages)
		) +
			(answer.read.last
				? ' ' +
					sprintf(
						/* translators: %s: day of the last check. */
						__('Last run %s.', 'seoproanalytics'),
						day(answer.read.last)
					)
				: ''),
		sprintf(
			/* translators: 1: number of days, 2: number of checks. */
			__('Found from visits and imported link exports. While the check is on, referring pages are opened daily and each again every %1$s days. A link missing on %2$s checks in a row, or on a page that is gone, is lost. Exports are samples: missing rows never mean lost links.', 'seoproanalytics'),
			number(answer.rules.recheck_days),
			number(answer.rules.misses)
		),
	];
	if (answer.kind === 'links' || answer.kind === 'lost') {
		notes.push(valueNote());
	}
	if (answer.kind === 'needs') {
		notes.push(__('Need is the clicks within reach × the gap: a page with much to gain and fewer useful referring domains than the pages already in the top three comes first. Clicks within reach assume the site’s own click rate at position 3; a link is one of many things that move a page.', 'seoproanalytics'));
	}
	if (answer.kind === 'links' || answer.kind === 'lost') {
		notes.push(__('DR is the linking site’s or page’s rank, 0–100; LD is how many sites link to it. Both come from a backlink data provider when one is connected (the SEO Pro Analytics Connect add-on), read again every 30 days; – means not read yet. Classes are yours: a link has its own, its referring page’s and those of the lists in Settings → Backlinks (a site on a list gives every link from it the class, now and later); an outlined class came from the page or a list, and unticking it in Classify… takes it off that link only. Classes after the line in Classes (Ignore and Spam, unless changed in Settings → Backlinks → Classes) are hidden until ticked.', 'seoproanalytics'));
	}
	if (answer.kind === 'domains') {
		notes.push(__('A site’s classes are its lines on the class lists in Settings → Backlinks, which come filled in for common spam, social and link-in-bio sites. Classing a site here adds it to a list, so every link from it, now and later, has the class; unclassing it takes it off, or adds an exception (!example.com) when a wider line gave it the class. Home Page, Listicle, Statistics, Quote, Guest Post and Needs Updating describe one page, not a site, so they are classed per link or page. Classes after the line in Classes (Ignore and Spam, unless changed in Settings → Backlinks → Classes) are hidden until ticked.', 'seoproanalytics'));
	}
	if (answer.kind === 'reported') {
		notes.push(__('A page’s classes go to every link on it, found now or later, and a class taken off the page is taken off its links too, unless a link was classed itself. Classes after the line in Classes (Ignore and Spam, unless changed in Settings → Backlinks → Classes) are hidden until ticked.', 'seoproanalytics'));
	}
	if (answer.totals.reported) {
		notes.push(
			sprintf(
				/* translators: 1: pages link exports named, 2: their sites, 3: of those, the pages opened. */
				__('Link exports named %1$s referring pages on %2$s sites; %3$s opened so far. Search Console names the page linking, not the page it links to, so its links show here once their page is opened; links an export reported are dated from the export, not marked new.', 'seoproanalytics'),
				number(answer.totals.reported),
				number(answer.totals.reported_domains),
				number(answer.totals.reported_checked)
			)
		);
	}
	if (answer.read.next) {
		notes.push(
			sprintf(
				/* translators: %s: time of the next catch-up check. */
				__('Catching up: the next batch of reported pages opens at %s.', 'seoproanalytics'),
				new Date(answer.read.next).toLocaleTimeString(locale, { hour: 'numeric', minute: '2-digit' })
			)
		);
	}
	return (
		<div className="seoproan-note">
			{notes.map((note) => (
				<p key={note}>{note}</p>
			))}
		</div>
	);
}

/** rel words, or followed. */
function relLabel(rel: BacklinkRow['rel']): string {
	return rel.length ? rel.join(', ') : __('followed', 'seoproanalytics');
}

/** How a link was found. */
function foundLabel(found: string[]): string {
	const names: Record<string, string> = { referrer: __('A visit', 'seoproanalytics'), dataforseo: 'DataForSEO', gsc: 'Search Console export', ahrefs: 'Ahrefs', semrush: 'Semrush', majestic: 'Majestic export', moz: 'Moz export', bing: 'Bing Webmaster Tools', generic: __('CSV export', 'seoproanalytics'), verified: __('Page check', 'seoproanalytics') };
	return found.map((how) => names[how] ?? how).join(', ') || '–';
}

/** The site's page: opens in Rankings. */
function PageButton({ page, open }: Readonly<{ page: string; open: BacklinksProps['open'] }>) {
	return (
		<button type="button" className="seoproan-link" title={__('Open in Rankings', 'seoproanalytics')} onClick={() => open({ page, query: '' })}>
			{page}
		</button>
	);
}

/** The other site's page, in a new tab, never followed from here. */
function SourceLink({ url }: Readonly<{ url: string }>) {
	return (
		<a href={url} target="_blank" rel="noopener noreferrer nofollow" className="seoproan-link">
			{url.replace(/^https:\/\//, '')}
			<span className="screen-reader-text">{__('(opens in a new tab)', 'seoproanalytics')}</span>
		</a>
	);
}

/** A metric's figure, or – when not read. */
function metric(value: number | null | undefined): string {
	return value === null || value === undefined ? '–' : number(value);
}

/** What a link's value was worked out from, as its cell's tooltip. */
function valueHint(from: BacklinkRow['value_from'] | undefined): string {
	if (from === 'rank') {
		return __('From the linking site’s and page’s rank (DR), with its rel and classes.', 'seoproanalytics');
	}
	if (from === 'authority') {
		return __('From the link export’s authority score, with its rel and classes.', 'seoproanalytics');
	}
	return __('No rank read yet: a low starting value, with its rel and classes. A backlink data provider (the SEO Pro Analytics Connect add-on) reads ranks.', 'seoproanalytics');
}

/** How a link's value is worked out. */
function valueNote(): string {
	return __('Value, 0–100, is an estimate to rank links by, not a figure from any search engine: the mean of the linking site’s and page’s DR where read (else the export’s authority, else 10), × 0.3 for nofollow or ugc and × 0.1 for sponsored, then by class: × 0 for Ignore, Spam or URL Unlinked; × 0.1 Private Blog (PBN); × 0.25 Paid Link; × 0.5 Cross-Site, Link Swap, Niche Edit, Press Release, Profile Page, Site Footer or Social Media; × 0.75 Citation of NAP, Forum, Redirect or Wiki; × 1.25 Topical. A link with several classes takes its lowest factor below 1 and its highest above 1, not all of them.', 'seoproanalytics');
}

/** The classes whose links do not make a site a useful referring domain (SEOProAnalytics_Backlinks::NOT_USEFUL). */
function notUseful(): string {
	return __('Ignore, Spam, Paid Link, Private Blog (PBN), Profile Page, Social Media or URL Unlinked', 'seoproanalytics');
}

/** Whether this person can class rows here (the owner, not on a shared report). */
function canClassify(): boolean {
	return boot.canManage && !shareAccess.token;
}

/** Ticked rows of a table, by key; only those still on this page count. */
function useTicks<K extends string | number>(keys: K[]) {
	const [ticked, setTicked] = useState<K[]>([]);
	const chosen = keys.filter((key) => ticked.includes(key));
	return {
		chosen,
		all: keys.length > 0 && chosen.length === keys.length,
		has: (key: K) => ticked.includes(key),
		toggle: (key: K) => setTicked(ticked.includes(key) ? ticked.filter((item) => item !== key) : [...ticked, key]),
		toggleAll: () => setTicked(keys.length > 0 && chosen.length === keys.length ? [] : keys),
		clear: () => setTicked([]),
	};
}

interface BulkProps {
	items: ClassifyItem[];
	scope: BacklinkClassifyScope;
	/** The menu's legend, naming the ticked rows. */
	describe: string;
	onDone: (message: string) => void;
}

/** Above a table: class the ticked rows together. */
function BulkClassify({ items, scope, describe, onDone }: Readonly<BulkProps>) {
	return (
		<div className="seoproan-backlinks__bulk">
			<ClassifyMenu
				items={items}
				scope={scope}
				variant="secondary"
				label={items.length ? sprintf(/* translators: %s: number of ticked rows. */ __('Classify %s ticked…', 'seoproanalytics'), number(items.length)) : __('Classify ticked…', 'seoproanalytics')}
				describe={describe}
				onDone={onDone}
			/>
		</div>
	);
}

/** The tick box of a row, or of every row in the header. */
function Tick({ checked, label, onChange, header = false }: Readonly<{ checked: boolean; label: string; onChange: () => void; header?: boolean }>) {
	const box = <input type="checkbox" checked={checked} aria-label={label} onChange={onChange} />;
	return header ? <td className="check-column seoproan-backlinks__tick">{box}</td> : <th scope="row" className="check-column seoproan-backlinks__tick">{box}</th>;
}

/** A link as the classify menu takes it. */
function linkItem(row: BacklinkRow): ClassifyItem {
	return { id: row.id, classes: row.classes ?? [], inherited: row.inherited, host: row.host };
}

interface LinksTableProps {
	rows: BacklinkRow[];
	kind: BacklinkKind;
	open: BacklinksProps['open'];
	refreshing: boolean;
	sorting: LinkSorting;
	onClassified: (message: string) => void;
}

/**
 * Live or lost links. The owner classes a link from its row, or ticks
 * rows and classes them together; either can class every link from the
 * site. Headers wrap, so the figures' columns stay narrow.
 */
function LinksTable({ rows, kind, open, refreshing, sorting, onClassified }: Readonly<LinksTableProps>) {
	const classify = canClassify();
	const ticks = useTicks(rows.map((row) => row.id));
	const chosen = rows.filter((row) => ticks.has(row.id));
	const done = (message: string) => {
		ticks.clear();
		onClassified(message);
	};
	const sortable = { sort: sorting.sort, order: sorting.order, onSort: sorting.onSort };
	return (
		<>
			{classify && <BulkClassify items={chosen.map(linkItem)} scope="links" describe={__('Classes of the ticked links', 'seoproanalytics')} onDone={done} />}
			<TableScroll label={backlinkKindName(kind)}>
				<table className={`widefat striped seoproan-table seoproan-backlinks__links seoproan-backlinks__table${refreshing ? ' is-refreshing' : ''}`}>
					<thead>
						<tr>
							{classify && <Tick header checked={ticks.all} label={__('Tick every link on this page', 'seoproanalytics')} onChange={ticks.toggleAll} />}
							<SortHeader<BacklinkSort> column="source" label={__('Linking page', 'seoproanalytics')} type="text" className="seoproan-backlinks__source" {...sortable} />
							<th scope="col" className="seoproan-backlinks__class-col">{__('Classes', 'seoproanalytics')}</th>
							<SortHeader<BacklinkSort> column="page" label={__('Links to', 'seoproanalytics')} type="text" className="seoproan-backlinks__target" {...sortable} />
							<SortHeader<BacklinkSort> column="anchor" label={__('Text', 'seoproanalytics')} type="text" className="seoproan-backlinks__anchor" {...sortable} />
							<th scope="col">{__('Rel', 'seoproanalytics')}</th>
							<SortHeader<BacklinkSort> column="value" label={__('Value', 'seoproanalytics')} className="num seoproan-backlinks__metric" {...sortable} />
							{BACKLINK_METRICS.map((m) => (
								<SortHeader<BacklinkSort> key={m} column={m} label={metricName(m)} className="num seoproan-backlinks__metric" {...sortable} />
							))}
							<SortHeader<BacklinkSort> column="first_seen" label={__('First found', 'seoproanalytics')} type="date" className="seoproan-backlinks__day" {...sortable} />
							{kind === 'lost' ? <SortHeader<BacklinkSort> column="lost" label={__('Lost', 'seoproanalytics')} type="date" className="seoproan-backlinks__day" {...sortable} /> : <SortHeader<BacklinkSort> column="last_seen" label={__('Last found', 'seoproanalytics')} type="date" className="seoproan-backlinks__day" {...sortable} />}
							<th scope="col">{__('Found by', 'seoproanalytics')}</th>
						</tr>
					</thead>
					<tbody>
						{rows.map((row) => {
							const isTicked = ticks.has(row.id);
							return (
								<tr key={row.id} className={isTicked ? 'is-selected' : undefined}>
									{classify && <Tick checked={isTicked} label={sprintf(/* translators: 1: linking page, 2: page of this site. */ __('Tick the link from %1$s to %2$s', 'seoproanalytics'), row.source, row.page)} onChange={() => ticks.toggle(row.id)} />}
									<td className="seoproan-backlinks__source">
										<SourceLink url={row.source} />
										<ResearchMenu site={row.host} page={row.source.replace(/^https?:\/\//, '')} />
										{row.new && kind === 'links' && <span className="seoproan-meta">{__('New in the period', 'seoproanalytics')}</span>}
									</td>
									<td className="seoproan-backlinks__class-col">
										<ClassBadges classes={row.classes ?? []} inherited={row.inherited} />
										{classify && (
											<ClassifyMenu
												items={[linkItem(row)]}
												scope="links"
												label={__('Classify…', 'seoproanalytics')}
												describe={sprintf(/* translators: %s: linking page. */ __('Classes of the link from %s', 'seoproanalytics'), row.source.replace(/^https:\/\//, ''))}
												onDone={done}
											/>
										)}
									</td>
									<td className="seoproan-backlinks__target">
										<PageButton page={row.page} open={open} />
									</td>
									<td className="seoproan-backlinks__anchor">{row.anchor || '–'}</td>
									<td>{relLabel(row.rel)}</td>
									<td className="num" title={valueHint(row.value_from)}>
										{row.value === undefined ? '–' : number(row.value)}
									</td>
									{BACKLINK_METRICS.map((m) => (
										<td key={m} className="num" title={metricHint(m)}>
											{metric(row[m])}
										</td>
									))}
									<td className="seoproan-backlinks__day">{day(row.first_seen)}</td>
									<td className="seoproan-backlinks__day">{day(kind === 'lost' ? row.lost : row.last_seen)}</td>
									<td>{foundLabel(row.found)}{Object.entries(row.providers ?? {}).map(([provider, facts]) => <span className="seoproan-meta" key={provider}>{foundLabel([provider])}: {facts.authority ?? '–'} · {day(facts.last_seen)}</span>)}</td>
								</tr>
							);
						})}
					</tbody>
				</table>
			</TableScroll>
		</>
	);
}

/** A site as the classify menu takes it: its classes are its lines on the class lists. */
function siteItem(row: BacklinkDomainRow): ClassifyItem {
	return { id: 0, classes: row.classes ?? [], inherited: row.inherited, host: row.host };
}

/** The sites linking. Classing a site puts it on the class lists, so every link from it has the class. */
function DomainsTable({ rows, refreshing, onClassified }: Readonly<{ rows: BacklinkDomainRow[]; refreshing: boolean; onClassified: (message: string) => void }>) {
	const classify = canClassify();
	const ticks = useTicks(rows.map((row) => row.host));
	const chosen = rows.filter((row) => ticks.has(row.host));
	const done = (message: string) => {
		ticks.clear();
		onClassified(message);
	};
	return (
		<>
			{classify && <BulkClassify items={chosen.map(siteItem)} scope="sites" describe={__('Classes of the ticked sites', 'seoproanalytics')} onDone={done} />}
		<TableScroll label={backlinkKindName('domains')}>
			<table className={`widefat striped seoproan-table seoproan-backlinks__table${refreshing ? ' is-refreshing' : ''}`}>
				<thead>
					<tr>
						{classify && <Tick header checked={ticks.all} label={__('Tick every site on this page', 'seoproanalytics')} onChange={ticks.toggleAll} />}
						<th scope="col">{__('Site', 'seoproanalytics')}</th>
						<th scope="col" className="seoproan-backlinks__class-col">{__('Classes', 'seoproanalytics')}</th>
						<th scope="col" className="num">
							{__('Links', 'seoproanalytics')}
						</th>
						<th scope="col" className="num">
							{__('Followed', 'seoproanalytics')}
						</th>
						<th scope="col" className="num">
							{__('Pages linked to', 'seoproanalytics')}
						</th>
						<th scope="col" className="num">
							{__('New', 'seoproanalytics')}
						</th>
						<th scope="col" className="num">
							{__('Lost', 'seoproanalytics')}
						</th>
						<th scope="col" className="num">
							{__('Visits', 'seoproanalytics')}
						</th>
						<th scope="col">{__('First found', 'seoproanalytics')}</th>
					</tr>
				</thead>
				<tbody>
					{rows.map((row) => {
						const isTicked = ticks.has(row.host);
						return (
							<tr key={row.host} className={isTicked ? 'is-selected' : undefined}>
								{classify && <Tick checked={isTicked} label={sprintf(/* translators: %s: a site, e.g. "example.com". */ __('Tick %s', 'seoproanalytics'), row.host)} onChange={() => ticks.toggle(row.host)} />}
								<td>
									{row.host}
									<ResearchMenu site={row.host} />
								</td>
								<td className="seoproan-backlinks__class-col">
									<ClassBadges classes={row.classes ?? []} inherited={row.inherited} />
									{classify && (
										<ClassifyMenu
											items={[siteItem(row)]}
											scope="sites"
											label={__('Classify…', 'seoproanalytics')}
											describe={sprintf(/* translators: %s: a site, e.g. "example.com". */ __('Classes of every link from %s', 'seoproanalytics'), row.host)}
											onDone={done}
										/>
									)}
								</td>
								<td className="num">{number(row.links)}</td>
								<td className="num">{number(row.followed)}</td>
								<td className="num">{number(row.pages)}</td>
								<td className="num">{number(row.new)}</td>
								<td className="num">{number(row.lost)}</td>
								<td className="num">{number(row.visits)}</td>
								<td>{day(row.first_seen)}</td>
							</tr>
						);
					})}
				</tbody>
			</table>
		</TableScroll>
		</>
	);
}

/** A class mix entry's name. */
function mixName(name: BacklinkMixClass): string {
	const names: Record<BacklinkMixClass, string> = {
		topical: __('topical', 'seoproanalytics'),
		general: __('general', 'seoproanalytics'),
		press_release: __('press', 'seoproanalytics'),
		profile_page: __('profile', 'seoproanalytics'),
		social_media: __('social', 'seoproanalytics'),
		spam: __('spam', 'seoproanalytics'),
	};
	return names[name];
}

/** A page's class mix: sites with a link of each class, the ones with any. */
function MixList({ mix }: Readonly<{ mix: BacklinkPageRow['mix'] }>) {
	const shown = (Object.keys(mix) as BacklinkMixClass[]).filter((name) => mix[name] > 0);
	if (!shown.length) {
		return <span className="seoproan-muted">–</span>;
	}
	return <>{shown.map((name) => `${mixName(name)} ${number(mix[name])}`).join(' · ')}</>;
}

/** A page of this site as a research subject: host and path. */
function ownAddress(page: string): string {
	return boot.siteHost ? `${boot.siteHost}${page.startsWith('/') ? page : `/${page}`}` : '';
}

function PagesTable({ rows, open, refreshing }: Readonly<{ rows: BacklinkPageRow[]; open: BacklinksProps['open']; refreshing: boolean }>) {
	return (
		<>
			<Notice status="info" isDismissible={false} className="seoproan-notice">
				<p>
					{sprintf(
						/* translators: %s: classes, e.g. "Ignore, Spam or Profile Page". */
						__('Most useful referring domains first: sites with a followed link that is not classed %s. Many links from profile or social pages count for less than a few topical ones. The class mix counts the sites with a link of each class; DR is the linking sites’ median rank, where read.', 'seoproanalytics'),
						notUseful()
					)}
				</p>
			</Notice>
			<TableScroll label={backlinkKindName('pages')}>
				<table className={`widefat striped seoproan-table${refreshing ? ' is-refreshing' : ''}`}>
					<thead>
						<tr>
							<th scope="col">{__('Page', 'seoproanalytics')}</th>
							<th scope="col" className="num" title={/* translators: %s: classes, e.g. "Ignore, Spam or Profile Page". */ sprintf(__('Sites with a followed link not classed %s', 'seoproanalytics'), notUseful())}>
								{__('Useful sites', 'seoproanalytics')}
							</th>
							<th scope="col" className="num">
								{__('Sites linking', 'seoproanalytics')}
							</th>
							<th scope="col" className="num" title={__('The linking sites’ median rank (DR, 0–100), where read', 'seoproanalytics')}>
								{__('Median DR', 'seoproanalytics')}
							</th>
							<th scope="col">{__('Class mix', 'seoproanalytics')}</th>
							<th scope="col" className="num">
								{__('Links', 'seoproanalytics')}
							</th>
							<th scope="col" className="num">
								{__('New', 'seoproanalytics')}
							</th>
							<th scope="col">{__('First found', 'seoproanalytics')}</th>
						</tr>
					</thead>
					<tbody>
						{rows.map((row) => (
							<tr key={row.page}>
								<td>
									<PageButton page={row.page} open={open} />
									<ResearchMenu page={ownAddress(row.page)} topic={pageTopic(row.page)} label={sprintf(/* translators: %s: a page of this site. */ __('Research %s and its link prospects', 'seoproanalytics'), row.page)} />
								</td>
								<td className="num">{number(row.useful)}</td>
								<td className="num">
									{number(row.domains)}
									<span className="seoproan-meta">
										{sprintf(/* translators: %s: number of sites with a followed link. */ __('%s followed', 'seoproanalytics'), number(row.followed))}
									</span>
								</td>
								<td className="num">{row.median_dr === null ? '–' : formatDecimal(row.median_dr, locale)}</td>
								<td>
									<MixList mix={row.mix} />
								</td>
								<td className="num">{number(row.links)}</td>
								<td className="num">{number(row.new)}</td>
								<td>{day(row.first_seen)}</td>
							</tr>
						))}
					</tbody>
				</table>
			</TableScroll>
		</>
	);
}

/**
 * The owner's Check now (live data), and where link exports are imported.
 * The check opens pages for up to 20 seconds; the catch-up carries on.
 */
function CheckNow({ answer }: Readonly<{ answer: BacklinksAnswer }>) {
	const data = useDataSet();
	const [busy, setBusy] = useState(false);
	const [message, setMessage] = useState<{ status: 'success' | 'error'; text: string } | null>(null);
	if (!boot.canManage || shareAccess.token || data !== 'live') {
		return null;
	}
	const importUrl = boot.settingsUrl ? addQueryArgs(boot.settingsUrl, { tab: 'import' }) : '';
	async function check() {
		setBusy(true);
		setMessage(null);
		try {
			const done = await checkBacklinks();
			setMessage({
				status: 'success',
				text:
					sprintf(
						/* translators: 1: links found new, 2: links lost. */
						__('Checked: %1$s new links, %2$s lost.', 'seoproanalytics'),
						number(done.links_new),
						number(done.links_lost)
					) +
					(done.waiting
						? ' ' +
							sprintf(
								/* translators: %s: reported pages not opened yet. */
								_n('%s reported page waits; it opens in the background.', '%s reported pages wait; they open in the background, a batch a minute.', done.waiting, 'seoproanalytics'),
								number(done.waiting)
							)
						: ''),
			});
		} catch (caught) {
			setMessage({ status: 'error', text: errorMessage(caught, __('The check could not run.', 'seoproanalytics')) });
		} finally {
			setBusy(false);
		}
	}
	const pending = answer.totals.reported - answer.totals.reported_checked;
	return (
		<div className="seoproan-backlinks__actions">
			<p>
				<Button variant="secondary" isBusy={busy} disabled={busy} onClick={() => void check()}>
					{__('Check now', 'seoproanalytics')}
				</Button>{' '}
				{importUrl && <a href={importUrl}>{__('Import a link export', 'seoproanalytics')}</a>}
			</p>
			{!message && pending > 0 && !answer.read.enabled && (
				<Notice status="warning" isDismissible={false} className="seoproan-notice">
					{__('Reported pages are opened only while “Check pages that send visitors for links” is on in Settings → Data; Check now opens a batch once.', 'seoproanalytics')}
				</Notice>
			)}
			{message && (
				<Notice status={message.status} isDismissible={false} className="seoproan-notice">
					{message.text}
				</Notice>
			)}
		</div>
	);
}

/** A reported page's check, in words. */
function stateLabel(state: BacklinkReportedState, links: number): string {
	switch (state) {
		case 'links':
			/* translators: %s: links to this site on the page. */
			return sprintf(_n('%s link to this site', '%s links to this site', links, 'seoproanalytics'), number(links));
		case 'none':
			// The check saw no link, but links added by scripts or hidden from automated visits can still be there.
			return __('Visit the link to check for backlinks', 'seoproanalytics');
		case 'error':
			return __('Could not be opened; tried again later', 'seoproanalytics');
		case 'gone':
			return __('Gone', 'seoproanalytics');
		default:
			return __('Not checked yet', 'seoproanalytics');
	}
}

/** A referring page as the classify menu takes it. */
function pageItem(row: BacklinkReportedRow): ClassifyItem {
	return { id: row.id, classes: row.classes ?? [], inherited: row.inherited, host: row.host };
}

/**
 * The reported pages. A page with links to the site opens a row under it
 * with each link: the page of this site it links to, its text and rel.
 * A page's classes go to every link on it, found now or later.
 */
function ReportedTable({ rows, open, refreshing, onClassified }: Readonly<{ rows: BacklinkReportedRow[]; open: BacklinksProps['open']; refreshing: boolean; onClassified: (message: string) => void }>) {
	const [shown, setShown] = useState<string | null>(null);
	const classify = canClassify();
	const ticks = useTicks(rows.map((row) => row.id));
	const chosen = rows.filter((row) => ticks.has(row.id));
	const done = (message: string) => {
		ticks.clear();
		onClassified(message);
	};
	const columns = classify ? 8 : 7;
	return (
		<>
			{classify && <BulkClassify items={chosen.map(pageItem)} scope="pages" describe={__('Classes of the ticked pages', 'seoproanalytics')} onDone={done} />}
		<TableScroll label={backlinkKindName('reported')}>
			<table className={`widefat striped seoproan-table seoproan-backlinks__table${refreshing ? ' is-refreshing' : ''}`}>
				<thead>
					<tr>
						{classify && <Tick header checked={ticks.all} label={__('Tick every page on this page', 'seoproanalytics')} onChange={ticks.toggleAll} />}
						<th scope="col" className="seoproan-backlinks__source">{__('Linking page', 'seoproanalytics')}</th>
						<th scope="col" className="seoproan-backlinks__class-col">{__('Classes', 'seoproanalytics')}</th>
						<th scope="col">{__('Site', 'seoproanalytics')}</th>
						<th scope="col">{__('Check', 'seoproanalytics')}</th>
						<th scope="col">{__('Reported', 'seoproanalytics')}</th>
						<th scope="col">{__('Checked', 'seoproanalytics')}</th>
						<th scope="col">{__('Found by', 'seoproanalytics')}</th>
					</tr>
				</thead>
				<tbody>
					{rows.map((row) => {
						const targets = row.state === 'links' ? row.targets : [];
						const isShown = shown === row.source && targets.length > 0;
						const isTicked = ticks.has(row.id);
						return (
							<Fragment key={row.source}>
								<tr className={isShown || isTicked ? 'is-selected' : ''}>
									{classify && <Tick checked={isTicked} label={sprintf(/* translators: %s: linking page. */ __('Tick %s', 'seoproanalytics'), row.source)} onChange={() => ticks.toggle(row.id)} />}
									<td className="seoproan-backlinks__source">
										<SourceLink url={row.source} />
										<ResearchMenu site={row.host} page={row.source.replace(/^https?:\/\//, '')} />
									</td>
									<td className="seoproan-backlinks__class-col">
										<ClassBadges classes={row.classes ?? []} inherited={row.inherited} />
										{classify && (
											<ClassifyMenu
												items={[pageItem(row)]}
												scope="pages"
												label={__('Classify…', 'seoproanalytics')}
												describe={sprintf(/* translators: %s: linking page. */ __('Classes of %s and every link on it', 'seoproanalytics'), row.source.replace(/^https:\/\//, ''))}
												onDone={done}
											/>
										)}
									</td>
									<td>{row.host}</td>
									<td>
										{targets.length > 0 ? (
											<button type="button" className="seoproan-link" aria-expanded={isShown} title={__('Show the links', 'seoproanalytics')} onClick={() => setShown(isShown ? null : row.source)}>
												{stateLabel(row.state, row.links)}
											</button>
										) : (
											stateLabel(row.state, row.links)
										)}
									</td>
									<td>{day(row.reported)}</td>
									<td>{day(row.checked)}</td>
									<td>{foundLabel(row.found)}</td>
								</tr>
								{isShown && (
									<tr className="seoproan-backlinks__detail">
										<td colSpan={columns}>
											<ReportedTargets targets={targets} open={open} />
										</td>
									</tr>
								)}
							</Fragment>
						);
					})}
				</tbody>
			</table>
		</TableScroll>
		</>
	);
}

/** A reported page's links to the site. */
function ReportedTargets({ targets, open }: Readonly<{ targets: BacklinkReportedRow['targets']; open: BacklinksProps['open'] }>) {
	return (
		<table className="widefat seoproan-table">
			<thead>
				<tr>
					<th scope="col">{__('Links to', 'seoproanalytics')}</th>
					<th scope="col">{__('Text', 'seoproanalytics')}</th>
					<th scope="col">{__('Rel', 'seoproanalytics')}</th>
					<th scope="col">{__('First found', 'seoproanalytics')}</th>
				</tr>
			</thead>
			<tbody>
				{targets.map((target) => (
					<tr key={target.page}>
						<td>
							<PageButton page={target.page} open={open} />
						</td>
						<td>{target.anchor || '–'}</td>
						<td>{relLabel(target.rel)}</td>
						<td>{day(target.first_seen)}</td>
					</tr>
				))}
			</tbody>
		</table>
	);
}

/** The engine read, in words. */
function engineName(engine: BacklinksAnswer['engine']): string {
	if (engine === 'bing') {
		return 'Bing Webmaster Tools';
	}
	return engine === 'all' ? __('every connected search engine (Combined)', 'seoproanalytics') : 'Google Search Console';
}

/** Needs links and Link impact: what the list shows, from which data, and how to read it. */
function SearchIntro({ answer }: Readonly<{ answer: BacklinksAnswer }>) {
	const search = answer.search;
	const period =
		search?.range && search.days
			? sprintf(
					/* translators: 1: number of days, 2: day of the newest search data. */
					_n('%1$s day of search data, to %2$s.', '%1$s days of search data, to %2$s.', search.days, 'seoproanalytics'),
					number(search.days),
					day(search.through)
				)
			: '';
	if (answer.kind === 'needs') {
		const top = search?.top;
		return (
			<Notice status="info" isDismissible={false} className="seoproan-notice">
				<p>
					{sprintf(
						/* translators: 1: a search engine's name, 2: classes, e.g. "Ignore, Spam or Profile Page". */
						__('Pages whose searches in %1$s sit at positions 4–20 with fewer useful referring domains than this site’s own pages in the top three. Useful: a followed link, not classed %2$s. The gap is their median less the page’s own; need is clicks within reach × gap, highest first.', 'seoproanalytics'),
						engineName(answer.engine),
						notUseful()
					)}{' '}
					{top &&
						sprintf(
							/* translators: 1: number of pages in the top three, 2: their median useful referring domains. */
							_n('%1$s page in the top three, with a median of %2$s useful referring domains.', '%1$s pages in the top three, with a median of %2$s useful referring domains.', top.pages, 'seoproanalytics'),
							number(top.pages),
							formatDecimal(top.median, locale)
						)}{' '}
					{period}
				</p>
				<p>{__('Links are one reason pages rank, not the only one: a gap says where links may help, not that they will. Only links this site has found count.', 'seoproanalytics')}</p>
				<p>{__('Open Research… on a page or query to look for where links could come from: resource and links pages, sites that take articles, “best” lists, statistics roundups and pages on the topic elsewhere. The searches open in a new tab, in the country and language chosen there; this site fetches nothing.', 'seoproanalytics')}</p>
			</Notice>
		);
	}
	const span = search?.rules.window ?? IMPACT_DAYS;
	return (
		<Notice status="info" isDismissible={false} className="seoproan-notice">
			<p>
				{sprintf(
					/* translators: 1: number of days, 2: a search engine's name, 3: classes, e.g. "Ignore, Spam or Profile Page". */
					__('Pages that gained useful referring domains in the period (a followed link, not classed %3$s), with the page’s clicks and position from %2$s in the %1$s days before the first new link was found and the %1$s days from then, next to the whole site’s change in the same days. Relative is the page’s change less the site’s, in points.', 'seoproanalytics'),
					number(span),
					engineName(answer.engine),
					notUseful()
				)}
			</p>
			<p>
				<strong>{__('Associated with the links, not proof they caused the change:', 'seoproanalytics')}</strong>{' '}
				{__('page edits, seasons, search engine updates and other links move pages too, and a link can be older than the day this site first found it. To test one, start an experiment from its change: it is measured against pages that were left alone, with a check for noise.', 'seoproanalytics')}
			</p>
		</Notice>
	);
}

/** Remember a Link impact row's change for the experiment form, as Changes does. */
function startFromImpact(row: BacklinkImpactRow): void {
	const host = row.hosts[0]?.host ?? '';
	startFrom({
		id: row.change_id ?? 0,
		t: row.first_seen,
		kind: 'backlink_new',
		group: 'seo',
		/* translators: %s: the linking site. */
		label: sprintf(__('New backlink: %s', 'seoproanalytics'), host),
		title: host,
		path: row.page,
		old: '',
		new: host,
		object: { type: 'backlink', id: 0 },
		meta: {},
		source: 'cron',
		user: null,
	});
}

/** Pages that need links, with their best queries and research menus for link prospects. */
function NeedsTable({ rows, open, refreshing }: Readonly<{ rows: BacklinkNeedRow[]; open: BacklinksProps['open']; refreshing: boolean }>) {
	return (
		<TableScroll label={backlinkKindName('needs')}>
			<table className={`widefat striped seoproan-table seoproan-backlinks__table${refreshing ? ' is-refreshing' : ''}`}>
				<thead>
					<tr>
						<th scope="col">{__('Page', 'seoproanalytics')}</th>
						<th scope="col" className="num" title={__('Clicks within reach × gap', 'seoproanalytics')}>
							{__('Need', 'seoproanalytics')}
						</th>
						<th scope="col" className="num" title={__('Clicks its queries at positions 4–20 could gain in the top three, in the period', 'seoproanalytics')}>
							{__('Clicks within reach', 'seoproanalytics')}
						</th>
						<th scope="col" className="num" title={__('The median useful referring domains of this site’s pages in the top three, less this page’s', 'seoproanalytics')}>
							{__('Gap', 'seoproanalytics')}
						</th>
						<th scope="col" className="num" title={/* translators: %s: classes, e.g. "Ignore, Spam or Profile Page". */ sprintf(__('Sites with a followed link not classed %s', 'seoproanalytics'), notUseful())}>
							{__('Useful sites', 'seoproanalytics')}
						</th>
						<th scope="col" className="num">
							{__('Sites linking', 'seoproanalytics')}
						</th>
						<th scope="col" className="num">
							{__('Impressions', 'seoproanalytics')}
						</th>
						<th scope="col" className="num">
							{__('Position', 'seoproanalytics')}
						</th>
						<th scope="col">{__('Queries to find links for', 'seoproanalytics')}</th>
					</tr>
				</thead>
				<tbody>
					{rows.map((row) => (
						<tr key={row.path_id}>
							<td>
								<PageCell row={row} query="" open={open} />
								<ResearchMenu page={ownAddress(row.page)} topic={pageTopic(row.page)} label={sprintf(/* translators: %s: a page of this site. */ __('Research %s and its link prospects', 'seoproanalytics'), row.page)} />
							</td>
							<td className="num">{number(row.need)}</td>
							<td className="num">{number(row.potential)}</td>
							<td className="num">{formatDecimal(row.gap, locale)}</td>
							<td className="num">
								{number(row.useful)}
								<span className="seoproan-meta">
									{sprintf(/* translators: %s: median useful referring domains of the pages in the top three. */ __('top three: %s', 'seoproanalytics'), formatDecimal(row.top_median, locale))}
								</span>
							</td>
							<td className="num">
								{number(row.domains)}
								<span className="seoproan-meta">
									{sprintf(/* translators: %s: number of sites with a followed link. */ __('%s followed', 'seoproanalytics'), number(row.followed))}
								</span>
							</td>
							<td className="num">{number(row.impressions)}</td>
							<td className="num">{formatDecimal(row.position, locale)}</td>
							<td>
								<ul className="seoproan-backlinks__queries">
									{row.queries.map((q) => (
										<li key={q.query_id}>
											{q.query || '–'}
											<ResearchMenu query={q.query} prospects label={sprintf(/* translators: %s: a search query. */ __('Research “%s” and its link prospects', 'seoproanalytics'), q.query)} />
											<span className="seoproan-meta">
												{sprintf(
													/* translators: 1: average position, 2: impressions, 3: clicks within reach. */
													__('Position %1$s · %2$s impressions · %3$s clicks within reach', 'seoproanalytics'),
													formatDecimal(q.position, locale),
													number(q.impressions),
													number(q.potential)
												)}
											</span>
										</li>
									))}
								</ul>
							</td>
						</tr>
					))}
				</tbody>
			</table>
		</TableScroll>
	);
}

/** A change's tone: a rise is good, a fall bad (for position, lower is better). */
function tone(change: number | null, lowerIsBetter = false): string {
	if (change === null || change === 0) {
		return 'seoproan-change is-flat';
	}
	return `seoproan-change ${change > 0 !== lowerIsBetter ? 'is-good' : 'is-bad'}`;
}

/** A link's measurement state, in words. */
function impactStatus(row: BacklinkImpactRow): string {
	if (row.status === 'measured') {
		return __('Measured', 'seoproanalytics');
	}
	if (row.status === 'measuring') {
		/* translators: 1: days measured so far, 2: days a measurement takes. */
		return sprintf(__('%1$s of %2$s days so far', 'seoproanalytics'), number(row.after.days), number(IMPACT_DAYS));
	}
	return __('Too little search data', 'seoproanalytics');
}

/** Pages that gained useful referring domains in the period, and their search before and after the first new link. */
function ImpactTable({ rows, open, refreshing, onExperiment }: Readonly<{ rows: BacklinkImpactRow[]; open: BacklinksProps['open']; refreshing: boolean; onExperiment: (row: BacklinkImpactRow) => void }>) {
	const canStart = boot.canManage && !shareAccess.token;
	return (
		<TableScroll label={backlinkKindName('impact')}>
			<table className={`widefat striped seoproan-table seoproan-backlinks__table${refreshing ? ' is-refreshing' : ''}`}>
				<thead>
					<tr>
						<th scope="col">{__('Page', 'seoproanalytics')}</th>
						<th scope="col">{__('New useful sites', 'seoproanalytics')}</th>
						<th scope="col">{__('First new link', 'seoproanalytics')}</th>
						<th scope="col" className="num">
							{__('Clicks a day before → after', 'seoproanalytics')}
						</th>
						<th scope="col" className="num">
							{__('Change', 'seoproanalytics')}
						</th>
						<th scope="col" className="num">
							{__('Whole site', 'seoproanalytics')}
						</th>
						<th scope="col" className="num" title={__('The page’s change less the whole site’s, in points', 'seoproanalytics')}>
							{__('Relative', 'seoproanalytics')}
						</th>
						<th scope="col" className="num">
							{__('Position before → after', 'seoproanalytics')}
						</th>
						<th scope="col">{__('Measurement', 'seoproanalytics')}</th>
					</tr>
				</thead>
				<tbody>
					{rows.map((row) => (
						<tr key={row.path_id}>
							<td>
								<PageCell row={row} query="" open={open} />
								<span className="seoproan-meta">
									{sprintf(/* translators: %s: number of useful referring domains now. */ _n('%s useful site now', '%s useful sites now', row.useful, 'seoproanalytics'), number(row.useful))}
								</span>
							</td>
							<td>
								<ul className="seoproan-backlinks__queries">
									{row.hosts.map((site) => (
										<li key={site.host}>
											{site.host}
											<ResearchMenu site={site.host} page={site.source.replace(/^https?:\/\//, '')} />
											<span className="seoproan-meta">
												{sprintf(/* translators: 1: day first found, 2: the site's best link value. */ __('%1$s · value %2$s', 'seoproanalytics'), day(site.first_seen), number(site.value))}
											</span>
										</li>
									))}
								</ul>
								{row.new_domains > row.hosts.length && (
									<span className="seoproan-meta">
										{sprintf(/* translators: %s: number of further new sites. */ __('and %s more', 'seoproanalytics'), number(row.new_domains - row.hosts.length))}
									</span>
								)}
							</td>
							<td>{day(row.first_seen)}</td>
							<td className="num">
								{formatDecimal(row.before.clicks, locale)} → {formatDecimal(row.after.clicks, locale)}
							</td>
							<td className="num">
								<span className={tone(row.change)}>{formatChange(row.change === null ? null : row.change / 100, locale)}</span>
							</td>
							<td className="num">{formatChange(row.site_change === null ? null : row.site_change / 100, locale)}</td>
							<td className="num">
								<span className={tone(row.relative)}>{formatPlaces(row.relative, locale)}</span>
							</td>
							<td className="num">
								{row.before.position ? formatDecimal(row.before.position, locale) : '–'} → {row.after.position ? formatDecimal(row.after.position, locale) : '–'}
								{row.position_change !== null && <span className={`seoproan-meta ${tone(row.position_change, true)}`}>{formatPlaces(row.position_change, locale)}</span>}
							</td>
							<td>
								{impactStatus(row)}
								{canStart && row.change_id !== null && (
									<span className="seoproan-meta">
										<Button variant="link" onClick={() => onExperiment(row)}>
											{__('Start an experiment', 'seoproanalytics')}
										</Button>
									</span>
								)}
							</td>
						</tr>
					))}
				</tbody>
			</table>
		</TableScroll>
	);
}

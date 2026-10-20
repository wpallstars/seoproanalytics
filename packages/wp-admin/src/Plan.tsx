/**
 * Search → Plan: one ranked list of what to do next, made from what
 * Opportunities finds (low CTR, missing from the page, striking distance,
 * losing clicks, overlapping pages), the content audit's findings
 * (./Audit), internal links, indexation and the refresh planner's
 * proposals for pages losing clicks (update, leave, protect or merge).
 * Each item says why it is listed, what to do, and how its score is made:
 *
 *     potential clicks per 28 days × value × confidence ÷ effort
 *
 * Administrators accept an item, mark it done (which opens an experiment
 * on its page, so the change is measured), dismiss it (hidden for 90
 * days), restore it, or set its effort and a note. Pages with a running
 * experiment get no new items: a second change would spoil the
 * measurement. Done items show their experiment's result.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 */

import { Fragment, useState } from 'react';
import { Button, Card, CardBody, CardHeader, ExternalLink, Notice, SelectControl, TextControl } from '@wordpress/components';
import { __, _n, sprintf } from '@wordpress/i18n';
import {
	apiArgs,
	formatChange,
	formatDecimal,
	formatNumber,
	formatPlaces,
	AUDIT_FINDINGS,
	INDEXATION_KINDS,
	LINKS_KINDS,
	QUEUE_FILTERS,
	REFRESH_PROPOSALS,
	type AuditFinding,
	type IndexationKind,
	type LinksKind,
	type QueueAction,
	type QueueAnswer,
	type QueueFigures,
	type QueueFilter,
	type QueueItem,
	type QueueKind,
	QUEUE_SITEMAP_PROBLEMS,
	type QueueSitemapProblem,
	type QueueStatus,
	type RefreshProposal,
	singleEngine,
	type OwnersAnswer,
	type SearchEngine,
	type TargetFinding,
} from '@seoproanalytics/core';
import { errorMessage, scopeKey, updateQueueItem, useQueue } from './api';
import { OwnerCell, OwnerFilter, OwnersSummary, useOwnerPeople } from './components/Owner';
import { boot, locale } from './boot';
import { useDataSet } from './data';
import { longLabel } from './dates';
import { PeriodLine } from './Overview';
import { PageCell, decayCutNote } from './Opportunities';
import { findingName } from './Audit';
import { linksName } from './Links';
import { indexationName } from './Indexation';
import { metricLabel, resultLabel } from './Experiments';
import { SearchSetup, sourceName, useReportEngines, type SearchPick, type SearchReportProps } from './components/SearchSetup';
import { ResearchMenu } from './components/ResearchMenu';
import { TableScroll } from './components/TableScroll';

const PER_PAGE = 25;

const number = (value: number) => formatNumber(value, locale, false);
const decimal = (value: number) => formatDecimal(value, locale);

export function kindName(kind: QueueKind): string {
	const names: Record<QueueKind, string> = {
		ctr: __('Low CTR', 'seoproanalytics'),
		missing: __('Missing from the page', 'seoproanalytics'),
		striking: __('Striking distance', 'seoproanalytics'),
		decay: __('Losing clicks', 'seoproanalytics'),
		overlap: __('Overlapping pages', 'seoproanalytics'),
		audit: __('Content audit', 'seoproanalytics'),
		links: __('Internal links', 'seoproanalytics'),
		index: __('Indexation', 'seoproanalytics'),
		refresh: __('Refresh', 'seoproanalytics'),
		target: __('Search target', 'seoproanalytics'),
		sitemap: __('Sitemap', 'seoproanalytics'),
		backlinks: __('Needs links', 'seoproanalytics'),
	};
	return names[kind];
}

/** A sitemap item's problem, or null. */
function sitemapProblem(item: QueueItem): QueueSitemapProblem | null {
	return item.kind === 'sitemap' && item.finding && (QUEUE_SITEMAP_PROBLEMS as readonly string[]).includes(item.finding) ? (item.finding as QueueSitemapProblem) : null;
}

/** A sitemap problem's name. */
function sitemapProblemName(problem: QueueSitemapProblem): string {
	const names: Record<QueueSitemapProblem, string> = {
		missing: __('Not submitted', 'seoproanalytics'),
		errors: __('Errors', 'seoproanalytics'),
		stale: __('Not downloaded lately', 'seoproanalytics'),
		warnings: __('Warnings', 'seoproanalytics'),
	};
	return names[problem];
}

/** A search target item's finding, or null. */
function targetFinding(item: QueueItem): TargetFinding | null {
	return item.kind === 'target' && (item.finding === 'wrong_page' || item.finding === 'striking') ? item.finding : null;
}

/** A search target finding's name. */
function targetFindingName(finding: TargetFinding): string {
	return finding === 'wrong_page' ? __('Another page ranks', 'seoproanalytics') : __('Striking distance', 'seoproanalytics');
}

/** A refresh proposal's name. */
export function proposalName(proposal: RefreshProposal): string {
	const names: Record<RefreshProposal, string> = {
		update: __('Update', 'seoproanalytics'),
		leave: __('Leave', 'seoproanalytics'),
		protect: __('Protect', 'seoproanalytics'),
		merge: __('Merge', 'seoproanalytics'),
	};
	return names[proposal];
}

/** A refresh item's proposal, or null. */
function refreshProposal(item: QueueItem): RefreshProposal | null {
	return item.kind === 'refresh' && item.finding && (REFRESH_PROPOSALS as readonly string[]).includes(item.finding) ? (item.finding as RefreshProposal) : null;
}

/** An audit item's finding, or null. */
function auditFinding(item: QueueItem): AuditFinding | 'page_experience' | 'clicked_broken' | null {
	if (item.kind === 'audit' && (item.finding === 'page_experience' || item.finding === 'clicked_broken')) {
		return item.finding;
	}
	return item.kind === 'audit' && item.finding && (AUDIT_FINDINGS as readonly string[]).includes(item.finding) ? (item.finding as AuditFinding) : null;
}

/** An internal links item's list, or null. */
function linksList(item: QueueItem): LinksKind | null {
	return item.kind === 'links' && item.finding && (LINKS_KINDS as readonly string[]).includes(item.finding) ? (item.finding as LinksKind) : null;
}

/** An indexation item's list, or null. */
function indexList(item: QueueItem): IndexationKind | null {
	return item.kind === 'index' && item.finding && (INDEXATION_KINDS as readonly string[]).includes(item.finding) ? (item.finding as IndexationKind) : null;
}

/** The detail after an item's kind: its finding, list or proposal; '' for none. */
function itemDetail(item: QueueItem): string {
	const finding = auditFinding(item);
	if (finding) {
		return findingName(finding);
	}
	const list = linksList(item);
	if (list) {
		return linksName(list);
	}
	const index = indexList(item);
	if (index) {
		return indexationName(index);
	}
	const proposal = refreshProposal(item);
	if (proposal) {
		return proposalName(proposal);
	}
	const target = targetFinding(item);
	if (target) {
		return targetFindingName(target);
	}
	const problem = sitemapProblem(item);
	return problem ? sitemapProblemName(problem) : '';
}

/** An item's kind, with the finding for an audit item, the list for an internal links or indexation one and the proposal for a refresh one. */
function itemKind(item: QueueItem): string {
	const detail = itemDetail(item);
	return detail
		? sprintf(/* translators: 1: a kind, e.g. "Content audit", 2: a finding, e.g. "No description". */ __('%1$s: %2$s', 'seoproanalytics'), kindName(item.kind), detail)
		: kindName(item.kind);
}

function statusName(status: QueueStatus): string {
	const names: Record<QueueStatus, string> = {
		new: __('New', 'seoproanalytics'),
		accepted: __('Accepted', 'seoproanalytics'),
		done: __('Done', 'seoproanalytics'),
		dismissed: __('Dismissed', 'seoproanalytics'),
	};
	return names[status];
}

function filterName(filter: QueueFilter): string {
	const names: Record<QueueFilter, string> = {
		open: __('To do (new and accepted)', 'seoproanalytics'),
		new: __('New', 'seoproanalytics'),
		accepted: __('Accepted', 'seoproanalytics'),
		done: __('Done', 'seoproanalytics'),
		dismissed: __('Dismissed', 'seoproanalytics'),
		all: __('All', 'seoproanalytics'),
	};
	return names[filter];
}

/** How many items a filter holds, from the answer's counts. */
function filterCount(answer: QueueAnswer, filter: QueueFilter): number {
	const c = answer.counts;
	switch (filter) {
		case 'done':
			return answer.done_count;
		case 'open':
			return c.new + c.accepted;
		case 'all':
			return c.new + c.accepted + c.done + c.dismissed;
		default:
			return c[filter];
	}
}

/** What an empty plan says: no data yet, nothing to do, or nothing in the state picked. */
function emptyText(hasData: boolean, status: QueueFilter): string {
	if (!hasData) {
		return __('No search data yet.', 'seoproanalytics');
	}
	return status === 'open'
		? __('Nothing to do in this period: no opportunity was found, or every one is done or dismissed.', 'seoproanalytics')
		: __('No item in this state.', 'seoproanalytics');
}

type PlanProps = SearchReportProps & {
	open: (pick: SearchPick) => void;
};

export function Plan({ state, update, open, onEngines }: Readonly<PlanProps>) {
	const status: QueueFilter = state.status ?? 'open';
	const goal = state.goal ?? '';
	const engine: SearchEngine = singleEngine(state.engine);
	const owner = state.owner ?? '';
	// Back to the first items when the period, filters, engine, state, goal or owner change.
	const scope = scopeKey(apiArgs(state), engine, status, goal, owner);
	const [at, setAt] = useState({ scope, offset: 0 });
	const offset = at.scope === scope ? at.offset : 0;
	const setOffset = (next: number) => setAt({ scope, offset: next });
	const query = useQueue(state, status, goal, PER_PAGE, offset);
	const answer = query.data;
	useReportEngines(answer, onEngines);
	const owners = useOwnerPeople(state);
	const items = answer?.items ?? [];
	const [error, setError] = useState('');

	return (
		<>
			{(query.isError || error) && (
				<Notice status="error" isDismissible={!!error} onRemove={() => setError('')} className="seoproan-notice">
					{error || errorMessage(query.error, __('The plan could not be loaded. Reload the page to try again.', 'seoproanalytics'))}
				</Notice>
			)}
			{answer && <SearchSetup answer={answer} />}
			{answer && answer.ignored.length > 0 && (
				<Notice status="warning" isDismissible={false} className="seoproan-notice">
					{__('Search data has no visits, so only page filters apply here; the other filters are left out.', 'seoproanalytics')}
				</Notice>
			)}

			<Card className="seoproan-app-card is-wide seoproan-app-section" size="small">
				<CardHeader className="seoproan-app-card__header">
					<div>
						<h2 className="seoproan-app-card__title">{__('Plan', 'seoproanalytics')}</h2>
						{answer?.through && answer.days > 0 && <PeriodLine range={answer.range} />}
						{answer?.through && (
							<p className="seoproan-meta">
								{sprintf(
									/* translators: 1: a source, e.g. "Google Search Console", 2: a day, e.g. "Sun 4 Oct 2026". */
									__('From %1$s, final days through %2$s', 'seoproanalytics'),
									sourceName(engine),
									longLabel(answer.through, 'day')
								)}
							</p>
						)}
					</div>
					{answer && (
						<div className="seoproan-changes__filters seoproan-plan__filters">
							<SelectControl
								__nextHasNoMarginBottom
								label={__('Show', 'seoproanalytics')}
								value={status}
								options={QUEUE_FILTERS.map((f) => ({ value: f, label: `${filterName(f)} (${number(filterCount(answer, f))})` }))}
								onChange={(next: string) => update({ status: next === 'open' ? undefined : (next as QueueFilter) })}
							/>
							{answer.goals.length > 0 && (
								<SelectControl
									__nextHasNoMarginBottom
									label={__('Value from conversions of', 'seoproanalytics')}
									value={answer.goal?.id ?? ''}
									options={answer.goals.map((g) => ({ value: g.id, label: g.name }))}
									onChange={(next: string) => update({ goal: next === answer.goals[0]?.id ? undefined : next })}
								/>
							)}
							{owners && <OwnerFilter owners={owners} value={owner} onChange={(next) => update({ owner: next })} />}
						</div>
					)}
				</CardHeader>
				<CardBody className="seoproan-app-card__body">
					{!answer && !query.isError && <div className="seoproan-skeleton seoproan-skeleton--table" aria-busy="true" />}
					{answer?.through && (
						<p className="seoproan-note seoproan-opportunities__intro">
							{__(
								'What to do next, best first. Score = potential clicks per 28 days × value (how well the page’s visits from search convert, against the site) × confidence (the kind’s, weighed by impressions) ÷ effort. Choose a score to see its parts.',
								'seoproanalytics'
							)}
						</p>
					)}
					{answer && !items.length && (
						<div className="seoproan-app-empty">
							<p>{emptyText(!!answer.through, status)}</p>
						</div>
					)}
					{answer && items.length > 0 && (
						<ItemTable answer={answer} items={items} offset={offset} state={state} goal={goal} open={open} refreshing={query.isFetching} owners={owners} onError={setError} showExperiments={() => update({ report: 'experiments', status: undefined, goal: undefined })} />
					)}
					{answer?.through && <Notes answer={answer} />}
					{answer && answer.total > PER_PAGE && (
						<nav className="seoproan-changes__pager" aria-label={__('Pages of the plan', 'seoproanalytics')}>
							<span className="seoproan-muted">
								{sprintf(
									/* translators: 1: first item shown, 2: last item shown, 3: number of items. */
									__('%1$s–%2$s of %3$s', 'seoproanalytics'),
									number(offset + 1),
									number(Math.min(offset + PER_PAGE, answer.total)),
									number(answer.total)
								)}
							</span>
							<Button variant="secondary" disabled={offset === 0} onClick={() => setOffset(Math.max(0, offset - PER_PAGE))}>
								{__('Previous', 'seoproanalytics')}
							</Button>
							<Button variant="secondary" disabled={!answer.more} onClick={() => setOffset(offset + PER_PAGE)}>
								{__('Next', 'seoproanalytics')}
							</Button>
						</nav>
					)}
					{/* After the list's pager, so the pager reads as the list's. */}
					{answer?.through && owners && <OwnersSummary owners={owners} pick={(next) => update({ owner: next })} />}
				</CardBody>
			</Card>
		</>
	);
}

/** Notes under the list: pages left out, the period read, where value comes from. */
function Notes({ answer }: Readonly<{ answer: QueueAnswer }>) {
	const notes: string[] = [];
	if (answer.left_out > 0) {
		notes.push(
			sprintf(
				/* translators: %s: number of items. */
				_n(
					'%s item is left out because its page has a running experiment: another change there now would spoil its measurement.',
					'%s items are left out because their pages have a running experiment: another change there now would spoil its measurement.',
					answer.left_out,
					'seoproanalytics'
				),
				number(answer.left_out)
			)
		);
	}
	if (answer.cut) {
		notes.push(
			sprintf(
				/* translators: %s: number of days. */
				_n('Read from the newest %s day of the period.', 'Read from the newest %s days of the period.', answer.days, 'seoproanalytics'),
				number(answer.days)
			)
		);
	}
	if (answer.decay) {
		notes.push(decayCutNote(answer.decay.range, answer.decay.compare));
	}
	notes.push(
		answer.goal
			? sprintf(
					/* translators: %s: a goal's name. */
					__('Value: conversions of “%s” by visits from search to the page, against the site’s rate (1 for a page without them).', 'seoproanalytics'),
					answer.goal.name
				)
			: __('Without a goal, every page has value 1. Add a goal so pages that convert rank higher.', 'seoproanalytics'),
		sprintf(
			/* translators: %s: number of days. */
			__('Dismissed items come back after %s days if they are still found. Items accepted or done stay listed when they are no longer found (marked “no longer found”).', 'seoproanalytics'),
			number(answer.rules.hide_days)
		)
	);
	return (
		<div className="seoproan-note">
			{notes.map((note) => (
				<p key={note}>{note}</p>
			))}
			{!answer.goal && (
				<p>
					<a href="#/goals">{__('Goals', 'seoproanalytics')}</a>
				</p>
			)}
		</div>
	);
}

interface ItemTableProps {
	answer: QueueAnswer;
	items: QueueItem[];
	offset: number;
	state: PlanProps['state'];
	goal: string;
	open: PlanProps['open'];
	refreshing: boolean;
	/** Who can own, when owners are shown. */
	owners: OwnersAnswer | undefined;
	onError: (message: string) => void;
	showExperiments: () => void;
}

function ItemTable({ answer, items, offset, state, goal, open, refreshing, owners, onError, showExperiments }: Readonly<ItemTableProps>) {
	const [shown, setShown] = useState<string | null>(null);
	const columns = 4 + (owners ? 1 : 0) + (boot.canManage ? 1 : 0);
	return (
		<TableScroll label={__('Plan', 'seoproanalytics')}>
			<table className={`widefat striped seoproan-table seoproan-plan${refreshing ? ' is-refreshing' : ''}`}>
				<thead>
					<tr>
						<th scope="col" className="num">
							#
						</th>
						<th scope="col">{__('Item', 'seoproanalytics')}</th>
						<th scope="col">{__('Why, and what to do', 'seoproanalytics')}</th>
						<th scope="col" className="num">
							{__('Score', 'seoproanalytics')}
						</th>
						<th scope="col">{__('State', 'seoproanalytics')}</th>
						{owners && <th scope="col">{__('Owner', 'seoproanalytics')}</th>}
						{boot.canManage && <th scope="col">{__('Actions', 'seoproanalytics')}</th>}
					</tr>
				</thead>
				<tbody>
					{items.map((item, i) => (
						<Fragment key={item.key}>
							<tr className={shown === item.key ? 'is-selected' : ''}>
								<td className="num">{number(offset + i + 1)}</td>
								<td>
									<strong className="seoproan-plan__kind">{itemKind(item)}</strong>
									{item.kind === 'sitemap' ? (
										<span className="seoproan-meta">{/^https?:\/\//.test(item.url) ? <ExternalLink href={item.url}>{item.url}</ExternalLink> : item.url}</span>
									) : (
										<PageCell row={item} query={item.query ?? ''} open={open} />
									)}
								</td>
								<td>
									{item.why}
									<span className="seoproan-meta">{item.todo}</span>
									{item.kind === 'backlinks' && item.figures.searches?.[0]?.query && (
										<ResearchMenu
											query={item.figures.searches[0].query}
											prospects
											label={sprintf(/* translators: %s: a search query. */ __('Research “%s” and its link prospects', 'seoproanalytics'), item.figures.searches[0].query)}
										/>
									)}
								</td>
								<td className="num">
									<button
										type="button"
										className="seoproan-link"
										aria-expanded={shown === item.key}
										title={__('How the score is made', 'seoproanalytics')}
										onClick={() => setShown(shown === item.key ? null : item.key)}
									>
										{decimal(item.score)}
									</button>
								</td>
								<td>
									<StateCell item={item} showExperiments={showExperiments} />
								</td>
								{owners && (
									<td className="seoproan-owner-cell">
										<OwnerCell owners={owners} owner={item.owner} kind="item" id={item.key} onError={onError} />
									</td>
								)}
								{boot.canManage && (
									<td className="seoproan-plan__actions-cell">
										<Actions item={item} state={state} goal={goal} onError={onError} />
									</td>
								)}
							</tr>
							{shown === item.key && (
								<tr className="seoproan-plan__detail">
									<td />
									<td colSpan={columns}>
										<Detail answer={answer} item={item} state={state} goal={goal} onError={onError} />
									</td>
								</tr>
							)}
						</Fragment>
					))}
				</tbody>
			</table>
		</TableScroll>
	);
}

/** An item's state, who acted and, when done, its experiment. */
function StateCell({ item, showExperiments }: Readonly<{ item: QueueItem; showExperiments: () => void }>) {
	const exp = item.experiment;
	let result = '';
	if (exp) {
		if (exp.status === 'decided' && exp.result) {
			/* translators: %s: a result, e.g. "Keep". */
			result = sprintf(__('Decided: %s', 'seoproanalytics'), resultLabel(exp.result));
		} else if (exp.status === 'cancelled') {
			result = __('Experiment cancelled', 'seoproanalytics');
		} else if (exp.due && exp.suggested) {
			/* translators: %s: a result, e.g. "Keep". */
			result = sprintf(__('Due for review (suggested: %s)', 'seoproanalytics'), resultLabel(exp.suggested));
		} else {
			/* translators: %s: a day. */
			result = sprintf(__('Measuring until %s', 'seoproanalytics'), longLabel(exp.review, 'day'));
		}
	}
	let effect = '';
	if (exp && exp.effect !== null) {
		effect = exp.unit === 'places' ? formatPlaces(-exp.effect, locale) : formatChange(exp.effect, locale);
	}
	return (
		<>
			{item.verified === 'confirmed' ? __('Confirmed', 'seoproanalytics') : item.verified === 'reopened' ? __('Reopened', 'seoproanalytics') : statusName(item.status)}
			{!item.found && <span className="seoproan-meta">{__('No longer found', 'seoproanalytics')}</span>}
			{exp && (
				<span className="seoproan-meta">
					<button type="button" className="seoproan-link" onClick={showExperiments}>
						{result}
					</button>
					{effect && ` · ${metricLabel(exp.metric)} ${effect}`}
				</span>
			)}
			{item.note && <span className="seoproan-meta">{item.note}</span>}
			{item.updated && (
				<span className="seoproan-meta">
					{item.user
						? sprintf(/* translators: 1: a person, 2: a day. */ __('%1$s, %2$s', 'seoproanalytics'), item.user, longLabel(item.updated.slice(0, 10), 'day'))
						: longLabel(item.updated.slice(0, 10), 'day')}
				</span>
			)}
		</>
	);
}

interface ActProps {
	item: QueueItem;
	state: PlanProps['state'];
	goal: string;
	onError: (message: string) => void;
}

/** Run an action on an item; errors go to the notice above the list. */
function useAct({ item, state, goal, onError }: ActProps) {
	const data = useDataSet();
	const [busy, setBusy] = useState(false);
	const act = async (action: QueueAction, extra: { effort?: number; note?: string } = {}) => {
		setBusy(true);
		try {
			await updateQueueItem(data, state, goal, item.key, { action, ...extra });
			onError('');
		} catch (e) {
			onError(errorMessage(e, __('It could not be saved. Try again.', 'seoproanalytics')));
		}
		setBusy(false);
	};
	return { busy, act };
}

/** The confirmation before Done: what marking it done starts, if anything. */
function doneQuestion(item: QueueItem): string {
	if (item.kind === 'sitemap') {
		return __('Mark it done? A sitemap is about the whole site, so no experiment starts; the item is listed again if Search Console still shows the problem.', 'seoproanalytics');
	}
	if (refreshProposal(item) === 'leave') {
		return __('Mark it done? The page stays as it is, so no experiment starts.', 'seoproanalytics');
	}
	if (item.finding === 'clicked_broken') {
		return __('Mark it done? Search clicks cannot measure a mended link, so no experiment starts. Import a new crawl to check it.', 'seoproanalytics');
	}
	const pages = item.figures.pages?.length ? item.figures.pages.map((page) => page.path).join(', ') : item.path;
	return sprintf(
		/* translators: 1: a measure, e.g. "CTR", 2: a page path. */
		__('Mark it done? An experiment starts now on %2$s and measures %1$s over the days before and after. Mark it done once the change is live.', 'seoproanalytics'),
		metricLabel(item.metric),
		pages
	);
}

function Actions(props: Readonly<ActProps>) {
	const { item } = props;
	const { busy, act } = useAct(props);
	const done = () => {
		// eslint-disable-next-line no-alert -- a plain confirmation, as WordPress uses.
		if (window.confirm(doneQuestion(item))) {
			void act('done');
		}
	};
	return (
		<div className="seoproan-plan__buttons">
			{item.status === 'new' && (
				<Button variant="secondary" size="small" disabled={busy} onClick={() => void act('accept')}>
					{__('Accept', 'seoproanalytics')}
				</Button>
			)}
			{(item.status === 'new' || item.status === 'accepted') && (
				<Button variant="primary" size="small" disabled={busy} onClick={done}>
					{__('Done', 'seoproanalytics')}
				</Button>
			)}
			{(item.status === 'new' || item.status === 'accepted') && (
				<Button variant="tertiary" size="small" disabled={busy} onClick={() => void act('dismiss')}>
					{__('Dismiss', 'seoproanalytics')}
				</Button>
			)}
			{(item.status === 'dismissed' || item.status === 'accepted') && (
				<Button variant="tertiary" size="small" disabled={busy} onClick={() => void act('restore')}>
					{__('Restore', 'seoproanalytics')}
				</Button>
			)}
		</div>
	);
}

/** A position, or a dash when there is none. */
const place = (value: number | null | undefined) => (value === null || value === undefined ? '–' : decimal(value));

/** A refresh item's content: when it changed, its size and links in, and whether it is old or changed lately. */
function contentFact(f: QueueItem['figures'], oldDays: number): string {
	if (f.age === null || f.age === undefined) {
		return __('Content: when it last changed is not known yet.', 'seoproanalytics');
	}
	const facts = sprintf(
		/* translators: 1: a day, 2: days ago, 3: words, 4: pages linking to it. */
		__('Content: changed %1$s (%2$s days ago), %3$s words, %4$s pages link to it.', 'seoproanalytics'),
		f.modified ? longLabel(f.modified.slice(0, 10), 'day') : '–',
		number(f.age),
		number(f.words ?? 0),
		number(f.links_in ?? 0)
	);
	if (f.old) {
		/* translators: %s: days. */
		return `${facts} ${sprintf(__('Old: over %s days.', 'seoproanalytics'), number(oldDays))}`;
	}
	return f.changed ? `${facts} ${__('Changed within the periods compared.', 'seoproanalytics')}` : facts;
}

/** A refresh item's facts: how proposals are chosen, the content, conversions and the searches lost most. */
function RefreshFacts({ answer, item }: Readonly<{ answer: QueueAnswer; item: QueueItem }>) {
	const f = item.figures;
	const rules = answer.rules.refresh;
	return (
		<>
			<li>
				{sprintf(
					/* translators: 1: times the site's conversion rate, 2: conversions. */
					__('Proposals: leave when fewer people search; protect when its visits from search convert at %1$s× the site’s rate or more, with at least %2$s conversions; merge when another page of the site overtook it for a search it lost; else update. Proposals only: nothing changes the page.', 'seoproanalytics'),
					decimal(rules?.protect_value ?? 2),
					number(rules?.protect_conversions ?? 3)
				)}
			</li>
			<li>{contentFact(f, rules?.old_days ?? 365)}</li>
			{f.visits !== null && f.visits !== undefined && (
				<li>
					{sprintf(
						/* translators: 1: visits from search, 2: conversions. */
						__('Visits from search: %1$s, conversions: %2$s.', 'seoproanalytics'),
						number(f.visits),
						number(f.conversions ?? 0)
					)}
				</li>
			)}
			{(f.lost_queries ?? []).map((q) => (
				<li key={q.query}>
					{sprintf(
						/* translators: 1: a search query, 2: clicks lost, 3: position before, 4: position now. */
						__('“%1$s”: %2$s clicks lost, position %3$s → %4$s', 'seoproanalytics'),
						q.query,
						number(q.lost),
						place(q.then_position),
						place(q.position)
					)}
					{q.rival &&
						` · ${sprintf(
							/* translators: 1: another page's path, 2: its position now, 3: its position before. */
							__('overtaken by %1$s (position %2$s, was %3$s)', 'seoproanalytics'),
							q.rival.path,
							place(q.rival.position),
							place(q.rival.then_position)
						)}`}
				</li>
			))}
		</>
	);
}

/** The effort set for an item's finding, list or proposal; undefined for the kind's own. */
function detailEffort(rules: QueueAnswer['rules'], item: QueueItem): number | undefined {
	const finding = auditFinding(item);
	if (finding) {
		return rules.audit_effort?.[finding];
	}
	const list = linksList(item);
	if (list) {
		return rules.links_effort?.[list];
	}
	const index = indexList(item);
	if (index) {
		return rules.index_effort?.[index];
	}
	const proposal = refreshProposal(item);
	if (proposal) {
		return rules.refresh_effort?.[proposal];
	}
	const problem = sitemapProblem(item);
	return problem ? rules.sitemap_effort?.[problem] : undefined;
}

/** A share (0–1) as a whole percentage, e.g. "20%". */
const percent = (share: number) => `${number(share * 100)}%`;

/** How an item's potential clicks are worked out, by kind. */
function potentialNote(answer: QueueAnswer, item: QueueItem): string {
	const f = item.figures;
	const share = percent(f.share ?? 0);
	switch (item.kind) {
		case 'decay':
			return sprintf(
				/* translators: 1: clicks before, 2: clicks now. */
				__('Potential clicks: those lost, scaled to 28 days (%1$s → %2$s clicks).', 'seoproanalytics'),
				number(f.then_clicks ?? 0),
				number(f.clicks)
			);
		case 'missing':
			return sprintf(
				/* translators: 1: share, e.g. 30%. */
				__('Potential clicks: impressions × the site’s CTR at its position × %1$s, scaled to 28 days.', 'seoproanalytics'),
				percent(answer.rules.missing_share)
			);
		case 'overlap':
			return __('Potential clicks: those the search would have if all its pages’ impressions had the best of their CTRs, scaled to 28 days.', 'seoproanalytics');
		case 'audit':
			if (item.finding === 'page_experience') {
				return __('Prioritisation stake: 10% of observed search clicks scaled to 28 days, at least one. Not a predicted traffic or ranking gain.', 'seoproanalytics');
			}
			if (item.finding === 'clicked_broken') {
				return __('Potential clicks: the clicks on the link that fails, scaled to 28 days: lost referrals (and commissions), not search clicks.', 'seoproanalytics');
			}
			return sprintf(
				/* translators: 1: share, e.g. 15%. */
				__('Potential clicks: the page’s impressions × the site’s CTR at its position × %1$s (what this finding puts at stake), scaled to 28 days.', 'seoproanalytics'),
				share
			);
		case 'links':
			return linksNote(item, share);
		case 'sitemap':
			return sprintf(
				/* translators: 1: a typical page's clicks per 28 days, 2: share, e.g. 50%. */
				__('Potential clicks: what a page search shows earns here, %1$s clicks per 28 days on average, × %2$s (what this sitemap problem puts at stake for the pages Google finds through it).', 'seoproanalytics'),
				decimal(f.typical ?? 0),
				share
			);
		case 'backlinks':
			return backlinksNote(item);
		case 'index':
			return sprintf(
				/* translators: 1: a typical page's clicks per 28 days, 2: share, e.g. 50%. */
				__('Potential clicks: what a page search shows earns here, %1$s clicks per 28 days on average, × %2$s.', 'seoproanalytics'),
				decimal(f.typical ?? 0),
				share
			);
		case 'refresh':
			return sprintf(
				/* translators: 1: clicks before, 2: clicks now, 3: share, e.g. 20%. */
				__('Potential clicks: those lost (%1$s → %2$s clicks) × %3$s (what this proposal can win back), scaled to 28 days.', 'seoproanalytics'),
				number(f.then_clicks ?? 0),
				number(f.clicks),
				share
			);
		case 'target':
			return targetNote(answer, item);
		default:
			return __('Potential clicks: those the opportunity names, scaled to 28 days.', 'seoproanalytics');
	}
}

/** Internal links: from the page that should link, or links in to the page. */
function linksNote(item: QueueItem, share: string): string {
	return linksList(item) === 'missing'
		? sprintf(
				/* translators: 1: share, e.g. 20%. */
				__('Potential clicks: the impressions of the searches on the page that should link × the site’s CTR at this page’s position × %1$s, scaled to 28 days.', 'seoproanalytics'),
				share
			)
		: sprintf(
				/* translators: 1: share, e.g. 20%. */
				__('Potential clicks: the page’s impressions × the site’s CTR at its position × %1$s (what links in could add), scaled to 28 days.', 'seoproanalytics'),
				share
			);
}

/** A page that needs links: scored as striking distance. */
function backlinksNote(item: QueueItem): string {
	return sprintf(
		/* translators: 1: clicks within reach, 2: useful referring domains, 3: median of the pages in the top three. */
		__('Potential clicks: the %1$s clicks its searches could gain in the top three, scaled to 28 days, as for striking distance. It is listed because %2$s useful sites link to it against a median of %3$s for this site’s pages in the top three. Links are one of many things that move a page: an estimate to rank work by.', 'seoproanalytics'),
		number(item.figures.potential ?? 0),
		number(item.figures.useful ?? 0),
		decimal(item.figures.top_median ?? 0)
	);
}

/** A search target: on the wrong page, or below the top three. */
function targetNote(answer: QueueAnswer, item: QueueItem): string {
	const f = item.figures;
	return targetFinding(item) === 'wrong_page'
		? sprintf(
				/* translators: 1: share, e.g. 50%, 2: the target's priority, 3: the default priority. */
				__('Potential clicks: the search’s impressions × the site’s CTR at its position × %1$s (what the wrong page puts at stake), × priority %2$s ÷ %3$s, scaled to 28 days.', 'seoproanalytics'),
				percent(answer.rules.target?.share ?? 0.5),
				number(f.priority ?? 50),
				number(answer.rules.target?.priority ?? 50)
			)
		: sprintf(
				/* translators: 1: the target's priority, 2: the default priority. */
				__('Potential clicks: those of the top three less those now, × priority %1$s ÷ %2$s, scaled to 28 days.', 'seoproanalytics'),
				number(f.priority ?? 50),
				number(answer.rules.target?.priority ?? 50)
			);
}

/** What Done does for an item: records a sitemap fix, a page left as it is, or opens an experiment. */
function doneNote(item: QueueItem): string {
	if (item.kind === 'sitemap') {
		return __('Done records the sitemap as fixed; no experiment starts, as a sitemap is about the whole site.', 'seoproanalytics');
	}
	if (refreshProposal(item) === 'leave') {
		return __('Done records that the page stays as it is; no experiment starts.', 'seoproanalytics');
	}
	if (item.finding === 'clicked_broken') {
		return __('Done records the link as mended; no experiment starts, as search clicks do not measure it.', 'seoproanalytics');
	}
	/* translators: %s: a measure, e.g. "CTR". */
	return sprintf(__('Done opens an experiment measuring %s.', 'seoproanalytics'), metricLabel(item.metric));
}

/** A clicked link that fails: where it goes, what the crawl got, where people clicked it and which pages link to it. */
function ClickedBroken({ figures }: Readonly<{ figures: QueueFigures }>) {
	const status = figures.status ?? 0;
	const kind = figures.affiliate ? __('Affiliate link', 'seoproanalytics') : __('Outbound link', 'seoproanalytics');
	return (
		<ul>
			<li>
				{sprintf(
					/* translators: 1: outbound or affiliate link, 2: the destination, 3: its HTTP status, or "no answer". */
					__('%1$s to %2$s: %3$s to the imported crawl.', 'seoproanalytics'),
					kind,
					figures.target ?? '',
					status > 0 ? `HTTP ${status}` : __('no answer', 'seoproanalytics')
				)}
			</li>
			{(figures.clicked_on ?? []).length > 0 && <li>{sprintf(/* translators: %s: pages, comma-separated. */ __('Clicked on: %s', 'seoproanalytics'), (figures.clicked_on ?? []).join(', '))}</li>}
			{(figures.linked_on ?? []).length > 0 && <li>{sprintf(/* translators: %s: pages, comma-separated. */ __('Linked on: %s', 'seoproanalytics'), (figures.linked_on ?? []).join(', '))}</li>}
		</ul>
	);
}

/** The score's parts, the item's figures, and (administrators) its effort and note. */
function Detail({ answer, item, state, goal, onError }: Readonly<{ answer: QueueAnswer } & ActProps>) {
	const { busy, act } = useAct({ item, state, goal, onError });
	const [note, setNote] = useState(item.note);
	const p = item.parts;
	const proposal = refreshProposal(item);
	const kindEffort = detailEffort(answer.rules, item) ?? answer.rules.effort[item.kind];
	return (
		<div className="seoproan-plan__parts">
			{item.finding === 'page_experience' && <ul>{(item.figures.samples ?? []).map((s) => <li key={`${s.form_factor}-${s.metric}`}>{s.form_factor} {s.metric.toUpperCase()}: {s.p75}{s.metric === 'cls' ? '' : ' ms'} · {s.status.replace('_', ' ')} · {s.day}</li>)}</ul>}
			{item.finding === 'clicked_broken' && <ClickedBroken figures={item.figures} />}
			<p>
				{sprintf(
					/* translators: 1: potential clicks, 2: value, 3: confidence, 4: effort, 5: score. */
					__('%1$s clicks × %2$s value × %3$s confidence ÷ %4$s effort = %5$s', 'seoproanalytics'),
					decimal(p.clicks),
					decimal(p.value),
					decimal(p.confidence),
					number(p.effort),
					decimal(item.score)
				)}
			</p>
			<ul className="seoproan-experiment__facts">
				<li>{potentialNote(answer, item)}</li>
				<li>
					{answer.site_rate !== null
						? __('Value: the page’s conversion rate of visits from search against the site’s, from 1 to 5.', 'seoproanalytics')
						: __('Value: 1, as there is no goal or no visits from search.', 'seoproanalytics')}
				</li>
				<li>
					{item.kind === 'index' || item.kind === 'sitemap'
						? sprintf(
								/* translators: %s: the kind's confidence. */
								__('Confidence: the kind’s %s; with no impressions there is nothing to weigh it by.', 'seoproanalytics'),
								decimal(answer.rules.confidence[item.kind])
							)
						: sprintf(
								/* translators: 1: the kind's confidence, 2: impressions for full confidence. */
								__('Confidence: the kind’s %1$s, less when there are fewer than %2$s impressions per 28 days.', 'seoproanalytics'),
								decimal(answer.rules.confidence[item.kind]),
								number(answer.rules.full_impressions)
							)}
				</li>
				<li>
					{item.effort_set
						? sprintf(/* translators: %s: the kind's effort. */ __('Effort: set by hand (the kind’s is %s).', 'seoproanalytics'), number(kindEffort))
						: __('Effort: the kind’s, from 1 (least) to 5.', 'seoproanalytics')}
				</li>
				<li>{doneNote(item)}</li>
				{proposal && <RefreshFacts answer={answer} item={item} />}
			</ul>
			{boot.canManage && item.status !== 'done' && (
				<div className="seoproan-changes__filters seoproan-plan__edit">
					<SelectControl
						__nextHasNoMarginBottom
						label={__('Effort', 'seoproanalytics')}
						value={String(p.effort)}
						disabled={busy}
						options={[1, 2, 3, 4, 5].map((n) => ({ value: String(n), label: n === kindEffort ? sprintf(/* translators: %s: effort. */ __('%s (the kind’s)', 'seoproanalytics'), number(n)) : number(n) }))}
						onChange={(next: string) => void act('effort', { effort: Number(next) })}
					/>
					<TextControl __nextHasNoMarginBottom label={__('Note', 'seoproanalytics')} value={note} maxLength={190} onChange={setNote} />
					<Button variant="secondary" disabled={busy || note === item.note} onClick={() => void act('note', { note })}>
						{__('Save the note', 'seoproanalytics')}
					</Button>
				</div>
			)}
		</div>
	);
}

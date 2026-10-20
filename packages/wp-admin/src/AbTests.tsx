/**
 * A/B tests: every test made with the A/B test block, with its visits per
 * variant and the leader; and one test's variants side by side (goals,
 * revenue, bounce rate, engaged time, clicks), each compared with the
 * control. Tests are started, paused and ended in the block editor.
 *
 * Numbers cover each test's life, not the period chosen above; the
 * filters apply. Not the Search → Experiments report (a change and its
 * expected effect on search).
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 */

import { Button, Card, CardBody, CardHeader, Notice } from '@wordpress/components';
import { __, _n, sprintf } from '@wordpress/i18n';
import {
	formatChange,
	formatDuration,
	formatNumber,
	formatPercent,
	hasFilterValue,
	toggleFilterValue,
	type AbComparison,
	type AbTestAnswer,
	type AbTestRow,
	type AbTestStatus,
	type AbTestVerdictCode,
	type AbThresholds,
	type AbVariantVerdict,
	type ViewState,
} from '@seoproanalytics/core';
import { errorMessage, useAbTest, useAbTests } from './api';
import { locale } from './boot';
import { longLabel } from './dates';
import type { ViewProps } from './App';
import { EditLink } from './components/EditLink';
import { Money } from './components/Money';
import { TableScroll } from './components/TableScroll';

export function statusLabel(status: AbTestStatus): string {
	const labels: Record<AbTestStatus, string> = {
		draft: __('Draft', 'seoproanalytics'),
		running: __('Running', 'seoproanalytics'),
		paused: __('Paused', 'seoproanalytics'),
		ended: __('Ended', 'seoproanalytics'),
	};
	return labels[status];
}

function verdictLabel(verdict: AbVariantVerdict): string {
	const labels: Record<AbVariantVerdict, string> = {
		control: __('Control', 'seoproanalytics'),
		too_early: __('Too early', 'seoproanalytics'),
		better: __('Better', 'seoproanalytics'),
		worse: __('Worse', 'seoproanalytics'),
		unclear: __('No clear difference', 'seoproanalytics'),
	};
	return labels[verdict];
}

const VERDICT_TONES: Partial<Record<AbVariantVerdict, string>> = { better: 'is-good', worse: 'is-bad' };

function verdictTone(verdict: AbVariantVerdict): string {
	return VERDICT_TONES[verdict] ?? 'is-flat';
}

const NOTICE_STATUSES: Partial<Record<AbTestVerdictCode, 'success' | 'warning'>> = { winner: 'success', control: 'warning' };

/** The test verdict's notice: a winner is good news, the rest is information. */
function noticeStatus(code: AbTestVerdictCode): 'success' | 'info' | 'warning' {
	return NOTICE_STATUSES[code] ?? 'info';
}

/** good when the whole interval is above zero, bad when below, else flat. */
function intervalTone(interval: AbComparison['interval']): string {
	if (interval && interval[0] > 0) {
		return 'is-good';
	}
	return interval && interval[1] < 0 ? 'is-bad' : 'is-flat';
}

/** A probability as a whole percentage, never 0% or 100% (nothing is certain; 0 and 1 are rounded). */
function chance(p: number | null): string {
	if (p === null) {
		return '—';
	}
	if (p > 0.995) {
		return `>${formatPercent(0.99, locale)}`;
	}
	if (p < 0.005) {
		return `<${formatPercent(0.01, locale)}`;
	}
	return formatPercent(Math.round(p * 100) / 100, locale);
}

/** The uplift and its 95% interval: +12% (−3% to +29%). */
function Uplift({ value, control }: Readonly<{ value: AbComparison; control: boolean }>) {
	if (control) {
		return <span className="seoproan-muted">{__('Control', 'seoproanalytics')}</span>;
	}
	if (value.uplift === null) {
		return <span className="seoproan-muted">—</span>;
	}
	const tone = intervalTone(value.interval);
	return (
		<>
			<span className={`seoproan-change ${tone}`}>{formatChange(value.uplift, locale)}</span>
			{value.interval && (
				<span className="seoproan-meta" title={__('95% interval: the uplift is likely within this range.', 'seoproanalytics')}>
					{sprintf(
						/* translators: 1: lowest likely uplift, 2: highest, e.g. "−3% to +29%". */
						__('%1$s to %2$s', 'seoproanalytics'),
						formatChange(value.interval[0], locale),
						formatChange(value.interval[1], locale)
					)}
				</span>
			)}
		</>
	);
}

/** When a test ran: from its start to its end, or since its start. */
function whenText(started: string | null, ended: string | null): string {
	if (!started) {
		return __('Not started', 'seoproanalytics');
	}
	const from = longLabel(started.slice(0, 10), 'day');
	if (ended) {
		/* translators: 1: first day, 2: last day. */
		return sprintf(__('%1$s to %2$s', 'seoproanalytics'), from, longLabel(ended.slice(0, 10), 'day'));
	}
	/* translators: %s: a day. */
	return sprintf(__('Since %s', 'seoproanalytics'), from);
}

function thresholdsText(t: AbThresholds): string {
	return sprintf(
		/* translators: 1: probability, such as 95%, 2: visits, 3: conversions, 4: days. */
		__('A variant is called better or worse at a %1$s chance to beat the control, once each side has %2$s visits, the two have %3$s conversions between them, and the test has run %4$s days. Before that it is too early to call.', 'seoproanalytics'),
		formatPercent(t.confidence, locale),
		formatNumber(t.visits, locale, false),
		formatNumber(t.conversions, locale, false),
		formatNumber(t.days, locale, false)
	);
}

function PageCell({ test }: Readonly<{ test: Pick<AbTestRow, 'post' | 'removed'> }>) {
	const title = test.post.title || test.post.path || __('(no page)', 'seoproanalytics');
	return (
		<span className="seoproan-meta">
			{test.post.edit_url ? (
				<EditLink href={test.post.edit_url} path={title}>
					{title}
				</EditLink>
			) : (
				title
			)}
			{test.post.path && test.post.title && <span className="seoproan-muted"> · {test.post.path}</span>}
			{test.removed && <span className="seoproan-muted"> · {__('block removed', 'seoproanalytics')}</span>}
		</span>
	);
}

export function AbTests({ state, update }: Readonly<ViewProps>) {
	return state.test ? <Detail id={state.test} state={state} update={update} /> : <List state={state} update={update} />;
}

function List({ state, update }: Readonly<ViewProps>) {
	const query = useAbTests(state.filters);
	const answer = query.data;
	const tests = answer?.tests ?? [];

	return (
		<>
			{query.isError && (
				<Notice status="error" isDismissible={false} className="seoproan-notice">
					{errorMessage(query.error, __('The A/B tests could not be loaded. Reload the page to try again.', 'seoproanalytics'))}
				</Notice>
			)}
			<Card className="seoproan-app-card is-wide seoproan-app-section" size="small">
				<CardHeader className="seoproan-app-card__header">
					<div>
						<h2 className="seoproan-app-card__title">{__('A/B tests', 'seoproanalytics')}</h2>
						<p className="seoproan-meta">
							{__('Each test over its whole life, not the period chosen above. Visits that saw more than one variant of a test are counted apart.', 'seoproanalytics')}
						</p>
					</div>
				</CardHeader>
				<CardBody className="seoproan-app-card__body">
					{!answer && !query.isError && <div className="seoproan-skeleton seoproan-skeleton--table" aria-busy="true" />}
					{answer && !tests.length && (
						<div className="seoproan-app-empty">
							<p>{__('No A/B tests yet.', 'seoproanalytics')}</p>
							<p>
								{__('Add an A/B test block to a post or page, put two or more versions of a headline, button or price in it, choose the goals that count, and start it. Each visitor sees one version; this report says which does better.', 'seoproanalytics')}
							</p>
						</div>
					)}
					{tests.length > 0 && (
						<TableScroll label={__('A/B tests', 'seoproanalytics')}>
							<table className={`widefat striped seoproan-table${query.isFetching ? ' is-refreshing' : ''}`}>
								<thead>
									<tr>
										<th scope="col">{__('Test', 'seoproanalytics')}</th>
										<th scope="col">{__('State', 'seoproanalytics')}</th>
										<th scope="col">{__('Variants', 'seoproanalytics')}</th>
										<th scope="col" className="num">
											{__('Visits', 'seoproanalytics')}
										</th>
										<th scope="col">{__('Leader', 'seoproanalytics')}</th>
									</tr>
								</thead>
								<tbody>
									{tests.map((test) => (
										<tr key={test.id}>
											<td>
												<button type="button" className="seoproan-link" onClick={() => update({ test: test.id })}>
													{test.name || test.id}
												</button>
												<PageCell test={test} />
											</td>
											<td>
												{statusLabel(test.status)}
												<span className="seoproan-meta">{whenText(test.started, test.ended)}</span>
											</td>
											<td>
												<ul className="seoproan-ab-variants">
													{test.variants.map((v) => (
														<li key={v.slug}>
															<span>{v.label}</span>{' '}
															<span className="seoproan-muted">
																{sprintf(
																	/* translators: %s: number of visits. */
																	_n('%s visit', '%s visits', v.visits, 'seoproanalytics'),
																	formatNumber(v.visits, locale)
																)}
															</span>
														</li>
													))}
												</ul>
											</td>
											<td className="num">
												{formatNumber(test.visits, locale)}
												{test.mixed.visits > 0 && (
													<span className="seoproan-meta">
														{sprintf(/* translators: %s: number of visits. */ __('%s mixed', 'seoproanalytics'), formatNumber(test.mixed.visits, locale))}
													</span>
												)}
											</td>
											<td>
												{test.leader ? test.leader.label : <span className="seoproan-muted">—</span>}
												<span className="seoproan-meta">{test.verdict.text}</span>
											</td>
										</tr>
									))}
								</tbody>
							</table>
						</TableScroll>
					)}
					{answer && tests.length > 0 && <p className="seoproan-note">{thresholdsText(answer.thresholds)}</p>}
				</CardBody>
			</Card>
		</>
	);
}

/** Show reports for visits that saw one variant (choosing it again takes the filter out). */
function VariantFilter({ value, label, state, update }: Readonly<{ value: string; label: string; state: ViewState; update: ViewProps['update'] }>) {
	const active = hasFilterValue(state.filters, 'variant', value);
	return (
		<button
			type="button"
			className={`seoproan-link${active ? ' is-active' : ''}`}
			aria-pressed={active}
			title={
				active
					? __('Remove this filter', 'seoproanalytics')
					: __('Show only visits that saw this variant, here and in the other reports', 'seoproanalytics')
			}
			onClick={() => update({ filters: toggleFilterValue(state.filters, 'variant', value) })}
		>
			{label}
		</button>
	);
}

function Detail({ id, state, update }: Readonly<ViewProps & { id: string }>) {
	const query = useAbTest(id, state.filters);
	const answer = query.data;
	const back = (
		<Button variant="link" onClick={() => update({ test: undefined })}>
			{__('← All A/B tests', 'seoproanalytics')}
		</Button>
	);
	if (query.isError) {
		return (
			<>
				{back}
				<Notice status="error" isDismissible={false} className="seoproan-notice">
					{errorMessage(query.error, __('The A/B test could not be loaded. Reload the page to try again.', 'seoproanalytics'))}
				</Notice>
			</>
		);
	}
	if (!answer) {
		return (
			<>
				{back}
				<div className="seoproan-skeleton seoproan-skeleton--table" aria-busy="true" />
			</>
		);
	}
	return (
		<>
			{back}
			<Report answer={answer} refreshing={query.isFetching} state={state} update={update} />
		</>
	);
}

function Report({ answer, refreshing, state, update }: Readonly<ViewProps & { answer: AbTestAnswer; refreshing: boolean }>) {
	const { test, variants, primary } = answer;
	const name = test.name || test.id;
	const table = `widefat striped seoproan-table${refreshing ? ' is-refreshing' : ''}`;
	const label = (v: (typeof variants)[number]) => (
		<>
			<VariantFilter value={`${test.id}:${v.slug}`} label={v.label} state={state} update={update} />
			{v.control && <span className="seoproan-app-badge">{__('Control', 'seoproanalytics')}</span>}
			{v.winner && <span className="seoproan-app-badge">{__('Chosen winner', 'seoproanalytics')}</span>}
		</>
	);

	return (
		<>
			<Card className="seoproan-app-card is-wide seoproan-app-section" size="small">
				<CardHeader className="seoproan-app-card__header">
					<div>
						<h2 className="seoproan-app-card__title">{name}</h2>
						<p className="seoproan-meta">
							{statusLabel(test.status)} · {whenText(test.started, test.ended)} ·{' '}
							{sprintf(/* translators: %s: number of days. */ _n('%s day', '%s days', answer.period.days, 'seoproanalytics'), formatNumber(answer.period.days, locale, false))}
						</p>
						<PageCell test={test} />
					</div>
				</CardHeader>
				<CardBody className="seoproan-app-card__body">
					<Notice status={noticeStatus(answer.verdict.code)} isDismissible={false} className="seoproan-notice">
						{answer.verdict.text}
					</Notice>
					<h3 className="seoproan-subtitle">
						{sprintf(
							/* translators: %s: what the variants are compared on, such as a goal's name. */
							__('Compared on: %s', 'seoproanalytics'),
							primary.name
						)}
					</h3>
					<TableScroll label={__('Variants', 'seoproanalytics')}>
						<table className={table}>
							<thead>
								<tr>
									<th scope="col">{__('Variant', 'seoproanalytics')}</th>
									<th scope="col" className="num">{__('Visits', 'seoproanalytics')}</th>
									<th scope="col" className="num">{__('Conversions', 'seoproanalytics')}</th>
									<th scope="col" className="num">{__('Conversion rate', 'seoproanalytics')}</th>
									<th scope="col" className="num">{__('Uplift', 'seoproanalytics')}</th>
									<th scope="col" className="num">{__('Chance to beat control', 'seoproanalytics')}</th>
									<th scope="col">{__('Verdict', 'seoproanalytics')}</th>
								</tr>
							</thead>
							<tbody>
								{variants.map((v) => (
									<tr key={v.slug}>
										<td>{label(v)}</td>
										<td className="num">
											{formatNumber(v.visits, locale)}
											<span className="seoproan-meta">{formatPercent(v.share, locale)}</span>
										</td>
										<td className="num">{formatNumber(v.primary.conversions, locale)}</td>
										<td className="num">{formatPercent(v.primary.rate, locale)}</td>
										<td className="num">
											<Uplift value={v.primary} control={v.control} />
										</td>
										<td className="num">{v.control ? <span className="seoproan-muted">—</span> : chance(v.primary.probability)}</td>
										<td>{v.control ? <span className="seoproan-muted">—</span> : <span className={`seoproan-change ${verdictTone(v.primary.verdict)}`}>{verdictLabel(v.primary.verdict)}</span>}</td>
									</tr>
								))}
							</tbody>
						</table>
					</TableScroll>
					<p className="seoproan-note">
						{answer.mixed.visits > 0
							? sprintf(
									/* translators: 1: number of visits, 2: their share of the test's visits. */
									__('%1$s visits (%2$s) saw more than one variant: they are counted apart, in no variant.', 'seoproanalytics'),
									formatNumber(answer.mixed.visits, locale, false),
									formatPercent(answer.mixed.share, locale)
								)
							: __('No visit saw more than one variant.', 'seoproanalytics')}{' '}
						{thresholdsText(answer.thresholds)}
					</p>
				</CardBody>
			</Card>

			{answer.goals.length > 0 && (
				<Card className="seoproan-app-card is-wide seoproan-app-section" size="small">
					<CardHeader className="seoproan-app-card__header">
						<h2 className="seoproan-app-card__title">{__('Goals', 'seoproanalytics')}</h2>
					</CardHeader>
					<CardBody className="seoproan-app-card__body">
						<TableScroll label={__('Goals by variant', 'seoproanalytics')}>
							<table className={table}>
								<thead>
									<tr>
										<th scope="col">{__('Goal', 'seoproanalytics')}</th>
										<th scope="col">{__('Variant', 'seoproanalytics')}</th>
										<th scope="col" className="num">{__('Conversions', 'seoproanalytics')}</th>
										<th scope="col" className="num">{__('Conversion rate', 'seoproanalytics')}</th>
										<th scope="col" className="num">{__('Uplift', 'seoproanalytics')}</th>
										<th scope="col" className="num">{__('Chance to beat control', 'seoproanalytics')}</th>
										<th scope="col" className="num">{__('Revenue', 'seoproanalytics')}</th>
									</tr>
								</thead>
								<tbody>
									{answer.goals.flatMap((goal, g) =>
										variants.map((v, i) => {
											const r = v.goals[g];
											if (!r) {
												return null;
											}
											return (
												<tr key={`${goal.id}:${v.slug}`}>
													{i === 0 && (
														<th scope="rowgroup" rowSpan={variants.length}>
															{goal.name || goal.match}
														</th>
													)}
													<td>{v.label}</td>
													<td className="num" title={sprintf(/* translators: %s: a number of times. */ __('Reached %s times', 'seoproanalytics'), formatNumber(r.completions, locale, false))}>
														{formatNumber(r.conversions, locale)}
													</td>
													<td className="num">{formatPercent(r.rate, locale)}</td>
													<td className="num">
														<Uplift value={r} control={v.control} />
													</td>
													<td className="num">{v.control ? <span className="seoproan-muted">—</span> : chance(r.probability)}</td>
													<td className="num">
														<Money revenue={r.revenue} />
													</td>
												</tr>
											);
										})
									)}
								</tbody>
							</table>
						</TableScroll>
						<p className="seoproan-note">{__('A conversion is a visit that reached the goal after it saw the test. Revenue is shown per currency, never added across currencies.', 'seoproanalytics')}</p>
					</CardBody>
				</Card>
			)}

			<Card className="seoproan-app-card is-wide seoproan-app-section" size="small">
				<CardHeader className="seoproan-app-card__header">
					<h2 className="seoproan-app-card__title">{__('Behaviour', 'seoproanalytics')}</h2>
				</CardHeader>
				<CardBody className="seoproan-app-card__body">
					<TableScroll label={__('Behaviour by variant', 'seoproanalytics')}>
						<table className={table}>
							<thead>
								<tr>
									<th scope="col">{__('Variant', 'seoproanalytics')}</th>
									<th scope="col" className="num">{__('Visitors', 'seoproanalytics')}</th>
									<th scope="col" className="num">{__('Page loads', 'seoproanalytics')}</th>
									<th scope="col" className="num">{__('Bounce rate', 'seoproanalytics')}</th>
									<th scope="col" className="num">{__('Engaged time', 'seoproanalytics')}</th>
									<th scope="col" className="num">{__('Clicks', 'seoproanalytics')}</th>
									<th scope="col" className="num">{__('Visits that clicked', 'seoproanalytics')}</th>
								</tr>
							</thead>
							<tbody>
								{variants.map((v) => (
									<tr key={v.slug}>
										<td>{v.label}</td>
										<td className="num">{formatNumber(v.visitors, locale)}</td>
										<td className="num">{formatNumber(v.pageviews, locale)}</td>
										<td className="num">{formatPercent(v.bounce_rate, locale)}</td>
										<td className="num">{formatDuration(v.engaged_time)}</td>
										<td className="num">{formatNumber(v.clicks, locale)}</td>
										<td className="num">{formatPercent(v.click_rate, locale)}</td>
									</tr>
								))}
							</tbody>
						</table>
					</TableScroll>
					<p className="seoproan-note">
						{__('Page loads that showed the variant; clicks inside it; engaged time is the time per visit with the site on screen.', 'seoproanalytics')}
					</p>
				</CardBody>
			</Card>
		</>
	);
}

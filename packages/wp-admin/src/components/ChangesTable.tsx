/**
 * Changes as a table: when, what (with its group and source), the page
 * and who. Used by the Changes section and the chart's changes modal.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 */

import { Button, ExternalLink } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import type { ChangeSource, Marker } from '@seoproanalytics/core';
import { shareAccess } from '../api';
import { boot } from '../boot';
import { groupLabel, sourceUrl } from '../changelog';
import { momentLabel, timeLabel } from '../dates';
import { TableScroll } from './TableScroll';

function sourceLabel(source: ChangeSource): string {
	const labels: Record<ChangeSource, string> = {
		wordpress: __('WordPress', 'seoproanalytics'),
		cli: __('WP-CLI', 'seoproanalytics'),
		api: __('REST API', 'seoproanalytics'),
		cron: __('Scheduled', 'seoproanalytics'),
		feed: __('Feed', 'seoproanalytics'),
		note: __('Note', 'seoproanalytics'),
	};
	return labels[source] ?? source;
}

interface Props {
	rows: Marker[];
	/** Dim the rows while newer ones load. */
	refreshing?: boolean;
	/** A page chosen; without it, pages are plain text. */
	onPage?: (path: string) => void;
	/** Delete a note (administrators). */
	onDelete?: (change: Marker) => void;
	/** Start an experiment on a page's change (administrators). */
	onExperiment?: (change: Marker) => void;
	/** All on one day (named elsewhere): show the time only. */
	timeOnly?: boolean;
}

/** A change an experiment can measure: one page's own, not a note, update or experiment. */
function measurable(change: Marker): boolean {
	return !!change.path && change.group !== 'note' && change.group !== 'search';
}

function indexnowLabel(change: Marker): string {
	const receipt = change.meta.indexnow;
	if (!receipt || typeof receipt !== 'object' || !('code' in receipt) || typeof receipt.code !== 'number') return '';
	return `${__('IndexNow', 'seoproanalytics')}: ${receipt.code === 200 || receipt.code === 202 ? __('Received, not proof of indexing', 'seoproanalytics') : __('Not accepted', 'seoproanalytics')} (HTTP ${receipt.code})`;
}

export function ChangesTable({ rows, refreshing = false, onPage, onDelete, onExperiment, timeOnly = false }: Readonly<Props>) {
	const actions = boot.canManage && (!!onDelete || !!onExperiment);
	// A shared report does not say who made a change.
	const who = !shareAccess.token;
	return (
		<TableScroll label={__('Changes', 'seoproanalytics')}>
			<table className={`widefat striped seoproan-table seoproan-changes-table${refreshing ? ' is-refreshing' : ''}`}>
				<thead>
					<tr>
						<th scope="col">{__('When', 'seoproanalytics')}</th>
						<th scope="col">{__('Change', 'seoproanalytics')}</th>
						<th scope="col">{__('Page', 'seoproanalytics')}</th>
						{who && <th scope="col">{__('By', 'seoproanalytics')}</th>}
						{actions && (
							<th scope="col">
								<span className="screen-reader-text">{__('Actions', 'seoproanalytics')}</span>
							</th>
						)}
					</tr>
				</thead>
				<tbody>
					{rows.map((change) => (
						<tr key={change.id}>
							<td className="seoproan-nowrap">{timeOnly ? timeLabel(change.t) : momentLabel(change.t)}</td>
							<td className="seoproan-changes__change">
								<span className="seoproan-changes__group">
									<span className="seoproan-chart-lane__dot" style={{ background: `var(--seoproan-mark-${change.group})` }} aria-hidden="true" />
									<span>{change.label}</span>
								</span>
								<span className="seoproan-meta">
									{groupLabel(change.group)}
									{indexnowLabel(change) && <> · {indexnowLabel(change)}</>}
									{sourceUrl(change) && (
										<>
											{' · '}
											<ExternalLink href={sourceUrl(change) ?? ''}>
												{__('Source', 'seoproanalytics')}
												<span className="screen-reader-text"> {change.label}</span>
											</ExternalLink>
										</>
									)}
								</span>
							</td>
							<td className="seoproan-changes__page">
								{change.path && onPage ? (
									<button
										type="button"
										className="seoproan-link"
										title={__('Show this page’s statistics', 'seoproanalytics')}
										onClick={() => onPage(change.path ?? '')}
									>
										{change.path}
									</button>
								) : (
									change.path ?? <span className="seoproan-muted">{__('Whole site', 'seoproanalytics')}</span>
								)}
							</td>
							{who && (
								<td>
									{change.user ?? <span className="seoproan-muted">–</span>}
									<span className="seoproan-meta">{sourceLabel(change.source)}</span>
								</td>
							)}
							{actions && (
								<td className="seoproan-actions">
									{onExperiment && measurable(change) && (
										<Button variant="link" onClick={() => onExperiment(change)}>
											{__('Start an experiment', 'seoproanalytics')}
											<span className="screen-reader-text"> {change.label}</span>
										</Button>
									)}
									{onDelete && change.kind === 'note' && (
										<Button variant="link" isDestructive onClick={() => onDelete(change)}>
											{__('Delete', 'seoproanalytics')}
											<span className="screen-reader-text"> {change.label}</span>
										</Button>
									)}
								</td>
							)}
						</tr>
					))}
				</tbody>
			</table>
		</TableScroll>
	);
}

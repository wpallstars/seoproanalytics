/**
 * Google Business Profile (Overview): each location the Connect add-on
 * reads through Google's API, with its Search and Maps views, website
 * clicks, calls, direction requests, rating and reviews without a reply
 * in the period. Shown only when locations are kept; choosing one with a
 * tagged website link filters every report by its campaign.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 */

import { __, _n, sprintf } from '@wordpress/i18n';
import { formatDecimal, formatNumber, type GbpAnswer, type GbpLocation } from '@seoproanalytics/core';
import { useGoogleBusiness } from '../api';
import type { ViewProps } from '../App';
import { locale } from '../boot';
import { longLabel } from '../dates';
import { CampaignFilter } from './CampaignFilter';
import { TableCard } from './TableCard';

const number = (value: number) => formatNumber(value, locale);

/** Its rating and review count, e.g. "4.6 ★ · 48 reviews". */
function ratingText(location: GbpLocation): string {
	const reviews = sprintf(/* translators: %s: number of reviews. */ _n('%s review', '%s reviews', location.reviews, 'seoproanalytics'), number(location.reviews));
	return location.rating === null ? reviews : `${formatDecimal(location.rating, locale)} ★ · ${reviews}`;
}

/** Where the figures come from, how late they are, and what No reply counts. */
function sourceNote(answer: GbpAnswer): string {
	const read = answer.through
		? sprintf(
				/* translators: %s: a day. */
				__('Read from your Business Profile through Google\'s API by SEO Pro Analytics Connect; Google\'s figures arrive a few days late (newest: %s). No reply: reviews of the last 90 days without one.', 'seoproanalytics'),
				longLabel(answer.through, 'day')
			)
		: __('Read from your Business Profile through Google\'s API by SEO Pro Analytics Connect. No reply: reviews of the last 90 days without one.', 'seoproanalytics');
	return answer.connected ? read : `${read} ${__('Not connected now: the figures already read stay until you delete them.', 'seoproanalytics')}`;
}

/** The numeric columns: each heading and the location's figure. */
function columns(): Array<[string, (location: GbpLocation) => number]> {
	return [
		[__('Search views', 'seoproanalytics'), (location) => location.totals.search],
		[__('Maps views', 'seoproanalytics'), (location) => location.totals.maps],
		[__('Website clicks', 'seoproanalytics'), (location) => location.totals.website],
		[__('Calls', 'seoproanalytics'), (location) => location.totals.calls],
		[__('Directions', 'seoproanalytics'), (location) => location.totals.directions],
		[__('No reply', 'seoproanalytics'), (location) => location.unanswered],
	];
}

export function GoogleBusinessCard({ state, update }: Readonly<ViewProps>) {
	const query = useGoogleBusiness({ range: state.range, from: state.from, to: state.to });
	const answer = query.data;
	if (!answer?.locations.length) {
		return null;
	}
	const figures = columns();
	return (
		<TableCard title={__('Google Business Profile', 'seoproanalytics')} refreshing={query.isFetching} note={sourceNote(answer)}>
			<thead>
				<tr>
					<th scope="col">{__('Location', 'seoproanalytics')}</th>
					{figures.map(([label]) => (
						<th key={label} scope="col" className="num">{label}</th>
					))}
				</tr>
			</thead>
			<tbody>
				{answer.locations.map((location) => (
					<tr key={location.location}>
						<td>
							{location.tagged && location.campaign !== '' ? <CampaignFilter campaign={location.campaign} name={location.title} state={state} update={update} /> : location.title}
							<span className="seoproan-meta">{ratingText(location)}</span>
						</td>
						{figures.map(([label, figure]) => (
							<td key={label} className="num">{number(figure(location))}</td>
						))}
					</tr>
				))}
			</tbody>
		</TableCard>
	);
}

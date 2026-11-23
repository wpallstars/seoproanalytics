/**
 * Google Business Profile (Overview): each location the Connect add-on
 * reads through Google's API, with its Search and Maps views, website
 * clicks, calls, direction requests, rating and reviews without a reply
 * in the period. Shown only when locations are kept, or with a notice
 * when they could not be loaded; choosing one with a tagged website link
 * filters every report by its campaign.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 */

import { Card, CardBody, CardHeader, Notice } from '@wordpress/components';
import { __, _n, sprintf } from '@wordpress/i18n';
import { formatDecimal, formatNumber, type GbpAnswer, type GbpLocation } from '@seoproanalytics/core';
import { errorMessage, useGoogleBusiness } from '../api';
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
	// A failed request says so; an answer without locations hides the card.
	if (query.isError && !answer) {
		return (
			<Card className="seoproan-app-card" size="small">
				<CardHeader className="seoproan-app-card__header">
					<h2 className="seoproan-app-card__title">{__('Google Business Profile', 'seoproanalytics')}</h2>
				</CardHeader>
				<CardBody className="seoproan-app-card__body">
					<Notice status="error" isDismissible={false}>
						{errorMessage(query.error, __('The Google Business Profile figures could not be loaded. Reload the page to try again.', 'seoproanalytics'))}
					</Notice>
				</CardBody>
			</Card>
		);
	}
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

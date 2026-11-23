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

import { Card, CardBody, CardHeader } from '@wordpress/components';
import { __, _n, sprintf } from '@wordpress/i18n';
import { formatDecimal, formatNumber, hasFilterValue, toggleFilterValue, type GbpLocation } from '@seoproanalytics/core';
import { useGoogleBusiness } from '../api';
import type { ViewProps } from '../App';
import { locale } from '../boot';
import { longLabel } from '../dates';
import { TableScroll } from './TableScroll';

const number = (value: number) => formatNumber(value, locale);

/** A location's name: a filter by its link's campaign when tagged, else text. */
function LocationName({ location, state, update }: Readonly<ViewProps & { location: GbpLocation }>) {
	if (!location.tagged || location.campaign === '') {
		return <>{location.title}</>;
	}
	const active = hasFilterValue(state.filters, 'utm_campaign', location.campaign, 'is');
	return (
		<button
			type="button"
			className={`seoproan-link${active ? ' is-active' : ''}`}
			aria-pressed={active}
			title={active ? __('Remove this filter', 'seoproanalytics') : __('Show only visits from this profile\'s link', 'seoproanalytics')}
			onClick={() => update({ filters: toggleFilterValue(state.filters, 'utm_campaign', location.campaign, 'is') })}
		>
			{location.title}
		</button>
	);
}

/** Its rating and review count, e.g. "4.6 ★ · 48 reviews". */
function ratingText(location: GbpLocation): string {
	const reviews = sprintf(/* translators: %s: number of reviews. */ _n('%s review', '%s reviews', location.reviews, 'seoproanalytics'), number(location.reviews));
	return location.rating === null ? reviews : `${formatDecimal(location.rating, locale)} ★ · ${reviews}`;
}

export function GoogleBusinessCard({ state, update }: Readonly<ViewProps>) {
	const query = useGoogleBusiness({ range: state.range, from: state.from, to: state.to });
	const answer = query.data;
	if (!answer?.locations.length) {
		return null;
	}
	return (
		<Card className="seoproan-app-card" size="small">
			<CardHeader className="seoproan-app-card__header">
				<h2 className="seoproan-app-card__title">{__('Google Business Profile', 'seoproanalytics')}</h2>
			</CardHeader>
			<CardBody className="seoproan-app-card__body">
				<TableScroll label={__('Google Business Profile', 'seoproanalytics')}>
					<table className={`widefat striped seoproan-table${query.isFetching ? ' is-refreshing' : ''}`}>
						<thead>
							<tr>
								<th scope="col">{__('Location', 'seoproanalytics')}</th>
								<th scope="col" className="num">{__('Search views', 'seoproanalytics')}</th>
								<th scope="col" className="num">{__('Maps views', 'seoproanalytics')}</th>
								<th scope="col" className="num">{__('Website clicks', 'seoproanalytics')}</th>
								<th scope="col" className="num">{__('Calls', 'seoproanalytics')}</th>
								<th scope="col" className="num">{__('Directions', 'seoproanalytics')}</th>
								<th scope="col" className="num">{__('No reply', 'seoproanalytics')}</th>
							</tr>
						</thead>
						<tbody>
							{answer.locations.map((location) => (
								<tr key={location.location}>
									<td>
										<LocationName location={location} state={state} update={update} />
										<span className="seoproan-meta">{ratingText(location)}</span>
									</td>
									<td className="num">{number(location.totals.search)}</td>
									<td className="num">{number(location.totals.maps)}</td>
									<td className="num">{number(location.totals.website)}</td>
									<td className="num">{number(location.totals.calls)}</td>
									<td className="num">{number(location.totals.directions)}</td>
									<td className="num">{number(location.unanswered)}</td>
								</tr>
							))}
						</tbody>
					</table>
				</TableScroll>
				<p className="seoproan-note">
					{answer.through
						? sprintf(
								/* translators: %s: a day. */
								__('Read from your Business Profile through Google\'s API by SEO Pro Analytics Connect; Google\'s figures arrive a few days late (newest: %s). No reply: reviews of the last 90 days without one.', 'seoproanalytics'),
								longLabel(answer.through, 'day')
							)
						: __('Read from your Business Profile through Google\'s API by SEO Pro Analytics Connect. No reply: reviews of the last 90 days without one.', 'seoproanalytics')}
					{!answer.connected && ` ${__('Not connected now: the figures already read stay until you delete them.', 'seoproanalytics')}`}
				</p>
			</CardBody>
		</Card>
	);
}

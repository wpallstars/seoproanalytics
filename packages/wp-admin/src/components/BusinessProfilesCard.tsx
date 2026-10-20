/**
 * Business profiles (Overview): each profile listed in Settings → Data,
 * with the visits, conversions and revenue from its tagged link. Shown
 * only when profiles are listed; choosing one filters every report by
 * its campaign.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 */

import { Card, CardBody, CardHeader } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { formatNumber, hasFilterValue, toggleFilterValue } from '@seoproanalytics/core';
import { useBusinessProfiles } from '../api';
import type { ViewProps } from '../App';
import { locale } from '../boot';
import { Money } from './Money';
import { TableScroll } from './TableScroll';

export function BusinessProfilesCard({ state, update }: Readonly<ViewProps>) {
	const query = useBusinessProfiles({ range: state.range, from: state.from, to: state.to, filters: state.filters });
	const answer = query.data;
	if (!answer?.profiles.length) {
		return null;
	}
	return (
		<Card className="seoproan-app-card" size="small">
			<CardHeader className="seoproan-app-card__header">
				<h2 className="seoproan-app-card__title">{__('Business profiles', 'seoproanalytics')}</h2>
			</CardHeader>
			<CardBody className="seoproan-app-card__body">
				<TableScroll label={__('Business profiles', 'seoproanalytics')}>
					<table className={`widefat striped seoproan-table${query.isFetching ? ' is-refreshing' : ''}`}>
						<thead>
							<tr>
								<th scope="col">{__('Profile', 'seoproanalytics')}</th>
								<th scope="col" className="num">{__('Visits', 'seoproanalytics')}</th>
								<th scope="col" className="num">{__('Conversions', 'seoproanalytics')}</th>
								<th scope="col" className="num">{__('Revenue', 'seoproanalytics')}</th>
							</tr>
						</thead>
						<tbody>
							{answer.profiles.map((profile) => {
								const active = hasFilterValue(state.filters, 'utm_campaign', profile.campaign, 'is');
								return (
									<tr key={profile.campaign}>
										<td>
											<button
												type="button"
												className={`seoproan-link${active ? ' is-active' : ''}`}
												aria-pressed={active}
												title={active ? __('Remove this filter', 'seoproanalytics') : __('Show only visits from this profile\'s link', 'seoproanalytics')}
												onClick={() => update({ filters: toggleFilterValue(state.filters, 'utm_campaign', profile.campaign, 'is') })}
											>
												{profile.name}
											</button>
											<span className="seoproan-meta">{profile.platform_label}</span>
										</td>
										<td className="num">{formatNumber(profile.visits, locale)}</td>
										<td className="num">{formatNumber(profile.conversions, locale)}</td>
										<td className="num">
											<Money revenue={profile.revenue} />
										</td>
									</tr>
								);
							})}
						</tbody>
					</table>
				</TableScroll>
				<p className="seoproan-note">
					{__('Visits from each profile\'s tagged link (Settings → Data → Business profiles). The Business profiles channel also counts map apps\' visits without it.', 'seoproanalytics')}
				</p>
			</CardBody>
		</Card>
	);
}

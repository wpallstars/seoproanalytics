/**
 * Business profiles (Overview): each profile listed in Settings → Data,
 * with the visits, conversions and revenue from its tagged link. Shown
 * only when profiles are listed; choosing one filters every report by
 * its campaign.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 */

import { __ } from '@wordpress/i18n';
import { formatNumber } from '@seoproanalytics/core';
import { useBusinessProfiles } from '../api';
import type { ViewProps } from '../App';
import { locale } from '../boot';
import { CampaignFilter } from './CampaignFilter';
import { Money } from './Money';
import { TableCard } from './TableCard';

export function BusinessProfilesCard({ state, update }: Readonly<ViewProps>) {
	const query = useBusinessProfiles({ range: state.range, from: state.from, to: state.to, filters: state.filters });
	const answer = query.data;
	if (!answer?.profiles.length) {
		return null;
	}
	return (
		<TableCard
			title={__('Business profiles', 'seoproanalytics')}
			refreshing={query.isFetching}
			note={__('Visits from each profile\'s tagged link (Settings → Data → Business profiles). The Business profiles channel also counts map apps\' visits without it.', 'seoproanalytics')}
		>
			<thead>
				<tr>
					<th scope="col">{__('Profile', 'seoproanalytics')}</th>
					<th scope="col" className="num">{__('Visits', 'seoproanalytics')}</th>
					<th scope="col" className="num">{__('Conversions', 'seoproanalytics')}</th>
					<th scope="col" className="num">{__('Revenue', 'seoproanalytics')}</th>
				</tr>
			</thead>
			<tbody>
				{answer.profiles.map((profile) => (
					<tr key={profile.campaign}>
						<td>
							<CampaignFilter campaign={profile.campaign} name={profile.name} state={state} update={update} />
							<span className="seoproan-meta">{profile.platform_label}</span>
						</td>
						<td className="num">{formatNumber(profile.visits, locale)}</td>
						<td className="num">{formatNumber(profile.conversions, locale)}</td>
						<td className="num">
							<Money revenue={profile.revenue} />
						</td>
					</tr>
				))}
			</tbody>
		</TableCard>
	);
}

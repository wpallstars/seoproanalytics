/**
 * Revenue, one amount per currency (amounts in different currencies are
 * never added together).
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 */

import { __ } from '@wordpress/i18n';
import { formatMoney, type Revenue } from '@seoproanalytics/core';
import { locale } from '../boot';

export function Money({ revenue }: Readonly<{ revenue: Revenue[] }>) {
	if (!revenue.length) {
		return <span className="seoproan-muted">—</span>;
	}
	return (
		<span className="seoproan-money">
			{revenue.map((r) => (
				<span key={r.currency} className="seoproan-money__amount">
					{formatMoney(r.amount, r.currency, locale)}
				</span>
			))}
			<span className="screen-reader-text"> {__('revenue', 'seoproanalytics')}</span>
		</span>
	);
}

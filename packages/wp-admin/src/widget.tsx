/**
 * The Dashboard widget: today so far against the same weekday last week at the same time,
 * the last 7 days' trend, and a link to the full statistics.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 */

import { Notice } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { formatMetric, formatNumber, METRICS, type MetricKey } from '@seoproanalytics/core';
import { errorMessage, useStats, useTimeseries } from './api';
import { boot, locale } from './boot';
import { Change } from './components/Change';
import { Sparkline } from './components/Sparkline';
import { setDataSet, useDataSet } from './data';
import { metricLabel } from './labels';
import { mount } from './mount';
import { placeWidget } from './placeWidget';
import './widget.css';

const TODAY_METRICS: MetricKey[] = ['visitors', 'pageviews', 'bounce_rate'];

// Demo data chosen but not made (yet): the widget shows live statistics.
if (boot.data === 'demo' && boot.demo.status !== 'ready') {
	setDataSet('live');
}

function Widget() {
	const data = useDataSet();
	// The same weekday a week earlier, as the dashboard's default for Today (defaultCompare()).
	const today = useStats({ range: 'today', compare: 'week', filters: [] });
	const week = useTimeseries({ range: '7d', compare: 'none', filters: [] });

	if (today.isError) {
		return (
			<Notice status="error" isDismissible={false}>
				{errorMessage(today.error, __('The statistics could not be loaded.', 'seoproanalytics'))}
			</Notice>
		);
	}
	const answer = today.data;
	const visitors = week.data?.points.map((p) => p.visitors) ?? [];
	const weekTotal = visitors.reduce((a, b) => a + b, 0);

	return (
		<div className="seoproan-widget">
			<h3 className="seoproan-widget__heading">
				{__('Today so far', 'seoproanalytics')}
				{data === 'demo' && <span className="seoproan-widget__demo">{__('Demo data', 'seoproanalytics')}</span>}
			</h3>
			<dl className="seoproan-widget__metrics">
				{TODAY_METRICS.map((key) => (
					<div key={key} className="seoproan-widget__metric">
						<dt>{metricLabel(key)}</dt>
						<dd>
							<span className="seoproan-widget__value">
								{answer ? formatMetric(answer.metrics[key], METRICS[key].format, locale) : <span className="seoproan-skeleton" />}
							</span>
							{answer?.compare && (
								<Change
									metric={key}
									change={answer.compare.change[key]}
									previous={formatMetric(answer.compare.metrics[key], METRICS[key].format, locale)}
								/>
							)}
						</dd>
					</div>
				))}
			</dl>
			{answer?.compare && <p className="seoproan-widget__note">{__('Compared with the same weekday last week at the same time.', 'seoproanalytics')}</p>}
			<div className="seoproan-widget__week">
				<div>
					<span className="seoproan-widget__week-label">{__('Visitors, last 7 days', 'seoproanalytics')}</span>
					<strong className="seoproan-widget__week-value">{week.data ? formatNumber(weekTotal, locale) : '…'}</strong>
				</div>
				<Sparkline values={visitors} width={160} height={36} />
			</div>
			{boot.dashboardUrl && (
				<p className="seoproan-widget__more">
					<a className="button" href={boot.dashboardUrl}>
						{__('View statistics', 'seoproanalytics')}
					</a>
				</p>
			)}
		</div>
	);
}

if (boot.placeWidget) {
	placeWidget();
}
mount('seoproan-widget', <Widget />);

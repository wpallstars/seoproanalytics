/**
 * Headline metrics. Each tile picks the metric the chart shows.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 */

import { __ } from '@wordpress/i18n';
import { CHART_METRICS, formatMetric, METRICS, type MetricKey, type StatsAnswer, type TimeseriesAnswer } from '@seoproanalytics/core';
import { locale } from '../boot';
import { compareLabel, metricHelp, metricLabel } from '../labels';
import { Change } from './Change';
import { Sparkline } from './Sparkline';

interface Props {
	stats?: StatsAnswer;
	series?: TimeseriesAnswer;
	selected: MetricKey;
	onSelect: (metric: MetricKey) => void;
	loading: boolean;
}

/** A tile's figure: the value, a placeholder while loading, or a dash. */
function TileValue({ value, format, loading }: Readonly<{ value: number | undefined; format: (typeof METRICS)[MetricKey]['format']; loading: boolean }>) {
	if (value !== undefined) {
		return <>{formatMetric(value, format, locale)}</>;
	}
	return loading ? <span className="seoproan-skeleton" /> : <>—</>;
}

export function MetricTiles({ stats, series, selected, onSelect, loading }: Readonly<Props>) {
	return (
		<div className="seoproan-tiles" role="group" aria-label={__('Chart a metric', 'seoproanalytics')}>{/* NOSONAR: a group of buttons; a fieldset would bring its own border, padding and min-width. */}
			{CHART_METRICS.map((key) => {
				const spec = METRICS[key];
				const value = stats?.metrics[key];
				const previous = stats?.compare?.metrics[key];
				const typical = Boolean(stats?.compare?.periods);
				const before = previous === undefined ? undefined : typical && spec.format === 'number' ? new Intl.NumberFormat(locale, { maximumFractionDigits: 2 }).format(previous) : formatMetric(previous, spec.format, locale);
				const help = metricHelp(key);
				return (
					<button
						key={key}
						type="button"
						className={`seoproan-tile${selected === key ? ' is-selected' : ''}`}
						aria-pressed={selected === key}
						title={help || undefined}
						onClick={() => onSelect(key)}
					>
						<span className="seoproan-tile__label">{metricLabel(key)}</span>
						<span className="seoproan-tile__value">
							<TileValue value={value} format={spec.format} loading={loading} />
						</span>
						<span className="seoproan-tile__foot">
							{stats?.compare && (
								<Change
									metric={key}
									change={stats.compare.change[key]}
									previous={typical && before !== undefined ? `${compareLabel('typical')}: ${before}` : before}
								/>
							)}
							{series && <Sparkline values={series.points.map((p) => p[key])} width={72} height={22} />}
						</span>
					</button>
				);
			})}
		</div>
	);
}

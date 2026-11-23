/**
 * An Overview card that is one table: its title, the table (scrolling
 * sideways on narrow screens, dimmed while it refreshes) and a note under
 * it. The caller gives the table's head and body.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 */

import { Card, CardBody, CardHeader } from '@wordpress/components';
import type { ReactNode } from 'react';
import { TableScroll } from './TableScroll';

export function TableCard({ title, refreshing, note, children }: Readonly<{ title: string; refreshing: boolean; note: ReactNode; children: ReactNode }>) {
	return (
		<Card className="seoproan-app-card" size="small">
			<CardHeader className="seoproan-app-card__header">
				<h2 className="seoproan-app-card__title">{title}</h2>
			</CardHeader>
			<CardBody className="seoproan-app-card__body">
				<TableScroll label={title}>
					<table className={`widefat striped seoproan-table${refreshing ? ' is-refreshing' : ''}`}>{children}</table>
				</TableScroll>
				<p className="seoproan-note">{note}</p>
			</CardBody>
		</Card>
	);
}

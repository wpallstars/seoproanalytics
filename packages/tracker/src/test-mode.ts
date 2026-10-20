/**
 * Local-only hit inspector, loaded before the tracker on test responses only.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 * Additional terms (GPL-3.0 section 7(b)): ATTRIBUTION.txt
 * Provenance: wpas-c8662019b99fb07b. Copies, even of part, keep these lines.
 */
import type { Api } from './index';

const hits: NonNullable<Api['hits']> = [];
let panel: HTMLDetailsElement | undefined;

document.addEventListener('seoproanalytics:hit', (event) => {
	const hit = (event as CustomEvent<NonNullable<Api['hits']>[number]>).detail;
	hits.push(hit);
	if (window.seoproanalytics) {
		window.seoproanalytics.hits = hits;
	}
	console.info('[SEO Pro Analytics test]', hit);
	if (!document.body) {
		return;
	}
	if (!panel) {
		panel = document.createElement('details');
		panel.id = 'seoproanalytics-test';
		panel.style.cssText = 'position:fixed;bottom:16px;inset-inline-end:16px;z-index:2147483647;box-sizing:border-box;max-width:calc(100vw - 32px);padding:12px;border:1px solid currentColor;background:var(--global-palette9,Canvas);color:var(--global-palette3,CanvasText);font:14px/1.5 sans-serif';
		panel.append(document.createElement('summary'), document.createElement('pre'));
		(panel.lastElementChild as HTMLElement).style.cssText = 'max-height:40vh;max-width:560px;overflow:auto;white-space:pre-wrap;overflow-wrap:anywhere';
		document.body.append(panel);
	}
	// Text only: never interpret a hit's name, selector or properties as markup.
	panel.firstElementChild!.textContent = 'Stats test mode: nothing is recorded (' + hits.length + ')';
	panel.lastElementChild!.textContent = hits.slice(-30).map((item) => JSON.stringify(item)).join('\n');
});

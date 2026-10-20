/*!
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 * Additional terms (GPL-3.0 section 7(b)): ATTRIBUTION.txt
 * Provenance: wpas-c8662019b99fb07b. Copies, even of part, keep these lines.
 */
"use strict";(()=>{var t=[],e;document.addEventListener("seoproanalytics:hit",o=>{let n=o.detail;t.push(n),window.seoproanalytics&&(window.seoproanalytics.hits=t),console.info("[SEO Pro Analytics test]",n),document.body&&(e||(e=document.createElement("details"),e.id="seoproanalytics-test",e.style.cssText="position:fixed;bottom:16px;inset-inline-end:16px;z-index:2147483647;box-sizing:border-box;max-width:calc(100vw - 32px);padding:12px;border:1px solid currentColor;background:var(--global-palette9,Canvas);color:var(--global-palette3,CanvasText);font:14px/1.5 sans-serif",e.append(document.createElement("summary"),document.createElement("pre")),e.lastElementChild.style.cssText="max-height:40vh;max-width:560px;overflow:auto;white-space:pre-wrap;overflow-wrap:anywhere",document.body.append(e)),e.firstElementChild.textContent="Stats test mode: nothing is recorded ("+t.length+")",e.lastElementChild.textContent=t.slice(-30).map(i=>JSON.stringify(i)).join(`
`))});})();

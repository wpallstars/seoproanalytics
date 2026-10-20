/**
 * Build of the wp-admin app (DEVELOPMENT.md → JavaScript builds): WordPress's
 * own configuration with our entries, written to assets/build/.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 * Additional terms (GPL-3.0 section 7(b)): ATTRIBUTION.txt
 * Provenance: wpas-c8662019b99fb07b. Copies, even of part, keep these lines.
 */

const path = require('node:path');
const fs = require('node:fs');
const webpack = require('webpack');
const wpConfig = require('@wordpress/scripts/config/webpack.config');

const base = Array.isArray(wpConfig) ? wpConfig[0] : wpConfig;
const provenance = fs.readFileSync('seoproanalytics.php', 'utf8')
	.match(/Provenance: (wpas-[a-f0-9]{16})/)[1];
const notice = `/*!\n * SPDX-License-Identifier: GPL-3.0-or-later
 * SPDX-FileCopyrightText: 2026 Marcus Quinn
 * Additional terms (GPL-3.0 section 7(b)): ATTRIBUTION.txt
 * Provenance: ${provenance}. Copies, even of part, keep these lines.
 */\n`;

module.exports = {
	...base,
	plugins: [
		...base.plugins,
		{
			apply(compiler) {
				compiler.hooks.thisCompilation.tap('SEOProAnalyticsNotices', (compilation) => {
					compilation.hooks.processAssets.tap({
						name: 'SEOProAnalyticsNotices',
						stage: webpack.Compilation.PROCESS_ASSETS_STAGE_REPORT,
					}, () => {
						for (const asset of compilation.getAssets()) {
							if (/\.(js|css)$/.test(asset.name)) {
								compilation.updateAsset(asset.name, new webpack.sources.ConcatSource(notice, asset.source));
							} else if (/\.asset\.php$/.test(asset.name)) {
								const source = asset.source.source().toString();
								compilation.updateAsset(asset.name, new webpack.sources.RawSource(source.replace(/^<\?php\s*/, `<?php\n${notice}`)));
							}
						}
					});
				});
			},
		},
	],
	entry: {
		dashboard: './packages/wp-admin/src/dashboard.tsx',
		widget: './packages/wp-admin/src/widget.tsx',
		share: './packages/wp-admin/src/share.tsx',
		editor: './packages/wp-admin/src/editor.tsx',
		'ab-test': './packages/wp-admin/src/ab-test/index.tsx',
	},
	output: {
		...base.output,
		path: path.resolve(__dirname, 'assets/build'),
	},
};

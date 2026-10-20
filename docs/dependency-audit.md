<!-- SPDX-License-Identifier: GPL-3.0-or-later -->
<!-- SPDX-FileCopyrightText: 2026 Marcus Quinn -->
<!-- Additional terms (GPL-3.0 section 7(b)): ATTRIBUTION.txt -->
<!-- Provenance: wpas-c8662019b99fb07b. Copies, even of part, keep these lines. -->

# npm audit: development-toolchain braces advisory

Disposition checked on 13 October 2026 against the committed lockfile with
Node 26.4.0 and npm 11.17.0: **not fixed; no published upstream patch**. Keep the existing dependencies and build contract.
This is a development-toolchain exposure, not a shipped security fix.

## Evidence and dependency chain

[GHSA-vfj7-8cjw-p6xm](https://github.com/advisories/GHSA-vfj7-8cjw-p6xm)
(CVE-2026-93687) lists braces through 3.0.3 as affected and reports that recursive
AST walkers without depth guards can terminate the Node process with an uncaught
RangeError when given deeply nested brace patterns. The maintainer disputes
that this is a vulnerability and says the existing `maxLength` limit addresses
the cited nesting depth. As checked on 13 October 2026,
[issue #70](https://github.com/micromatch/braces/issues/70) remains open;
the cross-referenced depth-limit [PR #72](https://github.com/micromatch/braces/pull/72)
is closed **without merging**. The advisory lists **Patched versions: None**.
`npm view braces version versions --json` confirms 3.0.3 is still the latest
published version, including all older published versions in the affected range.

The installed chain (`npm ls braces micromatch chokidar` and `npm explain braces`):

- `@wordpress/scripts@36.0.0` → `fast-glob@3.3.3` → `micromatch@4.0.8`
  → `braces@3.0.3`.
- Scripts also bring `stylelint@17.16.0`, whose micromatch/globby paths use
  the same braces, and `webpack-dev-server@5.2.6` → `chokidar@3.6.0`
  → the same braces.
- `@wordpress/components@41.0.0` → `@wordpress/ui@0.23.0`
  → `@wordpress/theme@2.2.0` has an optional stylelint peer. The installed
  stylelint explains the advisory's propagation to components, not a separate
  runtime braces dependency in the WordPress components external.

`npm ci --ignore-scripts` succeeds. `npm audit --json` exits 1 with **21
high-severity package entries**, propagated from this one underlying advisory;
these are not 21 independent vulnerabilities. `npm audit --json --omit=dev`
exits 0 with **zero vulnerabilities**. Before and after this documentation-only
disposition, the chain and these counts remain unchanged.

## Exposure and precautions

The affected packages run in Node during development, linting, building and
watching. According to the advisory, malicious patterns reaching their brace
parser can stop those processes. Do not pass untrusted glob patterns to development tools or expose
the `npm start` development server publicly. Run untrusted source builds in
isolated, disposable environments, without production credentials. Disabling
install scripts reduces a different supply-chain risk; it does **not** fix
the reported parser vulnerability.

`.distignore` excludes `node_modules`, npm manifests, the lockfile, sources,
tool configuration and `docs/` from both release builds. Only built assets
ship. `webpack.config.js` retains WordPress's dependency extraction and
externals; braces is a build-tool dependency, not imported by the application
sources. A clean production audit alone is not proof of bundle safety:
confirm the dependency chain and unchanged generated assets too.

## Why not update or override now?

- `@wordpress/scripts@36.1.0` still declares `fast-glob ^3.2.7` and
  `stylelint ^17.14.1`. Latest `micromatch@4.0.8` still declares `braces
  ^3.0.3`. A compatible scripts update therefore retains an affected path.
- Latest components 42.0.0 is a major update, not the smallest compatible
  remediation; it still brings UI 0.24.0 and theme 2.3.0, whose optional
  stylelint peer remains `^16 || ^17`. No patched braces exists to select.
- An override to braces 3.0.3 cannot fix an advisory that includes 3.0.3.
  No unpublished fork or unrelated major override is introduced.
- Audit's suggested scripts 19.2.4 and components 33.1.0 are breaking
  downgrades, not verified fixes. Do not run `npm audit fix --force`.

No audit suppression, package downgrade, lockfile change, platform-minimum
change or user-facing changelog entry is warranted for this disposition.

## Upstream wake condition and verification

Revisit at the next dependency review before release, or as soon as either:

1. The advisory identifies a patched braces version **and** that version is
   published to npm. Check its fix, API and the micromatch/chokidar ranges;
   prefer a compatible lockfile update, otherwise validate a scoped override.
2. A compatible WordPress toolchain release removes **all** affected braces
   paths, including stylelint and the development server. Verify the installed
   tree rather than trusting an audit `fixAvailable` recommendation.

Reproduce with the repository's normal commands:

```sh
npm ci --ignore-scripts
npm ls braces micromatch chokidar @wordpress/scripts @wordpress/components
npm explain braces
npm view braces version versions --json
npm audit --json
npm audit --json --omit=dev
npm run check
npm run build
scripts/lint.sh
git diff --exit-code -- package.json package-lock.json assets/build/
```

Until upstream fixes this, the full audit's nonzero exit is expected evidence,
not a passing check. Record the current counts rather than ignoring its output.
For a remediation, require no affected installed braces path, a clean full
audit for this advisory, no production regression, and the existing type/build/
lint checks. Commit only changed generated assets; when bundles change, also
run release preflight, Plugin Check and all existing WordPress smoke lanes
listed in `DEVELOPMENT.md`. Preserve exact-head results in the pull request.

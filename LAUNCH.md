# SEO Pro Analytics — launch state

The public repository `wpallstars/seoproanalytics` holds the free plugin.
It starts with one commit of the free tree; its first release follows
1.19.1. Versions up to 1.19.1 were released from an earlier repository,
now private, which keeps the SEO Pro Analytics Connect add-on. Sites on
1.19.1 update to this repository's first release through the GitHub
updater (the same `GitHub Plugin URI`); their connections and keys stay,
and the add-on's connections import again once the add-on is installed.

Before it went public, the free tree was scanned for secrets
(`DEVELOPMENT.md` → Secrets in history) and checked for private names and
paths. The REST API example's placeholder `admin:APPLICATION PASSWORD` is
not a password, if a scan names it.

Not yet:

- The repository's settings: description and topics; a branch ruleset on
  `main` requiring Lint, Release build and both Smoke tests, without
  "branch must be up to date"; CodeQL default setup (JavaScript and
  TypeScript, GitHub Actions; it has no PHP support), secret scanning with
  push protection, Dependabot alerts and security updates, private
  vulnerability reporting (`SECURITY.md`); SonarCloud
  (`.github/workflows/sonarcloud.yml` with `SONAR_TOKEN`) and OpenSSF
  Scorecard; `SYNC_PAT` for Repository metrics. Record each here once on.
- The first release, through `RELEASING.md`, and the update test from
  1.19.1 (`scripts/update-test.sh`).
- The rest of `DEVELOPMENT.md` → At public launch: fix the reviewers'
  findings by area, then raise the PHPStan level.

WordPress.org: planned (`readme.txt` → FAQ: versions reach WordPress.org 30
days after GitHub). Not submitted; follow `RELEASING.md` → WordPress.org
when the owner says, and record the submission here. It is submitted as
`seoproanalytics`: the old name was never on WordPress.org.

Renamed from SEO Pro Stats (`seoprostats`) in 1.19.0 (`docs/architecture.md`
→ Renamed from SEO Pro Stats); 1.19.1 fixes the move on admin pages and
cron runs for sites with stored connections. 1.19.0 is not offered to
sites on 1.18.0 or earlier: to WordPress it is a new plugin, and their
updater (`Release Asset: true`) looks for `seoprostats-1.19.0.zip`, which
the release does not have. Check the move from the 1.18.0 release with
`scripts/move-install.sh` on a disposable site.

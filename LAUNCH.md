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

On since 20 October 2026, when the repository went public:

- Description and topics; no wiki.
- The branch ruleset **main: required CI checks** on the default branch:
  Lint (PHP 7.4), Release build, and the Smoke tests on WordPress 6.2 with
  PHP 7.4 and WordPress latest with PHP 8.3, without "branch must be up to
  date" (`DEVELOPMENT.md` → At public launch). It also blocks deleting
  `main` and force pushes; repository admins can bypass it.
- CodeQL default setup (GitHub Actions, JavaScript and TypeScript; it has
  no PHP support), secret scanning with push protection, Dependabot alerts
  and security updates, and private vulnerability reporting
  (`SECURITY.md`).
- GitHub-hosted runners only: no `CI_RUNNER` variable (`DEVELOPMENT.md` →
  Services setup).
- OpenSSF Scorecard: its workflow runs on pushes to `main` and weekly.
- SonarCloud: this repository is imported as the project
  `wpallstars_seoproanalytics`, public like the repository. The SonarCloud
  workflow analyses pushes to `main` and pull requests with the
  organization's `SONAR_TOKEN`. It is not a required check, so its failure
  does not block a merge.
- `SYNC_PAT`: set, so Repository metrics commits `docs/metrics/` to the
  protected `main`.

Not yet:

- Codacy and CodeFactor (`DEVELOPMENT.md` → Services setup).
- The Scorecard badge, once its results are published
  (`scripts/readme-badges.sh --add scorecard`).
- The first release, through `RELEASING.md`, and the update test from
  1.19.1 (`scripts/update-test.sh`), once the add-on installs: sites that
  import connections the add-on now owns keep importing only with it.
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

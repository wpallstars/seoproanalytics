#!/usr/bin/env bash
# Move a site from SEO Pro Stats (seoprostats, the plugin's old name) to SEO
# Pro Analytics (seoproanalytics) with WP-CLI: back up the database,
# deactivate the old plugin, count its tables' rows, rename its wp-config.php
# constants, install and activate the new plugin, move the data
# (wp seoproanalytics migrate-name), check every table's row count, upgrade
# the tables, purge page caches, then delete the old plugin's files (never
# its uninstall, which would delete the data).
#
# Usage: scripts/move-install.sh <zip-or-url> [--yes] [--keep-old]
#            [--backup=<file>] [-- <wp-cli global options>]
#   <zip-or-url>  the seoproanalytics release zip (scripts/build-release.sh)
#                 or its URL; with --ssh, a path on the server.
#   --yes         do not ask before changing the site.
#   --keep-old    leave the old plugin's files (deactivated).
#   --backup      where the database backup goes (gzip; this computer).
#   After --: options for every wp command, such as --path=/var/www/html,
#   --ssh=user@host/path or --url=https://example.com.
# WP=<command> runs another WP-CLI command (default: wp).
#
# SPDX-License-Identifier: GPL-3.0-or-later
# SPDX-FileCopyrightText: 2026 Marcus Quinn
# Additional terms (GPL-3.0 section 7(b)): ATTRIBUTION.txt
# Provenance: wpas-c8662019b99fb07b. Copies, even of part, keep these lines.
# shellcheck disable=SC2016 # Single-quoted PHP deliberately keeps PHP variables literal.

set -euo pipefail

readonly OLD=seoprostats
readonly NEW=seoproanalytics
readonly CONSTANTS="ENCRYPTION_KEY CORE_ONLY GOOGLE_RELAY CONNECT_URL CRAWLER_ALLOW_PRIVATE"
WP="${WP:-wp}"
ZIP=""
YES=""
KEEP_OLD=""
BACKUP=""
WP_ARGS=()

die() {
	local message="$1"
	printf 'move-install: %s\n' "$message" >&2
	exit 2
	# shellcheck disable=SC2317 # exit terminates this run.
	return 1
}

usage() {
	sed -n '2,20p' "$0" | sed 's/^# \{0,1\}//'
	return 0
}

# Never from stdin: over --ssh, wp would read the rest of a loop's list.
wpc() {
	"$WP" ${WP_ARGS[@]+"${WP_ARGS[@]}"} "$@" </dev/null
	return $?
}

# Row counts of the plugin's tables on every site, keyed by the table's
# name without the plugin's prefix: one "name count" per line, sorted.
counts() {
	local prefix="$1"
	[[ "$prefix" =~ ^[a-z]+$ ]] || die "bad prefix: $prefix"
	# wp eval takes no arguments: the prefix (checked above) goes in the code.
	wpc eval '
		global $wpdb;
		$prefix = "'"$prefix"'_";
		$out = array();
		foreach ((array) $wpdb->get_col($wpdb->prepare("SHOW TABLES LIKE %s", "%" . $wpdb->esc_like($prefix) . "%")) as $table) {
			$at = strpos($table, $prefix);
			if ($at === false || !preg_match("/^\\w+$/", $table)) {
				continue;
			}
			$key = substr($table, 0, $at) . substr($table, $at + strlen($prefix));
			$out[] = $key . " " . (int) $wpdb->get_var("SELECT COUNT(*) FROM `" . $table . "`");
		}
		sort($out);
		echo implode("\n", $out), $out ? "\n" : "";
	' --skip-plugins --skip-themes
	return $?
}

# Sites where the old plugin is active: "network" when network-active,
# else one URL per site (one line, "site", on a single site).
active_sites() {
	if wpc core is-installed --network >/dev/null 2>&1; then
		if wpc plugin is-active "$OLD" --network >/dev/null 2>&1; then
			printf 'network\n'
			return 0
		fi
		local url urls
		urls="$(wpc site list --field=url)"
		while IFS= read -r url; do
			[[ -z "$url" ]] && continue
			if wpc plugin is-active "$OLD" --url="$url" >/dev/null 2>&1; then
				printf '%s\n' "$url"
			fi
		done <<<"$urls"
		return 0
	fi
	if wpc plugin is-active "$OLD" >/dev/null 2>&1; then
		printf 'site\n'
	fi
	return 0
}

move_constants() {
	local name value
	for name in $CONSTANTS; do
		wpc config has "SEOPROSTATS_$name" --type=constant >/dev/null 2>&1 || continue
		if wpc config has "SEOPROANALYTICS_$name" --type=constant >/dev/null 2>&1; then
			printf '  wp-config.php already defines SEOPROANALYTICS_%s; SEOPROSTATS_%s is left for you to remove.\n' "$name" "$name"
			continue
		fi
		# CONSTANTS holds plain names, so the name can go in the code.
		value="$(wpc eval "echo var_export(constant('SEOPROSTATS_$name'), true);" --skip-plugins --skip-themes)"
		wpc config set "SEOPROANALYTICS_$name" "$value" --raw --type=constant >/dev/null
		wpc config delete "SEOPROSTATS_$name" --type=constant >/dev/null
		printf '  wp-config.php: SEOPROSTATS_%s is now SEOPROANALYTICS_%s.\n' "$name" "$name"
	done
	return 0
}

# WP-CLI reports a failed activation when anything prints output while the
# plugin is activated, such as another plugin's activation hook, though
# WordPress has activated it: check the result instead of the exit code.
activate_one() {
	wpc plugin activate "$NEW" "$@" && return 0
	wpc plugin is-active "$NEW" "$@" >/dev/null 2>&1 || return 1
	printf '  It is active: the output came from another plugin while it was activated.\n'
	return 0
}

activate_new() {
	local where="$1"
	local site
	if [[ -z "$where" ]]; then
		printf '  The old plugin was not active; activating the new one on this site.\n'
		activate_one
		return $?
	fi
	while IFS= read -r site; do
		case "$site" in
		network) activate_one --network ;;
		site) activate_one ;;
		*) activate_one --url="$site" ;;
		esac
	done <<<"$where"
	return 0
}

# Bring the tables up to the new version on every site where the plugin
# runs, as an admin page would (the move kept the old version's tables).
# No process substitution here or below: some hosts have no /dev/fd.
upgrade_tables() {
	local code='if (class_exists("SEOProAnalytics_Schema") && !SEOProAnalytics_Schema::maybe_upgrade()) { WP_CLI::warning("The tables could not be upgraded: open an admin page."); }'
	local url urls
	if wpc core is-installed --network >/dev/null 2>&1; then
		urls="$(wpc site list --field=url)"
		while IFS= read -r url; do
			[[ -z "$url" ]] || wpc eval "$code" --url="$url"
		done <<<"$urls"
		return 0
	fi
	wpc eval "$code"
	return $?
}

deactivate_old() {
	local where="$1"
	local site
	[[ -z "$where" ]] && return 0
	while IFS= read -r site; do
		case "$site" in
		network) wpc plugin deactivate "$OLD" --network ;;
		site) wpc plugin deactivate "$OLD" ;;
		*) wpc plugin deactivate "$OLD" --url="$site" ;;
		esac
	done <<<"$where"
	return 0
}

main() {
	local arg before after where answer
	while [[ $# -gt 0 ]]; do
		arg="$1"
		shift
		case "$arg" in
		--yes) YES=1 ;;
		--keep-old) KEEP_OLD=1 ;;
		--backup=*) BACKUP="${arg#--backup=}" ;;
		-h | --help)
			usage
			return 0
			;;
		--)
			WP_ARGS=("$@")
			break
			;;
		-*) die "unknown option: $arg" ;;
		*)
			[[ -z "$ZIP" ]] || die "one zip or URL only"
			ZIP="$arg"
			;;
		esac
	done
	[[ -n "$ZIP" ]] || {
		usage
		die "give the seoproanalytics zip or its URL"
	}
	command -v "$WP" >/dev/null 2>&1 || die "WP-CLI ($WP) is not found"
	wpc core is-installed >/dev/null || die "no WordPress there (pass --path, --ssh or --url after --)"
	wpc plugin is-installed "$OLD" >/dev/null 2>&1 || die "SEO Pro Stats ($OLD) is not installed there: nothing to move"
	if wpc plugin is-installed "$NEW" >/dev/null 2>&1; then
		die "SEO Pro Analytics ($NEW) is already installed: run wp $NEW migrate-name there, or delete it first"
	fi

	where="$(active_sites)"
	printf 'SEO Pro Stats -> SEO Pro Analytics\n  active: %s\n  tables: %s\n' \
		"$(printf '%s' "${where:-nowhere}" | tr '\n' ' ')" "$(counts "$OLD" | grep -c . || true)"
	if [[ -z "$YES" ]]; then
		read -r -p 'Back up the database and move the site? [y/N] ' answer
		[[ "$answer" == [yY]* ]] || die "stopped; nothing was changed"
	fi

	BACKUP="${BACKUP:-$OLD-before-move-$(date +%Y%m%d-%H%M%S).sql.gz}"
	printf '1. Backing up the database to %s\n' "$BACKUP"
	wpc db export - | gzip >"$BACKUP"
	[[ -s "$BACKUP" ]] || die "the backup is empty; nothing was changed"

	printf '2. Deactivating SEO Pro Stats (its data stays)\n'
	deactivate_old "$where"
	# Counted once the old plugin is off (it notes its own deactivation on
	# the timeline), and compared before the new one loads and notes more.
	before="$(counts "$OLD")"
	printf '3. Renaming wp-config.php constants\n'
	move_constants
	printf '4. Installing and activating SEO Pro Analytics\n'
	wpc plugin install "$ZIP"
	activate_new "$where"

	printf '5. Moving the data\n'
	wpc "$NEW" migrate-name --dry-run
	wpc "$NEW" migrate-name

	printf '6. Checking row counts\n'
	after="$(counts "$NEW")"
	if [[ "$before" != "$after" ]]; then
		local left right
		left="$(mktemp)"
		right="$(mktemp)"
		printf '%s\n' "$before" >"$left"
		printf '%s\n' "$after" >"$right"
		diff "$left" "$right" >&2 || true
		rm -f "$left" "$right"
		die "row counts differ (left: before, right: after). The old plugin's files are kept; the backup is $BACKUP"
	fi
	printf '  %s tables, every row count matches.\n' "$(printf '%s' "$after" | grep -c . || true)"
	if [[ -n "$(counts "$OLD")" ]]; then
		die "tables under the old name are left; the old plugin's files are kept"
	fi
	wpc "$NEW" migrate-name

	printf '7. Upgrading the tables (otherwise the next admin page does)\n'
	upgrade_tables
	printf '8. Purging page caches\n'
	wpc "$NEW" purge-caches || printf '  Purge them by hand: cached pages still call the old collector.\n'

	if [[ -z "$KEEP_OLD" ]]; then
		printf '9. Deleting the old plugin'"'"'s files (not its data: there is none left under its name)\n'
		wpc plugin delete "$OLD"
	fi
	printf 'Done. Backup: %s\n' "$BACKUP"
	return 0
}

main "$@"

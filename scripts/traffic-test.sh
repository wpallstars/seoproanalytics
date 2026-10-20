#!/usr/bin/env bash
# Real collector hits, channel attribution, business profiles and revenue on a
# disposable localhost-only site. No outside profiles or payment services used.
# SPDX-License-Identifier: GPL-3.0-or-later
# SPDX-FileCopyrightText: 2026 Marcus Quinn
# Additional terms (GPL-3.0 section 7(b)): ATTRIBUTION.txt
# Provenance: wpas-c8662019b99fb07b. Copies, even of part, keep these lines.
# shellcheck disable=SC2016 # Single-quoted PHP deliberately keeps PHP variables literal.

set -euo pipefail
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
readonly SCRIPT_DIR
# shellcheck source=scripts/lib/plugin.sh disable=SC1091 # followed with -x
. "$SCRIPT_DIR/lib/plugin.sh"
PHP_VERSION=8.2
WP_VERSION=latest
REF=HEAD
ZIP=""
KEEP_LOG=""
TMP_DIR=""
NAME=""
BASE_URL=""
STARTED=0
FAILED=0
VISIT=0
readonly UA='Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 Chrome/130.0.0.0 Safari/537.36'

die() {
	local message="$1"
	printf 'traffic-test: %s\n' "$message" >&2
	exit 2
	# shellcheck disable=SC2317 # exit terminates this run.
	return 1
}

fail() {
	local message="$1"
	printf '  FAIL %s\n' "$message" >&2
	FAILED=1
	return 0
}

wp_cli() {
	docker run --rm --network "$NAME" -v "$NAME-wp:/var/www/html" -v "$TMP_DIR/zips:/zips:ro" \
		--user 33:33 "wordpress:cli-php$PHP_VERSION" php -d memory_limit=1G /usr/local/bin/wp "$@"
	return $?
}

eval_php() {
	local code="$1"
	shift
	code="${code//TEST_PACKAGE/$PLUGIN_PACKAGE}"
	code="${code//TEST_SLUG/$PLUGIN_SLUG}"
	wp_cli eval "$code" "$@"
	return $?
}

cleanup() {
	local code=$?
	local attempt id
	trap - EXIT
	if [[ "$STARTED" -eq 1 ]]; then
		if docker exec "$NAME-web" test -f /var/www/html/wp-content/debug.log 2>/dev/null; then
			docker cp "$NAME-web:/var/www/html/wp-content/debug.log" "$TMP_DIR/debug.log" >/dev/null || code=1
		fi
		if [[ -n "$KEEP_LOG" ]]; then
			[[ -f "$TMP_DIR/debug.log" ]] || touch "$TMP_DIR/debug.log"
			cp "$TMP_DIR/debug.log" "$KEEP_LOG" || code=1
		fi
		for attempt in 1 2 3; do
			while IFS= read -r id; do
				[[ -z "$id" ]] || docker rm -f "$id" >/dev/null 2>&1 || true
			done < <(docker ps -aq --filter "volume=$NAME-wp")
			docker rm -f "$NAME-web" "$NAME-db" >/dev/null 2>&1 || true
			docker volume rm "$NAME-wp" >/dev/null 2>&1 || true
			docker network rm "$NAME" >/dev/null 2>&1 || true
			if ! docker volume inspect "$NAME-wp" >/dev/null 2>&1 && ! docker network inspect "$NAME" >/dev/null 2>&1 \
				&& ! docker container inspect "$NAME-web" >/dev/null 2>&1 && ! docker container inspect "$NAME-db" >/dev/null 2>&1; then
				printf 'Removed Docker resources: %s\n' "$NAME"
				break
			fi
			if [[ "$attempt" -eq 3 ]]; then
				printf 'traffic-test: cleanup failed for %s\n' "$NAME" >&2
				code=1
			fi
			sleep 2
		done
	fi
	[[ -z "$TMP_DIR" ]] || rm -rf "$TMP_DIR"
	exit "$code"
	# shellcheck disable=SC2317 # exit preserves the test and cleanup status.
	return 0
}

parse_args() {
	local arg value
	while [[ $# -gt 0 ]]; do
		arg="$1"
		value="${2:-}"
		case "$arg" in
		--php | --wp | --ref | --zip | --keep-log)
			[[ -n "$value" ]] || die "$arg needs a value"
			case "$arg" in
			--php) [[ "$value" =~ ^[0-9]+\.[0-9]+$ ]] || die 'invalid PHP version'; PHP_VERSION="$value" ;;
			--wp) WP_VERSION="$value" ;;
			--ref) REF="$value" ;;
			--zip) [[ -f "$value" ]] || die "no such zip: $value"; ZIP="$(cd "$(dirname "$value")" && pwd)/$(basename "$value")" ;;
			--keep-log) KEEP_LOG="$value" ;;
			*) ;;
			esac
			shift ;;
		-h | --help)
			printf '%s\n' 'Usage: scripts/traffic-test.sh [--php VERSION] [--wp VERSION] [--ref REF]' \
				'       [--zip FILE] [--keep-log FILE]' 'Defaults: PHP 8.2, latest WordPress, build from HEAD.'
			exit 0 ;;
		*) die "unknown argument: $arg" ;;
		esac
		shift
	done
	return 0
}

start_site() {
	local waited=0 port
	STARTED=1
	docker network create "$NAME" >/dev/null
	docker volume create "$NAME-wp" >/dev/null
	docker run -d --name "$NAME-db" --network "$NAME" -e MARIADB_ROOT_PASSWORD=traffic-test-only \
		-e MARIADB_DATABASE=wordpress "$(plugin_env DB_IMAGE mariadb:10.6)" >/dev/null
	docker run --rm -v "$NAME-wp:/var/www/html" --user 0:0 "wordpress:cli-php$PHP_VERSION" chown 33:33 /var/www/html
	until docker exec "$NAME-db" mariadb-admin ping -h127.0.0.1 -uroot -ptraffic-test-only --silent >/dev/null 2>&1; do
		[[ "$waited" -lt 90 ]] || die 'database startup timed out'
		sleep 2
		waited=$((waited + 2))
	done
	wp_cli core download --version="$WP_VERSION" --quiet
	docker run -d --name "$NAME-web" --network "$NAME" -v "$NAME-wp:/var/www/html" \
		-p 127.0.0.1::80 "wordpress:php$PHP_VERSION-apache" >/dev/null
	port="$(docker port "$NAME-web" 80)"
	BASE_URL="http://$port"
	wp_cli config create --dbname=wordpress --dbuser=root --dbpass=traffic-test-only --dbhost="$NAME-db" --skip-check --quiet
	wp_cli config set WP_DEBUG true --raw --quiet
	wp_cli config set WP_DEBUG_LOG true --raw --quiet
	wp_cli config set WP_DEBUG_DISPLAY false --raw --quiet
	wp_cli config set DISABLE_WP_CRON true --raw --quiet
	wp_cli core install --title=TrafficTest --admin_user=admin --admin_password=traffic-test-only \
		--admin_email=admin@example.com --skip-email --quiet --url="$BASE_URL"
	wp_cli plugin install "/zips/$PLUGIN_SLUG.zip" --activate --quiet
	wp_cli rewrite structure '/%postname%/' --hard --quiet
	# WP-CLI does not detect Apache's mod_rewrite in its separate CLI image.
	docker exec -u www-data "$NAME-web" sh -c 'printf "%s\n" "RewriteEngine On" "RewriteBase /" \
		"RewriteRule ^index\.php$ - [L]" "RewriteCond %{REQUEST_FILENAME} !-f" "RewriteCond %{REQUEST_FILENAME} !-d" \
		"RewriteRule . /index.php [L]" > /var/www/html/.htaccess'
	printf 'WordPress %s, PHP %s at %s\n' "$(wp_cli core version)" "$PHP_VERSION" "$BASE_URL"
	return 0
}

request() {
	local path="$1"
	local expected="$2"
	shift 2
	local status
	status="$(curl -sS --max-time 60 -A "$UA" -o "$TMP_DIR/response" -w '%{http_code}' "$@" "$BASE_URL$path")" || die "request failed: $path"
	if [[ "$status" != "$expected" ]]; then
		fail "$path answered $status, expected $expected"
		return 1
	fi
	return 0
}

setup_profiles() {
	eval_php '
		if (!class_exists("TEST_PACKAGE_Business_Profiles")) { WP_CLI::error("Business profiles unavailable in tested build (requires the business-profile feature)"); }
		TEST_PACKAGE_Settings::set("business_profiles", true);
		TEST_PACKAGE_Settings::set("business_profiles_list", "google | Main Street shop | https://maps.app.goo.gl/abc | /\nBing Places | Main Street shop | | " . home_url("/sample-page/") . "\nother | Yelp listing | https://www.yelp.com/biz/x |");
		TEST_PACKAGE_Settings::set("purchases_thrivecart", "traffic-test-only");
		TEST_PACKAGE_Collection::rotate_salts();
		TEST_PACKAGE_Collection::write_config();
		echo wp_json_encode(TEST_PACKAGE_Business_Profiles::listed());' >"$TMP_DIR/profiles.json"
	jq -e '[.[] | [.platform,.campaign,.source,.page]] == [["google","main-street-shop","google","/"],["bing","main-street-shop-2","bing","/sample-page/"],["other","yelp-listing","yelp","/"]]' "$TMP_DIR/profiles.json" >/dev/null || fail 'profile parsing, unique campaigns and Yelp source'
	jq -e --arg home "$BASE_URL" '[.[] | [.name,.url,.link]] == [["Main Street shop","https://maps.app.goo.gl/abc",($home + "/?utm_source=google&utm_medium=business_profile&utm_campaign=main-street-shop")],["Main Street shop","",($home + "/sample-page/?utm_source=bing&utm_medium=business_profile&utm_campaign=main-street-shop-2")],["Yelp listing","https://www.yelp.com/biz/x",($home + "/?utm_source=yelp&utm_medium=business_profile&utm_campaign=yelp-listing")]]' "$TMP_DIR/profiles.json" >/dev/null || fail 'profile names, addresses and tagged website links'
	return 0
}

visit() {
	local label="$1"
	local url="$2"
	local referrer="$3"
	local expected="$4"
	local key body actual
	VISIT=$((VISIT + 1))
	printf -v key '%016x' "$VISIT"
	body="$(jq -cn --arg key "$key" --arg url "$url" --arg ref "$referrer" '{h:"127.0.0.1",e:[{t:"pv",p:$key,u:$url,r:$ref}]}')"
	request "/wp-json/$PLUGIN_SLUG/v1/collect" 204 -H 'Content-Type: application/json' -H "Origin: $BASE_URL" \
		-A "$UA Check/$VISIT" --data "$body"
	wp_cli "$PLUGIN_SLUG" process --quiet
	actual="$(eval_php 'global $wpdb; echo wp_json_encode($wpdb->get_col($wpdb->prepare("SELECT s.channel FROM %i p JOIN %i s ON s.id = p.session_id WHERE p.pkey = UNHEX(%s)", TEST_PACKAGE_Schema::table("pageviews"), TEST_PACKAGE_Schema::table("sessions"), "'"$key"'")));')"
	printf '%-28s %-10s %s\n' "$label" "$expected" "$actual"
	[[ "$actual" == "[\"$expected\"]" || "$actual" == "[$expected]" ]] || fail "$label: expected exactly one pageview in channel $expected, got $actual"
	return 0
}

check_reports() {
	local report rest settings_url i
	request "/wp-json/$PLUGIN_SLUG/v1/thrivecart" 200 --data-urlencode 'thrivecart_secret=traffic-test-only' \
		--data-urlencode 'event=order.success' --data-urlencode 'mode=live' --data-urlencode 'order_id=traffic-1' \
		--data-urlencode 'currency=USD' --data-urlencode 'order[total]=99700' --data-urlencode "passthrough[$PLUGIN_CSS]=0000000000000001"
	jq -e '.result == "recorded"' "$TMP_DIR/response" >/dev/null || fail 'ThriveCart purchase did not join first Google visit'
	wp_cli "$PLUGIN_SLUG" process --quiet
	report="$(wp_cli "$PLUGIN_SLUG" business-profiles --format=json)"
	jq -e '.channel.visits == 4 and .channel.revenue == [{currency:"USD",amount:997,count:1}] and (.profiles | length) == 3 and .profiles[0].visits == 2 and .profiles[0].revenue == [{currency:"USD",amount:997,count:1}] and .profiles[1].visits == 1 and .profiles[1].revenue == [] and .profiles[2].visits == 0' <<<"$report" >/dev/null || fail "business-profile visits/revenue: $report"
	rest="$(eval_php '$r = rest_do_request("/TEST_SLUG/v1/business-profiles"); echo $r->get_status();' --user=admin)"
	[[ "$rest" == 200 ]] || fail "admin REST expected 200, got $rest"
	request "/wp-json/$PLUGIN_SLUG/v1/business-profiles" 401
	request '/wp-login.php' 200 -c "$TMP_DIR/cookies"
	request '/wp-login.php' 302 -b "$TMP_DIR/cookies" -c "$TMP_DIR/cookies" --data-urlencode 'log=admin' \
		--data-urlencode 'pwd=traffic-test-only' --data-urlencode 'wp-submit=Log In' --data 'testcookie=1'
	settings_url="$(eval_php 'require_once WP_PLUGIN_DIR . "/TEST_SLUG/admin/includes/class-admin-manager.php"; echo TEST_PACKAGE_Admin_Manager::tab_url("data");' --user=admin)"
	[[ "$settings_url" == "$BASE_URL/"* ]] || die 'settings URL is not on disposable site'
	request "${settings_url#"$BASE_URL"}" 200 -b "$TMP_DIR/cookies"
	for i in 0 1 2; do
		grep -Eq "<input[^>]*id=\"$PLUGIN_CSS-profile-link-$i\"" "$TMP_DIR/response" || fail "settings profile $i input missing"
		grep -q "data-$PLUGIN_CSS-copy=\"$PLUGIN_CSS-profile-link-$i\"" "$TMP_DIR/response" || fail "settings profile $i Copy button missing"
	done
	return 0
}

check_timeline() {
	eval_php '
		global $wpdb;
		$table = TEST_PACKAGE_Schema::table("changes");
		$before = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM %i WHERE kind = 53", $table));
		TEST_PACKAGE_Settings::set("business_profiles_list", "google | Main Street shop | https://maps.app.goo.gl/abc | /\nBing Places | Main Street shop | | /contact/");
		$rows = $wpdb->get_col($wpdb->prepare("SELECT meta FROM %i WHERE kind = 53 ORDER BY id", $table));
		$tail = array_map(static function ($row) { $m = json_decode($row, true); return array($m["action"], $m["campaign"], $m["fields"]); }, array_slice($rows, $before));
		$expected = array(array("changed", "main-street-shop-2", array("page")), array("removed", "yelp-listing", array()));
		if ($tail !== $expected) { WP_CLI::error("FAIL timeline changed/removed: " . wp_json_encode($tail)); }
		$count = count($rows);
		TEST_PACKAGE_Settings::set("business_profiles", false);
		TEST_PACKAGE_Settings::set("business_profiles_list", "google | Main Street shop | | /off/");
		$after = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM %i WHERE kind = 53", $table));
		if ($after !== $count) { WP_CLI::error("FAIL timeline recorded an edit while switched off"); }
		WP_CLI::success("Timeline changed(page), removed, and switch-off checks");' || fail 'business-profile timeline'
	return 0
}

main() {
	parse_args "$@"
	local tool zip
	for tool in docker curl jq unzip; do command -v "$tool" >/dev/null || die "needs $tool"; done
	docker info >/dev/null 2>&1 || die 'Docker is not running'
	plugin_identity "$REF" || die 'cannot identify plugin'
	NAME="$PLUGIN_SLUG-traffic-$$"
	TMP_DIR="$(mktemp -d "${TMPDIR:-/tmp}/$NAME.XXXXXX")"
	trap cleanup EXIT
	trap 'exit 130' INT
	trap 'exit 143' TERM
	mkdir "$TMP_DIR/zips"
	chmod 755 "$TMP_DIR" "$TMP_DIR/zips"
	zip="$ZIP"
	if [[ -z "$zip" ]]; then
		"$SCRIPT_DIR/build-release.sh" --ref "$REF" --out "$TMP_DIR/build" --quiet >/dev/null
		local built=("$TMP_DIR"/build/"$PLUGIN_SLUG"-*.zip)
		zip="${built[0]}"
	fi
	cp "$zip" "$TMP_DIR/zips/$PLUGIN_SLUG.zip"
	chmod 644 "$TMP_DIR/zips/$PLUGIN_SLUG.zip"
	start_site
	setup_profiles
	printf '%-28s %-10s %s\n' 'Case' 'Expected' 'Stored channel(s)'
	visit 'Google profile first' '/?utm_source=google&utm_medium=business_profile&utm_campaign=main-street-shop' '' 9
	visit 'Google profile second' '/?utm_source=google&utm_medium=business_profile&utm_campaign=main-street-shop' '' 9
	visit 'Bing profile' '/sample-page/?utm_source=bing&utm_medium=business_profile&utm_campaign=main-street-shop-2' '' 9
	visit 'Apple Maps' '/' 'https://maps.apple.com/?q=main+street' 9
	visit 'Google organic' '/' 'https://www.google.com/' 1
	visit 'Plain Google maps path' '/' 'https://www.google.com/maps/place/x' 1
	visit 'Email beats search' '/?utm_medium=email' 'https://www.google.com/' 6
	visit 'Organic social' '/' 'https://www.facebook.com/' 4
	visit 'Paid search click ID' '/?gclid=traffic-check' 'https://www.google.com/' 2
	visit 'AI assistant' '/' 'https://chatgpt.com/' 3
	visit 'Referral' '/' 'https://example.org/article' 7
	visit 'Direct' '/' '' 0
	visit 'Paid social' '/?utm_source=facebook&utm_medium=paid_social' '' 5
	visit 'Paid other' '/?utm_source=sponsor&utm_medium=display' '' 8
	check_reports
	check_timeline
	eval_php '$file = WP_CONTENT_DIR . "/debug.log"; clearstatcache(); if (is_file($file) && filesize($file) > 0) { WP_CLI::error("FAIL nonempty debug.log: " . file_get_contents($file)); }' || fail 'PHP debug log'
	[[ "$FAILED" -eq 0 ]] || return 1
	printf 'Traffic test passed (WordPress %s, PHP %s).\n' "$WP_VERSION" "$PHP_VERSION"
	return 0
}

main "$@"

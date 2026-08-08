#!/usr/bin/env bash
#
# Install WordPress core and the WordPress PHPUnit test library so the
# integration suite can run against a real WordPress.
#
# Usage:
#   bin/install-wp-tests.sh <db-name> <db-user> <db-pass> [db-host] [wp-version] [skip-db-create]
#
# wp-version accepts:
#   6.8            → resolves to the newest 6.8.x
#   7.0.2          → that exact release
#   latest         → whatever wordpress.org currently calls latest
#   nightly|trunk  → the development branch (used only as a canary)
#
# The test library is fetched from the WordPress/wordpress-develop GitHub
# tarballs rather than Subversion: the GitHub Actions Ubuntu images no longer
# ship `svn`, and a missing binary is a confusing way to fail a compatibility
# matrix. Core itself still comes from wordpress.org, which is the artifact
# users actually install.

set -euo pipefail

DB_NAME="${1:-}"
DB_USER="${2:-}"
DB_PASS="${3:-}"
DB_HOST="${4:-localhost}"
WP_VERSION="${5:-latest}"
SKIP_DB_CREATE="${6:-false}"

if [ -z "$DB_NAME" ] || [ -z "$DB_USER" ]; then
	echo "usage: $0 <db-name> <db-user> <db-pass> [db-host] [wp-version] [skip-db-create]" >&2
	exit 1
fi

WP_TESTS_DIR="${WP_TESTS_DIR:-/tmp/wordpress-tests-lib}"
WP_CORE_DIR="${WP_CORE_DIR:-/tmp/wordpress/}"

download() {
	curl -sSL --fail --retry 3 --retry-delay 2 "$1" > "$2"
}

# ---------------------------------------------------------------------------
# Resolve the requested version to something concrete.
#
# This is deliberately loud. When a compatibility matrix names a WordPress
# version that does not exist, the useful output is "that version is not a
# thing" — not a download error forty lines into a build log.
# ---------------------------------------------------------------------------
resolve_version() {
	local requested="$1"

	if [ "$requested" = "nightly" ] || [ "$requested" = "trunk" ]; then
		echo "trunk"
		return
	fi

	local stable_check
	stable_check="$(curl -sSL --fail --retry 3 https://api.wordpress.org/core/stable-check/1.0/)"

	if [ "$requested" = "latest" ]; then
		echo "$stable_check" \
			| tr ',' '\n' \
			| grep ':"latest"' \
			| tr -d '":{} ' \
			| cut -d'l' -f1 \
			| head -1
		return
	fi

	# An exact match wins.
	if echo "$stable_check" | grep -q "\"$requested\""; then
		echo "$requested"
		return
	fi

	# Otherwise treat the request as a branch (6.8 → newest 6.8.x).
	local newest
	newest="$(echo "$stable_check" \
		| tr ',' '\n' \
		| grep -oE '"[0-9]+\.[0-9]+(\.[0-9]+)?"' \
		| tr -d '"' \
		| grep -E "^${requested}(\.[0-9]+)?$" \
		| sort -t. -k1,1n -k2,2n -k3,3n \
		| tail -1)"

	if [ -z "$newest" ]; then
		echo "ERROR: WordPress '$requested' does not exist on wordpress.org." >&2
		echo "Newest versions currently published:" >&2
		echo "$stable_check" | tr ',' '\n' | grep -oE '"[0-9]+\.[0-9]+(\.[0-9]+)?"' \
			| tr -d '"' | sort -t. -k1,1n -k2,2n -k3,3n | tail -10 >&2
		exit 1
	fi

	echo "$newest"
}

RESOLVED_VERSION="$(resolve_version "$WP_VERSION")"
echo "Requested WordPress '$WP_VERSION' → installing '$RESOLVED_VERSION'"
echo "WP_RESOLVED_VERSION=$RESOLVED_VERSION" >> "${GITHUB_ENV:-/dev/null}" 2>/dev/null || true

install_wp() {
	if [ -d "$WP_CORE_DIR" ]; then
		echo "WordPress core already present at $WP_CORE_DIR"
		return
	fi

	mkdir -p "$WP_CORE_DIR"

	local archive
	if [ "$RESOLVED_VERSION" = "trunk" ]; then
		archive="https://wordpress.org/nightly-builds/wordpress-latest.zip"
		download "$archive" /tmp/wordpress.zip
		unzip -q /tmp/wordpress.zip -d /tmp/wp-extract
		mv /tmp/wp-extract/wordpress/* "$WP_CORE_DIR"
	else
		archive="https://wordpress.org/wordpress-${RESOLVED_VERSION}.tar.gz"
		download "$archive" /tmp/wordpress.tar.gz
		tar --strip-components=1 -zxmf /tmp/wordpress.tar.gz -C "$WP_CORE_DIR"
	fi

	download https://raw.githubusercontent.com/markoheijnen/wp-mysqli/master/db.php "$WP_CORE_DIR/wp-content/db.php"
}

install_test_suite() {
	if [ -d "$WP_TESTS_DIR/includes" ]; then
		echo "Test library already present at $WP_TESTS_DIR"
		return
	fi

	mkdir -p "$WP_TESTS_DIR"

	local ref
	if [ "$RESOLVED_VERSION" = "trunk" ]; then
		ref="trunk"
	else
		ref="$RESOLVED_VERSION"
	fi

	echo "Fetching wordpress-develop@$ref for the PHPUnit test library"
	if ! download "https://github.com/WordPress/wordpress-develop/archive/refs/tags/${ref}.tar.gz" /tmp/wp-develop.tar.gz; then
		# Point releases sometimes ship without their own develop tag; fall
		# back to the branch head, which carries the same test library.
		local branch="${ref%.*}"
		echo "No develop tag for $ref — falling back to branch $branch"
		download "https://github.com/WordPress/wordpress-develop/archive/refs/heads/${branch}.tar.gz" /tmp/wp-develop.tar.gz
	fi

	mkdir -p /tmp/wp-develop
	tar --strip-components=1 -zxmf /tmp/wp-develop.tar.gz -C /tmp/wp-develop

	cp -r /tmp/wp-develop/tests/phpunit/includes "$WP_TESTS_DIR/includes"
	cp -r /tmp/wp-develop/tests/phpunit/data "$WP_TESTS_DIR/data"
	cp /tmp/wp-develop/wp-tests-config-sample.php "$WP_TESTS_DIR/wp-tests-config.php"

	local core_dir_escaped
	core_dir_escaped="$(echo "$WP_CORE_DIR" | sed 's/[\/&]/\\&/g')"

	sed -i "s:dirname( __FILE__ ) . '/src/':'${core_dir_escaped}':" "$WP_TESTS_DIR/wp-tests-config.php"
	sed -i "s/youremptytestdbnamehere/$DB_NAME/" "$WP_TESTS_DIR/wp-tests-config.php"
	sed -i "s/yourusernamehere/$DB_USER/" "$WP_TESTS_DIR/wp-tests-config.php"
	sed -i "s/yourpasswordhere/$DB_PASS/" "$WP_TESTS_DIR/wp-tests-config.php"
	sed -i "s|localhost|${DB_HOST}|" "$WP_TESTS_DIR/wp-tests-config.php"
}

create_db() {
	if [ "$SKIP_DB_CREATE" = "true" ]; then
		return
	fi

	local host="$DB_HOST"
	local port_arg=""

	if [[ "$DB_HOST" == *":"* ]]; then
		host="${DB_HOST%%:*}"
		port_arg="--port=${DB_HOST##*:} --protocol=tcp"
	fi

	# shellcheck disable=SC2086
	mysqladmin create "$DB_NAME" --user="$DB_USER" --password="$DB_PASS" --host="$host" $port_arg
}

install_wp
install_test_suite
create_db

echo "WordPress $RESOLVED_VERSION installed at $WP_CORE_DIR"
echo "Test library installed at $WP_TESTS_DIR"

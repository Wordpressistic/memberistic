#!/usr/bin/env bash
#
# Build the distributable plugin tree (and optionally a zip) from .distignore.
#
# .distignore is the single source of truth for what is product and what is
# development tooling. Both the release workflow and the CI leak-guard call
# this script rather than restating the copy-and-prune logic, because two
# hand-maintained copies drift the first time someone adds a dev-only file:
# the release would ship it while CI still reported the archive clean.
#
# Usage:
#   bin/build-dist.sh                  # tree only -> build/memberistic/
#   bin/build-dist.sh --zip 2.0.1      # tree + build/memberistic-2.0.1.zip + .sha256
#
# Deliberately depends only on coreutils, tar and (for --zip) zip, so it runs
# the same on a runner and on a maintainer's machine.

set -euo pipefail

PLUGIN_SLUG="${PLUGIN_SLUG:-memberistic}"
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
BUILD="${ROOT}/build"
DEST="${BUILD}/${PLUGIN_SLUG}"

VERSION=""
if [ "${1:-}" = "--zip" ]; then
	VERSION="${2:-}"
	if [ -z "$VERSION" ]; then
		echo "error: --zip requires a version argument" >&2
		exit 2
	fi
fi

cd "$ROOT"

if [ ! -f .distignore ]; then
	echo "error: .distignore not found at repository root" >&2
	exit 2
fi

rm -rf "$DEST"
mkdir -p "$DEST"

# Copy everything, then remove what .distignore names. Done this way rather
# than with `rsync --exclude-from` so the exclusion list stays readable in the
# CI log and the script needs no extra package on the runner.
tar --exclude=./build --exclude=./.git -cf - . | tar -xf - -C "$DEST"

while IFS= read -r pattern || [ -n "$pattern" ]; do
	# Skip blanks and comments.
	case "$pattern" in '' | '#'*) continue ;; esac
	# Trim trailing whitespace/CR, which would otherwise make the path miss.
	pattern="${pattern%"${pattern##*[![:space:]]}"}"
	[ -z "$pattern" ] && continue
	echo "excluding: $pattern"
	# shellcheck disable=SC2086 # patterns may legitimately glob (e.g. *.zip)
	rm -rf ${DEST}/${pattern}
done < .distignore

echo "--- distributable tree assembled at build/${PLUGIN_SLUG} ---"

if [ -n "$VERSION" ]; then
	cd "$BUILD"
	rm -f "${PLUGIN_SLUG}-${VERSION}.zip" "${PLUGIN_SLUG}-${VERSION}.zip.sha256"
	zip -rq "${PLUGIN_SLUG}-${VERSION}.zip" "${PLUGIN_SLUG}"
	sha256sum "${PLUGIN_SLUG}-${VERSION}.zip" > "${PLUGIN_SLUG}-${VERSION}.zip.sha256"
	echo "--- archive contents (top level) ---"
	unzip -l "${PLUGIN_SLUG}-${VERSION}.zip" | head -40
	echo "--- checksum ---"
	cat "${PLUGIN_SLUG}-${VERSION}.zip.sha256"
fi

#!/usr/bin/env bash
#
# Assert that the built distributable contains no development or internal
# material. Run after bin/build-dist.sh.
#
# Two independent checks, because they fail in different ways:
#
#   1. Every path named in .distignore must actually be gone. This catches the
#      build and .distignore drifting apart — a pattern that silently stopped
#      matching still looks fine in the log, which prints what it *tried* to
#      exclude, not what it removed.
#
#   2. An explicit deny-list must be absent regardless of what .distignore
#      says. This is the check that survives someone editing .distignore: the
#      strategy documents carry pricing models, revenue targets and competitor
#      analysis, and must never reach a customer's wp-content/plugins
#      directory, whatever the ignore file happens to contain that week.
#
# Usage:
#   bin/assert-dist-clean.sh                 # check the tree
#   bin/assert-dist-clean.sh --zip 2.0.1     # also check inside the zip

set -euo pipefail

PLUGIN_SLUG="${PLUGIN_SLUG:-memberistic}"
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
DEST="${ROOT}/build/${PLUGIN_SLUG}"

cd "$ROOT"

if [ ! -d "$DEST" ]; then
	echo "error: ${DEST} does not exist — run bin/build-dist.sh first" >&2
	exit 2
fi

fail=0
note() { echo "::error::$1"; fail=1; }

echo "--- check 1: every .distignore entry is absent from the build ---"
while IFS= read -r pattern || [ -n "$pattern" ]; do
	case "$pattern" in '' | '#'*) continue ;; esac
	pattern="${pattern%"${pattern##*[![:space:]]}"}"
	[ -z "$pattern" ] && continue
	# shellcheck disable=SC2086
	matches=$(compgen -G "${DEST}/${pattern}" || true)
	if [ -n "$matches" ]; then
		note ".distignore lists '${pattern}' but it is present in the build:"
		echo "$matches" | sed 's|^|    |'
	fi
done < .distignore
[ "$fail" -eq 0 ] && echo "ok: nothing named in .distignore survived the build"

echo "--- check 2: explicit deny-list ---"
# Internal planning material, developer tooling, and anything that would make a
# WordPress.org reviewer reject the submission.
FORBIDDEN=(
	'docs/strategy'
	'docs/governance'
	'tests'
	'bin'
	'vendor'
	'node_modules'
	'.git'
	'.github'
	'build'
	'composer.json'
	'composer.lock'
	'phpunit.xml'
	'phpunit-integration.xml'
	'.phpunit.cache'
	'.distignore'
	'.editorconfig'
	'.gitattributes'
	'.gitignore'
	'CLAUDE.md'
	'CONTRIBUTING.md'
	'CODE_OF_CONDUCT.md'
	'SUPPORT.md'
	'docs/README.md'
)
for path in "${FORBIDDEN[@]}"; do
	if [ -e "${DEST}/${path}" ]; then
		note "forbidden path present in distributable: ${path}"
	fi
done

# Nothing executable-but-unexpected, and no stray archives.
while IFS= read -r -d '' stray; do
	note "stray archive in distributable: ${stray#"${DEST}/"}"
done < <(find "$DEST" \( -name '*.zip' -o -name '*.tar.gz' \) -print0)

echo "--- check 3: product files that MUST ship ---"
# The mirror image of the deny-list. A .distignore pattern that is too greedy
# (say, `docs` instead of `docs/strategy`) would otherwise pass every check
# above by shipping an empty plugin.
REQUIRED=(
	'memberistic-membership-solutions.php'
	'uninstall.php'
	'index.php'
	'readme.txt'
	'README.md'
	'LICENSE'
	'CHANGELOG.md'
	'THIRD-PARTY-LICENSES.md'
	'includes'
	'assets'
	'templates'
	'languages/memberistic.pot'
	'docs/HOOKS.md'
	'docs/INSTALL.md'
	'docs/INTEGRATIONS.md'
)
for path in "${REQUIRED[@]}"; do
	if [ ! -e "${DEST}/${path}" ]; then
		note "required product file missing from distributable: ${path}"
	fi
done

# A file can ship perfectly and still be broken by what was excluded around it.
# docs/README.md was the case that prompted this: a repository index whose links
# mostly pointed into docs/strategy, docs/governance and CONTRIBUTING.md, so in
# a customer's plugin directory it rendered as a page of dead links. Excluding
# one file fixes that instance; this check is what stops the next one.
echo "--- check 4: no shipped markdown links into excluded material ---"
while IFS= read -r -d '' doc; do
	rel="${doc#"${DEST}/"}"
	docdir=$(dirname "$doc")
	# Relative markdown link targets only: skip anchors, and skip anything with
	# a URL scheme (http:, https:, mailto:) — those are not ours to resolve.
	while IFS= read -r target; do
		case "$target" in '' | '#'* | *:*) continue ;; esac
		target="${target%%#*}"
		[ -z "$target" ] && continue
		if [ ! -e "${docdir}/${target}" ]; then
			note "${rel} links to '${target}', which is not in the distributable"
		fi
	done < <(grep -o '](\([^)]*\))' "$doc" 2>/dev/null | sed 's/^](//;s/)$//')
done < <(find "$DEST" -name '*.md' -print0)

if [ "${1:-}" = "--zip" ]; then
	VERSION="${2:-}"
	ZIP="${ROOT}/build/${PLUGIN_SLUG}-${VERSION}.zip"
	echo "--- check 5: archive listing ---"
	if [ ! -f "$ZIP" ]; then
		note "expected archive not found: ${ZIP}"
	else
		listing=$(unzip -Z1 "$ZIP")
		# Every path in the archive must sit under the slug directory, or
		# WordPress installs the plugin to the wrong folder.
		if grep -qv "^${PLUGIN_SLUG}/" <<<"$listing"; then
			note "archive has entries outside ${PLUGIN_SLUG}/:"
			grep -v "^${PLUGIN_SLUG}/" <<<"$listing" | sed 's|^|    |'
		fi
		for forbidden in "${FORBIDDEN[@]}"; do
			if grep -q "^${PLUGIN_SLUG}/${forbidden}\(/\|$\)" <<<"$listing"; then
				note "forbidden path present in archive: ${forbidden}"
			fi
		done
	fi
fi

if [ "$fail" -ne 0 ]; then
	echo
	echo "distributable is NOT clean — see the errors above"
	exit 1
fi

echo
echo "distributable is clean"

#!/usr/bin/env python3
"""Validate the WordPress compatibility targets, and emit the CI matrix.

This is a release gate, not a report. The integration workflow used to print
"NOT PUBLISHED" for a version that does not exist and then run the matrix
anyway; nine jobs would fail to download WordPress, or — worse — a version
string that happened to resolve to a *different* release would pass and be
recorded as coverage of the version named in the matrix. Either way the
compatibility claim in readme.txt stops being a statement about what was
tested.

So the rules are enforced here, once, and a violation exits non-zero:

  1. Every blocking version in .github/wordpress-targets.json is published on
     wordpress.org, matched exactly. An exact match matters now that targets
     name patch releases: "7.0" resolving to the newest 7.0.x is convenient
     for a developer and dishonest for a matrix.
  2. readme.txt's "Tested up to" names the major.minor line of the highest
     blocking target, and nothing higher. Raising the claim therefore requires
     adding that release to `blocking` and watching the matrix pass first,
     which is the only order that makes the claim true.
  3. readme.txt's "Requires at least" and "Requires PHP" match the declaration.

Usage:
  bin/check-wp-targets.py                 # validate, print a summary
  bin/check-wp-targets.py --github-output # also write matrix outputs for CI
"""

import json
import os
import re
import sys
import urllib.request

STABLE_CHECK = "https://api.wordpress.org/core/stable-check/1.0/"

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
TARGETS_FILE = os.path.join(ROOT, ".github", "wordpress-targets.json")
README_FILE = os.path.join(ROOT, "readme.txt")


def fail(message):
    print("ERROR: %s" % message, file=sys.stderr)
    sys.exit(1)


def version_key(value):
    return tuple(int(part) for part in value.split("."))


def minor_line(value):
    return ".".join(value.split(".")[:2])


def load_targets():
    with open(TARGETS_FILE, "r", encoding="utf-8") as handle:
        targets = json.load(handle)

    for key in ("blocking", "php", "readme_tested_up_to"):
        if not targets.get(key):
            fail("%s is missing `%s`" % (TARGETS_FILE, key))

    for version in targets["blocking"]:
        if not re.fullmatch(r"\d+\.\d+\.\d+", version):
            fail(
                "blocking target '%s' is not an exact patch version. Name the "
                "release the matrix will actually install (e.g. 7.0.2), so the "
                "coverage recorded is the coverage obtained." % version
            )

    return targets


def fetch_published():
    request = urllib.request.Request(
        STABLE_CHECK, headers={"User-Agent": "memberistic-ci-target-check"}
    )
    with urllib.request.urlopen(request, timeout=30) as response:
        return json.loads(response.read().decode("utf-8"))


def check_published(targets, published):
    missing = [v for v in targets["blocking"] if v not in published]
    if not missing:
        return

    newest = sorted(published, key=version_key)[-12:]
    fail(
        "these blocking WordPress targets are not published on wordpress.org: "
        "%s\n\nNewest published releases: %s\n\n"
        "Correct .github/wordpress-targets.json. Do not work around this by "
        "loosening the match — the matrix would then report coverage of a "
        "version it never installed."
        % (", ".join(missing), ", ".join(newest))
    )


def newer_patches(targets, published):
    """Blocking targets that are no longer the newest patch on their line.

    Advisory, deliberately: which patch to certify against is a release
    decision, and pinning an older one is legitimate (it is what the previous
    release was verified on). What is not legitimate is *not noticing*. A
    security patch lands on the line you claim to support and the matrix keeps
    reporting green against the release before it, silently.
    """
    drift = []
    for version in targets["blocking"]:
        line = minor_line(version)
        siblings = [
            v
            for v in published
            if re.fullmatch(r"\d+\.\d+\.\d+", v) and minor_line(v) == line
        ]
        if not siblings:
            continue
        newest = sorted(siblings, key=version_key)[-1]
        if version_key(newest) > version_key(version):
            drift.append((version, newest))
    return drift


def read_readme_header(field):
    with open(README_FILE, "r", encoding="utf-8") as handle:
        for line in handle:
            match = re.match(r"^%s:\s*(.+?)\s*$" % re.escape(field), line)
            if match:
                return match.group(1)
            # Stop at the first section heading ("== Description =="). The
            # plugin title above the headers is "=== Name ===", three equals,
            # so it must not be mistaken for the end of the header block.
            if re.match(r"^==\s", line):
                break
    fail("readme.txt has no `%s:` header" % field)


def check_readme(targets):
    highest = sorted(targets["blocking"], key=version_key)[-1]
    expected_line = minor_line(highest)
    declared = targets["readme_tested_up_to"]

    if declared != expected_line:
        fail(
            "wordpress-targets.json claims `Tested up to: %s` but the highest "
            "blocking target is %s (the %s line). Test the line before "
            "claiming it." % (declared, highest, expected_line)
        )

    actual = read_readme_header("Tested up to")
    if actual != declared:
        fail(
            "readme.txt says `Tested up to: %s`; the blocking matrix supports "
            "`%s`." % (actual, declared)
        )

    for field, key in (
        ("Requires at least", "readme_requires_at_least"),
        ("Requires PHP", "readme_requires_php"),
    ):
        if key not in targets:
            continue
        actual = read_readme_header(field)
        if actual != targets[key]:
            fail(
                "readme.txt says `%s: %s`; wordpress-targets.json declares "
                "`%s`." % (field, actual, targets[key])
            )


def emit_github_output(targets, published):
    path = os.environ.get("GITHUB_OUTPUT")
    if not path:
        return
    with open(path, "a", encoding="utf-8") as handle:
        handle.write("wordpress=%s\n" % json.dumps(targets["blocking"]))
        handle.write("php=%s\n" % json.dumps(targets["php"]))
        handle.write("canary=%s\n" % targets.get("canary", "trunk"))

    summary = os.environ.get("GITHUB_STEP_SUMMARY")
    if not summary:
        return
    latest = next((k for k, v in published.items() if v == "latest"), "unknown")
    with open(summary, "a", encoding="utf-8") as handle:
        handle.write("### WordPress compatibility targets\n\n")
        handle.write("Latest published: **%s**\n\n" % latest)
        handle.write("| Target | Published | Role |\n|---|---|---|\n")
        for version in targets["blocking"]:
            handle.write(
                "| `%s` | yes | blocking |\n" % version
            )
        handle.write(
            "| `%s` | n/a | canary, advisory |\n" % targets.get("canary", "trunk")
        )
        handle.write(
            "\nPHP: %s\n" % ", ".join("`%s`" % p for p in targets["php"])
        )
        handle.write(
            "\n`Tested up to: %s` in readme.txt, asserted against the highest "
            "blocking target.\n" % targets["readme_tested_up_to"]
        )


def main():
    targets = load_targets()
    published = fetch_published()

    check_published(targets, published)
    check_readme(targets)

    print("WordPress targets validated.")
    print("  blocking : %s" % ", ".join(targets["blocking"]))
    print("  php      : %s" % ", ".join(targets["php"]))
    print("  canary   : %s (advisory)" % targets.get("canary", "trunk"))
    print("  readme   : Tested up to %s" % targets["readme_tested_up_to"])

    for pinned, newest in newer_patches(targets, published):
        print(
            "  NOTE     : %s is pinned, but %s is the newest patch on that "
            "line. Certifying against the older patch is a choice; make sure "
            "it is a deliberate one." % (pinned, newest)
        )

    if "--github-output" in sys.argv:
        emit_github_output(targets, published)


if __name__ == "__main__":
    main()

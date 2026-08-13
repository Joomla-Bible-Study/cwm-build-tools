#!/usr/bin/env bash
#
# Tests for scripts/verify-artifact-tokens.php — the release gate that asserts a
# built artifact carries no unsubstituted placeholder tokens (#85).
#
# The scanning itself is covered by tests/Release/ArtifactTokenScannerTest.php.
# What is tested here is the behaviour release.sh depends on and a unit test
# cannot express: the exit codes that decide whether a release stops, the
# opt-in gate, and --warn-only, which is what keeps a dry run from failing on a
# stale zip left over from the previous release.

set -uo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=helpers.sh
source "${SCRIPT_DIR}/helpers.sh"

VERIFY="${SCRIPT_DIR}/../../scripts/verify-artifact-tokens.php"

WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT

write_config() {
    # $1 = "opted-in" | "not-opted-in"
    if [ "$1" = "opted-in" ]; then
        cat > "${WORK}/cwm-build.config.json" <<'JSON'
{
    "extension": { "name": "pkg_thing" },
    "versionTracking": {
        "substituteTokens": { "paths": ["src/"] }
    }
}
JSON
    else
        cat > "${WORK}/cwm-build.config.json" <<'JSON'
{ "extension": { "name": "pkg_thing" } }
JSON
    fi
}

# Build a zip containing one file with the given contents.
make_zip() {
    local name="$1" entry="$2" contents="$3"
    rm -rf "${WORK}/stage" && mkdir -p "$(dirname "${WORK}/stage/${entry}")"
    printf '%s' "$contents" > "${WORK}/stage/${entry}"
    (cd "${WORK}/stage" && zip -qr "${WORK}/${name}" .)
}

run_verify() {
    (cd "$WORK" && php "$VERIFY" "$@" >/dev/null 2>&1; echo $?)
}

# --- A clean artifact passes -------------------------------------------------
write_config opted-in
make_zip clean.zip "src/Thing.php" '<?php /** @since 1.2.3 */'
assert_equals "0" "$(run_verify -f clean.zip)" "a clean artifact exits 0"

# --- A dirty artifact stops the release --------------------------------------
# This is the whole point: it must exit non-zero, and it must do so at a moment
# when nothing has been pushed, tagged or published.
make_zip dirty.zip "src/Thing.php" '<?php /** @since __DEPLOY_VERSION__ */'
assert_equals "1" "$(run_verify -f dirty.zip)" "an unsubstituted token exits 1"

# --- --warn-only reports without failing -------------------------------------
# Used by release.sh under --dry-run, where the artifact on disk is usually the
# PREVIOUS release: a finding there describes that older zip, not the release
# being cut, so it must never fail the run.
assert_equals "0" "$(run_verify -f dirty.zip --warn-only)" "--warn-only exits 0 despite findings"

# --- Opt-in parity with substitute-tokens.php --------------------------------
write_config not-opted-in
assert_equals "0" "$(run_verify -f dirty.zip)" \
    "a project with no substituteTokens block is skipped, not failed"

# The skip is loud rather than silent — a check that says nothing is
# indistinguishable from a check that passed.
skip_output="$(cd "$WORK" && php "$VERIFY" -f dirty.zip 2>&1)"
assert_contains "$skip_output" "Skipped" "the skip is reported, not silent"

# --- Argument and input handling ---------------------------------------------
write_config opted-in
assert_equals "1" "$(run_verify)" "a missing -f is a usage error"
assert_equals "1" "$(run_verify -f does-not-exist.zip)" \
    "a missing artifact fails rather than reporting clean"

# --- The report names the file and line --------------------------------------
report="$(cd "$WORK" && php "$VERIFY" -f dirty.zip 2>&1)"
assert_contains "$report" "src/Thing.php" "the report names the offending entry"
assert_contains "$report" "dirty.zip!" "the report names the archive path"

finish

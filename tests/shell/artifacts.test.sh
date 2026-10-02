#!/usr/bin/env bash
#
# Tests for scripts/lib/artifacts.sh.
#
set -uo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=helpers.sh
source "${SCRIPT_DIR}/helpers.sh"
# shellcheck source=../../scripts/lib/artifacts.sh
source "${SCRIPT_DIR}/../../scripts/lib/artifacts.sh"

WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT
mkdir -p "$WORK/dist"

fixture() {
    rm -f "$WORK"/dist/*.zip
    for name in "$@"; do
        touch "$WORK/dist/$name"
    done
}

GLOB="$WORK/dist/pkg_proclaim-*.zip"

# --- The Proclaim 10.3.3 incident -------------------------------------------
# A stale 10.3.2 sat in build/dist and sorted first, so the release published
# it as 10.3.3. This is the case the whole function exists for.
fixture pkg_proclaim-10.3.2.zip pkg_proclaim-10.3.3.zip
got="$(cwm_select_artifact_for_version 10.3.3 "$GLOB" 2>/dev/null)"
assert_equals "$WORK/dist/pkg_proclaim-10.3.3.zip" "$got" \
    "selects its own version when a stale earlier artifact is present"

# --- Lexical ordering is wrong for versions ---------------------------------
# 10.3.10 sorts before 10.3.2, so anything relying on glob order is wrong in
# both directions, not merely "usually right".
fixture pkg_proclaim-10.3.2.zip pkg_proclaim-10.3.10.zip
got="$(cwm_select_artifact_for_version 10.3.2 "$GLOB" 2>/dev/null)"
assert_equals "$WORK/dist/pkg_proclaim-10.3.2.zip" "$got" \
    "selects 10.3.2 even though 10.3.10 sorts first"

got="$(cwm_select_artifact_for_version 10.3.10 "$GLOB" 2>/dev/null)"
assert_equals "$WORK/dist/pkg_proclaim-10.3.10.zip" "$got" \
    "selects 10.3.10 even though it sorts first"

# --- A single artifact still works ------------------------------------------
fixture pkg_proclaim-10.3.6.zip
got="$(cwm_select_artifact_for_version 10.3.6 "$GLOB" 2>/dev/null)"
assert_equals "$WORK/dist/pkg_proclaim-10.3.6.zip" "$got" "selects the only artifact"

# --- Pre-release versions ---------------------------------------------------
# A hyphenated suffix must not confuse the *-<version>.zip match.
fixture pkg_proclaim-10.3.6.zip pkg_proclaim-10.3.6-beta1.zip
got="$(cwm_select_artifact_for_version 10.3.6-beta1 "$GLOB" 2>/dev/null)"
assert_equals "$WORK/dist/pkg_proclaim-10.3.6-beta1.zip" "$got" "selects a pre-release build"

got="$(cwm_select_artifact_for_version 10.3.6 "$GLOB" 2>/dev/null)"
assert_equals "$WORK/dist/pkg_proclaim-10.3.6.zip" "$got" \
    "10.3.6 does not match 10.3.6-beta1"

# --- Failure modes ----------------------------------------------------------
# Each must be an error, not a guess: a release publishes to the world and
# reports success either way.
fixture
cwm_select_artifact_for_version 10.3.6 "$GLOB" >/dev/null 2>&1
assert_equals "1" "$?" "empty directory returns 1"

fixture pkg_proclaim-10.3.2.zip
cwm_select_artifact_for_version 10.3.6 "$GLOB" >/dev/null 2>&1
assert_equals "2" "$?" "no artifact for the requested version returns 2"

# Two files that both end -10.3.6.zip: ambiguous, so refuse rather than pick.
fixture pkg_proclaim-10.3.6.zip
touch "$WORK/dist/pkg_proclaim-extra-10.3.6.zip"
cwm_select_artifact_for_version 10.3.6 "$GLOB" >/dev/null 2>&1
assert_equals "3" "$?" "an ambiguous match returns 3 rather than guessing"

# --- Diagnostics ------------------------------------------------------------
# "No artifact" is the error someone will actually hit, so it should say what
# it did find rather than only what it wanted.
fixture pkg_proclaim-10.3.2.zip
err="$(cwm_select_artifact_for_version 10.3.6 "$GLOB" 2>&1 >/dev/null)"
case "$err" in
    *"10.3.2"*) PASS=$((PASS + 1)) ;;
    *) FAIL=$((FAIL + 1)); echo "  FAIL: the error should list what was found instead" ;;
esac

# The selected path must be the only thing on stdout, so callers can capture it.
fixture pkg_proclaim-10.3.2.zip pkg_proclaim-10.3.6.zip
out="$(cwm_select_artifact_for_version 10.3.6 "$GLOB" 2>/dev/null)"
assert_equals "1" "$(printf '%s' "$out" | grep -c .)" "stdout carries only the path"


# --- cwm_require_artifact ----------------------------------------------------
#
# Added for #160: the bare [ -f ] it replaces refused a file that was present,
# at the one step past the point of no return, and its message carried nothing
# that would have said why. Fast retries here so the suite stays quick.
export CWM_ARTIFACT_RETRY_DELAY=1

# A present file passes, quietly.
fixture pkg_proclaim-10.3.6.zip
out="$(cwm_require_artifact "$WORK/dist/pkg_proclaim-10.3.6.zip" 2>&1)"
assert_equals "0" "$?" "an existing artifact is accepted"
assert_equals "" "$out" "and accepted silently — retry noise on the happy path would train people to ignore it"

# A genuinely missing file fails, and the diagnostics carry what #160 lacked:
# where the path was resolved from and what the directory actually held.
fixture pkg_proclaim-10.3.2.zip
err="$(cwm_require_artifact "$WORK/dist/pkg_proclaim-10.3.6.zip" 2>&1)"
rc=$?
assert_equals "1" "$rc" "a missing artifact still fails"
assert_contains "$err" "looked from:" "the failure names the directory it resolved against"
assert_contains "$err" "pkg_proclaim-10.3.2.zip" "and lists what the directory actually held"

# The transient case: the file is not there on first look and is on the second.
# This is the #160 shape — the publish must proceed, and must say it happened,
# because that message is the evidence the next investigation needs.
rm -f "$WORK/dist/pkg_proclaim-10.3.6.zip"
( sleep 0.4; touch "$WORK/dist/pkg_proclaim-10.3.6.zip" ) &
LATE=$!
err="$(cwm_require_artifact "$WORK/dist/pkg_proclaim-10.3.6.zip" 2>&1)"
rc=$?
wait "$LATE"
assert_equals "0" "$rc" "a file that appears on retry is accepted"
assert_contains "$err" "appeared" "and the retry is reported loudly, never silently"

unset CWM_ARTIFACT_RETRY_DELAY

finish

#!/usr/bin/env bash
#
# Tests for scripts/lib/dryrun.sh.
#
# The wrapper is the single point where --dry-run is honoured across all nine
# release steps. If it executes anything, the operator was told a tag, a push
# or an ARS publish would not happen and it did — so the case that matters
# most here is the boring one: that the command really did not run.

set -uo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=helpers.sh
source "${SCRIPT_DIR}/helpers.sh"
# shellcheck source=../../scripts/lib/dryrun.sh
source "${SCRIPT_DIR}/../../scripts/lib/dryrun.sh"

WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT

# --- Flag parsing ------------------------------------------------------------
cwm_parse_dry_run 1.2.3
assert_equals "0" "$CWM_DRY_RUN" "no flag means a real run"
assert_equals "1.2.3" "${CWM_ARGS[*]}" "the version survives parsing"

cwm_parse_dry_run 1.2.3 --dry-run
assert_equals "1" "$CWM_DRY_RUN" "--dry-run after the version is found"
assert_equals "1.2.3" "${CWM_ARGS[*]}" "and is removed from the arguments"

cwm_parse_dry_run --dry-run 1.2.3
assert_equals "1" "$CWM_DRY_RUN" "--dry-run before the version is found"
assert_equals "1.2.3" "${CWM_ARGS[*]}" "argument order does not matter"

cwm_parse_dry_run -n 1.2.3
assert_equals "1" "$CWM_DRY_RUN" "-n is accepted as the short form"

# No positional arguments at all: release.sh then prompts for the version, so
# CWM_ARGS must be empty rather than carrying the flag through.
cwm_parse_dry_run --dry-run
assert_equals "1" "$CWM_DRY_RUN" "the flag alone is recognised"
assert_equals "0" "${#CWM_ARGS[@]}" "no positional arguments remain"

# A version that merely looks flag-ish is not swallowed.
cwm_parse_dry_run 1.2.3-beta1
assert_equals "1.2.3-beta1" "${CWM_ARGS[*]}" "a pre-release version passes through"

# --- --skip-tests, parsed in the same pass -----------------------------------
cwm_parse_dry_run 1.2.3
assert_equals "0" "$CWM_SKIP_TESTS" "no flag means the gate runs"

cwm_parse_dry_run 1.2.3 --skip-tests
assert_equals "1" "$CWM_SKIP_TESTS" "--skip-tests after the version is found"
assert_equals "1.2.3" "${CWM_ARGS[*]}" "and is removed from the arguments"

cwm_parse_dry_run --skip-tests --dry-run 1.2.3
assert_equals "1" "$CWM_SKIP_TESTS" "both flags are recognised together..."
assert_equals "1" "$CWM_DRY_RUN" "...regardless of order..."
assert_equals "1.2.3" "${CWM_ARGS[*]}" "...leaving only the version behind"

# --- The wrapper actually withholds the command ------------------------------
CWM_DRY_RUN=1
rm -f "${WORK}/evidence"
out="$(cwm_mutate touch "${WORK}/evidence")"
assert_equals "0" "$([ -e "${WORK}/evidence" ] && echo 1 || echo 0)" \
    "under --dry-run the command does not run"
assert_contains "$out" "would run: touch" "and is described instead"

CWM_DRY_RUN=0
cwm_mutate touch "${WORK}/evidence"
assert_equals "1" "$([ -e "${WORK}/evidence" ] && echo 1 || echo 0)" \
    "with the flag off the command does run"

# --- Exit status -------------------------------------------------------------
# release.sh runs under `set -e`, so a described command must report success;
# otherwise a dry run aborts at step 1.
CWM_DRY_RUN=1
cwm_mutate false >/dev/null
assert_equals "0" "$?" "a described command reports success under set -e"

CWM_DRY_RUN=0
cwm_mutate false
assert_equals "1" "$?" "a real command's failure is propagated"

# --- Arguments are passed through untouched ----------------------------------
# Release notes and commit messages carry arbitrary text; nothing may be
# re-evaluated by a shell on the way through.
CWM_DRY_RUN=0
got="$(cwm_mutate printf '%s' 'a b; rm -rf /  $(echo hi) `date`')"
assert_equals 'a b; rm -rf /  $(echo hi) `date`' "$got" \
    "metacharacters reach the command verbatim"

CWM_DRY_RUN=1
out="$(cwm_mutate git commit -m 'fix: a "quoted" thing & more')"
assert_contains "$out" 'fix: a "quoted" thing & more' "the description shows the real argument"

# --- Skip announcements ------------------------------------------------------
out="$(cwm_skip_in_dry_run "stash and checkout dev")"
assert_contains "$out" "skipped: stash and checkout dev" "a skipped step says what it skipped"

finish

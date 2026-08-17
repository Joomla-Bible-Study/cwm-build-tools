#!/usr/bin/env bash
#
# Tests for scripts/lib/baseline.sh.
#
# Selection only. Downloading the chosen release is a `gh` call and belongs to
# scripts/baseline.sh; what decides whether an upgrade test exercises the right
# transition is which version comes out of here, and that needs no network to
# check.

set -uo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=helpers.sh
source "${SCRIPT_DIR}/helpers.sh"
# shellcheck source=../../scripts/lib/baseline.sh
source "${SCRIPT_DIR}/../../scripts/lib/baseline.sh"

# A release history shaped like CWMLivingWord's at the 5.7.1 release.
RELEASES="$(printf '%s\n' \
    "v5.7.0-beta4	true" \
    "v5.7.0	false" \
    "v5.6.2	false" \
    "v5.6.0	false" \
    "v5.5.0	false")"

# --- The release target picks the release immediately before it ---------------
assert_equals "5.7.0" "$(cwm_select_baseline_version '5.7.1' '' "$RELEASES")" \
    "the newest stable release older than the target is chosen"

# --- The bug this exists to stop (#101) ---------------------------------------
# Resolving against the development pointer instead of the release target skips
# a whole release: every migration 5.7.0 shipped goes unexercised by the phase
# whose job is to exercise migrations.
assert_equals "5.6.2" "$(cwm_select_baseline_version '5.7.0-beta4' '' "$RELEASES")" \
    "resolving against a stale pointer selects an older baseline (the #101 shape)"

# --- Prereleases are skipped when a stable release qualifies ------------------
assert_equals "5.7.0" "$(cwm_select_baseline_version '5.7.1' '' "$RELEASES")" \
    "v5.7.0-beta4 is not chosen over v5.7.0"

# --- ...but used when nothing stable does -------------------------------------
BETAS_ONLY="$(printf '%s\n' "v1.0.0-beta2	true" "v1.0.0-beta1	true")"
assert_equals "1.0.0-beta2" "$(cwm_select_baseline_version '1.0.0' '' "$BETAS_ONLY")" \
    "a project that has only ever shipped betas still gets a baseline"

# --- Version ordering is numeric, not lexical ---------------------------------
# 10.3.10 sorts before 10.3.2 as a string; that is how a release picked a stale
# artifact before lib/artifacts.sh started matching on the version.
TENS="$(printf '%s\n' "v10.3.2	false" "v10.3.10	false" "v10.3.9	false")"
assert_equals "10.3.10" "$(cwm_select_baseline_version '10.4.0' '' "$TENS")" \
    "10.3.10 is newer than 10.3.9 and 10.3.2"

# --- The floor excludes releases that cannot install --------------------------
assert_equals "5.6.2" "$(cwm_select_baseline_version '5.7.0' '5.6.1' "$RELEASES")" \
    "a floor keeps unusable early releases out of the pool"

assert_equals "" "$(cwm_select_baseline_version '5.6.0' '5.6.1' "$RELEASES")" \
    "nothing qualifies when the floor excludes everything older than the target"

# --- Strictly older -----------------------------------------------------------
# Equal is not a baseline: installing a release over itself does not route
# Joomla to update(), which is the whole point of the phase.
assert_equals "5.6.2" "$(cwm_select_baseline_version '5.7.0' '' "$RELEASES")" \
    "the target's own release is not chosen as its baseline"

# --- No usable baseline is a status, not a crash ------------------------------
# The first release of a project has nothing to upgrade from. The caller decides
# whether that is fatal; #101 is the story of that decision being made silently
# in the wrong place.
if cwm_select_baseline_version '5.0.0' '' "$RELEASES" > /dev/null; then
    FAIL=$((FAIL + 1))
    echo "  FAIL: no release older than the target returns non-zero"
else
    PASS=$((PASS + 1))
fi

if cwm_select_baseline_version '5.7.1' '' '' > /dev/null; then
    FAIL=$((FAIL + 1))
    echo "  FAIL: an empty release list returns non-zero"
else
    PASS=$((PASS + 1))
fi

# --- Asset naming from the output glob ----------------------------------------
assert_equals "pkg_proclaim-10.3.2.zip" \
    "$(cwm_baseline_asset_from_glob 'build/dist/pkg_proclaim-*.zip' '10.3.2')" \
    "the asset name is the glob with the version substituted"

assert_equals "build/dist" \
    "$(cwm_baseline_dir_from_glob 'build/dist/pkg_proclaim-*.zip')" \
    "the download directory is the glob's directory"

# A glob that cannot name one asset is refused rather than guessed at: a wrong
# name reports "not found on the release", which sends the reader after the
# wrong problem.
if cwm_baseline_asset_from_glob 'build/dist/pkg_proclaim.zip' '10.3.2' 2>/dev/null; then
    FAIL=$((FAIL + 1))
    echo "  FAIL: a glob with no '*' is refused"
else
    PASS=$((PASS + 1))
fi

if cwm_baseline_asset_from_glob 'build/dist/*-proclaim-*.zip' '10.3.2' 2>/dev/null; then
    FAIL=$((FAIL + 1))
    echo "  FAIL: a glob with two '*' is refused"
else
    PASS=$((PASS + 1))
fi

# --- The CLI, against a stub gh -----------------------------------------------
# The selection above is the interesting logic, but it reaches nobody unless the
# script wires config -> gh -> selection -> download. A stub on PATH exercises
# that wiring without a network.
#
# It also pins --help. The first version of this script used <<'USAGE' as its
# heredoc delimiter while the help text carries a USAGE heading — the shape
# CLAUDE.md prescribes for every command — so the heredoc closed early and the
# remaining help lines ran as shell commands. `--help` printed two thirds of
# itself, invoked composer, and exited 1.
BASELINE_SH="${SCRIPT_DIR}/../../scripts/baseline.sh"
WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT

mkdir -p "${WORK}/bin"
cat > "${WORK}/cwm-build.config.json" <<'JSON'
{
    "manifests": { "package": "pkg_smoke.xml" },
    "build":     { "outputGlob": "build/dist/pkg_smoke-*.zip" },
    "github":    { "owner": "acme", "repo": "smoke" },
    "baseline":  { "minimum": "1.0.0" }
}
JSON
cat > "${WORK}/pkg_smoke.xml" <<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<extension type="package"><name>pkg_smoke</name><version>1.3.0</version></extension>
XML
cat > "${WORK}/bin/gh" <<'STUB'
#!/usr/bin/env bash
case "$1 $2" in
    "release list")
        printf 'v1.3.0-beta1\ttrue\nv1.2.0\tfalse\nv1.1.0\tfalse\nv0.9.0\tfalse\n' ;;
    "release download")
        for a in "$@"; do
            case "$prev" in --dir) d="$a" ;; --pattern) p="$a" ;; esac
            prev="$a"
        done
        mkdir -p "$d"; echo stub-zip > "$d/$p" ;;
esac
STUB
chmod +x "${WORK}/bin/gh"

run_baseline() {
    (cd "$WORK" && PATH="${WORK}/bin:$PATH" bash "$BASELINE_SH" "$@" 2>&1)
}

# --help must render in full and exit 0. Checking the last section catches the
# truncation the heredoc bug caused; the earlier sections printed fine.
HELP="$(run_baseline --help)"
HELP_STATUS=$?
assert_equals "0" "$HELP_STATUS" "--help exits 0"
assert_contains "$HELP" "cwm-install-zip" "--help renders through to its last section"

# The target defaults to the manifest version (1.3.0) -> newest stable below it.
assert_equals "1.2.0" "$(run_baseline --print | tail -1)" \
    "the CLI resolves a baseline from the manifest version"

# A release in flight overrides the manifest, which is stale before the bump.
assert_equals "1.1.0" "$(cd "$WORK" && PATH="${WORK}/bin:$PATH" CWM_RELEASE_VERSION=1.1.5 bash "$BASELINE_SH" --print 2>/dev/null | tail -1)" \
    "CWM_RELEASE_VERSION takes precedence over the manifest"

run_baseline > /dev/null
if [ -f "${WORK}/build/dist/pkg_smoke-1.2.0.zip" ]; then
    PASS=$((PASS + 1))
else
    FAIL=$((FAIL + 1))
    echo "  FAIL: the resolved baseline is downloaded into the output directory"
fi

assert_contains "$(run_baseline)" "Already present" \
    "a second run does not re-download"

# Exit 3, not 1: no usable baseline is a status the caller interprets.
run_baseline --target 0.5.0 --print > /dev/null
assert_equals "3" "$?" "no usable baseline exits 3"

# --- An error is not "not applicable" ------------------------------------------
# gh failing -- auth, network, a typo'd repo -- must NOT come back as 3. A
# caller is told 3 means "nothing to upgrade from, carry on", so reporting an
# auth failure that way makes the upgrade phase stand down silently: the #101
# shape, in the tool written to fix #101 (#125).
cat > "${WORK}/bin/gh" <<'FAILSTUB'
#!/usr/bin/env bash
echo "gh: Could not resolve to a Repository with the name 'acme/smoke'." >&2
exit 1
FAILSTUB
chmod +x "${WORK}/bin/gh"

ERR="$(run_baseline --print 2>&1)"
STATUS=$?

assert_equals "1" "$STATUS" "a gh failure exits 1, not the not-applicable 3"
assert_contains "$ERR" "Could not resolve to a Repository" "gh's reason is surfaced, not discarded"
assert_contains "$ERR" "not the same as having no usable baseline" "the two outcomes are told apart in words"

# And a gh that simply reports no releases is still the not-applicable case.
cat > "${WORK}/bin/gh" <<'EMPTYSTUB'
#!/usr/bin/env bash
exit 0
EMPTYSTUB
chmod +x "${WORK}/bin/gh"

run_baseline --print > /dev/null 2>&1
assert_equals "3" "$?" "a repo with genuinely no releases still exits 3"

finish

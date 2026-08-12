#!/usr/bin/env bash
#
# Tests for scripts/lib/version.sh.

set -uo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=helpers.sh
source "${SCRIPT_DIR}/helpers.sh"
# shellcheck source=../../scripts/lib/version.sh
source "${SCRIPT_DIR}/../../scripts/lib/version.sh"

WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT

# --- Validation --------------------------------------------------------------
cwm_validate_release_version "10.3.6" 2>/dev/null
assert_equals "0" "$?" "a plain semantic version is releasable"

cwm_validate_release_version "10.4.0-beta1" 2>/dev/null
assert_equals "0" "$?" "a beta is releasable (as a pre-release)"

cwm_validate_release_version "" 2>/dev/null
assert_equals "1" "$?" "an empty version returns 1"

# -dev is the working state between releases; it must never ship. This is
# a distinct code so a caller can tell "forgot the argument" from "tried
# to release the development version".
cwm_validate_release_version "10.4.0-dev" 2>/dev/null
assert_equals "2" "$?" "a -dev version returns 2"

err="$(cwm_validate_release_version "10.4.0-dev" 2>&1)"
assert_contains "$err" "-alpha, -beta, or -rc" "the -dev error names the valid channels"

# --- Tag and pre-release derivation ------------------------------------------
assert_equals "v10.3.6" "$(cwm_tag_for_version 10.3.6)" "tag is the v-prefixed version"

cwm_is_prerelease "10.3.6"
assert_equals "1" "$?" "a stable version is not a pre-release"

cwm_is_prerelease "10.4.0-rc1"
assert_equals "0" "$?" "a hyphenated suffix marks a pre-release"

assert_equals "--prerelease" "$(cwm_prerelease_flag 10.4.0-beta1)" "beta gets the gh --prerelease flag"
assert_equals "" "$(cwm_prerelease_flag 10.3.6)" "stable gets no flag"

# --- ARS maturity ------------------------------------------------------------
assert_equals "alpha" "$(cwm_maturity_for_version 11.0.0-alpha2)" "alpha maturity"
assert_equals "beta" "$(cwm_maturity_for_version 10.4.0-beta1)" "beta maturity"
assert_equals "rc" "$(cwm_maturity_for_version 10.4.0-rc1)" "rc maturity"
assert_equals "stable" "$(cwm_maturity_for_version 10.3.6)" "no suffix is stable"

# --- Version from a manifest -------------------------------------------------
# A package manifest lists member extensions after its own header; only
# the first <version> is the package's.
cat > "$WORK/pkg.xml" <<'XML'
<?xml version="1.0" encoding="utf-8"?>
<extension type="package" method="upgrade">
    <name>pkg_proclaim</name>
    <version>10.3.6</version>
    <files>
        <file type="component" id="com_proclaim">com_proclaim.zip</file>
    </files>
    <description>A member manifest fragment carrying <version>9.9.9</version> must not win.</description>
</extension>
XML
assert_equals "10.3.6" "$(cwm_version_from_manifest "$WORK/pkg.xml")" \
    "the first <version> element wins"

cwm_version_from_manifest "$WORK/does-not-exist.xml" >/dev/null
assert_equals "1" "$?" "a missing manifest returns 1"

printf '<extension><name>x</name></extension>\n' > "$WORK/noversion.xml"
cwm_version_from_manifest "$WORK/noversion.xml" >/dev/null
assert_equals "1" "$?" "a manifest without a <version> element returns 1"


# --- cwm_latest_stable_tag ---------------------------------------------------
#
# The case that produced empty changelog entries: 1.1.10 cut straight after
# 1.1.10-rc1, where git describe answers with the rc and the range holds only
# the version bump (#74).
assert_equals "v1.1.9" "$(cwm_latest_stable_tag "$(printf 'v1.1.8\nv1.1.9\nv1.1.10-rc1')")" \
    "an rc is skipped in favour of the last stable"

assert_equals "v1.1.9" "$(cwm_latest_stable_tag "$(printf 'v1.1.9\nv1.1.10-beta1\nv1.1.10-rc1\nv1.1.10-rc2')")" \
    "a run of pre-releases is skipped wholesale"

assert_equals "v1.2.0" "$(cwm_latest_stable_tag "$(printf 'v1.1.9\nv1.2.0')")" \
    "with no pre-releases the newest tag is used"

assert_equals "v2.0.0-beta1" "$(cwm_latest_stable_tag "$(printf 'v1.0.0-alpha1\nv2.0.0-beta1')")" \
    "a project with only pre-releases still gets a base"

assert_equals "" "$(cwm_latest_stable_tag "")" \
    "no tags yields nothing rather than an error"

assert_equals "1.1.9" "$(cwm_latest_stable_tag "$(printf '1.1.9\n1.1.10-rc1')")" \
    "tags without a v prefix work too"

# A v prefix must not be mistaken for a pre-release suffix.
assert_equals "v10.5.7" "$(cwm_latest_stable_tag "$(printf 'v10.5.6\nv10.5.7')")" \
    "the leading v is not read as a hyphenated suffix"

finish

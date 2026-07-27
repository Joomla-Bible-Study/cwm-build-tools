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

finish

#!/usr/bin/env bash
#
# Tests for scripts/lib/ars.sh.
#
# What is left in that file after the lookups moved to PHP: deriving the ARS
# release alias, and refusing to publish an item with no environments (#58) —
# a guard that runs here, before the 1Password and GitHub round-trips, so a
# misconfigured project fails in a second rather than half way through.

set -uo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=helpers.sh
source "${SCRIPT_DIR}/helpers.sh"
# shellcheck source=../../scripts/lib/ars.sh
source "${SCRIPT_DIR}/../../scripts/lib/ars.sh"

# --- Alias derivation --------------------------------------------------------
assert_equals "proclaim" "$(cwm_ars_alias_prefix pkg_proclaim "")" "pkg_ prefix is stripped"
assert_equals "cwmscripture" "$(cwm_ars_alias_prefix lib_cwmscripture "")" "lib_ prefix is stripped"
assert_equals "scripture" "$(cwm_ars_alias_prefix pkg_cwmscripturelinks scripture)" \
    "an explicit ars.aliasPrefix wins"
assert_equals "custom" "$(cwm_ars_alias_prefix custom "")" "an unprefixed name passes through"

assert_equals "proclaim-10-3-6" "$(cwm_ars_release_alias proclaim 10.3.6)" \
    "dots become dashes: the alias is a URL slug"
assert_equals "proclaim-10-4-0-beta1" "$(cwm_ars_release_alias proclaim 10.4.0-beta1)" \
    "a pre-release suffix survives the slug"

# The release and item lookups moved to src/Release/ArsPublisher.php, and so
# did their tests — see tests/Release/ArsPublisherTest.php, which keeps every
# case that used to live here (the 10.3.1 vs 10.3.10 substring near-miss, the
# other-pkg_proclaim basename near-miss, an item with no url, unparseable
# bodies) and adds the ones a shell function could not express.

# --- Environments validation -------------------------------------------------
# Publishing an item with NO environments makes ARS emit update XML with
# bogus php_minimum / targetplatform values that block the update on every
# real site (Proclaim 10.3.4-10.4.0). The publish must refuse instead.

check_envs() {
    if cwm_ars_validate_environments "$1"; then echo ok; else echo fail; fi
}

assert_equals "ok" "$(check_envs '["45","46","48","49","50"]')" "a string-id array is valid"
assert_equals "ok" "$(check_envs '[45, 46]')" "a numeric-id array is valid"
assert_equals "fail" "$(check_envs 'null')" "an unset key (json null) is refused"
assert_equals "fail" "$(check_envs '[]')" "an empty array is refused"
assert_equals "fail" "$(check_envs '')" "an empty string is refused"
assert_equals "fail" "$(check_envs '"45"')" "a bare string is refused: must be an array"
assert_equals "fail" "$(check_envs '{"45": true}')" "an object is refused"
assert_equals "fail" "$(check_envs '["45", ""]')" "a blank id inside the array is refused"
assert_equals "fail" "$(check_envs 'not json')" "malformed JSON is refused"

# --- Local artifact vs the asset users download (#132) --------------------------
# ARS `type: link` items point at the GitHub asset, so publishing the local
# file's checksums when the two differ makes Joomla refuse the update while the
# feed stays valid and ARS reports success.

# Identical: the local checksums provably describe the served bytes.
cwm_ars_local_matches_asset 389213 "abc123" 389213 "sha256:abc123"
assert_equals "0" "$?" "same size and digest means the local file is the served bytes"

# Different size — the cheap half. This is pkg_licenseportal 1.5.1: 392410
# served against 388404 local, which a size check alone would have caught.
cwm_ars_local_matches_asset 388404 "31d1fb0d6f36" 392410 "sha256:f7cf344d2a33"
assert_equals "1" "$?" "a size difference is enough to know they differ"

# Same size, different content. This is 1.5.0, and the reason size alone is not
# enough: identical byte counts, different bytes, hidden for two releases.
cwm_ars_local_matches_asset 389213 "72e5ae2db827" 389213 "sha256:b3c16981a684"
assert_equals "1" "$?" "equal sizes with different digests still differ"

# No digest: undecidable, not "fine". GitHub added the field recently, so an
# older release carries none — and 1.5.0 proves equal sizes prove nothing.
cwm_ars_local_matches_asset 389213 "72e5ae2db827" 389213 ""
assert_equals "2" "$?" "a missing digest is undecidable rather than a match"

cwm_ars_local_matches_asset 389213 "72e5ae2db827" 389213 "null"
assert_equals "2" "$?" "gh's literal null is treated as no digest"

# A size mismatch outranks a missing digest: already known to differ.
cwm_ars_local_matches_asset 100 "aaa" 200 ""
assert_equals "1" "$?" "a size difference decides even without a digest"

# --- The entry point refuses what the pipeline refuses ------------------------
# ars-publish.sh sourced lib/version.sh for the tag and maturity helpers but
# never asked whether the version was releasable, so `cwm-ars-publish -v
# 10.5.11-dev` reached the update server while `cwm-release 10.5.11-dev` was
# rejected at its first step (#155). The gate runs before the artifact check,
# the config reads and every network round-trip, so this exercises it without
# any of them.
ARS_WORK="$(mktemp -d)"
trap 'rm -rf "$ARS_WORK"' EXIT
echo '{}' > "${ARS_WORK}/cwm-build.config.json"

run_publish() {
    (cd "$ARS_WORK" && bash "${SCRIPT_DIR}/../../scripts/ars-publish.sh" -v "$1" -f absent.zip 2>&1)
}

assert_contains "$(run_publish 10.5.11-dev)" "Development versions cannot be released" \
    "a -dev version is refused before anything is published"

# The gate is the version, not the arguments: a releasable pre-release gets
# past it and stops at the missing artifact instead.
assert_contains "$(run_publish 10.5.11-alpha1)" "artifact not found" \
    "an -alpha version passes the gate"

finish

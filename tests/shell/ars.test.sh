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

finish

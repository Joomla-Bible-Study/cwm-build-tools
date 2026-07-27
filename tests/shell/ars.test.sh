#!/usr/bin/env bash
#
# Tests for scripts/lib/ars.sh.
#
# The lookups are fed fixture JSON shaped like real ARS v7 responses:
# data[].attributes with id, version, url. A miss on either lookup makes
# the caller create a duplicate release or item (#37), which is why the
# misses tested here matter as much as the hits.

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

# --- Release lookup ----------------------------------------------------------
RELEASES='{
  "data": [
    {"type": "releases", "id": "41", "attributes": {"id": 41, "version": "10.3.10"}},
    {"type": "releases", "id": "42", "attributes": {"id": 42, "version": "10.3.1"}}
  ]
}'

# The `search` parameter substring-matches, so a query for 10.3.1 also
# returns 10.3.10 — the exact-match here is what keeps the create-or-update
# decision honest.
got="$(printf '%s' "$RELEASES" | cwm_ars_find_release_id 10.3.1)"
assert_equals "42" "$got" "matches the exact version, not the 10.3.10 substring cousin"

got="$(printf '%s' "$RELEASES" | cwm_ars_find_release_id 10.3.10)"
assert_equals "41" "$got" "finds 10.3.10 as itself"

got="$(printf '%s' "$RELEASES" | cwm_ars_find_release_id 10.3.2)"
assert_equals "" "$got" "an absent version yields nothing (caller creates)"

got="$(printf '%s' '{"data": []}' | cwm_ars_find_release_id 10.3.1)"
assert_equals "" "$got" "an empty data array yields nothing"

# Malformed JSON must yield nothing and exit 0: the caller runs under
# set -e, and "cannot parse" must read as "not found → create", never as
# an update PATCHed at a guessed id.
got="$(printf '%s' '<html>Fatal error' | cwm_ars_find_release_id 10.3.1)"
rc=$?
assert_equals "" "$got" "malformed JSON yields nothing"
assert_equals "0" "$rc" "malformed JSON does not abort the caller"

# --- Item lookup -------------------------------------------------------------
ITEMS='{
  "data": [
    {"type": "items", "id": "7", "attributes": {"id": 7,
      "url": "https://github.com/o/r/releases/download/v10.3.5/pkg_proclaim-10.3.5.zip"}},
    {"type": "items", "id": "8", "attributes": {"id": 8,
      "url": "https://github.com/o/r/releases/download/v10.3.6/pkg_proclaim-10.3.6.zip"}}
  ]
}'

got="$(printf '%s' "$ITEMS" | cwm_ars_find_item_id pkg_proclaim-10.3.6.zip)"
assert_equals "8" "$got" "finds the item whose URL basename is the zip"

got="$(printf '%s' "$ITEMS" | cwm_ars_find_item_id pkg_proclaim-10.3.7.zip)"
assert_equals "" "$got" "an absent zip yields nothing (caller creates)"

# The match is on the exact basename: a suffix match would claim
# other-pkg_proclaim-10.3.6.zip for pkg_proclaim-10.3.6.zip and update
# the wrong item.
NEAR_MISS='{"data": [{"type": "items", "id": "9", "attributes": {"id": 9,
  "url": "https://example.org/dl/other-pkg_proclaim-10.3.6.zip"}}]}'
got="$(printf '%s' "$NEAR_MISS" | cwm_ars_find_item_id pkg_proclaim-10.3.6.zip)"
assert_equals "" "$got" "a basename that merely ends with the zip name does not match"

# An item without a url (type "file" items store a filename instead) is
# skipped, not a crash.
NO_URL='{"data": [{"type": "items", "id": "10", "attributes": {"id": 10}}]}'
got="$(printf '%s' "$NO_URL" | cwm_ars_find_item_id pkg_proclaim-10.3.6.zip)"
assert_equals "" "$got" "an item without a url is skipped"

got="$(printf '%s' 'not json' | cwm_ars_find_item_id pkg_proclaim-10.3.6.zip)"
assert_equals "" "$got" "malformed JSON yields nothing"

finish

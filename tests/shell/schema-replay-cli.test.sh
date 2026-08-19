#!/usr/bin/env bash
#
# cwm-schema-replay's argument and configuration handling, without a database.
#
# The replay itself is covered by tests/Dev/SchemaReplayTest.php, which needs a
# real MySQL. These are the branches that decide whether a replay happens at
# all, and every one of them must exit NON-ZERO: a replay that ran nothing and
# exited 0 would read exactly like a replay that passed, which is the failure
# this command exists to prevent rather than commit.
#
# Deliberately no DSN is set for the "nothing configured" cases, so this file
# runs in the lint job alongside the other shell tests, where there is no
# database service.

set -uo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=helpers.sh
source "${SCRIPT_DIR}/helpers.sh"

ROOT="$(cd "${SCRIPT_DIR}/../.." && pwd)"
BIN="${ROOT}/bin/cwm-schema-replay"

WORK="$(mktemp -d)"
trap 'rm -rf "${WORK}"' EXIT

# --- no project at all -------------------------------------------------------

cd "${WORK}" || exit 1
OUT="$("${BIN}" 2>&1)"
RC=$?
assert_equals "1" "${RC}" "exits 1 outside a configured project"
assert_contains "${OUT}" "cwm-build.config.json" "names the missing config file"

# --- configured project, but no schemaReplay block ---------------------------

mkdir -p "${WORK}/nothing"
cd "${WORK}/nothing" || exit 1
echo '{"extension":{"name":"x"}}' > cwm-build.config.json

OUT="$("${BIN}" 2>&1)"
RC=$?
# The load-bearing assertion in this file. A check with no target checked
# nothing, and exiting 0 there lets a release gate pass over an empty set --
# the exact silence that made Proclaim#1866 expensive.
assert_equals "1" "${RC}" "exits 1 when no target is configured, rather than 0"
assert_contains "${OUT}" "nothing to replay" "says plainly that nothing ran"

# --- a target that does not exist --------------------------------------------

mkdir -p "${WORK}/named"
cd "${WORK}/named" || exit 1
cat > cwm-build.config.json <<'JSON'
{
  "extension": { "name": "x" },
  "schemaReplay": {
    "targets": [
      { "name": "com_real", "manifest": "m.xml", "baseline": "b.sql", "from": "1.0.0" }
    ]
  }
}
JSON

OUT="$("${BIN}" --target com_missing 2>&1)"
RC=$?
assert_equals "1" "${RC}" "exits 1 for an unknown --target"
assert_contains "${OUT}" "com_missing" "names the target that was not found"

# --- a real target, but no DSN -----------------------------------------------

OUT="$(env -u CWM_TEST_MYSQL_DSN "${BIN}" 2>&1)"
RC=$?
assert_equals "1" "${RC}" "exits 1 when CWM_TEST_MYSQL_DSN is unset"
assert_contains "${OUT}" "CWM_TEST_MYSQL_DSN" "names the variable to set"
assert_contains "${OUT}" "docker compose" "points at the compose file that provides one"

# --- --help stays inert ------------------------------------------------------

# Covered generically by help-is-inert.test.sh; asserted here too because this
# command's help is the only place the baseline is explained, and a wrapper
# change that made it require config would hide that.
BEFORE="$(ls -a | sort | tr '\n' ' ')"
OUT="$(env -u CWM_TEST_MYSQL_DSN "${BIN}" --help 2>&1)"
RC=$?
AFTER="$(ls -a | sort | tr '\n' ' ')"

assert_equals "0" "${RC}" "--help exits 0"
assert_equals "${BEFORE}" "${AFTER}" "--help writes nothing"
assert_contains "${OUT}" "THE BASELINE" "--help explains the baseline"
assert_contains "${OUT}" "vendor/bin/cwm-schema-replay" "--help shows an invocation that always works"

finish

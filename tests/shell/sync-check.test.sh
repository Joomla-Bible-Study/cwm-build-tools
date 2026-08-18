#!/usr/bin/env bash
#
# `cwm-sync-configs --check`: is any managed config out of date?
#
# The point is CI. A managed block that has quietly fallen behind is invisible
# otherwise -- it gets found when someone happens to run a sync, which is
# exactly how Proclaim's .gitignore drifted without anyone noticing.
#
# ⚠️ The load-bearing distinction is *maintained* versus *seeded*. phpunit.xml
# and docker-compose.databases.yml are created once and never updated: they are
# an offer, and a project that has not taken one up is not out of date. If those
# counted, every consumer would fail forever, and a check that always fails is
# one people learn to ignore -- the failure this exists to prevent, caused by
# the check itself.

set -uo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=helpers.sh
source "${SCRIPT_DIR}/helpers.sh"

ROOT="$(cd "${SCRIPT_DIR}/../.." && pwd)"
SYNC="${ROOT}/scripts/sync-configs.php"

WORK="$(mktemp -d)"
trap 'rm -rf "${WORK}"' EXIT

PROJECT="${WORK}/project"
mkdir -p "${PROJECT}"
printf '{"name":"demo/x"}\n' > "${PROJECT}/composer.json"
printf '{"extension":{"name":"demo","type":"component"}}\n' > "${PROJECT}/cwm-build.config.json"

# --- a project that has never been synced is out of date ---------------------

( cd "${PROJECT}" && php "${SYNC}" --check >/dev/null 2>&1 )
assert_equals "1" "$?" "an unsynced project reports drift"

BEFORE="$(cd "${PROJECT}" && ls -a | sort | tr '\n' ' ')"
( cd "${PROJECT}" && php "${SYNC}" --check >/dev/null 2>&1 )
AFTER="$(cd "${PROJECT}" && ls -a | sort | tr '\n' ' ')"
assert_equals "${BEFORE}" "${AFTER}" "--check must never write"

# --- after a real sync it is up to date --------------------------------------

( cd "${PROJECT}" && php "${SYNC}" >/dev/null 2>&1 )
( cd "${PROJECT}" && php "${SYNC}" --check >/dev/null 2>&1 )
assert_equals "0" "$?" "a freshly synced project is up to date"

# --- ⚠️ a seeded file that was never taken up is NOT drift --------------------
#
# Delete both seeded files. The sync would offer to create them again, and that
# must not fail the check.

rm -f "${PROJECT}/phpunit.xml" "${PROJECT}/docker-compose.databases.yml"

OUT="$( cd "${PROJECT}" && php "${SYNC}" --check 2>&1 )"
( cd "${PROJECT}" && php "${SYNC}" --check >/dev/null 2>&1 )
assert_equals "0" "$?" "a missing seeded file is an un-taken offer, not drift"
assert_contains "${OUT}" "would create from boilerplate" "the offer is still reported"
assert_contains "${OUT}" "up to date" "but the verdict is clean"

# --- a managed file that has drifted DOES fail -------------------------------

# Drop the first real entry from inside the managed block. Found rather than
# hardcoded: an earlier version of this test removed 'node_modules/' while the
# block actually contains '/node_modules/', so it changed nothing, the check
# correctly said "up to date", and the test blamed the code.
python3 - "${PROJECT}/.gitignore" <<'DRIFT'
import sys

path  = sys.argv[1]
lines = open(path).read().split('\n')

start = next(i for i, l in enumerate(lines) if 'cwm-build-tools: managed' in l)
end   = next(i for i, l in enumerate(lines) if 'end managed' in l)

for i in range(start + 1, end):
    entry = lines[i].strip()

    if entry and not entry.startswith('#'):
        lines.pop(i)
        break
else:
    raise SystemExit('no entry inside the managed block to remove')

open(path, 'w').write('\n'.join(lines))
DRIFT

OUT="$( cd "${PROJECT}" && php "${SYNC}" --check 2>&1 )"
( cd "${PROJECT}" && php "${SYNC}" --check >/dev/null 2>&1 )
assert_equals "1" "$?" "a drifted managed block fails the check"
assert_contains "${OUT}" ".gitignore" "the drifted file is named"
assert_contains "${OUT}" "composer sync-configs" "and the fix is given"

# --- --check implies dry-run: it reports without repairing --------------------

DRIFTED="$(md5 -q "${PROJECT}/.gitignore" 2>/dev/null || md5sum "${PROJECT}/.gitignore" | cut -d' ' -f1)"
( cd "${PROJECT}" && php "${SYNC}" --check >/dev/null 2>&1 )
STILL="$(md5 -q "${PROJECT}/.gitignore" 2>/dev/null || md5sum "${PROJECT}/.gitignore" | cut -d' ' -f1)"

assert_equals "${DRIFTED}" "${STILL}" "--check must not repair the file it reports"

# --- and a real sync does repair it ------------------------------------------
#
# Without this the assertion above is satisfied by a --check that does nothing
# at all, including reporting.

( cd "${PROJECT}" && php "${SYNC}" >/dev/null 2>&1 )
( cd "${PROJECT}" && php "${SYNC}" --check >/dev/null 2>&1 )
assert_equals "0" "$?" "a real sync clears the drift the check reported"

finish

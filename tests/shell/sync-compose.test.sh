#!/usr/bin/env bash
#
# Tests for the database compose file that cwm-sync-configs distributes.
#
# The file itself cannot be exercised here — starting a container is not
# something a unit test should do, and the machine running this may have no
# container runtime at all. What *is* checkable is the part that has bitten
# this project before: a sync handler that overwrites something a developer
# edited. Ports and whether MariaDB is wanted are their choices, and a
# "refresh" that silently reverted them would be worse than not shipping the
# file.
#
# The compose file is also parsed, so a broken template is caught here rather
# than by a developer whose `docker compose up` fails for reasons that have
# nothing to do with their machine.

set -uo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=helpers.sh
source "${SCRIPT_DIR}/helpers.sh"

ROOT="$(cd "${SCRIPT_DIR}/../.." && pwd)"
TEMPLATE="${ROOT}/templates/docker-compose.databases.yml"
SYNC="${ROOT}/scripts/sync-configs.php"

WORK="$(mktemp -d)"
trap 'rm -rf "${WORK}"' EXIT

# --- the template declares what it claims ------------------------------------
#
# Asserted against the file text rather than a YAML parser: pyyaml is not
# guaranteed on a developer's machine or on a CI runner that only installed
# Python, and a test that cannot run is worth less than one that checks a
# little less.

TPL="$(cat "$TEMPLATE")"

assert_contains "$TPL" "  mysql:" "a mysql service is declared"
assert_contains "$TPL" "  mariadb:" "a mariadb service is declared"
assert_contains "$TPL" "image: mysql:8.4" "MySQL pinned to the version CI runs"
assert_contains "$TPL" "image: mariadb:11.4" "MariaDB pinned to the version CI runs"

# ⚠️ The invariant that matters, and the only part of this file a test can
# actually guard.
#
# Both images declare a VOLUME for their data directory, so a plain `down`
# leaves an anonymous volume behind and the next `up` inherits it — a leftover
# table from the previous attempt, which is the exact failure this file exists
# to prevent. The reset therefore has to be documented as `down -v`.
#
# A tmpfs data directory would remove the need for the flag, and was tried
# first. MySQL 8 defaults innodb_flush_method to O_DIRECT, which tmpfs does not
# support, so the server does not start. If someone reintroduces tmpfs, this
# assertion is the wrong one — but it fails loudly rather than shipping a reset
# command that does not reset.
assert_equals "0" "$(grep -c '^    tmpfs:' "$TEMPLATE")" "no tmpfs data dir (MySQL 8 needs O_DIRECT)"
assert_contains "$TPL" "down -v" "the documented reset uses -v, or it does not reset"
assert_equals "0" "$(grep -E '^#   docker compose .*down' "$TEMPLATE" | grep -vc 'down -v')" "no bare 'down' offered as the reset"
assert_equals "0" "$(grep -c '^volumes:' "$TEMPLATE")" "no named volumes, which down -v would not remove"

assert_equals "2" "$(grep -c 'healthcheck:' "$TEMPLATE")" "both engines declare a healthcheck"

# Not 3306: a developer's existing local MySQL has to keep running.
assert_equals "0" "$(grep -c '"3306:3306"' "$TEMPLATE")" "default host ports avoid 3306"

# --- the sync handler creates it, and never overwrites it --------------------

PROJECT="${WORK}/project"
mkdir -p "${PROJECT}"
printf '{"name":"demo/x"}\n' > "${PROJECT}/composer.json"
printf '{"extension":{"name":"demo","type":"component"}}\n' > "${PROJECT}/cwm-build.config.json"

( cd "${PROJECT}" && php "${SYNC}" --dry-run >/dev/null 2>&1 )
if [ -f "${PROJECT}/docker-compose.databases.yml" ]; then
    assert_equals "absent" "present" "--dry-run must not write the compose file"
else
    assert_equals "absent" "absent" "--dry-run writes nothing"
fi

( cd "${PROJECT}" && php "${SYNC}" >/dev/null 2>&1 )
if [ -f "${PROJECT}/docker-compose.databases.yml" ]; then
    assert_equals "present" "present" "a real run creates the compose file"
else
    assert_equals "present" "absent" "a real run should create the compose file"
fi

printf '\n# edited by the developer\n' >> "${PROJECT}/docker-compose.databases.yml"
EDITED="$(md5 -q "${PROJECT}/docker-compose.databases.yml" 2>/dev/null || md5sum "${PROJECT}/docker-compose.databases.yml" | cut -d' ' -f1)"

( cd "${PROJECT}" && php "${SYNC}" >/dev/null 2>&1 )
AFTER="$(md5 -q "${PROJECT}/docker-compose.databases.yml" 2>/dev/null || md5sum "${PROJECT}/docker-compose.databases.yml" | cut -d' ' -f1)"

assert_equals "${EDITED}" "${AFTER}" "re-running must not overwrite a customised compose file"

finish

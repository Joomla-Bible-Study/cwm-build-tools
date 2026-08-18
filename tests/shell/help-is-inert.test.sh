#!/usr/bin/env bash
#
# `--help` must print help and change nothing.
#
# cwm-sync-configs --help used to run the whole sync, rewriting .gitignore,
# build.dist.properties, .editorconfig and phpunit.xml in whatever directory it
# was called from. It was found by running --help across every cwm-* binary as
# a post-upgrade smoke test and watching four working trees go dirty.
#
# Two properties are checked here, for every command at once:
#
#   1. nothing is written  — the defect above, for all of them, not just the
#      one that had it
#   2. help works outside a configured project — several wrappers checked for
#      cwm-build.config.json before reaching the script that handles --help, so
#      you had to already be set up to find out what a command did
#
# Commands still missing a --help text are listed in KNOWN_MISSING rather than
# silently tolerated: the list is asserted to be exactly what is expected, so
# fixing one without updating it fails, and so does a regression that adds one.

set -uo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=helpers.sh
source "${SCRIPT_DIR}/helpers.sh"

ROOT="$(cd "${SCRIPT_DIR}/../.." && pwd)"

WORK="$(mktemp -d)"
trap 'rm -rf "${WORK}"' EXIT

# An empty directory: no config, no properties. Nothing here can act even if a
# command wanted to, which is what makes running all of them safe.
PROJECT="${WORK}/empty"
mkdir -p "${PROJECT}"

# Commands that do not yet print help. Each exits non-zero with its own usage
# or a missing-prerequisite error — none of them writes — but they do not meet
# the documented shape yet.
KNOWN_MISSING="cwm-ars-create-stream cwm-ars-list cwm-ars-publish cwm-ars-reorder cwm-article cwm-changelog cwm-release cwm-sync-languages"

MISSING_FOUND=""
WROTE=""

for BIN in "${ROOT}"/bin/cwm-*; do
    NAME="$(basename "${BIN}")"

    BEFORE="$(cd "${PROJECT}" && ls -a | sort | tr '\n' ' ')"
    OUT="$(cd "${PROJECT}" && "${BIN}" --help 2>&1 </dev/null | head -1)"
    AFTER="$(cd "${PROJECT}" && ls -a | sort | tr '\n' ' ')"

    if [ "${BEFORE}" != "${AFTER}" ]; then
        WROTE="${WROTE} ${NAME}"
    fi

    # A help text names its own command on the first line: "cwm-x — does y".
    case "${OUT}" in
        "${NAME} — "*) : ;;
        *) MISSING_FOUND="${MISSING_FOUND} ${NAME}" ;;
    esac
done

assert_equals "" "${WROTE}" "no command may write anything when asked for --help"

# Compared as sorted sets so the assertion does not depend on glob order.
EXPECTED="$(echo ${KNOWN_MISSING} | tr ' ' '\n' | sort | tr '\n' ' ')"
ACTUAL="$(echo ${MISSING_FOUND} | tr ' ' '\n' | sed '/^$/d' | sort | tr '\n' ' ')"

assert_equals "${EXPECTED}" "${ACTUAL}" "exactly the known commands lack a --help text"

finish

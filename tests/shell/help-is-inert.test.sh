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

# ⚠️ Empty, and it should stay that way.
#
# This began as a list of eight -- the four cwm-ars-*, cwm-article,
# cwm-changelog, cwm-release and cwm-sync-languages -- none of which printed
# help. They all do now, so the assertion below reads "no command lacks a help
# text" rather than "these ones are excused".
#
# A new command that ships without help fails here, which is the point: the list
# existed so the gap could not be forgotten, and an empty list is the strongest
# form of that.
KNOWN_MISSING=""

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
# Blank lines are dropped on both sides and the result trimmed, so an empty list
# compares equal to an empty list -- `echo "" | tr` yields a space, not nothing,
# which made the two differ while both were empty.
normalise() {
    echo "$1" | tr ' ' '\n' | sed '/^$/d' | sort | tr '\n' ' ' | sed 's/ *$//'
}

EXPECTED="$(normalise "${KNOWN_MISSING}")"
ACTUAL="$(normalise "${MISSING_FOUND}")"

assert_equals "${EXPECTED}" "${ACTUAL}" "exactly the known commands lack a --help text"

finish

#!/usr/bin/env bash
#
# The schema-replay job must be identical in both reusable CI workflows.
#
# joomla-package-ci.yml and joomla-library-ci.yml each carry their own copy.
# Nesting one reusable workflow inside another would avoid the duplication, but
# a local `uses:` path inside a workflow that is itself being called resolves in
# a way that cannot be verified without a consumer round-trip, and only one
# project uses the library variant. Copying was the cheaper certainty.
#
# What copying costs is drift: a fix applied to one and not the other is
# invisible, and the project that noticed the bug is not the project still
# carrying it. This test converts that into a failure — which is the whole
# reason the duplication is acceptable.
#
# Compares the `schema-replay:` job and the `run-schema-replay:` input, not the
# whole file: the two workflows differ everywhere else on purpose.

set -uo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=helpers.sh
source "${SCRIPT_DIR}/helpers.sh"

ROOT="$(cd "${SCRIPT_DIR}/../.." && pwd)"
PKG="${ROOT}/.github/workflows/joomla-package-ci.yml"
LIB="${ROOT}/.github/workflows/joomla-library-ci.yml"

# Everything from the `schema-replay:` job header to end of file.
extract_job() {
    sed -n '/^  schema-replay:$/,$p' "$1"
}

# The input block, from its key to the next key at the same indent.
extract_input() {
    awk '
        /^      run-schema-replay:$/ { grab = 1; print; next }
        grab && /^      [a-z-]+:$/   { exit }
        grab                          { print }
    ' "$1"
}

PKG_JOB="$(extract_job "${PKG}")"
LIB_JOB="$(extract_job "${LIB}")"

# Guard against the extraction silently matching nothing, which would make the
# comparison below pass by comparing two empty strings.
if [ -z "${PKG_JOB}" ]; then
    FAIL=$((FAIL + 1))
    echo "  FAIL: no schema-replay job found in joomla-package-ci.yml"
else
    PASS=$((PASS + 1))
fi

if [ -z "${LIB_JOB}" ]; then
    FAIL=$((FAIL + 1))
    echo "  FAIL: no schema-replay job found in joomla-library-ci.yml"
else
    PASS=$((PASS + 1))
fi

assert_equals "${PKG_JOB}" "${LIB_JOB}" "the schema-replay job is identical in both reusable workflows"

PKG_IN="$(extract_input "${PKG}")"
LIB_IN="$(extract_input "${LIB}")"

if [ -z "${PKG_IN}" ]; then
    FAIL=$((FAIL + 1))
    echo "  FAIL: no run-schema-replay input found in joomla-package-ci.yml"
else
    PASS=$((PASS + 1))
fi

assert_equals "${PKG_IN}" "${LIB_IN}" "the run-schema-replay input is identical in both reusable workflows"

# It must stay opt-in in both. A default of true would start a MySQL service on
# every consumer's every pull request, including the ones replaying nothing.
assert_contains "${PKG_IN}" "default: false" "package workflow keeps the input off by default"
assert_contains "${LIB_IN}" "default: false" "library workflow keeps the input off by default"

finish

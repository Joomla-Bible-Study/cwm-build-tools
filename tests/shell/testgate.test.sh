#!/usr/bin/env bash
#
# Tests for scripts/lib/testgate.sh.
#
# What matters here is the detection alone: whether a project opted in to the
# release gate by defining test:release. release.sh decides what to do with
# that answer; running composer for real isn't something a unit test should
# do.

set -uo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=helpers.sh
source "${SCRIPT_DIR}/helpers.sh"
# shellcheck source=../../scripts/lib/testgate.sh
source "${SCRIPT_DIR}/../../scripts/lib/testgate.sh"

WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT

# --- A project that defines the script ---------------------------------------
cat > "${WORK}/with-gate.json" <<'JSON'
{
    "scripts": {
        "test": "phpunit",
        "test:release": "bash build/test-release.sh"
    }
}
JSON
if cwm_has_test_release_script "${WORK}/with-gate.json"; then
    PASS=$((PASS + 1))
else
    FAIL=$((FAIL + 1))
    echo "  FAIL: a project defining test:release is detected"
fi

# --- A project with scripts but not this one ---------------------------------
cat > "${WORK}/without-gate.json" <<'JSON'
{
    "scripts": {
        "test": "phpunit"
    }
}
JSON
if cwm_has_test_release_script "${WORK}/without-gate.json"; then
    FAIL=$((FAIL + 1))
    echo "  FAIL: a project without test:release is left alone"
else
    PASS=$((PASS + 1))
fi

# --- A project with no scripts block at all -----------------------------------
echo '{"name": "cwm/example"}' > "${WORK}/no-scripts.json"
if cwm_has_test_release_script "${WORK}/no-scripts.json"; then
    FAIL=$((FAIL + 1))
    echo "  FAIL: a composer.json with no scripts block is left alone"
else
    PASS=$((PASS + 1))
fi

# --- No composer.json at all --------------------------------------------------
# A library or non-PHP consumer of the release pipeline shouldn't be blocked
# on a file it has no reason to have.
if cwm_has_test_release_script "${WORK}/does-not-exist.json"; then
    FAIL=$((FAIL + 1))
    echo "  FAIL: a missing composer.json is left alone"
else
    PASS=$((PASS + 1))
fi

# --- Malformed JSON ------------------------------------------------------------
# Read this as "not opted in" rather than crashing the release pipeline over
# an unrelated syntax error someone else's editor introduced.
echo '{ not valid json' > "${WORK}/broken.json"
if cwm_has_test_release_script "${WORK}/broken.json"; then
    FAIL=$((FAIL + 1))
    echo "  FAIL: invalid JSON is treated as not opted in, not a crash"
else
    PASS=$((PASS + 1))
fi

finish

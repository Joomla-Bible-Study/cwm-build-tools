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

# --- Invoking the gate (#101) -------------------------------------------------
# A stub `composer` on PATH, not the real one — the header's "running composer
# for real isn't something a unit test should do" still holds. What is under
# test is the environment the gate is handed and the status it returns, and
# neither needs a real composer process to observe.
mkdir -p "${WORK}/bin"
cat > "${WORK}/bin/composer" <<'STUB'
#!/usr/bin/env bash
echo "args=$* version=${CWM_RELEASE_VERSION-<unset>}"
exit "${STUB_EXIT:-0}"
STUB
chmod +x "${WORK}/bin/composer"
PATH="${WORK}/bin:${PATH}"

# The release target reaches the gate. Without it the gate has no way to know
# which version is about to ship — the bump hasn't happened yet — and an
# upgrade phase resolving it from versions.json compares a version to itself.
assert_equals \
    "args=test:release version=1.2.3" \
    "$(cwm_run_test_release '1.2.3')" \
    "the gate runs test:release with CWM_RELEASE_VERSION set to the target"

# Scoped to that one command, not exported: the bump, the build and every
# later step must keep the environment they had.
assert_equals \
    "<unset>" \
    "${CWM_RELEASE_VERSION-<unset>}" \
    "CWM_RELEASE_VERSION does not leak into the caller's environment"

# release.sh branches three ways on this status (pass / dry-run warning / hard
# stop), so it has to arrive unchanged rather than swallowed by the wrapper.
export STUB_EXIT=7
cwm_run_test_release '1.2.3' > /dev/null
GATE_STATUS=$?
unset STUB_EXIT
assert_equals "7" "$GATE_STATUS" "composer's exit status reaches the caller unchanged"

finish

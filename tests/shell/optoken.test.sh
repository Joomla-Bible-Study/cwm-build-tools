#!/usr/bin/env bash
#
# Tests for scripts/lib/optoken.sh.
#
# A stub `op` on PATH, so the failure modes can be exercised without a vault.
# The point of this function is what it says when it fails, so most of these
# assert on the diagnostic rather than the happy path.

set -uo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=helpers.sh
source "${SCRIPT_DIR}/helpers.sh"
# shellcheck source=../../scripts/lib/optoken.sh
source "${SCRIPT_DIR}/../../scripts/lib/optoken.sh"

WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT
mkdir -p "${WORK}/bin"
PATH="${WORK}/bin:${PATH}"

# `op` that succeeds, and writes a warning to stderr as the real one may.
cat > "${WORK}/bin/op" <<'STUB'
#!/usr/bin/env bash
case "${OP_MODE:-ok}" in
    ok)      echo "warning: using desktop app integration" >&2; echo "s3cr3t-token" ;;
    locked)  echo "[ERROR] 2026/08/17 authorization prompt dismissed or timed out" >&2; exit 1 ;;
    missing) echo "[ERROR] 2026/08/17 \"CWM ARS API Token\" isn't an item in the \"CWM\" vault" >&2; exit 1 ;;
    empty)   exit 0 ;;
esac
STUB
chmod +x "${WORK}/bin/op"

# --- The happy path returns only the credential --------------------------------
# The real `op` writes warnings to stderr on success. Merging them into the
# value — which one caller did — sends them to an API as if they were a token.
OUT="$(OP_MODE=ok cwm_op_token 'CWM ARS API Token' 'CWM' 2>/dev/null)"
assert_equals "s3cr3t-token" "$OUT" "only the credential reaches stdout, never op's warnings"

# --- The environment variable wins, and op is not consulted --------------------
OUT="$(ARS_API_TOKEN=from-env OP_MODE=locked cwm_op_token 'x' 'y' 2>/dev/null)"
assert_equals "from-env" "$OUT" "ARS_API_TOKEN short-circuits 1Password"

# A caller may name a different variable.
OUT="$(CWM_OTHER_TOKEN=other cwm_op_token 'x' 'y' CWM_OTHER_TOKEN 2>/dev/null)"
assert_equals "other" "$OUT" "the environment variable to prefer is configurable"

# --- A failure names the cause -------------------------------------------------
# The whole point: "Could not retrieve API token" with no cause sent a
# maintainer after composer for an hour when the answer was in op's stderr.
ERR="$(OP_MODE=locked cwm_op_token 'CWM ARS API Token' 'CWM' 2>&1 >/dev/null)"
assert_contains "$ERR" "authorization prompt dismissed" "op's stderr is surfaced, not discarded"
assert_contains "$ERR" "op exited 1" "the exit status is preserved rather than swallowed"
assert_contains "$ERR" "ARS_API_TOKEN" "the bypass is named in the error, not only in the docs"
assert_contains "$ERR" "CWM ARS API Token" "the item that was looked up is named"

# A different cause reads differently, which is the point of preserving it.
ERR="$(OP_MODE=missing cwm_op_token 'CWM ARS API Token' 'CWM' 2>&1 >/dev/null)"
assert_contains "$ERR" "isn't an item" "a misnamed item is distinguishable from a locked account"

# --- A zero exit with no credential is still a failure -------------------------
if OP_MODE=empty cwm_op_token 'x' 'y' > /dev/null 2>&1; then
    FAIL=$((FAIL + 1))
    echo "  FAIL: an empty credential with exit 0 is treated as failure"
else
    PASS=$((PASS + 1))
fi

# --- Nothing leaks to stdout on failure ----------------------------------------
# A caller does TOKEN="$(cwm_op_token ...)". If diagnostics went to stdout the
# token would become the error text and be sent to the API.
OUT="$(OP_MODE=locked cwm_op_token 'x' 'y' 2>/dev/null)"
assert_equals "" "$OUT" "failure writes nothing to stdout"

# --- op absent is its own message ----------------------------------------------
ERR="$(PATH="${WORK}/empty:/usr/bin:/bin" cwm_op_token 'x' 'y' 2>&1 >/dev/null)"
assert_contains "$ERR" "not installed" "a missing op is reported as such"
assert_contains "$ERR" "ARS_API_TOKEN" "and still names the bypass"

finish

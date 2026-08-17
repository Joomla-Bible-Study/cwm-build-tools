#!/usr/bin/env bash
#
# API-token retrieval from 1Password, with the reason preserved when it fails.
#
# Five scripts needed this and four of them discarded `op`'s stderr with
# `2>/dev/null || echo ""`, collapsing every failure into one line with no
# cause: item or vault misnamed, the field not being `credential`, the account
# locked, the desktop app not having authorized this invocation yet, or `op`
# not signed in at all. The `|| echo ""` threw away the exit status too.
#
# That is not a cosmetic loss. A release stopped with "Could not retrieve API
# token" while the same `op item get` run by hand in the same terminal returned
# the credential — the visible evidence pointed at composer's environment, and
# a bug report against composer was drafted before the cause turned out to be a
# transient authorization state on the 1Password side, which is exactly what
# `op`'s own stderr says when it happens (#126).
#
# The fifth copy, in cwm-article.sh, captured stderr with `2>&1` — into the
# token. That reads better on failure and is worse on success: any warning `op`
# writes ends up prepended to the credential, and the caller sends it to an API.
#
# So: the credential comes back on stdout and nothing else ever does;
# diagnostics go to stderr; the exit status is preserved.

# Fetch the API token, preferring an environment variable over 1Password.
#
# The environment variable is the reliable path in CI and any non-interactive
# context, and it is not obvious from a failure message that it exists — so it
# is named in the diagnostics rather than only in the docs.
#
# Arguments:
#   $1  1Password item title.
#   $2  1Password vault.
#   $3  Environment variable to prefer. Optional, defaults to ARS_API_TOKEN.
#
# Outputs:
#   The token on stdout. Diagnostics on stderr, never on stdout.
#
# Returns:
#   0 with a token, 1 with a reason printed.
cwm_op_token() {
    local item="$1"
    local vault="$2"
    local envvar="${3:-ARS_API_TOKEN}"
    local preset token errfile status=0

    preset="$(printenv "$envvar" 2>/dev/null || true)"

    if [ -n "$preset" ]; then
        printf '%s\n' "$preset"

        return 0
    fi

    if ! command -v op > /dev/null 2>&1; then
        {
            echo "Error: ${envvar} is not set and the 1Password CLI (op) is not installed."
            echo "  Set ${envvar}, or install op: https://developer.1password.com/docs/cli/"
        } >&2

        return 1
    fi

    errfile="$(mktemp)"

    # stderr to a file, never merged into the value: `op` writes the credential
    # to stdout and diagnostics to stderr, so keeping them apart is both what
    # makes the diagnostic printable and what stops a warning being sent to an
    # API as if it were a token.
    token="$(op item get "$item" --vault "$vault" --fields label=credential --reveal 2>"$errfile")" || status=$?

    if [ "$status" -eq 0 ] && [ -n "$token" ]; then
        rm -f "$errfile"
        printf '%s\n' "$token"

        return 0
    fi

    {
        echo "Error: could not retrieve the API token from 1Password."
        echo "  item:  '${item}'"
        echo "  vault: '${vault}'"
        echo "  field: 'credential'"

        if [ "$status" -ne 0 ]; then
            echo "  op exited ${status}."
        fi

        if [ -s "$errfile" ]; then
            sed 's/^/  op: /' "$errfile"
        fi

        echo "  Set ${envvar} to bypass 1Password entirely — the reliable path in CI."
    } >&2

    rm -f "$errfile"

    return 1
}

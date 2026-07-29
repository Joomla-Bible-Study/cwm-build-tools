#!/usr/bin/env bash
#
# Dry-run plumbing for the release pipeline.
#
# Sourceable rather than executable so the flag parsing and the command
# wrapper can be exercised by tests/shell — the same reason lib/artifacts.sh
# and lib/ars.sh exist.
#
# What is being protected here is narrow but expensive to get wrong: a
# --dry-run that quietly executes one of the nine steps anyway has tagged,
# pushed or published something the operator was told would not happen. So
# the wrapper is a single choke point, and its behaviour is pinned by tests
# rather than by reading the call sites.

# Split --dry-run/-n out of the argument list.
#
# Sets CWM_DRY_RUN to 1 or 0 and CWM_ARGS to the remaining positional
# arguments, so a caller can accept the flag before or after the version:
# both `release.sh --dry-run 1.2.3` and `release.sh 1.2.3 --dry-run` work.
#
# Arguments:
#   The caller's "$@".
#
# Sets:
#   CWM_DRY_RUN  1 when the flag was present, else 0.
#   CWM_ARGS     Array of the non-flag arguments (may be empty).
cwm_parse_dry_run() {
    CWM_DRY_RUN=0
    CWM_ARGS=()

    local arg
    for arg in "$@"; do
        case "$arg" in
            --dry-run|-n) CWM_DRY_RUN=1 ;;
            *)            CWM_ARGS+=("$arg") ;;
        esac
    done
}

# Run a command, or describe it when CWM_DRY_RUN is 1.
#
# Always called in array form — `cwm_mutate git commit -m "$msg"` — so the
# arguments are passed through untouched. Nothing is re-evaluated by a shell,
# which matters because release messages and notes carry arbitrary text.
#
# Arguments:
#   $@  The command and its arguments.
#
# Returns:
#   The command's exit status, or 0 when describing.
cwm_mutate() {
    if [ "${CWM_DRY_RUN:-0}" = "1" ]; then
        printf '  [dry-run] would run: %s\n' "$*"

        return 0
    fi

    "$@"
}

# Report a step that is being skipped wholesale under --dry-run.
#
# Used where simulating the individual commands would be misleading — the
# versions.json step stashes and switches branches, and a partially-described
# version of that reads as though the sequence were safe to interrupt.
#
# Arguments:
#   $1  What is being skipped, and why.
cwm_skip_in_dry_run() {
    printf '  [dry-run] skipped: %s\n' "$1"
}

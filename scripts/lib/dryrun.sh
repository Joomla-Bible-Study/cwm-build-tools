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

# Split release.sh's top-level flags out of the argument list.
#
# Both flags are stripped in the same pass so a caller can put either one
# anywhere relative to the version: `release.sh --dry-run 1.2.3`,
# `release.sh 1.2.3 --skip-tests`, and combinations of both all work.
#
# Arguments:
#   The caller's "$@".
#
# Sets:
#   CWM_DRY_RUN    1 when --dry-run/-n was present, else 0.
#   CWM_SKIP_TESTS 1 when --skip-tests was present, else 0.
#   CWM_ARGS       Array of the non-flag arguments (may be empty).
cwm_parse_dry_run() {
    CWM_DRY_RUN=0
    CWM_SKIP_TESTS=0
    CWM_ARGS=()

    local arg
    for arg in "$@"; do
        # shellcheck disable=SC2034  # CWM_SKIP_TESTS is read by release.sh, not this file
        case "$arg" in
            --dry-run|-n)  CWM_DRY_RUN=1 ;;
            --skip-tests)  CWM_SKIP_TESTS=1 ;;
            *)             CWM_ARGS+=("$arg") ;;
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

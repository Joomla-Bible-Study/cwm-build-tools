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
# An unrecognised option is an error, not a positional. `--dry-runn` used to
# fall through to CWM_ARGS, where it was ignored, and the release ran for real
# while the operator believed they had asked for a preview — which is exactly
# what happened cutting pkg_cwmscripture 1.2.2. The flag being dropped is the
# one that says "do not do any of this", so silence is the worst response.
#
# Only arguments beginning with `-` are judged. A version never does, so a
# mistyped version still reaches the semver check that already exists, with a
# better message than this function could give.
#
# Sets:
#   CWM_DRY_RUN    1 when --dry-run/-n was present, else 0.
#   CWM_SKIP_TESTS 1 when --skip-tests was present, else 0.
#   CWM_HELP       1 when --help/-h was present, else 0.
#   CWM_ARGS       Array of the non-flag arguments (may be empty).
#   CWM_BAD_FLAG   The offending option when the return status is non-zero.
#
# Returns:
#   0 normally, 1 when an unrecognised option was given.
cwm_parse_dry_run() {
    CWM_DRY_RUN=0
    CWM_SKIP_TESTS=0
    CWM_HELP=0
    CWM_ARGS=()
    CWM_BAD_FLAG=''

    local arg
    for arg in "$@"; do
        # shellcheck disable=SC2034  # CWM_SKIP_TESTS/CWM_HELP are read by release.sh, not this file
        case "$arg" in
            --dry-run|-n)  CWM_DRY_RUN=1 ;;
            --skip-tests)  CWM_SKIP_TESTS=1 ;;
            --help|-h)     CWM_HELP=1 ;;
            --)            ;;
            -*)            CWM_BAD_FLAG="$arg"; return 1 ;;
            *)             CWM_ARGS+=("$arg") ;;
        esac
    done

    return 0
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

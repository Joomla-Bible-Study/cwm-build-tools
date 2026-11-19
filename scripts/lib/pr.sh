#!/usr/bin/env bash
#
# Pull-request plumbing for `release.sh --pr`.
#
# Sourceable rather than executable, like the rest of lib/, so the decisions
# that matter are pinned by tests/shell rather than by reading release.sh:
# whether a branch needs a pull request, which uncommitted files a release may
# carry, and when a release pull request is safe to merge.
#
# Why the mode exists: a ruleset that requires pull requests still lets its
# bypass actors push. A plain run pushes its release commits straight to the
# branch, so on a protected branch it either fails or, for an admin, goes
# through by bypassing the rule. --pr puts those commits through pull requests
# instead, and a plain run refuses where a rule requires one.
#
# JSON is parsed with php, not gh's --jq, so nothing here needs jq and a test
# can stand in for gh by printing plain JSON.

# Whether a ruleset requires a pull request to change a branch.
#
# Asks GitHub for the rules in force on the branch: every active ruleset that
# targets it, which is what decides whether a push needs a pull request.
#
# Arguments:
#   $1  owner/repo
#   $2  branch name
#
# Returns:
#   0  a pull_request rule applies
#   1  none does
#   2  the rules could not be read (no network, no gh, an unknown branch).
#      The caller decides what an unknown answer means.
cwm_branch_requires_pr() {
    local repo="$1" branch="$2" json
    json="$(gh api "repos/${repo}/rules/branches/${branch}" 2>/dev/null)" || return 2
    printf '%s' "$json" | php -r '
        $rules = json_decode(stream_get_contents(STDIN), true);
        if (!is_array($rules)) {
            exit(2);
        }
        foreach ($rules as $rule) {
            if (is_array($rule) && ($rule["type"] ?? "") === "pull_request") {
                exit(0);
            }
        }
        exit(1);'
}

# Paths the working tree changes that a release may not carry.
#
# A --pr release may bring exactly two uncommitted files into its release
# commit, so that one pull request carries the whole release: the changelog
# and this version's notes file. Nothing else, because the release commit is
# staged with `git add -A`, which is only safe when the tree held nothing the
# release did not mean to ship.
#
# Untracked files are listed one by one (--untracked-files=all), so a new
# notes file in a new directory is judged by its own path.
#
# Arguments:
#   $@  The allowed paths, relative to the project root. Empty ones are
#       ignored, so an unconfigured changelog allows nothing.
#
# Prints:
#   Every other path, one per line. Nothing means the tree is fine.
cwm_unexpected_dirty_paths() {
    local line path allowed ok
    git status --porcelain --untracked-files=all | while IFS= read -r line; do
        path="${line:3}"
        # A rename reads "old -> new"; the new path is the one being changed.
        case "$path" in
            *' -> '*) path="${path##* -> }" ;;
        esac
        # Porcelain quotes a path with spaces or unusual bytes.
        path="${path#\"}"
        path="${path%\"}"
        ok=0
        for allowed in "$@"; do
            if [ -n "$allowed" ] && [ "$path" = "$allowed" ]; then
                ok=1
            fi
        done
        if [ "$ok" = "0" ]; then
            printf '%s\n' "$path"
        fi
    done
}

# The number at the end of a pull request URL.
#
# Arguments:
#   $1  A URL such as https://github.com/owner/repo/pull/12, as `gh pr create`
#       prints it.
cwm_pr_number_from_url() {
    local url="${1%/}"
    printf '%s\n' "${url##*/}"
}

# What a release pull request is waiting for.
#
# "Every check passed" is decided from the checks themselves, not from
# mergeStateStatus. With no required checks configured — License Portal has
# none — GitHub reports CLEAN while CI is still running, and merging then
# would release a commit nothing had checked. CLEAN only confirms.
#
# A pull request with no checks at all gets a grace period before it counts
# as ready: one that was pushed seconds ago reports none, even in a repository
# that runs CI on every pull request.
#
# Arguments:
#   $1  JSON from `gh pr view --json state,headRefOid,mergeStateStatus,statusCheckRollup`
#   $2  The head commit the release run pushed
#   $3  Seconds waited so far
#   $4  Grace period, in seconds, for checks to appear
#
# Prints one word:
#   merged          already merged, by someone else or an earlier run
#   closed          closed without merging
#   moved           its head is no longer the commit the run pushed
#   failed:<names>  a check finished with anything but success, skipped or neutral
#   pending         a check is still running, or GitHub has not settled
#   conflict        the branch no longer merges cleanly
#   behind          the base moved, and a rule requires an up-to-date branch
#   blocked         every check passed, and GitHub still will not merge it
#   ready           every check reported and passed: merge
cwm_pr_verdict() {
    printf '%s' "$1" | php -r '
        [, $head, $waited, $grace] = $argv;
        $pr = json_decode(stream_get_contents(STDIN), true);
        if (!is_array($pr)) {
            echo "pending";
            exit;
        }
        $state = $pr["state"] ?? "";
        if ($state === "MERGED") {
            echo "merged";
            exit;
        }
        if ($state === "CLOSED") {
            echo "closed";
            exit;
        }
        if (($pr["headRefOid"] ?? "") !== $head) {
            echo "moved";
            exit;
        }

        $checks = $pr["statusCheckRollup"] ?? [];
        $failed = [];
        $pending = false;
        foreach ($checks as $check) {
            $isStatus = ($check["__typename"] ?? "") === "StatusContext"
                || (array_key_exists("context", $check) && !array_key_exists("conclusion", $check));
            $name = $check["name"] ?? ($check["context"] ?? "unnamed check");
            if ($isStatus) {
                // A commit status: one state, no separate conclusion.
                $s = $check["state"] ?? "";
                if ($s === "PENDING" || $s === "EXPECTED" || $s === "") {
                    $pending = true;
                } elseif ($s !== "SUCCESS") {
                    $failed[$name] = true;
                }
                continue;
            }
            // A check run: finished once its status is COMPLETED.
            if (($check["status"] ?? "") !== "COMPLETED") {
                $pending = true;
                continue;
            }
            if (!in_array($check["conclusion"] ?? "", ["SUCCESS", "SKIPPED", "NEUTRAL"], true)) {
                $failed[$name] = true;
            }
        }

        if ($failed !== []) {
            echo "failed:" . implode(", ", array_keys($failed));
            exit;
        }
        if ($pending) {
            echo "pending";
            exit;
        }

        $merge = $pr["mergeStateStatus"] ?? "";
        if ($merge === "DIRTY") {
            echo "conflict";
            exit;
        }
        if ($merge === "BEHIND") {
            echo "behind";
            exit;
        }
        if ($checks === [] && (int) $waited < (int) $grace) {
            echo "pending";
            exit;
        }
        if (in_array($merge, ["CLEAN", "HAS_HOOKS", "UNSTABLE"], true)) {
            echo "ready";
            exit;
        }
        if ($merge === "BLOCKED") {
            echo "blocked";
            exit;
        }
        echo "pending";' -- "$2" "$3" "$4"
}

# Whether a GitHub Actions workflow run for a commit is still going.
#
# A check run appears in statusCheckRollup only once its job is queued, and a
# job held back by `needs:` doesn't exist while the jobs before it run. So the
# rollup can read all green for a moment before the rest of a workflow starts,
# as when Proclaim's lint jobs finish before its e2e jobs are created. The
# workflow run itself stays in progress until its last job is done.
#
# Arguments:
#   $1  owner/repo
#   $2  The commit SHA
#
# Returns:
#   0  a workflow run for the commit is still going
#   1  none is, or there are none
#   2  the runs could not be read
cwm_pr_runs_pending() {
    local repo="$1" sha="$2" json
    json="$(gh run list --repo "$repo" --commit "$sha" --limit 100 --json status 2>/dev/null)" || return 2
    printf '%s' "$json" | php -r '
        $runs = json_decode(stream_get_contents(STDIN), true);
        if (!is_array($runs)) {
            exit(2);
        }
        foreach ($runs as $run) {
            if (($run["status"] ?? "") !== "completed") {
                exit(0);
            }
        }
        exit(1);'
}

# The merge commit SHA from `gh pr view --json mergeCommit` output.
#
# Arguments:
#   $1  JSON with a mergeCommit object
#
# Prints:
#   The SHA, or nothing when the pull request has not been merged.
cwm_pr_merge_commit() {
    printf '%s' "$1" | php -r '
        $pr = json_decode(stream_get_contents(STDIN), true);
        echo is_array($pr) ? ($pr["mergeCommit"]["oid"] ?? "") : "";'
    echo
}

# Wait for a release pull request to pass, merge it, and print the merge commit.
#
# Squash-merges, pinned with --match-head-commit, so what merges is exactly the
# commit whose checks passed. The branch is not deleted here: gh's
# --delete-branch also switches and deletes the local branch, and the caller
# decides what the checkout does next.
#
# Three things guard the moment of merging, because a merge here is followed
# straight away by a tag and a publish:
#   - "ready" has to hold on two polls in a row, so a check that registers a
#     moment late is seen;
#   - no GitHub Actions run for the head commit may still be going, which
#     covers jobs held back by `needs:` (cwm_pr_runs_pending);
#   - BLOCKED is taken as final only once it has lasted the grace period.
#     Required checks that haven't registered yet leave a pull request
#     BLOCKED, with everything reported so far green, for its first minute.
#
# Polls every CWM_PR_POLL_SECONDS (20), gives a new pull request
# CWM_PR_GRACE_SECONDS (90) to report its checks, and gives up after
# CWM_PR_TIMEOUT_SECONDS (5400; Proclaim's full CI takes about 15 minutes).
#
# Arguments:
#   $1  owner/repo
#   $2  Pull request number
#   $3  The head commit the release run pushed
#
# Prints:
#   The merge commit's SHA on stdout. Progress and reasons go to stderr.
#
# Returns:
#   0 when merged, 1 otherwise. A failure never closes or edits the pull
#   request: it stays where it is for a person to look at.
cwm_pr_wait_and_merge() {
    local repo="$1" number="$2" head="$3"
    local poll="${CWM_PR_POLL_SECONDS:-20}"
    local grace="${CWM_PR_GRACE_SECONDS:-90}"
    local timeout="${CWM_PR_TIMEOUT_SECONDS:-5400}"
    local waited=0 json verdict sha streak=0 blocked_since="" runs_rc

    while :; do
        if ! json="$(gh pr view "$number" --repo "$repo" --json state,headRefOid,mergeStateStatus,statusCheckRollup,mergeCommit 2>/dev/null)"; then
            echo "  Could not read pull request #${number}." >&2
            return 1
        fi
        verdict="$(cwm_pr_verdict "$json" "$head" "$waited" "$grace")"

        if [ "$verdict" = "ready" ]; then
            runs_rc=0
            cwm_pr_runs_pending "$repo" "$head" || runs_rc=$?
            if [ "$runs_rc" = "0" ]; then
                verdict="pending"
            fi
        fi
        if [ "$verdict" = "ready" ]; then
            streak=$((streak + 1))
            if [ "$streak" -lt 2 ]; then
                verdict="pending"
            fi
        else
            streak=0
        fi
        if [ "$verdict" = "blocked" ]; then
            if [ -z "$blocked_since" ]; then
                blocked_since="$waited"
            fi
            if [ $((waited - blocked_since)) -lt "$grace" ]; then
                verdict="pending"
            fi
        else
            blocked_since=""
        fi

        case "$verdict" in
            merged)
                echo "  Pull request #${number} is already merged." >&2
                sha="$(cwm_pr_merge_commit "$json")"
                [ -n "$sha" ] || return 1
                printf '%s\n' "$sha"
                return 0
                ;;
            ready)
                echo "  Every check passed. Merging pull request #${number}..." >&2
                if ! gh pr merge "$number" --repo "$repo" --squash --match-head-commit "$head" >&2; then
                    echo "  GitHub refused the merge." >&2
                    return 1
                fi
                json="$(gh pr view "$number" --repo "$repo" --json state,mergeCommit 2>/dev/null)" || return 1
                sha="$(cwm_pr_merge_commit "$json")"
                [ -n "$sha" ] || return 1
                printf '%s\n' "$sha"
                return 0
                ;;
            pending)
                ;;
            failed:*)
                echo "  Pull request #${number}: these checks failed: ${verdict#failed:}." >&2
                return 1
                ;;
            closed)
                echo "  Pull request #${number} was closed without merging." >&2
                return 1
                ;;
            moved)
                echo "  Pull request #${number}'s branch moved. It no longer holds the commit this run pushed." >&2
                return 1
                ;;
            conflict)
                echo "  Pull request #${number} no longer merges cleanly." >&2
                return 1
                ;;
            behind)
                echo "  Pull request #${number} is behind its base, and a rule requires it to be up to date." >&2
                return 1
                ;;
            blocked)
                echo "  Every check on pull request #${number} passed, but GitHub won't merge it: a required review, or a rule this run can't satisfy." >&2
                return 1
                ;;
            *)
                echo "  Pull request #${number} is in a state this run doesn't know: ${verdict}." >&2
                return 1
                ;;
        esac

        if [ "$waited" -ge "$timeout" ]; then
            echo "  Gave up waiting for pull request #${number} after ${waited}s." >&2
            return 1
        fi
        if [ "$waited" -gt 0 ] && [ $((waited % 300)) -lt "$poll" ]; then
            echo "  Still waiting for pull request #${number}'s checks ($((waited / 60)) min)..." >&2
        fi
        sleep "$poll"
        # At least 1, so a zero poll interval (the tests use one) still
        # counts towards the grace period and the timeout.
        waited=$((waited + (poll > 0 ? poll : 1)))
    done
}

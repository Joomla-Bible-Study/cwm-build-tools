#!/usr/bin/env bash
#
# Tests for scripts/lib/pr.sh: the decisions `release.sh --pr` rests on.
#
# The one that matters most is when a release pull request may be merged. A
# merge too early releases a commit nothing checked, and GitHub's own
# mergeStateStatus can't be trusted to say: with no required checks it reports
# CLEAN while CI is still running. So most cases below are about checks.
#
# gh is a stub that answers from files, so no network is used.

set -uo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=helpers.sh
source "${SCRIPT_DIR}/helpers.sh"
# shellcheck source=../../scripts/lib/pr.sh
source "${SCRIPT_DIR}/../../scripts/lib/pr.sh"

WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT

export GIT_CONFIG_GLOBAL="${WORK}/gitconfig"
export GIT_CONFIG_NOSYSTEM=1
git config --global user.name "PR Test"
git config --global user.email "pr-test@example.invalid"
git config --global init.defaultBranch main

HEAD_SHA="1111111111111111111111111111111111111111"

# pr_json <state> <head> <mergeStateStatus> <statusCheckRollup JSON array>
pr_json() {
    printf '{"state":"%s","headRefOid":"%s","mergeStateStatus":"%s","statusCheckRollup":%s}' "$1" "$2" "$3" "$4"
}

run_ok='{"__typename":"CheckRun","name":"PHP 8.4","status":"COMPLETED","conclusion":"SUCCESS"}'
run_skip='{"__typename":"CheckRun","name":"Nightly","status":"COMPLETED","conclusion":"SKIPPED"}'
run_neutral='{"__typename":"CheckRun","name":"Advice","status":"COMPLETED","conclusion":"NEUTRAL"}'
run_busy='{"__typename":"CheckRun","name":"E2E","status":"IN_PROGRESS","conclusion":""}'
run_bad='{"__typename":"CheckRun","name":"PHP 8.5","status":"COMPLETED","conclusion":"FAILURE"}'
status_ok='{"__typename":"StatusContext","context":"ci/legacy","state":"SUCCESS"}'
status_wait='{"__typename":"StatusContext","context":"ci/legacy","state":"PENDING"}'
status_err='{"__typename":"StatusContext","context":"ci/legacy","state":"ERROR"}'

verdict() {
    cwm_pr_verdict "$1" "$HEAD_SHA" "${2:-0}" "${3:-90}"
}

# --- When a pull request may be merged -------------------------------------------
assert_equals "ready" "$(verdict "$(pr_json OPEN "$HEAD_SHA" CLEAN "[$run_ok]")")" \
    "every check passed and GitHub says CLEAN: merge"
assert_equals "pending" "$(verdict "$(pr_json OPEN "$HEAD_SHA" CLEAN "[$run_ok,$run_busy]")")" \
    "CLEAN while a check still runs is not ready: with no required checks GitHub says CLEAN early"
assert_equals "ready" "$(verdict "$(pr_json OPEN "$HEAD_SHA" CLEAN "[$run_ok,$run_skip,$run_neutral]")")" \
    "skipped and neutral checks count as passing"
assert_equals "ready" "$(verdict "$(pr_json OPEN "$HEAD_SHA" CLEAN "[$run_ok,$run_ok]")")" \
    "a check reported twice under one name is still one passing check"
assert_equals "pending" "$(verdict "$(pr_json OPEN "$HEAD_SHA" CLEAN "[$status_wait]")")" \
    "a pending commit status keeps it waiting"
assert_equals "ready" "$(verdict "$(pr_json OPEN "$HEAD_SHA" CLEAN "[$status_ok,$run_ok]")")" \
    "commit statuses and check runs are judged together"

assert_equals "failed:PHP 8.5" "$(verdict "$(pr_json OPEN "$HEAD_SHA" UNSTABLE "[$run_ok,$run_bad]")")" \
    "a failed check run is reported by name"
assert_equals "failed:ci/legacy" "$(verdict "$(pr_json OPEN "$HEAD_SHA" CLEAN "[$status_err]")")" \
    "an errored commit status is a failure too"
assert_equals "failed:PHP 8.5" "$(verdict "$(pr_json OPEN "$HEAD_SHA" CLEAN "[$run_busy,$run_bad]")")" \
    "a failure is reported without waiting for the rest"

assert_equals "pending" "$(verdict "$(pr_json OPEN "$HEAD_SHA" CLEAN "[]")" 10 90)" \
    "no checks yet, inside the grace period: they may not have registered"
assert_equals "ready" "$(verdict "$(pr_json OPEN "$HEAD_SHA" CLEAN "[]")" 90 90)" \
    "no checks after the grace period: a repository without CI"
assert_equals "blocked" "$(verdict "$(pr_json OPEN "$HEAD_SHA" BLOCKED "[$run_ok]")")" \
    "every check passed and GitHub still blocks it: a review or a rule this run can't meet"
assert_equals "conflict" "$(verdict "$(pr_json OPEN "$HEAD_SHA" DIRTY "[$run_ok]")")" "a conflict"
assert_equals "behind" "$(verdict "$(pr_json OPEN "$HEAD_SHA" BEHIND "[$run_ok]")")" \
    "behind a base that a rule requires it to be up to date with"
assert_equals "pending" "$(verdict "$(pr_json OPEN "$HEAD_SHA" UNKNOWN "[$run_ok]")")" \
    "GitHub still working out mergeability"

assert_equals "moved" "$(verdict "$(pr_json OPEN 2222222222222222222222222222222222222222 CLEAN "[$run_ok]")")" \
    "a head that is no longer the commit the run pushed is never merged"
assert_equals "merged" "$(verdict "$(pr_json MERGED "$HEAD_SHA" UNKNOWN "[$run_ok]")")" "already merged"
assert_equals "closed" "$(verdict "$(pr_json CLOSED "$HEAD_SHA" UNKNOWN "[$run_ok]")")" "closed"

# --- Pull request numbers and merge commits ---------------------------------------
assert_equals "12" "$(cwm_pr_number_from_url https://github.com/owner/repo/pull/12)" "the number from a URL"
assert_equals "12" "$(cwm_pr_number_from_url https://github.com/owner/repo/pull/12/)" "with a trailing slash"
assert_equals "abc123" "$(cwm_pr_merge_commit '{"mergeCommit":{"oid":"abc123"}}')" "the merge commit"
assert_equals "" "$(cwm_pr_merge_commit '{"mergeCommit":null}')" "nothing before a merge"

# --- Whether a branch requires a pull request ---------------------------------------
mkdir -p "${WORK}/bin"
cat > "${WORK}/bin/gh" <<STUB
#!/usr/bin/env bash
# gh api repos/<o>/<r>/rules/branches/<branch>: answered from rules.<branch>.
case "\$1 \$2" in
    "api repos/"*)
        f="${WORK}/rules.\${2##*/}"
        [ -f "\$f" ] || exit 1
        cat "\$f"
        ;;
    *) exit 1 ;;
esac
STUB
chmod +x "${WORK}/bin/gh"
PATH="${WORK}/bin:${PATH}"

echo '[{"type":"deletion"},{"type":"pull_request","parameters":{}}]' > "${WORK}/rules.main"
echo '[{"type":"deletion"},{"type":"non_fast_forward"},{"type":"update"}]' > "${WORK}/rules.release"
cwm_branch_requires_pr owner/repo main; rc=$?
assert_equals "0" "$rc" "a pull_request rule means a pull request is required"
cwm_branch_requires_pr owner/repo release; rc=$?
assert_equals "1" "$rc" "admin-only updates are not a pull request rule"
cwm_branch_requires_pr owner/repo unknown; rc=$?
assert_equals "2" "$rc" "rules that can't be read are reported as unknown, not as none"
echo 'not json' > "${WORK}/rules.broken"
cwm_branch_requires_pr owner/repo broken; rc=$?
assert_equals "2" "$rc" "an answer that isn't JSON is unknown too"

# --- Which uncommitted files a release may carry -------------------------------------
repo="${WORK}/tree"
mkdir -p "${repo}/build"
(
    cd "$repo" \
        && git init -q \
        && echo '<changelogs/>' > build/changelog.xml \
        && echo 'code' > code.php \
        && git add -A \
        && git commit -qm initial
)
dirty() {
    (cd "$repo" && cwm_unexpected_dirty_paths "build/changelog.xml" "build/notes/release-notes-1.2.3.md")
}
assert_equals "" "$(dirty)" "a clean tree has nothing unexpected"

echo '<changelogs><changelog/></changelogs>' > "${repo}/build/changelog.xml"
mkdir -p "${repo}/build/notes"
echo 'Notes.' > "${repo}/build/notes/release-notes-1.2.3.md"
assert_equals "" "$(dirty)" "the changelog and this version's notes, new directory and all, are allowed"

echo 'Older notes.' > "${repo}/build/notes/release-notes-1.2.2.md"
assert_equals "build/notes/release-notes-1.2.2.md" "$(dirty)" "another version's notes are not"

rm "${repo}/build/notes/release-notes-1.2.2.md"
echo 'changed' >> "${repo}/code.php"
assert_equals "code.php" "$(dirty)" "nor is a change to anything else"

# --- Waiting for checks, then merging ---------------------------------------------------
# view.N answers the Nth `gh pr view`; `gh pr merge` is recorded and flips the
# answer to merged.
cat > "${WORK}/bin/gh" <<STUB
#!/usr/bin/env bash
log="${WORK}/calls"
case "\$1 \$2" in
    "pr view")
        n=\$(( \$(cat "${WORK}/views" 2>/dev/null || echo 0) + 1 ))
        echo "\$n" > "${WORK}/views"
        if [ -f "${WORK}/merged" ]; then
            echo '{"state":"MERGED","mergeCommit":{"oid":"feedface"}}'
        elif [ -f "${WORK}/view.\$n" ]; then
            cat "${WORK}/view.\$n"
        else
            cat "${WORK}/view.last"
        fi
        ;;
    "pr merge")
        echo "merge \$*" >> "\$log"
        touch "${WORK}/merged"
        ;;
    *) exit 1 ;;
esac
STUB
reset_pr() {
    rm -f "${WORK}/views" "${WORK}/view."* "${WORK}/merged" "${WORK}/calls"
}
export CWM_PR_POLL_SECONDS=0 CWM_PR_GRACE_SECONDS=0 CWM_PR_TIMEOUT_SECONDS=50

reset_pr
pr_json OPEN "$HEAD_SHA" CLEAN "[$run_busy]" > "${WORK}/view.1"
pr_json OPEN "$HEAD_SHA" CLEAN "[$run_ok]" > "${WORK}/view.last"
sha="$(cwm_pr_wait_and_merge owner/repo 7 "$HEAD_SHA" 2>/dev/null)"; rc=$?
assert_equals "0" "$rc" "a pull request whose checks pass is merged"
assert_equals "feedface" "$sha" "and the merge commit is printed"
assert_contains "$(cat "${WORK}/calls")" "--match-head-commit ${HEAD_SHA}" "the merge is pinned to the commit that was checked"
assert_contains "$(cat "${WORK}/calls")" "--squash" "and squashed"

reset_pr
pr_json OPEN "$HEAD_SHA" UNSTABLE "[$run_bad]" > "${WORK}/view.last"
out="$(cwm_pr_wait_and_merge owner/repo 7 "$HEAD_SHA" 2>&1 >/dev/null)"; rc=$?
assert_equals "1" "$rc" "failed checks are never merged"
assert_contains "$out" "PHP 8.5" "and the failure is named"
assert_equals "0" "$([ -e "${WORK}/calls" ] && echo 1 || echo 0)" "gh pr merge was not called"

reset_pr
pr_json OPEN "$HEAD_SHA" CLEAN "[$run_busy]" > "${WORK}/view.last"
out="$(cwm_pr_wait_and_merge owner/repo 7 "$HEAD_SHA" 2>&1 >/dev/null)"; rc=$?
assert_equals "1" "$rc" "a check that never finishes times out"
assert_contains "$out" "Gave up waiting" "and says so"
assert_equals "0" "$([ -e "${WORK}/calls" ] && echo 1 || echo 0)" "nothing was merged"

reset_pr
pr_json OPEN 2222222222222222222222222222222222222222 CLEAN "[$run_ok]" > "${WORK}/view.last"
cwm_pr_wait_and_merge owner/repo 7 "$HEAD_SHA" >/dev/null 2>&1; rc=$?
assert_equals "1" "$rc" "a branch pushed to after the run is not merged"

reset_pr
touch "${WORK}/merged"
sha="$(cwm_pr_wait_and_merge owner/repo 7 "$HEAD_SHA" 2>/dev/null)"; rc=$?
assert_equals "0" "$rc" "a pull request someone else merged counts as merged"
assert_equals "feedface" "$sha" "with its merge commit"

finish

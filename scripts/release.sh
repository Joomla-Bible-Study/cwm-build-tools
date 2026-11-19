#!/usr/bin/env bash
#
# Generic 9-step release pipeline for a CWM Joomla extension.
#
# Reads cwm-build.config.json from the current working directory. All
# project-specific values (manifest paths, ARS endpoint, GitHub repo)
# come from there.
#
# Steps:
#   1. Version bump across all manifests in cwm-build.config.json
#   2. Substitute __DEPLOY_VERSION__ placeholder in source (if configured)
#   3. Run the project's build command
#   4. Commit version bump and push
#   5. Create GitHub release with built zip(s) attached
#   6. Generate changelog entry (if generator is configured)
#   7. Publish to ARS
#   8. Update versions.json (if configured)
#   9. Post CWM release announcement article (optional, skipped if no bullets file)
#
# Usage:
#   release.sh 1.2.3                # release specific version
#   release.sh 1.2.3-beta1          # pre-release
#   release.sh                      # prompts for version
#   release.sh 1.2.3 --dry-run      # print the plan, change nothing
#   release.sh 1.2.3 --skip-tests   # release without the test:release gate
#
# Dry run:
#   --dry-run (-n) walks the whole pipeline and writes nothing: no manifest
#   bump, no build, no commit, tag, push, GitHub release, ARS publish or
#   announcement article. Every mutating command is printed instead of run.
#
#   The read-only checks still happen, because they are most of what can be
#   wrong: the version is validated, the config is read, the artifact glob is
#   resolved against what is already on disk, and divergence from origin is
#   reported. The two pre-conditions a real run refuses to start without — the
#   right branch and a clean tree — are reported as warnings rather than
#   errors, since wanting to know what a release *would* do before tidying up
#   is the normal reason to ask. The test:release gate (below) is a read-only
#   check by this definition too — it verifies, it doesn't write release
#   artifacts — so it runs under --dry-run as well.
#
# Test gate:
#   If the project's composer.json defines a `test:release` script, it runs
#   as a pre-flight step and a failure stops the release before anything is
#   bumped, built, or published. Projects without that script are unaffected
#   — the gate is opt-in by presence, not a hard requirement of every CWM
#   project. Pass --skip-tests to release anyway (an emergency hotfix where
#   the suite's own environment is the thing broken, say); the flag exists
#   so that's a deliberate, visible choice rather than a reason to touch this
#   script. See lib/testgate.sh.
#
#   The gate is handed CWM_RELEASE_VERSION — the version about to ship. It
#   runs before the bump, so nothing on disk names that version yet, and a
#   test phase that derives it from versions.json instead reads a stale
#   pointer and can quietly decide it has nothing to do (#101). Read the
#   variable, not the file.
#
# Tag-only (--tag-only):
#   For a version with nothing worth publishing on its own, such as a
#   dependency or changelog-only release that consumers pick up by pinning the
#   tag. Steps 1-4 run as usual, so placeholders are substituted and the built
#   artifact passes the token gate. Step 6 then tags the release commit and
#   pushes the tag, never with --force. Nothing is published: no GitHub release
#   (5), no ARS publish (7), no versions.json update (8), no announcement (9)
#   and no post-flight stream check. Pre-flight refuses when the changelog has
#   no entry for the version, since there is no GitHub release to generate one
#   from, and when the tag already exists. Before this flag such releases were
#   cut by hand, and the hand path skipped step 2 and the gate (#176).
#
# Pull requests (--pr, or release.viaPullRequest in cwm-build.config.json):
#   For a branch a ruleset protects. A plain run pushes its release commits
#   straight to the branch, and on a branch that requires pull requests that
#   push either fails or, for a bypass actor, goes through by bypassing the
#   rule. So a plain run now refuses such a branch, and --pr releases through
#   pull requests instead (lib/pr.sh):
#
#     - Steps 1-3 run on chore/release-vX.Y.Z, which becomes the release
#       commit and a pull request into the branch the release commit belongs
#       on: github.developmentBranch when the project has one, else the
#       release branch. The run waits for every check to pass and
#       squash-merges it.
#     - The merged commit is rebuilt and must reproduce: nothing tracked may
#       change. For a project with a development branch, the release branch
#       is then fast-forwarded to it, which needs a rule that lets admins
#       update it, not one that requires a pull request.
#     - Steps 5-7 tag and publish that exact commit. The changelog entry has
#       to be in the release commit, since there is no published release to
#       generate one from.
#     - Step 8's versions.json update goes through a pull request of its own.
#
#   This version's notes file and the changelog may stay uncommitted when the
#   run starts; the release commit carries them, so one pull request holds the
#   whole release. Anything else uncommitted stops the run. A run interrupted
#   while waiting resumes on the open pull request, and one whose pull request
#   was merged by hand finds the release commit in place and carries on.
#
# Prerequisites:
#   - Clean working tree, on the configured release branch (default: main)
#   - gh CLI authenticated
#   - 1Password CLI (op) authenticated, if ARS publish needs it
#
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
TOOLS_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"

# shellcheck source=lib/artifacts.sh
source "${SCRIPT_DIR}/lib/artifacts.sh"
# shellcheck source=lib/version.sh
source "${SCRIPT_DIR}/lib/version.sh"
# shellcheck source=lib/notes.sh
source "${SCRIPT_DIR}/lib/notes.sh"
# shellcheck source=lib/dryrun.sh
source "${SCRIPT_DIR}/lib/dryrun.sh"
# shellcheck source=lib/testgate.sh
source "${SCRIPT_DIR}/lib/testgate.sh"
# shellcheck source=lib/pr.sh
source "${SCRIPT_DIR}/lib/pr.sh"
PROJECT_ROOT="$(pwd)"

# --- Parse flags ---
#
# Flags are pulled out wherever they appear, so `release.sh --dry-run 1.2.3`
# and `release.sh 1.2.3 --dry-run --skip-tests` both work; what remains is the
# positional version argument the rest of the script already expects. Parsing
# and the command wrapper live in lib/dryrun.sh so they can be tested.
usage() {
    cat <<'USAGE'
cwm-release — full release pipeline for a CWM Joomla extension.

Usage:
  composer release -- <version> [--dry-run|-n] [--skip-tests] [--tag-only] [--pr]
  composer release                  # prompts for the version

Options:
  -n, --dry-run     Print every mutating command instead of running it.
  --skip-tests      Skip the composer test:release gate.
  --tag-only        Bump, substitute, build, gate, commit and push a new tag.
                    Publish nothing: no GitHub release, ARS item,
                    versions.json update or announcement.
  --pr              Release through pull requests, for a branch a ruleset
                    protects: the release commit is a pull request that is
                    merged once every check passes, then the merged commit is
                    tagged and published, and versions.json follows in a pull
                    request of its own. release.viaPullRequest in
                    cwm-build.config.json turns it on for every run.
  -h, --help        Show this and exit.
USAGE
}

if ! cwm_parse_dry_run "$@"; then
    echo "Error: unknown option '${CWM_BAD_FLAG}'." >&2
    echo >&2
    usage >&2
    exit 1
fi

if [ "$CWM_HELP" = "1" ]; then
    usage
    exit 0
fi

DRY_RUN="$CWM_DRY_RUN"
SKIP_TESTS="$CWM_SKIP_TESTS"
TAG_ONLY="$CWM_TAG_ONLY"
PR_FLAG="$CWM_PR"
set -- "${CWM_ARGS[@]+"${CWM_ARGS[@]}"}"

CONFIG_FILE="${PROJECT_ROOT}/cwm-build.config.json"

if [ ! -f "$CONFIG_FILE" ]; then
    echo "Error: $CONFIG_FILE not found"
    exit 1
fi

# --- Helpers ---
read_config() {
    php -r "\$c = json_decode(file_get_contents('${CONFIG_FILE}'), true); \$keys = explode('.', '$1'); \$v = \$c; foreach (\$keys as \$k) { \$v = \$v[\$k] ?? null; if (\$v === null) break; } echo \$v ?? '';"
}

RELEASE_BRANCH=$(read_config "github.releaseBranch")
RELEASE_BRANCH="${RELEASE_BRANCH:-main}"

GH_OWNER=$(read_config "github.owner")
GH_REPO=$(read_config "github.repo")
PKG_NAME=$(read_config "extension.name")
PKG_MANIFEST=$(read_config "manifests.package")
DEV_BRANCH=$(read_config "github.developmentBranch")
GH_SLUG="${GH_OWNER}/${GH_REPO}"

# The project-relative path to versions.json, when the project tracks one. Read
# here rather than at step 8 because the pre-flight below needs to know whether
# step 8 will write anywhere.
VERSIONS_FILE=$(php "${TOOLS_DIR}/scripts/resolve-tracking.php" versionsJson)

# --pr, or release.viaPullRequest in the config: the release commit and the
# versions.json update reach their branches through pull requests. See the
# header and lib/pr.sh.
PR_MODE=0
case "${PR_FLAG}:$(read_config "release.viaPullRequest")" in
    1:*|*:1|*:true) PR_MODE=1 ;;
esac

# The branch the release commit goes into. A plain run commits on the release
# branch. In PR mode it is the development branch when the project has one: the
# release commit merges there like any other change, and the release branch is
# fast-forwarded to it afterwards.
TARGET_BRANCH="$RELEASE_BRANCH"
if [ "$PR_MODE" = "1" ] && [ -n "$DEV_BRANCH" ]; then
    TARGET_BRANCH="$DEV_BRANCH"
fi

# PR-mode state, filled in as the run goes.
REL_PR_BRANCH=""
RESUME_PR=""
RESUME_HEAD=""
RELEASE_SHA=""
VERSIONS_OWED=""

# --- Pre-checks ---
#
# Under --dry-run these are reported rather than enforced. Both describe the
# state of the tree rather than the correctness of the release, and wanting to
# see what a release would do before tidying up is the ordinary reason to ask
# for a dry run in the first place.
if [ "$DRY_RUN" = "1" ]; then
    echo "=== DRY RUN — no files, commits, tags, releases or publishes will be made ==="
    echo ""
fi

BRANCH=$(git branch --show-current)
if [ "$BRANCH" != "$TARGET_BRANCH" ]; then
    if [ "$DRY_RUN" = "1" ]; then
        echo "  [dry-run] WARNING: on '$BRANCH', not the branch the release commit goes into, '$TARGET_BRANCH'."
        echo "            A real run would stop here."
    else
        if [ "$TARGET_BRANCH" != "$RELEASE_BRANCH" ]; then
            echo "Error: With --pr the release commit goes into $TARGET_BRANCH, so run it from there (currently on '$BRANCH')."
        else
            echo "Error: Must be on $TARGET_BRANCH branch (currently on '$BRANCH')."
        fi
        echo "Switch with: git checkout $TARGET_BRANCH && git pull"
        exit 1
    fi
fi

if [ "$PR_MODE" = "1" ]; then
    # Two files may stay uncommitted in PR mode: the changelog and this
    # version's notes file. The release commit carries them, so one pull
    # request holds the whole release. The notes file is named after the
    # version, so it has to be known before the tree is judged.
    if [ -z "${1:-}" ]; then
        echo "Error: --pr needs the version as an argument: composer release -- <version> --pr"
        exit 1
    fi
    PR_NOTES_PATH=""
    PR_NOTES_PATTERN=$(read_config "release.notesFile")
    if [ -n "$PR_NOTES_PATTERN" ]; then
        PR_NOTES_PATH="${PR_NOTES_PATTERN//\{version\}/$1}"
    fi
    UNEXPECTED_CHANGES=$(cwm_unexpected_dirty_paths "$(read_config "changelog.file")" "$PR_NOTES_PATH")
    if [ -n "$UNEXPECTED_CHANGES" ]; then
        if [ "$DRY_RUN" = "1" ]; then
            echo "  [dry-run] WARNING: uncommitted changes besides the changelog and this version's notes."
            echo "            A real run would stop here:"
        else
            echo "Error: Uncommitted changes the release commit can't carry. With --pr, only the changelog"
            echo "       and this version's notes file may be uncommitted. Commit or stash these first:"
        fi
        printf '%s\n' "$UNEXPECTED_CHANGES" | sed 's/^/    /'
        if [ "$DRY_RUN" != "1" ]; then
            exit 1
        fi
    fi
elif [ -n "$(git status --porcelain)" ]; then
    if [ "$DRY_RUN" = "1" ]; then
        echo "  [dry-run] WARNING: working tree is not clean. A real run would stop here."
        echo "            It matters beyond tidiness: step 4 commits with 'git add -A',"
        echo "            and relies on this check having proved the tree was empty first."
    else
        echo "Error: Working tree is not clean. Commit or stash changes first."
        exit 1
    fi
fi

# --- Pre-flight: sync with origin ---
# Catches the "I forgot to pull" failure mode where the release builds from
# stale parent state, and the "submodule working tree on a different commit
# than recorded" failure mode where the package silently embeds an unintended
# snapshot. Both happened during the Proclaim 10.3.2 release pass (issue #6).
echo "[pre-flight] Fetching origin..."
# Fetching only updates remote-tracking refs, so it runs even under --dry-run:
# without it there is nothing current to compare against, and reporting
# divergence is one of the more useful things a dry run can tell you.
git fetch origin --prune --tags

if [ "$DRY_RUN" = "1" ]; then
    # Report what a fast-forward pull would do, without moving anything.
    echo "[pre-flight] Comparing ${TARGET_BRANCH} with origin (no pull under --dry-run)..."
    BEHIND=$(git rev-list --count "HEAD..origin/${TARGET_BRANCH}" 2>/dev/null || echo "?")
    AHEAD=$(git rev-list --count "origin/${TARGET_BRANCH}..HEAD" 2>/dev/null || echo "?")

    if [ "$BEHIND" = "0" ] && [ "$AHEAD" = "0" ]; then
        echo "  In sync with origin/${TARGET_BRANCH}."
    elif [ "$AHEAD" != "0" ] && [ "$BEHIND" != "0" ]; then
        echo "  WARNING: diverged from origin/${TARGET_BRANCH} (${AHEAD} ahead, ${BEHIND} behind)."
        echo "           A real run would stop here."
    elif [ "$BEHIND" != "0" ]; then
        echo "  ${BEHIND} commit(s) behind origin/${TARGET_BRANCH}; a real run would fast-forward."
    else
        echo "  ${AHEAD} unpushed commit(s) on ${TARGET_BRANCH}."
    fi

    cwm_skip_in_dry_run "git submodule update (would move submodule working trees)"
else
    echo "[pre-flight] Pulling ${TARGET_BRANCH} (fast-forward only)..."
    if ! git pull --ff-only origin "$TARGET_BRANCH"; then
        echo ""
        echo "Error: Local ${TARGET_BRANCH} has diverged from origin/${TARGET_BRANCH}."
        echo "Resolve manually before running release."
        echo "  Inspect: git status && git log @{u}.."
        echo "  Rebase:  git pull --rebase origin ${TARGET_BRANCH}"
        exit 1
    fi

    # `submodule update` is a no-op when no submodules are configured.
    echo "[pre-flight] Syncing submodules to recorded pointers..."
    git submodule update --init --recursive
fi

# Warn (don't block) when a submodule pointer isn't at a tagged release commit.
# Shipping an untagged snapshot is sometimes intentional, but it should be a
# deliberate choice — surface it loudly.
if [ -f .gitmodules ]; then
    git submodule foreach --quiet '
        ptr=$(git rev-parse HEAD)
        if ! git describe --exact-match --tags "$ptr" >/dev/null 2>&1; then
            short=$(echo "$ptr" | cut -c1-8)
            echo "  WARNING: submodule $name pointer ${short} is not at a tagged release."
            echo "           The package will embed a snapshot, not a tagged version."
            echo "           If this is unintentional, release the submodule first."
        fi
    '
fi
echo ""

# --- Get version ---
#
# Resolved before the gate below, not after, so the gate can be told which
# version is about to ship (#101). Everything here reads — the manifest for the
# prompt hint, the argument or the terminal for the answer, lib/version.sh to
# validate it — so the gate's "nothing has been mutated yet" property survives
# the move intact. It also means the two version strings the pipeline refuses
# outright (empty, and -dev) now cost a second rather than a full acceptance
# suite.
if [ -n "${1:-}" ]; then
    VERSION="$1"
else
    if [ -n "$PKG_MANIFEST" ]; then
        CURRENT=$(cwm_version_from_manifest "$PKG_MANIFEST" || true)
        echo "Current package version: ${CURRENT:-unknown}"
    fi
    printf "Enter new version (e.g., 1.2.3): "
    read -r VERSION
    # Separate the prompt from the gate's output below. Only on this path —
    # when the version came in as an argument this block prints nothing, and
    # the preceding pre-flight already left a blank line.
    echo ""
fi

# Validation, tag and pre-release derivation live in lib/version.sh so
# they can be tested — see #52.
cwm_validate_release_version "$VERSION" || exit 1

TAG=$(cwm_tag_for_version "$VERSION")
PRERELEASE_FLAG=$(cwm_prerelease_flag "$VERSION")

# --- Pre-flight: release gate (composer test:release) ---
# Verifies the commit about to be released, not the commit that results from
# bumping/building it — so this runs before step 1, against what's on disk
# right now. The version above is handed to it as CWM_RELEASE_VERSION, since
# nothing on disk names it yet. See lib/testgate.sh for both.
if [ "$SKIP_TESTS" = "1" ]; then
    echo "[pre-flight] Skipping composer test:release (--skip-tests)."
elif ! cwm_has_test_release_script "${PROJECT_ROOT}/composer.json"; then
    echo "[pre-flight] No composer test:release script — skipping pre-release verification."
else
    echo "[pre-flight] Running composer test:release (CWM_RELEASE_VERSION=${VERSION})..."
    if cwm_run_test_release "$VERSION"; then
        echo "  test:release passed."
    elif [ "$DRY_RUN" = "1" ]; then
        echo "  WARNING: test:release failed. A real run would stop here."
    else
        echo ""
        echo "Error: composer test:release failed. Fix the failure, or pass --skip-tests to release anyway."
        exit 1
    fi
fi

# --- Pre-flight: a changelog entry to go with hand-written notes ---
# Without one, step 6 generates it from the release body, which keeps the
# notes' bullets but not their paragraphs or categories. A warning rather than
# a stop: an entry generated from notes is still better than none.
PREFLIGHT_NOTES=$(cwm_resolve_notes_file "$(read_config "release.notesFile")" "$VERSION" || true)
PREFLIGHT_CHANGELOG=$(read_config "changelog.file")
if [ "$TAG_ONLY" != "1" ] && [ "$PR_MODE" != "1" ] && [ -n "$PREFLIGHT_NOTES" ] && cwm_changelog_needs_entry "$PREFLIGHT_CHANGELOG" "$VERSION"; then
    echo "[pre-flight] WARNING: ${PREFLIGHT_CHANGELOG} has no ${VERSION} entry to go with ${PREFLIGHT_NOTES}."
    echo "  Step 6 would generate one from the notes' bullets alone, without their paragraphs or categories."
    echo "  Write it by hand in the notes PR; the generator skips a version that is already there."
fi

# --- Pre-flight: what a tag-only release needs (#176) ---
# Both refusals come before step 1, where they cost nothing.
#
# The changelog entry must already be committed: step 6 generates one from the
# GitHub release, and a tag-only release has none.
#
# The tag must be new. Step 6 creates it without --force, because moving a tag
# a consumer may already pin is not something to do as a side effect.
if [ "$TAG_ONLY" = "1" ]; then
    echo "[pre-flight] Tag-only release: nothing will be published (no GitHub release, ARS item,"
    echo "             versions.json update or announcement)."

    TAG_ONLY_PROBLEMS=()
    if cwm_changelog_needs_entry "$PREFLIGHT_CHANGELOG" "$VERSION"; then
        TAG_ONLY_PROBLEMS+=("${PREFLIGHT_CHANGELOG} has no ${VERSION} entry. Write it by hand and commit it first: with no GitHub release, step 6 has nothing to generate one from.")
    fi
    if git rev-parse -q --verify "refs/tags/${TAG}" >/dev/null \
        || git ls-remote --exit-code --tags origin "refs/tags/${TAG}" >/dev/null 2>&1; then
        TAG_ONLY_PROBLEMS+=("Tag ${TAG} already exists. A tag-only release creates a new tag and never moves one.")
    fi

    for problem in "${TAG_ONLY_PROBLEMS[@]+"${TAG_ONLY_PROBLEMS[@]}"}"; do
        if [ "$DRY_RUN" = "1" ]; then
            echo "  WARNING: ${problem}"
            echo "           A real run would stop here."
        else
            echo "Error: ${problem}"
        fi
    done
    if [ "${#TAG_ONLY_PROBLEMS[@]}" -gt 0 ] && [ "$DRY_RUN" != "1" ]; then
        exit 1
    fi

    if [ "$(git config --type=bool --get tag.gpgSign 2>/dev/null || true)" != "true" ]; then
        echo "  Note: tag.gpgSign is not set, so ${TAG} will be an unsigned annotated tag."
    fi
fi

# --- Pre-flight: pull requests ---
#
# Everything below reads; nothing is bumped until it has all passed.
if [ "$PR_MODE" = "1" ]; then
    echo "[pre-flight] Releasing through pull requests: the release commit goes into ${TARGET_BRANCH} by pull request."
    PR_PROBLEMS=()

    if [ -z "$GH_OWNER" ] || [ -z "$GH_REPO" ]; then
        PR_PROBLEMS+=("github.owner and github.repo are not set in cwm-build.config.json, and pull requests need them.")
    fi

    # A branch left by an earlier run. Its open pull request is waited on rather
    # than opened again; a merged one means the release commit should already
    # be in place, which step 4 finds.
    REL_PR_BRANCH="chore/release-${TAG}"
    if [ "$DRY_RUN" != "1" ] && git ls-remote --exit-code --heads origin "$REL_PR_BRANCH" >/dev/null 2>&1; then
        RESUME_LINE=$(gh pr list --repo "$GH_SLUG" --head "$REL_PR_BRANCH" --state all \
            --json number,state,headRefOid 2>/dev/null | php -r '
                $prs = json_decode(stream_get_contents(STDIN), true) ?: [];
                usort($prs, fn ($a, $b) => ($b["number"] ?? 0) <=> ($a["number"] ?? 0));
                $pr = $prs[0] ?? null;
                echo $pr ? $pr["number"] . " " . $pr["state"] . " " . $pr["headRefOid"] : "";' || true)
        RESUME_STATE=""
        read -r RESUME_PR RESUME_STATE RESUME_HEAD <<<"$RESUME_LINE" || true
        case "$RESUME_STATE" in
            OPEN)
                echo "  Resuming: pull request #${RESUME_PR} for ${REL_PR_BRANCH} is open, so this run waits for it."
                ;;
            MERGED)
                echo "  Pull request #${RESUME_PR} for ${REL_PR_BRANCH} is already merged."
                RESUME_PR=""
                RESUME_HEAD=""
                ;;
            *)
                PR_PROBLEMS+=("${REL_PR_BRANCH} exists on origin with no open pull request. Delete it (git push origin --delete ${REL_PR_BRANCH}) and run again.")
                RESUME_PR=""
                RESUME_HEAD=""
                ;;
        esac
    fi

    # The entry has to reach the release commit: with no published release yet,
    # step 6 has nothing to generate one from. It may stay uncommitted. A
    # resumed pull request already holds it.
    if [ -z "$RESUME_PR" ] && cwm_changelog_needs_entry "$PREFLIGHT_CHANGELOG" "$VERSION"; then
        PR_PROBLEMS+=("${PREFLIGHT_CHANGELOG} has no ${VERSION} entry. Write it by hand; it can stay uncommitted, since the release commit carries it.")
    fi

    if git rev-parse -q --verify "refs/tags/${TAG}" >/dev/null \
        || git ls-remote --exit-code --tags origin "refs/tags/${TAG}" >/dev/null 2>&1; then
        PR_PROBLEMS+=("Tag ${TAG} already exists.")
    fi

    # The release branch is fast-forwarded to the merged release commit. Find
    # out now whether it can be, rather than after a merge that would leave the
    # release commit stranded on the development branch.
    if [ "$TARGET_BRANCH" != "$RELEASE_BRANCH" ]; then
        if ! git merge-base --is-ancestor "origin/${RELEASE_BRANCH}" "origin/${TARGET_BRANCH}" 2>/dev/null; then
            PR_PROBLEMS+=("origin/${RELEASE_BRANCH} has commits origin/${TARGET_BRANCH} doesn't, so the release can't fast-forward it.")
        fi
        RULE_RC=0
        cwm_branch_requires_pr "$GH_SLUG" "$RELEASE_BRANCH" || RULE_RC=$?
        if [ "$RULE_RC" = "0" ]; then
            PR_PROBLEMS+=("${RELEASE_BRANCH} requires a pull request, which can't fast-forward it to ${TARGET_BRANCH}. Give it a rule that lets admins update it (rule type 'update') instead.")
        elif [ "$RULE_RC" = "2" ]; then
            echo "  Note: couldn't read ${RELEASE_BRANCH}'s rules. If it requires a pull request, fast-forwarding it will fail."
        fi
    fi

    for problem in "${PR_PROBLEMS[@]+"${PR_PROBLEMS[@]}"}"; do
        if [ "$DRY_RUN" = "1" ]; then
            echo "  WARNING: ${problem}"
            echo "           A real run would stop here."
        else
            echo "Error: ${problem}"
        fi
    done
    if [ "${#PR_PROBLEMS[@]}" -gt 0 ] && [ "$DRY_RUN" != "1" ]; then
        exit 1
    fi
else
    # A plain run pushes to the release branch (steps 4 and 6), and step 8 to the
    # development branch when versions.json lives there. Where a ruleset requires
    # a pull request, that push either fails or, for one of its bypass actors,
    # goes through by bypassing the rule. Neither is a release anyone meant to
    # make, so stop before step 1 and point at --pr.
    DIRECT_BRANCHES=("$RELEASE_BRANCH")
    if [ "$TAG_ONLY" != "1" ] && [ -n "$VERSIONS_FILE" ] && [ -n "$DEV_BRANCH" ] && [ "$DEV_BRANCH" != "$RELEASE_BRANCH" ]; then
        DIRECT_BRANCHES+=("$DEV_BRANCH")
    fi
    if [ -n "$GH_OWNER" ] && [ -n "$GH_REPO" ]; then
        for direct_branch in "${DIRECT_BRANCHES[@]}"; do
            RULE_RC=0
            cwm_branch_requires_pr "$GH_SLUG" "$direct_branch" || RULE_RC=$?
            if [ "$RULE_RC" = "0" ]; then
                if [ "$DRY_RUN" = "1" ]; then
                    echo "[pre-flight] WARNING: ${direct_branch} requires a pull request, and this run would push to it directly."
                    echo "             A real run would stop here. Release with --pr, or set release.viaPullRequest."
                else
                    echo "Error: ${direct_branch} requires a pull request, and this run would push to it directly."
                    echo "  That push only succeeds by bypassing the ruleset. Release through pull requests instead:"
                    echo "    composer release -- ${VERSION} --pr"
                    echo "  or set \"release\": {\"viaPullRequest\": true} in cwm-build.config.json."
                    exit 1
                fi
            elif [ "$RULE_RC" = "2" ]; then
                echo "[pre-flight] Note: couldn't read ${direct_branch}'s rules from GitHub. If it requires pull requests, release with --pr."
            fi
        done
    fi
fi

echo ""
echo "=== ${PKG_NAME} Release ${VERSION} ==="
echo ""

# Steps 1 and 2 are one function, and step 3 with the token gate another, so
# a --pr release can build a second time from the merged commit without
# bumping again: the bump stamps today's date, and a merge that lands after
# midnight UTC would otherwise change the commit it is meant to reproduce.
release_bump() {
    # --- Step 1: Version bump ---
    echo "[1/9] Bumping version to ${VERSION}..."
    cwm_mutate php "${TOOLS_DIR}/scripts/bump.php" -v "$VERSION"
    echo ""

    # --- Step 2: Substitute __DEPLOY_VERSION__ placeholder ---
    # Replaces the configured placeholder token (default __DEPLOY_VERSION__) with
    # the release version across configured source paths. Joomla core uses this
    # convention for @since PHPDoc tags on in-flight code where the author can't
    # predict the release number. Substitution runs BEFORE the build so the
    # packaged zip carries the real version, not the placeholder.
    echo "[2/9] Substituting __DEPLOY_VERSION__ placeholder..."
    cwm_mutate php "${TOOLS_DIR}/scripts/substitute-tokens.php" -v "$VERSION"
    echo ""
}

release_build() {
    # --- Step 3: Build ---
    echo "[3/9] Building package..."
    BUILD_CMD=$(read_config "build.command")
    if [ -z "$BUILD_CMD" ]; then
        echo "Error: build.command not set in cwm-build.config.json"
        exit 1
    fi
    cwm_mutate bash -c "$BUILD_CMD"

    OUTPUT_GLOB=$(read_config "build.outputGlob")
    if [ -z "$OUTPUT_GLOB" ]; then
        echo "Error: build.outputGlob not set in cwm-build.config.json"
        exit 1
    fi

    # Resolve the artifact for *this* version.
    #
    # The selection lives in lib/artifacts.sh so it can be tested — see #52. It was
    # wrong once already: cwm-release took the first glob match, and bash sorts
    # lexically, so Proclaim 10.3.3 published pkg_proclaim-10.3.2.zip to GitHub and
    # ARS while reporting success.
    if [ "$DRY_RUN" = "1" ]; then
        # No build ran, so the artifact for this version normally will not exist.
        # Still worth attempting: the selection is exactly the step that shipped
        # the wrong zip once, and running it against whatever is already on disk
        # shows which file a real run would pick up — including a stale one left
        # over from a previous release, which is the failure mode #51 describes.
        if ARTIFACT="$(cwm_select_artifact_for_version "$VERSION" "$OUTPUT_GLOB" 2>/dev/null)"; then
            echo "  Would publish (already on disk): ${ARTIFACT}"
        else
            echo "  Would publish: the ${OUTPUT_GLOB} entry matching *-${VERSION}.zip (not built yet)."
        fi
        ARTIFACTS=( "${ARTIFACT:-<unbuilt>}" )
    else
        ARTIFACT="$(cwm_select_artifact_for_version "$VERSION" "$OUTPUT_GLOB")" || exit 1
        ARTIFACTS=( "$ARTIFACT" )
        echo "  Built: ${ARTIFACTS[*]}"
    fi
    echo ""

    # --- Gate: no unsubstituted placeholder tokens in the artifact ---
    #
    # Deliberately placed between build and push. At this point nothing has been
    # committed, pushed, tagged, released or published, so a failure costs a re-run;
    # thirty lines further down it would cost a cleanup.
    #
    # This asks whether the token is in the bytes about to ship, rather than whether
    # `substituteTokens.paths` looks right — the only form of the question that
    # survives a stale vendored copy of these tools (CWMScriptureLinks#29), a path
    # list that never covered a sub-extension (#27), or the installer blind spot
    # that #75 fixed narrowly. See #85.
    if [ "$DRY_RUN" = "1" ]; then
        # No build ran, so this is whatever the artifact selection above found on
        # disk — usually the *previous* release. Scanning it is still informative
        # (same reasoning as that selection block), but a hit describes that older
        # zip, not the release being cut, so it must never fail the run.
        if [ -f "${ARTIFACTS[0]}" ]; then
            echo "[gate] Scanning ${ARTIFACTS[0]} for unsubstituted tokens (previously built — report only)..."
            php "${TOOLS_DIR}/scripts/verify-artifact-tokens.php" -f "${ARTIFACTS[0]}" --warn-only || true
        else
            echo "[gate] Would scan the built artifact for unsubstituted tokens (nothing built under --dry-run)."
        fi
    else
        echo "[gate] Scanning ${ARTIFACTS[0]} for unsubstituted tokens..."
        php "${TOOLS_DIR}/scripts/verify-artifact-tokens.php" -f "${ARTIFACTS[0]}" || exit 1
    fi
    echo ""
}

# In PR mode the release commit is made on its own branch. A resumed run
# already has one, in the open pull request it waits on.
if [ "$PR_MODE" = "1" ] && [ -z "$RESUME_PR" ]; then
    echo "[pr] Making the release commit on ${REL_PR_BRANCH}, for a pull request into ${TARGET_BRANCH}."
    cwm_mutate git switch -C "$REL_PR_BRANCH"
    echo ""
fi
if [ -z "$RESUME_PR" ]; then
    release_bump
    release_build
fi

# --- Step 4: Commit and push ---

# Step 4 as a plain run does it: commit on the release branch and push.
release_commit_direct() {
    echo "[4/9] Committing version bump..."
    # Add only files modified by step 1 + 2, not unrelated untracked files. The
    # bump and build steps may dirty several manifests and rebuild the zip; the
    # release branch was clean before step 1 (pre-check), so anything modified or
    # added here is part of the release.
    cwm_mutate git add -u
    # Pull in any new files the build step generated (e.g. a fresh changelog
    # stub, regenerated build artifacts that live in tracked directories).
    #
    # `git add -A` is deliberate here and safe for a reason worth stating: the
    # step 1 pre-check uses `git status --porcelain`, which reports untracked
    # files too, so the tree was empty of them before this run began. Anything
    # untracked now was produced by steps 1-3.
    #
    # It is still listed, because "produced by the build" is an inference about a
    # window several steps wide, and a release commit is a bad place to discover
    # it was wrong.
    UNTRACKED=$(git ls-files --others --exclude-standard)
    if [ -n "$UNTRACKED" ]; then
        echo "  New files being committed with the version bump:"
        printf '    %s\n' $UNTRACKED
    fi
    cwm_mutate git add -A

    # The bump does not always produce a diff. Bumping first, running the release
    # gate against that build, and only then releasing is a sound workflow — and it
    # leaves the tree already at the target version, so `git commit` exits non-zero
    # with "nothing to commit" and takes the whole release down with it under
    # `set -e`: after the build has run, before anything is tagged.
    #
    # Staged changes are committed exactly as before; an already-bumped tree simply
    # carries on to the tag.
    if [ "${CWM_DRY_RUN:-0}" = "1" ]; then
        cwm_mutate git commit -m "chore: bump version to ${VERSION}"
    elif git diff --cached --quiet; then
        echo "  Version already at ${VERSION} — nothing to commit, continuing."
    else
        git commit -m "chore: bump version to ${VERSION}"
    fi

    cwm_mutate git push
    echo ""
}

# Step 4 in PR mode: the release commit reaches the branch by pull request.
#
# Commits steps 1-3 on the release branch, opens the pull request, waits for
# every check on it to pass and squash-merges it (lib/pr.sh). The merged commit
# is then checked out and built again, without the bump, and must reproduce:
# building it may not change a tracked file. That commit, and nothing else, is
# what steps 5-7 tag and publish. A project with a development branch has its
# release branch fast-forwarded to it last.
#
# Sets RELEASE_SHA.
release_commit_pr() {
    local pr_number="" pr_head="" pr_url="" merge_sha="" drift=""

    echo "[4/9] Committing the release commit, for a pull request into ${TARGET_BRANCH}..."
    if [ -n "$RESUME_PR" ]; then
        pr_number="$RESUME_PR"
        pr_head="$RESUME_HEAD"
        echo "  Waiting on the open pull request #${pr_number} instead of committing again."
    else
        # Staged as a plain run stages them; see release_commit_direct. The
        # pre-check let through only the changelog and this version's notes.
        cwm_mutate git add -u
        UNTRACKED=$(git ls-files --others --exclude-standard)
        if [ -n "$UNTRACKED" ]; then
            echo "  New files in the release commit:"
            printf '    %s\n' $UNTRACKED
        fi
        cwm_mutate git add -A

        if [ "$DRY_RUN" = "1" ]; then
            cwm_mutate git commit -m "chore(release): cut ${TAG}"
            cwm_mutate git push -u origin "$REL_PR_BRANCH"
            cwm_mutate gh pr create --repo "$GH_SLUG" --base "$TARGET_BRANCH" --head "$REL_PR_BRANCH" --title "chore(release): cut ${TAG}"
            echo "  [dry-run] would wait for every check on it to pass, then squash-merge it."
        elif git diff --cached --quiet; then
            # The release commit is already on the branch: a pull request merged
            # by hand after an earlier run, or a tree bumped before this one.
            echo "  The release commit is already on ${TARGET_BRANCH} — nothing to commit, no pull request needed."
            git switch -q "$TARGET_BRANCH"
            git branch -D "$REL_PR_BRANCH" >/dev/null 2>&1 || true
            RELEASE_SHA="$(git rev-parse HEAD)"
        else
            git commit -m "chore(release): cut ${TAG}"
            pr_head="$(git rev-parse HEAD)"
            git push -u origin "$REL_PR_BRANCH"
            pr_url="$(gh pr create --repo "$GH_SLUG" --base "$TARGET_BRANCH" --head "$REL_PR_BRANCH" \
                --title "chore(release): cut ${TAG}" \
                --body "The release commit for ${TAG}, opened by \`cwm-release --pr\`: the version bump, the \`__DEPLOY_VERSION__\` substitution, and the changelog entry and notes when they came with it. The release run merges it once every check passes, then rebuilds, tags and publishes the merged commit.")"
            pr_number="$(cwm_pr_number_from_url "$pr_url")"
            echo "  Opened ${pr_url}"
        fi
    fi

    if [ -n "$pr_number" ]; then
        echo "  Waiting for every check on #${pr_number} to pass, then squash-merging it..."
        if ! merge_sha="$(cwm_pr_wait_and_merge "$GH_SLUG" "$pr_number" "$pr_head")"; then
            git switch -q "$TARGET_BRANCH" 2>/dev/null || true
            echo ""
            echo "Error: release pull request #${pr_number} was not merged, so nothing has been tagged or published."
            echo "  Once it is fixed or merged, run the same command again. It waits on the open pull request,"
            echo "  or finds the merged release commit in place and carries on."
            exit 1
        fi
        echo "  Merged as ${merge_sha:0:9}."

        git fetch -q origin --prune --tags
        git push -q origin --delete "$REL_PR_BRANCH" 2>/dev/null || true
        git switch -q --detach "$merge_sha"
        git branch -D "$REL_PR_BRANCH" >/dev/null 2>&1 || true
        git submodule update --init --recursive
        RELEASE_SHA="$merge_sha"

        # The release gate ran before the pull request. Anything else that
        # reached the branch while it waited is in the merged commit too.
        if [ -n "$pr_head" ] && ! git diff --quiet "$pr_head" "$RELEASE_SHA"; then
            echo "  WARNING: ${TARGET_BRANCH} gained other changes while the pull request waited. The merged"
            echo "           commit includes them, and the release gate ran before they arrived:"
            git diff --stat "$pr_head" "$RELEASE_SHA" | sed 's/^/    /'
        fi
        echo ""

        echo "[4/9] Rebuilding from the merged release commit ${RELEASE_SHA:0:9}..."
        release_build
        drift="$(git status --porcelain)"
        if [ -n "$drift" ]; then
            echo "Error: building the merged release commit changed files, so it doesn't reproduce:"
            printf '%s\n' "$drift" | sed 's/^/    /'
            echo "  Nothing has been tagged or published. The release commit is merged; once the build"
            echo "  leaves tracked files alone, run the same command again."
            exit 1
        fi
    fi

    if [ "$DRY_RUN" = "1" ]; then
        RELEASE_SHA="<the merged release commit>"
    fi

    # A project with a development branch: the release branch follows it. The
    # pre-flight proved this is a fast-forward and that no rule requires a pull
    # request for it.
    if [ "$TARGET_BRANCH" != "$RELEASE_BRANCH" ]; then
        if [ "$DRY_RUN" = "1" ]; then
            cwm_mutate git push origin "${RELEASE_SHA}:refs/heads/${RELEASE_BRANCH}"
        elif [ "$(git rev-parse "origin/${RELEASE_BRANCH}")" != "$RELEASE_SHA" ]; then
            echo "  Fast-forwarding ${RELEASE_BRANCH} to ${RELEASE_SHA:0:9}..."
            git push origin "${RELEASE_SHA}:refs/heads/${RELEASE_BRANCH}"
        fi
    fi
    echo ""
}

if [ "$PR_MODE" = "1" ]; then
    release_commit_pr
else
    release_commit_direct
fi

# --- Step 5: GitHub release ---
echo "[5/9] Creating GitHub release ${TAG}..."

# What the release is made from. A plain run names the release branch, whose
# tip is the commit it just pushed. A --pr run names the exact commit it merged
# and rebuilt: the branch may have moved on since.
RELEASE_TARGET="$RELEASE_BRANCH"
if [ "$PR_MODE" = "1" ]; then
    RELEASE_TARGET="$RELEASE_SHA"
fi

if [ "$TAG_ONLY" = "1" ]; then
    echo "  Skipped (--tag-only): no GitHub release. Step 6 creates the tag."
else
    # The changelog base is "since the last thing anyone could install", so
    # pre-release tags are skipped. `git describe` answers with the nearest tag,
    # which straight after a release candidate is the candidate — and the range
    # then holds only the version bump, producing an entry with nothing in it
    # while the real description sits under a tag no site was offered (#74).
    PREV_TAG=$(cwm_latest_stable_tag "$(git tag --merged HEAD --sort=v:refname 2>/dev/null || echo "")")
    if [ -n "$PREV_TAG" ] && [ -n "$GH_OWNER" ] && [ -n "$GH_REPO" ]; then
        NOTES=$(gh api "repos/${GH_OWNER}/${GH_REPO}/releases/generate-notes" \
            -f tag_name="$TAG" -f target_commitish="$RELEASE_TARGET" -f previous_tag_name="$PREV_TAG" \
            --jq '.body' 2>/dev/null || echo "Release ${VERSION}")
    else
        NOTES="Release ${VERSION}"
    fi

    # Hand-written notes, if this release has any.
    #
    # What GitHub generates is a list of pull request titles — accurate, and written
    # for us rather than for the person deciding whether to update. Those same notes
    # are republished on the ARS download page, where they are the only changelog a
    # site administrator following an update link ever sees.
    #
    # So a notes file, when present, leads; the generated list is kept beneath it so
    # nothing is lost. Configure `release.notesFile` with a {version} placeholder,
    # e.g. "build/release-notes-{version}.md". Absent file, absent config, or a
    # release nobody wrote notes for: unchanged behaviour.
    #
    # Resolution and assembly live in lib/notes.sh so they can be tested — see #52.
    NOTES_FILE_PATTERN=$(read_config "release.notesFile")
    NOTES_FILE=$(cwm_resolve_notes_file "$NOTES_FILE_PATTERN" "$VERSION" || true)
    if [ -n "$NOTES_FILE" ]; then
        echo "  Using hand-written release notes: ${NOTES_FILE}"
    elif [ -n "$NOTES_FILE_PATTERN" ]; then
        echo "  No hand-written notes at ${NOTES_FILE_PATTERN//\{version\}/$VERSION}; using generated notes."
    fi
    NOTES=$(cwm_assemble_release_notes "$NOTES_FILE" "$NOTES")

    GH_REPO_ARG=""
    if [ -n "$GH_OWNER" ] && [ -n "$GH_REPO" ]; then
        GH_REPO_ARG="--repo ${GH_OWNER}/${GH_REPO}"
    fi

    # shellcheck disable=SC2086
    # ⚠️ NO_COLOR, because gh probes the terminal when its output is a TTY.
    # Its markdown renderer asks for the background colour (OSC 11) and the
    # cursor position (CPR) to pick a light or dark theme, then exits without
    # consuming the replies -- so the terminal's answers land in the input
    # buffer and are echoed at the next prompt, as
    #
    #     11;rgb:1919/1a1a/1c1c;1R
    #
    # after a release that otherwise finished cleanly. Cosmetic, but it looks
    # like a fault and it is the last thing a release prints.
    #
    # Only the calls whose output reaches a terminal need this; the ones whose
    # output is captured into a variable are not a TTY and never query. Setting
    # it per-call rather than exporting it keeps our own coloured PASS/FAIL
    # output intact.
    cwm_mutate env NO_COLOR=1 gh release create "$TAG" "${ARTIFACTS[@]}" \
        $GH_REPO_ARG \
        --target "$RELEASE_TARGET" \
        --title "${TAG}" \
        --notes "$NOTES" \
        $PRERELEASE_FLAG
fi
echo ""

# --- Step 6: Changelog ---
echo "[6/9] Updating changelog..."
CHANGELOG_FILE=$(read_config "changelog.file")
if [ "$TAG_ONLY" = "1" ]; then
    # Pre-flight proved any entry is already committed, and the generator would
    # read a GitHub release that does not exist. What is left is the tag, which
    # step 5 makes on a full release. Pre-flight also proved it is new, so no
    # --force. Signed when tag.gpgSign is set.
    echo "  Tag-only: nothing to generate. Tagging the release commit."
    if [ "$PR_MODE" = "1" ]; then
        cwm_mutate git tag -a "$TAG" -m "$TAG" "$RELEASE_SHA"
    else
        cwm_mutate git tag -a "$TAG" -m "$TAG"
    fi
    cwm_mutate git push origin "$TAG"
elif [ "$PR_MODE" = "1" ]; then
    # The pre-flight required the entry, and the release commit carries it.
    # Generating one now would mean another commit, and a tag moved onto it.
    echo "  The ${VERSION} entry is part of the release commit."
elif [ -n "$CHANGELOG_FILE" ] && [ -f "$CHANGELOG_FILE" ]; then
    cwm_mutate bash "${TOOLS_DIR}/scripts/generate-changelog-entry.sh" "$VERSION"
    # Stage the changelog and nothing else. This used to be `git add -A` behind
    # a `git diff --quiet` guard, and those two disagree: the guard inspects
    # only tracked files, while the action staged everything, untracked
    # included. Anything left in the working tree after step 4 — a scratch
    # script, a downloaded artifact, a dumped token — was committed here, and
    # the tag is force-moved onto this commit immediately below, so it landed
    # in the published tag too.
    #
    # The guard is `git status --porcelain`, not `git diff --quiet`: the latter
    # reports no change for a file that is new and untracked, so a changelog
    # created rather than edited would be silently skipped.
    # Under --dry-run the generator never ran, so there is nothing staged to
    # detect; the commands are described unconditionally instead.
    if [ "$DRY_RUN" = "1" ]; then
        cwm_mutate git add -- "$CHANGELOG_FILE"
        cwm_mutate git commit -m "chore: add changelog entry for ${VERSION}"
        cwm_mutate git push
        cwm_mutate git tag -af "$TAG" -m "$TAG"
        cwm_mutate git push origin "$TAG" --force
    elif [ -n "$(git status --porcelain -- "$CHANGELOG_FILE")" ]; then
        git add -- "$CHANGELOG_FILE"
        git commit -m "chore: add changelog entry for ${VERSION}"
        git push
        # Move tag to include changelog commit
        git tag -af "$TAG" -m "$TAG"
        git push origin "$TAG" --force
    fi
else
    echo "  Skipped: no changelog.file configured (or file missing)."
fi
echo ""

# --- Step 7: ARS publish ---
#
# ⚠️ A failure here no longer stops the pipeline. By this point the release is
# public — tag pushed, GitHub release out, bump commits on the branch — and the
# two remaining steps serve different masters: step 8 records what has already
# happened, step 9 announces it. Killing both over a failed ARS publish is what
# left versions.json pointing at the previous release twice (10.5.9 and
# 10.6.0, cwm-build-tools#161), and a stale `current` then sends
# verify-update-stream.php to inspect the wrong version — the false FAIL that
# makes recovery start by distrusting a correct release.
#
# So: the failure is captured, step 8 runs regardless, step 9 is deferred
# (announcing a release whose download item does not exist yet helps nobody),
# and the run still exits non-zero at the end with exactly what is owed.
echo "[7/9] Publishing to ARS..."
ARS_ENDPOINT=$(read_config "ars.endpoint")
ARS_FAILED=0
if [ "$TAG_ONLY" = "1" ]; then
    echo "  Skipped (--tag-only): nothing is published to ARS."
elif [ -n "$ARS_ENDPOINT" ]; then
    # The GitHub release now carries the hand-written notes plus the generated
    # list, so ARS reads them back from there and both pages agree.
    cwm_mutate bash "${TOOLS_DIR}/scripts/ars-publish.sh" -v "$VERSION" -f "${ARTIFACTS[0]}" || ARS_FAILED=$?
    if [ "$ARS_FAILED" -ne 0 ]; then
        echo "  ✗ ARS publish failed (exit ${ARS_FAILED}). Continuing: step 8 records what"
        echo "    already happened and must not be skipped over this. Details at the end."
    fi
else
    echo "  Skipped: no ars.endpoint configured."
fi
echo ""

# --- Step 8: versions.json update (current + next.* + _updated) ---
echo "[8/9] Updating versions.json..."
# VERSIONS_FILE and DEV_BRANCH are read at the top. VERSIONS_FILE is the only
# file `version-tracker.php --mode=release` writes (VersionTracker::
# updateForRelease touches versionsJson alone), and every commit below stages
# exactly it.

if [ "$TAG_ONLY" = "1" ]; then
    # `current` names the last *published* release, and verify-update-stream
    # checks the stream against it. A tag nobody is offered must not move it.
    echo "  Skipped (--tag-only): versions.json tracks published releases."
elif [ -z "$VERSIONS_FILE" ]; then
    echo "  Skipped: no versionTracking.versionsJson configured."
elif [ "$PR_MODE" = "1" ]; then
    # The same update as below, by pull request into the branch the release
    # commit went into. It records a release that is already public, so a
    # pull request that doesn't merge is reported as owed rather than undoing
    # anything.
    V_BRANCH="chore/versions-json-${TAG}"
    if [ "$DRY_RUN" = "1" ]; then
        cwm_skip_in_dry_run "branch ${V_BRANCH} from origin/${TARGET_BRANCH}, update ${VERSIONS_FILE}, commit and push it, open a pull request into ${TARGET_BRANCH}, wait for its checks and squash-merge it"
    else
        git fetch -q origin "$TARGET_BRANCH"
        git switch -q -C "$V_BRANCH" "origin/${TARGET_BRANCH}"
        php "${TOOLS_DIR}/scripts/version-tracker.php" --mode=release -v "$VERSION"
        if [ -z "$(git status --porcelain -- "$VERSIONS_FILE")" ]; then
            echo "  ${VERSIONS_FILE} needs no change."
        else
            git add -- "$VERSIONS_FILE"
            git commit -q -m "chore: update versions.json for ${TAG} release"
            V_HEAD="$(git rev-parse HEAD)"
            git push -q -u origin "$V_BRANCH"
            V_URL="$(gh pr create --repo "$GH_SLUG" --base "$TARGET_BRANCH" --head "$V_BRANCH" \
                --title "chore: update versions.json for ${TAG} release" \
                --body "Step 8 of the ${TAG} release, after publishing: ${VERSIONS_FILE} records ${VERSION} as released. Opened by \`cwm-release --pr\`, which merges it once every check passes.")"
            echo "  Opened ${V_URL}"
            if cwm_pr_wait_and_merge "$GH_SLUG" "$(cwm_pr_number_from_url "$V_URL")" "$V_HEAD" >/dev/null; then
                echo "  Merged."
                git push -q origin --delete "$V_BRANCH" 2>/dev/null || true
            else
                VERSIONS_OWED="$V_URL"
                echo "  ✗ Not merged. The release is published; the pull request stays open: ${V_URL}"
            fi
        fi
        git switch -q "$TARGET_BRANCH"
        git branch -D "$V_BRANCH" >/dev/null 2>&1 || true
    fi
elif [ "$DRY_RUN" = "1" ]; then
    # Described rather than wrapped in mutate: this branch stashes and checks
    # out another branch, and a half-applied version of that is worse than not
    # simulating it at all.
    if [ -n "$DEV_BRANCH" ]; then
        cwm_skip_in_dry_run "stash, checkout ${DEV_BRANCH}, update ${VERSIONS_FILE}, commit, push, checkout ${RELEASE_BRANCH}, stash pop"
    else
        cwm_skip_in_dry_run "update ${VERSIONS_FILE} on ${RELEASE_BRANCH}, then commit and push it"
    fi
    if [ -n "$PRERELEASE_FLAG" ]; then
        echo "  Would leave ${VERSIONS_FILE} unchanged — current/next track stable releases."
    else
        echo "  Would set current.version=${VERSION}, recompute next.{patch,minor,major}, refresh _updated,"
        echo "  and reopen active_development on the new next.patch."
    fi
elif [ -n "$DEV_BRANCH" ]; then
    # Project uses separate dev branch (versions.json lives there, not on release branch)
    #
    # Note `git stash` does not take untracked files, so any that are present
    # follow the checkout onto the development branch. Staging the versions
    # file by name — rather than the `git add -A` this used to run — is what
    # keeps them from being committed and pushed there.
    #
    # Pop only a stash this step made. With no tracked changes `git stash`
    # saves nothing and still exits 0, so an unconditional `git stash pop`
    # below popped whatever the developer had stashed before the release
    # (seen cutting pkg_licenseportal 1.5.2).
    RELEASE_STASHED=0
    if [ -n "$(git status --porcelain --untracked-files=no)" ]; then
        git stash push -m "cwm-release ${TAG}: step 8" >/dev/null && RELEASE_STASHED=1
    fi
    git checkout "$DEV_BRANCH"
    git pull

    php "${TOOLS_DIR}/scripts/version-tracker.php" --mode=release -v "$VERSION"

    if [ -n "$(git status --porcelain -- "$VERSIONS_FILE")" ]; then
        git add -- "$VERSIONS_FILE"
        git commit -m "chore: update versions.json for ${TAG} release"
        git push
    fi

    git checkout "$RELEASE_BRANCH"

    if [ "$RELEASE_STASHED" = 1 ]; then
        git stash pop || echo "  ⚠ Could not restore the changes stashed for step 8 — they are still in 'git stash list' (\"cwm-release ${TAG}: step 8\")."
    fi
else
    # Single-branch project: update inline on the release branch
    php "${TOOLS_DIR}/scripts/version-tracker.php" --mode=release -v "$VERSION"

    if [ -n "$(git status --porcelain -- "$VERSIONS_FILE")" ]; then
        git add -- "$VERSIONS_FILE"
        git commit -m "chore: update versions.json for ${TAG} release"
        git push
    fi
fi
echo ""

# A --pr run built and released a detached merge commit. Hand the checkout back
# on the branch it started on, now carrying that commit and anything merged
# after it.
if [ "$PR_MODE" = "1" ] && [ "$DRY_RUN" != "1" ]; then
    if [ "$(git branch --show-current)" != "$TARGET_BRANCH" ]; then
        git switch -q "$TARGET_BRANCH"
    fi
    git pull -q --ff-only origin "$TARGET_BRANCH" || echo "  Note: couldn't fast-forward ${TARGET_BRANCH}; pull it when ready."
    git submodule update --init --recursive
fi

# --- Step 9: CWM article (optional, only if bullets file exists) ---
echo "[9/9] Posting CWM release announcement..."
BULLETS_DIR=$(read_config "announcement.bulletsDir")
BULLETS_DIR="${BULLETS_DIR:-build}"
BULLETS_FILE="${BULLETS_DIR}/release-bullets-${VERSION}.txt"
ARTICLE_CMD=$(read_config "announcement.command")

if [ "$TAG_ONLY" = "1" ]; then
    echo "  Skipped (--tag-only): nothing was published to announce."
elif [ "$ARS_FAILED" -ne 0 ]; then
    echo "  Deferred: the ARS publish failed, so the download this would announce"
    echo "  does not exist yet. Announce after finishing step 7 by hand."
elif [ -n "$ARTICLE_CMD" ] && [ -f "$BULLETS_FILE" ]; then
    cwm_mutate bash -c "$ARTICLE_CMD '$VERSION' '$BULLETS_FILE'"
elif [ -n "$ARTICLE_CMD" ]; then
    echo "  Skipped: ${BULLETS_FILE} not found."
    echo "  Create it (one bullet per line) and run when ready."
else
    echo "  Skipped: no announcement.command configured."
fi
echo ""

# --- Post-flight: is the published release actually reachable? ---
#
# Every other check in this pipeline runs before publish and so cannot see what
# the update stream serves afterwards. The failure is silent by construction:
# valid XML, a clean release, and some population of sites is never offered the
# update again. Null ARS environments mis-shipped three Proclaim releases that
# way — published fine, offered to nobody.
#
# It REPORTS and never fails the run. Nothing it finds can be undone by exiting
# non-zero at this point: the tag is pushed, the GitHub release is out and ARS
# has the item. A red pipeline here would imply the release did not happen, and
# would teach people to ignore the one check that runs after it did. The whole
# subject of #101 is a check whose result was easy to misread.
#
# Skipped under --dry-run: nothing was published, so the stream still describes
# the previous release and checking it would report on the wrong version.
if [ "$ARS_FAILED" -ne 0 ]; then
    : # The stream cannot serve an item that was never published; the debt
      # block below says so once, instead of a verification failing to
      # discover what is already known.
elif [ "$DRY_RUN" != "1" ] && [ "$TAG_ONLY" != "1" ] && [ -n "$PKG_MANIFEST" ]; then
    echo "[post-flight] Verifying the published update stream..."

    if php "${TOOLS_DIR}/scripts/verify-update-stream.php" "$VERSION"; then
        :
    else
        echo ""
        echo "  WARNING: the update stream does not serve ${VERSION} usably."
        echo "           The release IS published — this is about whether sites will see it."
        echo "           Re-check once ARS has caught up:"
        echo "             composer cwm-verify-update-stream -- ${VERSION}"
    fi
    echo ""
fi

if [ "$DRY_RUN" = "1" ]; then
    echo "=== DRY RUN complete — nothing was changed ==="
    echo "  Re-run without --dry-run to release ${VERSION}."
elif [ "$ARS_FAILED" -ne 0 ]; then
    # The message that was missing both times this happened. Steps 1-6 are
    # public and step 8 has run, so the repository is honest — what remains is
    # exactly one command. Spelled with composer exec so it is correct in every
    # repo this pipeline serves, whatever its vendor-dir.
    echo "=== Release ${VERSION} is INCOMPLETE — the ARS publish failed ==="
    echo ""
    echo "  DONE and public:   tag ${TAG}, the GitHub release, the version-bump"
    echo "                     commits, and versions.json (step 8 ran anyway)."
    echo "  STILL OWED:        the ARS item — sites are NOT offered ${VERSION} yet."
    if [ -n "$VERSIONS_OWED" ]; then
        echo "                     The versions.json pull request didn't merge: ${VERSIONS_OWED}"
    fi
    echo "  DEFERRED:          the release announcement (step 9)."
    echo ""
    echo "  Finish with (safe to repeat — ars-publish refuses version collisions):"
    echo "    composer exec -- cwm-ars-publish -v ${VERSION} -f ${ARTIFACTS[0]}"
    echo "  then verify sites are offered it:"
    echo "    composer exec -- cwm-verify-update-stream ${VERSION}"
    echo "  and post the announcement when ready."

    exit 1
elif [ -n "$VERSIONS_OWED" ]; then
    echo "=== Release ${VERSION} is published, and one step is still owed ==="
    echo ""
    echo "  The versions.json pull request didn't merge: ${VERSIONS_OWED}"
    echo "  Merge it once its checks pass. Nothing else is owed."
    exit 1
elif [ "$TAG_ONLY" = "1" ]; then
    echo "=== Tag-only release ${VERSION} complete: ${TAG} pushed, nothing published ==="
else
    echo "=== Release ${VERSION} complete! ==="
    if [ -n "$GH_OWNER" ] && [ -n "$GH_REPO" ]; then
        echo "  GitHub: https://github.com/${GH_OWNER}/${GH_REPO}/releases/tag/${TAG}"
    fi
fi

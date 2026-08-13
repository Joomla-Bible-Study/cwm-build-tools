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
  composer release -- <version> [--dry-run|-n] [--skip-tests]
  composer release                  # prompts for the version

Options:
  -n, --dry-run     Print every mutating command instead of running it.
  --skip-tests      Skip the composer test:release gate.
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
if [ "$BRANCH" != "$RELEASE_BRANCH" ]; then
    if [ "$DRY_RUN" = "1" ]; then
        echo "  [dry-run] WARNING: on '$BRANCH', not the release branch '$RELEASE_BRANCH'."
        echo "            A real run would stop here."
    else
        echo "Error: Must be on $RELEASE_BRANCH branch (currently on '$BRANCH')."
        echo "Switch with: git checkout $RELEASE_BRANCH && git pull"
        exit 1
    fi
fi

if [ -n "$(git status --porcelain)" ]; then
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
    echo "[pre-flight] Comparing ${RELEASE_BRANCH} with origin (no pull under --dry-run)..."
    BEHIND=$(git rev-list --count "HEAD..origin/${RELEASE_BRANCH}" 2>/dev/null || echo "?")
    AHEAD=$(git rev-list --count "origin/${RELEASE_BRANCH}..HEAD" 2>/dev/null || echo "?")

    if [ "$BEHIND" = "0" ] && [ "$AHEAD" = "0" ]; then
        echo "  In sync with origin/${RELEASE_BRANCH}."
    elif [ "$AHEAD" != "0" ] && [ "$BEHIND" != "0" ]; then
        echo "  WARNING: diverged from origin/${RELEASE_BRANCH} (${AHEAD} ahead, ${BEHIND} behind)."
        echo "           A real run would stop here."
    elif [ "$BEHIND" != "0" ]; then
        echo "  ${BEHIND} commit(s) behind origin/${RELEASE_BRANCH}; a real run would fast-forward."
    else
        echo "  ${AHEAD} unpushed commit(s) on ${RELEASE_BRANCH}."
    fi

    cwm_skip_in_dry_run "git submodule update (would move submodule working trees)"
else
    echo "[pre-flight] Pulling ${RELEASE_BRANCH} (fast-forward only)..."
    if ! git pull --ff-only origin "$RELEASE_BRANCH"; then
        echo ""
        echo "Error: Local ${RELEASE_BRANCH} has diverged from origin/${RELEASE_BRANCH}."
        echo "Resolve manually before running release."
        echo "  Inspect: git status && git log @{u}.."
        echo "  Rebase:  git pull --rebase origin ${RELEASE_BRANCH}"
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

# --- Pre-flight: release gate (composer test:release) ---
# Verifies the commit about to be released, not the commit that results from
# bumping/building it — so this runs before step 1, against what's on disk
# right now. See lib/testgate.sh for why this exists at all.
if [ "$SKIP_TESTS" = "1" ]; then
    echo "[pre-flight] Skipping composer test:release (--skip-tests)."
elif ! cwm_has_test_release_script "${PROJECT_ROOT}/composer.json"; then
    echo "[pre-flight] No composer test:release script — skipping pre-release verification."
else
    echo "[pre-flight] Running composer test:release..."
    if composer test:release; then
        echo "  test:release passed."
    elif [ "$DRY_RUN" = "1" ]; then
        echo "  WARNING: test:release failed. A real run would stop here."
    else
        echo ""
        echo "Error: composer test:release failed. Fix the failure, or pass --skip-tests to release anyway."
        exit 1
    fi
fi
echo ""

# --- Get version ---
if [ -n "${1:-}" ]; then
    VERSION="$1"
else
    if [ -n "$PKG_MANIFEST" ]; then
        CURRENT=$(cwm_version_from_manifest "$PKG_MANIFEST" || true)
        echo "Current package version: ${CURRENT:-unknown}"
    fi
    printf "Enter new version (e.g., 1.2.3): "
    read -r VERSION
fi

# Validation, tag and pre-release derivation live in lib/version.sh so
# they can be tested — see #52.
cwm_validate_release_version "$VERSION" || exit 1

TAG=$(cwm_tag_for_version "$VERSION")
PRERELEASE_FLAG=$(cwm_prerelease_flag "$VERSION")

echo ""
echo "=== ${PKG_NAME} Release ${VERSION} ==="
echo ""

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

# --- Step 4: Commit and push ---
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

# --- Step 5: GitHub release ---
echo "[5/9] Creating GitHub release ${TAG}..."

# The changelog base is "since the last thing anyone could install", so
# pre-release tags are skipped. `git describe` answers with the nearest tag,
# which straight after a release candidate is the candidate — and the range
# then holds only the version bump, producing an entry with nothing in it
# while the real description sits under a tag no site was offered (#74).
PREV_TAG=$(cwm_latest_stable_tag "$(git tag --merged HEAD --sort=v:refname 2>/dev/null || echo "")")
if [ -n "$PREV_TAG" ] && [ -n "$GH_OWNER" ] && [ -n "$GH_REPO" ]; then
    NOTES=$(gh api "repos/${GH_OWNER}/${GH_REPO}/releases/generate-notes" \
        -f tag_name="$TAG" -f target_commitish="$RELEASE_BRANCH" -f previous_tag_name="$PREV_TAG" \
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
cwm_mutate gh release create "$TAG" "${ARTIFACTS[@]}" \
    $GH_REPO_ARG \
    --target "$RELEASE_BRANCH" \
    --title "${TAG}" \
    --notes "$NOTES" \
    $PRERELEASE_FLAG

echo ""

# --- Step 6: Changelog ---
echo "[6/9] Updating changelog..."
CHANGELOG_FILE=$(read_config "changelog.file")
if [ -n "$CHANGELOG_FILE" ] && [ -f "$CHANGELOG_FILE" ]; then
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
echo "[7/9] Publishing to ARS..."
ARS_ENDPOINT=$(read_config "ars.endpoint")
if [ -n "$ARS_ENDPOINT" ]; then
    # The GitHub release now carries the hand-written notes plus the generated
    # list, so ARS reads them back from there and both pages agree.
    cwm_mutate bash "${TOOLS_DIR}/scripts/ars-publish.sh" -v "$VERSION" -f "${ARTIFACTS[0]}"
else
    echo "  Skipped: no ars.endpoint configured."
fi
echo ""

# --- Step 8: versions.json update (current + next.* + _updated) ---
echo "[8/9] Updating versions.json..."
DEV_BRANCH=$(read_config "github.developmentBranch")
# This is the project-relative path to versions.json, and it is the only file
# `version-tracker.php --mode=release` writes (VersionTracker::updateForRelease
# touches versionsJson alone). Both commits below stage exactly it.
VERSIONS_FILE=$(php "${TOOLS_DIR}/scripts/resolve-tracking.php" versionsJson)

if [ -z "$VERSIONS_FILE" ]; then
    echo "  Skipped: no versionTracking.versionsJson configured."
elif [ "$DRY_RUN" = "1" ]; then
    # Described rather than wrapped in mutate: this branch stashes and checks
    # out another branch, and a half-applied version of that is worse than not
    # simulating it at all.
    if [ -n "$DEV_BRANCH" ]; then
        cwm_skip_in_dry_run "stash, checkout ${DEV_BRANCH}, update ${VERSIONS_FILE}, commit, push, checkout ${RELEASE_BRANCH}, stash pop"
    else
        cwm_skip_in_dry_run "update ${VERSIONS_FILE} on ${RELEASE_BRANCH}, then commit and push it"
    fi
    echo "  Would set current.version=${VERSION}, recompute next.{patch,minor,major}, refresh _updated."
elif [ -n "$DEV_BRANCH" ]; then
    # Project uses separate dev branch (versions.json lives there, not on release branch)
    #
    # Note `git stash` does not take untracked files, so any that are present
    # follow the checkout onto the development branch. Staging the versions
    # file by name — rather than the `git add -A` this used to run — is what
    # keeps them from being committed and pushed there.
    git stash 2>/dev/null || true
    git checkout "$DEV_BRANCH"
    git pull

    php "${TOOLS_DIR}/scripts/version-tracker.php" --mode=release -v "$VERSION"

    if [ -n "$(git status --porcelain -- "$VERSIONS_FILE")" ]; then
        git add -- "$VERSIONS_FILE"
        git commit -m "chore: update versions.json for ${TAG} release"
        git push
    fi

    git checkout "$RELEASE_BRANCH"
    git stash pop 2>/dev/null || true
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

# --- Step 9: CWM article (optional, only if bullets file exists) ---
echo "[9/9] Posting CWM release announcement..."
BULLETS_DIR=$(read_config "announcement.bulletsDir")
BULLETS_DIR="${BULLETS_DIR:-build}"
BULLETS_FILE="${BULLETS_DIR}/release-bullets-${VERSION}.txt"
ARTICLE_CMD=$(read_config "announcement.command")

if [ -n "$ARTICLE_CMD" ] && [ -f "$BULLETS_FILE" ]; then
    cwm_mutate bash -c "$ARTICLE_CMD '$VERSION' '$BULLETS_FILE'"
elif [ -n "$ARTICLE_CMD" ]; then
    echo "  Skipped: ${BULLETS_FILE} not found."
    echo "  Create it (one bullet per line) and run when ready."
else
    echo "  Skipped: no announcement.command configured."
fi
echo ""

if [ "$DRY_RUN" = "1" ]; then
    echo "=== DRY RUN complete — nothing was changed ==="
    echo "  Re-run without --dry-run to release ${VERSION}."
else
    echo "=== Release ${VERSION} complete! ==="
    if [ -n "$GH_OWNER" ] && [ -n "$GH_REPO" ]; then
        echo "  GitHub: https://github.com/${GH_OWNER}/${GH_REPO}/releases/tag/${TAG}"
    fi
fi

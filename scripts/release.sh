#!/usr/bin/env bash
#
# Generic 8-step release pipeline for a CWM Joomla extension.
#
# Reads cwm-build.config.json from the current working directory. All
# project-specific values (manifest paths, ARS endpoint, GitHub repo)
# come from there.
#
# Steps:
#   1. Version bump across all manifests in cwm-build.config.json
#   2. Run the project's build command
#   3. Commit version bump and push
#   4. Create GitHub release with built zip(s) attached
#   5. Generate changelog entry (if generator is configured)
#   6. Publish to ARS
#   7. Update versions.json on development branch (if configured)
#   8. Post CWM release announcement article (optional, skipped if no bullets file)
#
# Usage:
#   release.sh 1.2.3                # release specific version
#   release.sh 1.2.3-beta1          # pre-release
#   release.sh                      # prompts for version
#
# Prerequisites:
#   - Clean working tree, on the configured release branch (default: main)
#   - gh CLI authenticated
#   - 1Password CLI (op) authenticated, if ARS publish needs it
#
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
TOOLS_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"
PROJECT_ROOT="$(pwd)"

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
BRANCH=$(git branch --show-current)
if [ "$BRANCH" != "$RELEASE_BRANCH" ]; then
    echo "Error: Must be on $RELEASE_BRANCH branch (currently on '$BRANCH')."
    echo "Switch with: git checkout $RELEASE_BRANCH && git pull"
    exit 1
fi

if [ -n "$(git status --porcelain)" ]; then
    echo "Error: Working tree is not clean. Commit or stash changes first."
    exit 1
fi

# --- Get version ---
if [ -n "${1:-}" ]; then
    VERSION="$1"
else
    if [ -n "$PKG_MANIFEST" ] && [ -f "$PKG_MANIFEST" ]; then
        CURRENT=$(grep -oE '<version>[^<]+</version>' "$PKG_MANIFEST" | head -1 | sed -E 's|</?version>||g')
        echo "Current package version: ${CURRENT:-unknown}"
    fi
    printf "Enter new version (e.g., 1.2.3): "
    read -r VERSION
fi

if [ -z "$VERSION" ]; then
    echo "Error: No version provided."
    exit 1
fi

if [[ "$VERSION" == *-dev* ]]; then
    echo "Error: Development versions cannot be released. Use -alpha, -beta, or -rc for testing."
    exit 1
fi

TAG="v${VERSION}"

# Pre-release detection
PRERELEASE_FLAG=""
if [[ "$VERSION" == *-* ]]; then
    PRERELEASE_FLAG="--prerelease"
fi

echo ""
echo "=== ${PKG_NAME} Release ${VERSION} ==="
echo ""

# --- Step 1: Version bump ---
echo "[1/8] Bumping version to ${VERSION}..."
php "${TOOLS_DIR}/scripts/bump.php" -v "$VERSION"
echo ""

# --- Step 2: Build ---
echo "[2/8] Building package..."
BUILD_CMD=$(read_config "build.command")
if [ -z "$BUILD_CMD" ]; then
    echo "Error: build.command not set in cwm-build.config.json"
    exit 1
fi
bash -c "$BUILD_CMD"

OUTPUT_GLOB=$(read_config "build.outputGlob")
if [ -z "$OUTPUT_GLOB" ]; then
    echo "Error: build.outputGlob not set in cwm-build.config.json"
    exit 1
fi

# Resolve glob — should match exactly one file
shopt -s nullglob
ARTIFACTS=( $OUTPUT_GLOB )
shopt -u nullglob

if [ "${#ARTIFACTS[@]}" -eq 0 ]; then
    echo "Error: No build artifact matched $OUTPUT_GLOB"
    exit 1
fi
echo "  Built: ${ARTIFACTS[*]}"
echo ""

# --- Step 3: Commit and push ---
echo "[3/8] Committing version bump..."
# Add only files modified by step 1 + 2, not unrelated untracked files. The
# bump and build steps may dirty several manifests and rebuild the zip; the
# release branch was clean before step 1 (pre-check), so anything modified or
# added here is part of the release.
git add -u
# Pull in any new files the build step generated (e.g. a fresh changelog
# stub, regenerated build artifacts that live in tracked directories).
git add -A
git commit -m "chore: bump version to ${VERSION}"
git push
echo ""

# --- Step 4: GitHub release ---
echo "[4/8] Creating GitHub release ${TAG}..."

# `HEAD` is the bump commit we just pushed; `git describe` from there finds the
# previous reachable tag, which is what we want for the changelog "since" base.
PREV_TAG=$(git describe --tags --abbrev=0 HEAD 2>/dev/null || echo "")
if [ -n "$PREV_TAG" ] && [ -n "$GH_OWNER" ] && [ -n "$GH_REPO" ]; then
    NOTES=$(gh api "repos/${GH_OWNER}/${GH_REPO}/releases/generate-notes" \
        -f tag_name="$TAG" -f target_commitish="$RELEASE_BRANCH" -f previous_tag_name="$PREV_TAG" \
        --jq '.body' 2>/dev/null || echo "Release ${VERSION}")
else
    NOTES="Release ${VERSION}"
fi

GH_REPO_ARG=""
if [ -n "$GH_OWNER" ] && [ -n "$GH_REPO" ]; then
    GH_REPO_ARG="--repo ${GH_OWNER}/${GH_REPO}"
fi

# shellcheck disable=SC2086
gh release create "$TAG" "${ARTIFACTS[@]}" \
    $GH_REPO_ARG \
    --target "$RELEASE_BRANCH" \
    --title "${TAG}" \
    --notes "$NOTES" \
    $PRERELEASE_FLAG

echo ""

# --- Step 5: Changelog ---
echo "[5/8] Updating changelog..."
CHANGELOG_FILE=$(read_config "changelog.file")
if [ -n "$CHANGELOG_FILE" ] && [ -f "$CHANGELOG_FILE" ]; then
    bash "${TOOLS_DIR}/scripts/generate-changelog-entry.sh" "$VERSION"
    if ! git diff --quiet 2>/dev/null; then
        git add -A
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

# --- Step 6: ARS publish ---
echo "[6/8] Publishing to ARS..."
ARS_ENDPOINT=$(read_config "ars.endpoint")
if [ -n "$ARS_ENDPOINT" ]; then
    bash "${TOOLS_DIR}/scripts/ars-publish.sh" -v "$VERSION" -f "${ARTIFACTS[0]}"
else
    echo "  Skipped: no ars.endpoint configured."
fi
echo ""

# --- Step 7: versions.json on development branch ---
echo "[7/8] Updating development branch..."
DEV_BRANCH=$(read_config "github.developmentBranch")
VERSIONS_JSON=$(read_config "versionsFile")
VERSIONS_JSON="${VERSIONS_JSON:-build/versions.json}"

if [ -n "$DEV_BRANCH" ] && [ -f "$VERSIONS_JSON" ]; then
    IFS='.' read -r MAJOR MINOR PATCH <<< "${VERSION%%-*}"
    NEXT_PATCH="${MAJOR}.${MINOR}.$((PATCH + 1))"

    git stash 2>/dev/null || true
    git checkout "$DEV_BRANCH"
    git pull

    python3 -c "
import json
with open('${VERSIONS_JSON}', 'r') as f:
    v = json.load(f)
v['_updated'] = '$(date +%Y-%m-%d)'
v['current']['version'] = '${VERSION}'
v['next']['patch'] = '${NEXT_PATCH}'
with open('${VERSIONS_JSON}', 'w') as f:
    json.dump(v, f, indent=4)
    f.write('\n')
"
    git add "$VERSIONS_JSON"
    git commit -m "chore: update versions.json for ${TAG} release"
    git push

    git checkout "$RELEASE_BRANCH"
    git stash pop 2>/dev/null || true
else
    echo "  Skipped: no developmentBranch configured or ${VERSIONS_JSON} missing."
fi
echo ""

# --- Step 8: CWM article (optional, only if bullets file exists) ---
echo "[8/8] Posting CWM release announcement..."
BULLETS_DIR=$(read_config "announcement.bulletsDir")
BULLETS_DIR="${BULLETS_DIR:-build}"
BULLETS_FILE="${BULLETS_DIR}/release-bullets-${VERSION}.txt"
ARTICLE_CMD=$(read_config "announcement.command")

if [ -n "$ARTICLE_CMD" ] && [ -f "$BULLETS_FILE" ]; then
    bash -c "$ARTICLE_CMD '$VERSION' '$BULLETS_FILE'"
elif [ -n "$ARTICLE_CMD" ]; then
    echo "  Skipped: ${BULLETS_FILE} not found."
    echo "  Create it (one bullet per line) and run when ready."
else
    echo "  Skipped: no announcement.command configured."
fi
echo ""

echo "=== Release ${VERSION} complete! ==="
if [ -n "$GH_OWNER" ] && [ -n "$GH_REPO" ]; then
    echo "  GitHub: https://github.com/${GH_OWNER}/${GH_REPO}/releases/tag/${TAG}"
fi

#!/usr/bin/env bash
#
# Fetch the released artifact an upgrade test should upgrade FROM.
#
# Reads cwm-build.config.json from the current working directory. The release
# list comes from the project's GitHub repo; selection lives in
# lib/baseline.sh so it can be tested without a network.
#
# Why the baseline is a released artifact rather than a rebuild of an old tree:
# the released zip carries the old install.sql, without the newer migrations,
# so installing the new build over it genuinely exercises the ADD COLUMN /
# CREATE TABLE update SQL. A rebuild of an old checkout with today's build
# tooling does not reliably reproduce that.
#
# Usage:
#   cwm-baseline                # resolve and fetch, target from the manifest
#   cwm-baseline --target 5.7.1 # resolve relative to a specific version
#   cwm-baseline --version 5.6.2 # skip resolution, fetch this release
#   cwm-baseline --print        # print the resolved version, download nothing
#
# Exit codes are the point of this script's interface — see EXIT CODE in --help.
#
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

# shellcheck source=lib/baseline.sh
source "${SCRIPT_DIR}/lib/baseline.sh"
# shellcheck source=lib/version.sh
source "${SCRIPT_DIR}/lib/version.sh"

PROJECT_ROOT="$(pwd)"
CONFIG_FILE="${PROJECT_ROOT}/cwm-build.config.json"

usage() {
    cat <<'CWM_HELP'
cwm-baseline — fetch the released package an upgrade test upgrades from.

WHAT IT DOES
  Resolves which released version is the right "before" state, then downloads
  that release's artifact into the project's build output directory.

  Resolution picks the newest release strictly older than the target that is
  at or above the configured floor, preferring stable releases and falling
  back to pre-releases only when no stable one qualifies.

  Already-downloaded baselines are left alone, so re-running is cheap.

PREREQUISITES
  - cwm-build.config.json with github.owner, github.repo and build.outputGlob
  - gh CLI, authenticated against that repo

USAGE
  composer cwm-baseline                     # resolve and fetch
  composer cwm-baseline -- --target 5.7.1   # resolve relative to this version
  composer cwm-baseline -- --version 5.6.2  # fetch this release, no resolution
  composer cwm-baseline -- --print          # print the resolution, fetch nothing

OPTIONS
  --target <ver>   Resolve relative to this version instead of the manifest's.
                   During a release, CWM_RELEASE_VERSION is used automatically.
  --version <ver>  Use this baseline verbatim. Skips resolution entirely.
  --print          Print the resolved version and exit without downloading.
  -h, --help       Show this and exit.

CONFIGURATION
  github.owner / github.repo   Which repo's releases to read.
  build.outputGlob             Names the artifact and the directory it lands
                               in. Must contain exactly one '*'.
  baseline.minimum             Optional. Releases older than this are never
                               chosen — for projects whose early packages do
                               not install at all.

EXIT CODE
  0  a baseline is present in the output directory
  1  an error: bad config, gh missing or failing (including an auth failure,
     which is deliberately NOT reported as 3), asset not on the release
  3  no usable baseline exists — the first release of a project, or every
     candidate below the floor. NOT a failure. A caller that treats this as
     fatal blocks the first release of every new project; one that treats it
     as success reports an upgrade test that never ran.

RELATED
  composer cwm-release      # runs the test:release gate before bumping
  composer cwm-install-zip  # install a built or downloaded zip
CWM_HELP
}

TARGET=""
EXPLICIT_VERSION=""
PRINT_ONLY=0

while [ $# -gt 0 ]; do
    case "$1" in
        -h|--help) usage; exit 0 ;;
        --print)   PRINT_ONLY=1; shift ;;
        --target)  TARGET="${2:-}"; shift 2 ;;
        --version) EXPLICIT_VERSION="${2:-}"; shift 2 ;;
        *)
            echo "Error: unknown option '$1'." >&2
            echo >&2
            usage >&2
            exit 1
            ;;
    esac
done

if [ ! -f "$CONFIG_FILE" ]; then
    echo "Error: $CONFIG_FILE not found" >&2
    exit 1
fi

read_config() {
    php -r "\$c = json_decode(file_get_contents('${CONFIG_FILE}'), true); \$keys = explode('.', '$1'); \$v = \$c; foreach (\$keys as \$k) { \$v = \$v[\$k] ?? null; if (\$v === null) break; } echo \$v ?? '';"
}

GH_OWNER=$(read_config "github.owner")
GH_REPO=$(read_config "github.repo")
OUTPUT_GLOB=$(read_config "build.outputGlob")
FLOOR=$(read_config "baseline.minimum")

if [ -z "$GH_OWNER" ] || [ -z "$GH_REPO" ]; then
    echo "Error: github.owner and github.repo must be set in cwm-build.config.json" >&2
    exit 1
fi

if [ -z "$OUTPUT_GLOB" ]; then
    echo "Error: build.outputGlob must be set in cwm-build.config.json" >&2
    exit 1
fi

REPO="${GH_OWNER}/${GH_REPO}"
DIST="$(cwm_baseline_dir_from_glob "$OUTPUT_GLOB")"

# --- Resolve the baseline version ---
if [ -n "$EXPLICIT_VERSION" ]; then
    BASEVER="$EXPLICIT_VERSION"
    echo "Baseline named explicitly: ${BASEVER}"
else
    # The target: an explicit --target, else the version cwm-release is about
    # to ship, else the manifest. CWM_RELEASE_VERSION matters because the gate
    # runs before the bump, so during a release the manifest still carries the
    # previous cycle's version and resolving against it selects a baseline one
    # release too old (#101).
    if [ -z "$TARGET" ]; then
        TARGET="${CWM_RELEASE_VERSION:-}"
    fi

    if [ -z "$TARGET" ]; then
        PKG_MANIFEST=$(read_config "manifests.package")

        if [ -n "$PKG_MANIFEST" ]; then
            TARGET=$(cwm_version_from_manifest "$PKG_MANIFEST" || true)
        fi
    fi

    if [ -z "$TARGET" ]; then
        echo "Error: could not determine the target version." >&2
        echo "       Pass --target <ver>, or set manifests.package in cwm-build.config.json." >&2
        exit 1
    fi

    if ! command -v gh > /dev/null 2>&1; then
        echo "Error: gh CLI not found — needed to list ${REPO} releases." >&2
        echo "       Install it, or pass --version <ver> to name a baseline directly." >&2
        exit 1
    fi

    # `2>/dev/null || true` here would report an auth failure, an unreachable
    # network or a typo'd repo as "no releases" — and exit 3, which callers are
    # told to read as "not applicable, not a failure". A gate standing down
    # silently on an error it could have named is the #101 shape, in the tool
    # written to fix #101. So the two outcomes are kept apart (#125).
    GH_ERR="$(mktemp)"

    if ! RELEASES="$(gh release list --repo "$REPO" --limit 100 \
            --json tagName,isPrerelease \
            --jq '.[] | [.tagName, (.isPrerelease | tostring)] | @tsv' 2>"$GH_ERR")"; then
        echo "Error: could not list releases for ${REPO}." >&2

        if [ -s "$GH_ERR" ]; then
            sed 's/^/  gh: /' "$GH_ERR" >&2
        fi

        echo "  This is not the same as having no usable baseline: check that gh is" >&2
        echo "  authenticated for this repo (gh auth status), or name one explicitly" >&2
        echo "  with --version <ver>." >&2
        rm -f "$GH_ERR"
        exit 1
    fi

    rm -f "$GH_ERR"

    if [ -z "$RELEASES" ]; then
        echo "NOT APPLICABLE: ${REPO} has no releases to use as a baseline." >&2
        exit 3
    fi

    if ! BASEVER="$(cwm_select_baseline_version "$TARGET" "$FLOOR" "$RELEASES")"; then
        echo "NOT APPLICABLE: no released package older than ${TARGET} is usable as a baseline." >&2

        if [ -n "$FLOOR" ]; then
            echo "                Releases below baseline.minimum (${FLOOR}) are excluded." >&2
        fi

        echo "                To name one explicitly: cwm-baseline --version <ver>" >&2
        exit 3
    fi

    echo "Baseline for ${TARGET}: ${BASEVER}"
fi

if [ "$PRINT_ONLY" = "1" ]; then
    printf '%s\n' "$BASEVER"
    exit 0
fi

# --- Fetch it ---
ASSET="$(cwm_baseline_asset_from_glob "$OUTPUT_GLOB" "$BASEVER")" || exit 1
TAG="$(cwm_tag_for_version "$BASEVER")"
OUT="${DIST}/${ASSET}"

mkdir -p "$DIST"

if [ -f "$OUT" ]; then
    echo "Already present: ${OUT}"
    exit 0
fi

if ! command -v gh > /dev/null 2>&1; then
    echo "Error: gh CLI not found — needed to download the ${TAG} release asset." >&2
    exit 1
fi

echo "Downloading ${ASSET} from release ${TAG}..."

if ! gh release download "$TAG" --repo "$REPO" --pattern "$ASSET" --dir "$DIST" --clobber; then
    echo "Error: could not download ${ASSET} from ${TAG}." >&2
    echo "       Check the release's assets: gh release view ${TAG} --repo ${REPO}" >&2
    exit 1
fi

if [ ! -f "$OUT" ]; then
    echo "Error: ${ASSET} is not attached to release ${TAG}." >&2
    echo "       Check the release's assets: gh release view ${TAG} --repo ${REPO}" >&2
    exit 1
fi

echo "Baseline ready: ${OUT}"

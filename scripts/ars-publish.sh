#!/usr/bin/env bash
#
# Publish a release artifact to Akeeba Release System (ARS) via its JSON API.
#
# Reads configuration from the consuming project's cwm-build.config.json.
# Required ars.* fields:
#   endpoint        Base URL of the Joomla site running ARS (no trailing slash)
#   categoryId      Numeric ARS category id for releases of this extension
#   updateStreamId  Numeric ARS update-stream id; the changelog URL is also
#                   patched on this stream when changelogUrl is set
#   environments    Non-empty JSON array of ARS environment ids (Joomla / PHP
#                   versions). Required: publishing an item with no
#                   environments makes ARS emit update XML with wrong
#                   php_minimum / targetplatform values that block updates
#                   on every real site.
# Optional ars.* fields:
#   tokenItem        1Password item label (default: "CWM ARS API Token")
#   tokenVault       1Password vault (default: "CWM")
#   zipPrefix        Override the artifact prefix when scanning local builds.
#                    If unset, the script reads the prefix from extension.name.
#   aliasPrefix      Slug prefix for the ARS Release alias. Defaults to
#                    extension.name with the "pkg_"/"com_"/"lib_" stripped.
#   itemDescription  HTML-friendly description for the download Item shown
#                    on the ARS public page. Defaults to extension.name.
#
# Note: there is no `ars.changelogUrl` setting any longer. Modern ARS
# (v7.x) does not expose a changelog field on update streams; Joomla
# reads `<changelogurl>` from the installed extension manifest instead.
# The URL belongs in the manifest XML and in `changelog.url` for the
# publish helper, not on the ARS update stream.
#
# Required github.* fields:
#   owner, repo                Used to resolve the GitHub release for the
#                              version and to construct download URLs.
#
# Required env (or 1Password integration):
#   ARS_API_TOKEN              Joomla API token. If unset, the script asks
#                              1Password CLI for the configured tokenItem.
#
# Usage:
#   bash scripts/ars-publish.sh -v <version> -f <path-to-zip> [-n <notes-file>]
#
# Release notes:
#   ARS renders a release's notes as HTML on the public download page. Notes are
#   authored in Markdown and converted by scripts/render-notes.php before they
#   are sent — publishing Markdown directly leaves "##" and "**" literal and
#   collapses the whole changelog onto one line.
#
#   -n <file> (or ARS_NOTES_FILE) supplies notes written for the people reading
#   that page. Without it the notes fall back to the GitHub release body, which
#   is normally GitHub's auto-generated list of pull request titles.
#
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(pwd)"

# shellcheck source=lib/version.sh
source "${SCRIPT_DIR}/lib/version.sh"
# shellcheck source=lib/ars.sh
source "${SCRIPT_DIR}/lib/ars.sh"
# shellcheck source=lib/optoken.sh
source "${SCRIPT_DIR}/lib/optoken.sh"

CONFIG_FILE="${PROJECT_ROOT}/cwm-build.config.json"

if [ ! -f "$CONFIG_FILE" ]; then
    echo "Error: $CONFIG_FILE not found"
    exit 1
fi

# --- Helpers ---
read_config() {
    php -r "\$c = json_decode(file_get_contents('${CONFIG_FILE}'), true); \$keys = explode('.', '$1'); \$v = \$c; foreach (\$keys as \$k) { \$v = \$v[\$k] ?? null; if (\$v === null) break; } echo \$v ?? '';"
}

read_config_json() {
    php -r "\$c = json_decode(file_get_contents('${CONFIG_FILE}'), true); \$keys = explode('.', '$1'); \$v = \$c; foreach (\$keys as \$k) { \$v = \$v[\$k] ?? null; if (\$v === null) break; } echo json_encode(\$v ?? null);"
}

# --- Parse args ---
VERSION=""
ZIP_PATH=""
NOTES_FILE="${ARS_NOTES_FILE:-}"
while getopts "v:f:n:" opt; do
    case "$opt" in
        v) VERSION="$OPTARG" ;;
        f) ZIP_PATH="$OPTARG" ;;
        n) NOTES_FILE="$OPTARG" ;;
        *) echo "Usage: ars-publish.sh -v <version> -f <path-to-zip> [-n <notes-file>]"; exit 1 ;;
    esac
done

if [ -z "$VERSION" ] || [ -z "$ZIP_PATH" ]; then
    echo "Usage: ars-publish.sh -v <version> -f <path-to-zip> [-n <notes-file>]"
    exit 1
fi

if [ -n "$NOTES_FILE" ] && [ ! -f "$NOTES_FILE" ]; then
    echo "Error: notes file not found: $NOTES_FILE"
    exit 1
fi

if [ ! -f "$ZIP_PATH" ]; then
    echo "Error: artifact not found: $ZIP_PATH"
    exit 1
fi

# --- Read config ---
SITE_URL=$(read_config "ars.endpoint")
ARS_CATEGORY_ID=$(read_config "ars.categoryId")
ARS_UPDATE_STREAM_ID=$(read_config "ars.updateStreamId")
ARS_ENVIRONMENTS=$(read_config_json "ars.environments")
TOKEN_ITEM=$(read_config "ars.tokenItem")
TOKEN_VAULT=$(read_config "ars.tokenVault")
ZIP_PREFIX=$(read_config "ars.zipPrefix")
ALIAS_PREFIX=$(read_config "ars.aliasPrefix")
ITEM_DESCRIPTION=$(read_config "ars.itemDescription")

EXT_NAME=$(read_config "extension.name")
GH_OWNER=$(read_config "github.owner")
GH_REPO=$(read_config "github.repo")

if [ -z "$SITE_URL" ] || [ -z "$ARS_CATEGORY_ID" ] || [ -z "$ARS_UPDATE_STREAM_ID" ]; then
    echo "Error: ars.endpoint, ars.categoryId, ars.updateStreamId are required in cwm-build.config.json"
    exit 1
fi

# ars.environments must be a non-empty array: an item published without
# environments makes ARS emit update XML with bogus php_minimum /
# targetplatform requirements that block the update on every real site.
if ! cwm_ars_validate_environments "$ARS_ENVIRONMENTS"; then
    echo "Error: ars.environments must be a non-empty JSON array of ARS environment ids in cwm-build.config.json (got: ${ARS_ENVIRONMENTS})."
    echo "       Copy the ids from a known-good existing item: GET {endpoint}/api/index.php/v1/ars/items and read its \"environments\" attribute."
    exit 1
fi

if [ -z "$GH_OWNER" ] || [ -z "$GH_REPO" ]; then
    echo "Error: github.owner and github.repo are required in cwm-build.config.json"
    exit 1
fi

ZIP_PREFIX="${ZIP_PREFIX:-$EXT_NAME}"
TOKEN_ITEM="${TOKEN_ITEM:-CWM ARS API Token}"
TOKEN_VAULT="${TOKEN_VAULT:-CWM}"
ARS_ENVIRONMENTS="${ARS_ENVIRONMENTS:-null}"

# Naming and version derivation live in lib/ars.sh and lib/version.sh so
# they can be tested — see #52.
ALIAS_PREFIX=$(cwm_ars_alias_prefix "$EXT_NAME" "$ALIAS_PREFIX")

TAG=$(cwm_tag_for_version "$VERSION")
ALIAS=$(cwm_ars_release_alias "$ALIAS_PREFIX" "$VERSION")
ZIP_NAME=$(basename "$ZIP_PATH")
GITHUB_DOWNLOAD_URL="https://github.com/${GH_OWNER}/${GH_REPO}/releases/download/${TAG}/${ZIP_NAME}"

ARS_MATURITY=$(cwm_maturity_for_version "$VERSION")

echo "Publishing ${EXT_NAME} ${VERSION} to ARS (maturity: ${ARS_MATURITY})..."
echo "  endpoint:      ${SITE_URL}"
echo "  category:      ${ARS_CATEGORY_ID}"
echo "  artifact:      ${ZIP_NAME}"
echo "  download URL:  ${GITHUB_DOWNLOAD_URL}"

# --- Get API token ---
# Retrieval, and the reason when it fails, live in lib/optoken.sh (#126).
TOKEN="$(cwm_op_token "$TOKEN_ITEM" "$TOKEN_VAULT")" || exit 1

# --- Verify GitHub release exists, and read what we need from it ---
#
# Through `gh`, not a bare curl to api.github.com. The unauthenticated call
# this replaces answered 404 on a PRIVATE repository whether or not the release
# existed, so publishing stopped and told the reader to create a release that
# was already there — with no flag to skip it, which left private-repo
# consumers unable to publish at all (#125).
#
# It was redundant as well as broken: the statements below already read the
# same release through authenticated `gh`. One call now does both, so there is
# no second way for the check and the read to disagree.
echo "Verifying GitHub release ${TAG}..."
GH_ERR="$(mktemp)"

if ! RELEASE_DATE=$(gh release view "$TAG" --repo "${GH_OWNER}/${GH_REPO}" \
        --json publishedAt --jq '.publishedAt' 2>"$GH_ERR"); then
    echo "Error: GitHub release ${TAG} not found in ${GH_OWNER}/${GH_REPO}."

    if [ -s "$GH_ERR" ]; then
        sed 's/^/  gh: /' "$GH_ERR"
    fi

    echo "  Create it first: gh release create ${TAG} ${ZIP_PATH}"
    echo "  If the release does exist, check that gh is authenticated for this repo:"
    echo "    gh auth status"
    rm -f "$GH_ERR"
    exit 1
fi

rm -f "$GH_ERR"
RELEASE_DATE=$(printf '%s' "$RELEASE_DATE" | sed 's/T/ /' | sed 's/Z//')

ASSET_INFO=$(gh release view "$TAG" --repo "${GH_OWNER}/${GH_REPO}" --json assets --jq ".assets[] | select(.name==\"${ZIP_NAME}\")")

if [ -z "$ASSET_INFO" ]; then
    echo "Error: Asset ${ZIP_NAME} not found in GitHub release ${TAG}."
    exit 1
fi

FILESIZE=$(echo "$ASSET_INFO" | python3 -c "import json,sys; print(json.load(sys.stdin)['size'])" 2>/dev/null || echo "0")
ASSET_DIGEST=$(echo "$ASSET_INFO" | python3 -c "import json,sys; print(json.load(sys.stdin).get('digest') or '')" 2>/dev/null || echo "")

# --- Checksums must describe the bytes users download ---
#
# This item is `type: link`: it points at the GitHub asset, so a site downloads
# THAT, not whatever is in build/dist right now. Publishing the local file's
# checksums when the two differ makes Joomla fetch the asset, compare it
# against the advertised sha512 and refuse the update — while the feed stays
# valid and ARS reports success (#132).
#
# So the local file is only trusted once it is shown to be the served bytes.
# When it is not, the asset itself is downloaded and hashed.
echo "Computing checksums..."
HASH_SOURCE="$ZIP_PATH"
LOCAL_SIZE=$(wc -c < "$ZIP_PATH" | tr -d ' ')
LOCAL_SHA256=$(shasum -a 256 "$ZIP_PATH" 2>/dev/null | cut -d' ' -f1 || echo "")

cwm_ars_local_matches_asset "$LOCAL_SIZE" "$LOCAL_SHA256" "$FILESIZE" "$ASSET_DIGEST"
MATCH_STATUS=$?

if [ "$MATCH_STATUS" -ne 0 ]; then
    if [ "$MATCH_STATUS" -eq 1 ]; then
        echo "  The local artifact is NOT the published asset:"
        echo "    local: ${LOCAL_SIZE} bytes, sha256 ${LOCAL_SHA256}"
        echo "    asset: ${FILESIZE} bytes, ${ASSET_DIGEST:-no digest reported}"
        echo "  Hashing the asset instead, so the update stream advertises what sites receive."
        echo "  (A rebuild between build and publish is the usual cause: gzip records an"
        echo "   mtime, so .gz output differs from identical sources.)"
    else
        echo "  GitHub reported no digest for this asset, so the local file cannot be"
        echo "  shown to be the served bytes. Hashing the asset to be certain."
    fi

    ASSET_DIR=$(mktemp -d)

    if gh release download "$TAG" --repo "${GH_OWNER}/${GH_REPO}" \
            --pattern "$ZIP_NAME" --dir "$ASSET_DIR" >/dev/null 2>&1 \
        && [ -f "${ASSET_DIR}/${ZIP_NAME}" ]; then
        HASH_SOURCE="${ASSET_DIR}/${ZIP_NAME}"
    else
        echo "Error: could not download ${ZIP_NAME} from ${TAG} to checksum it."
        echo "  Publishing the local file's checksums would advertise bytes no site receives,"
        echo "  and every update would fail its integrity check. Refusing to publish."
        rm -rf "$ASSET_DIR"
        exit 1
    fi
else
    echo "  Local artifact matches the published asset (${LOCAL_SIZE} bytes, sha256 verified)."
fi

MD5=$(md5 -q "$HASH_SOURCE" 2>/dev/null || md5sum "$HASH_SOURCE" 2>/dev/null | cut -d' ' -f1 || echo "")
SHA1=$(shasum -a 1 "$HASH_SOURCE" 2>/dev/null | cut -d' ' -f1 || echo "")
SHA256=$(shasum -a 256 "$HASH_SOURCE" 2>/dev/null | cut -d' ' -f1 || echo "")
SHA384=$(shasum -a 384 "$HASH_SOURCE" 2>/dev/null | cut -d' ' -f1 || echo "")
SHA512=$(shasum -a 512 "$HASH_SOURCE" 2>/dev/null | cut -d' ' -f1 || echo "")

[ -n "${ASSET_DIR:-}" ] && rm -rf "$ASSET_DIR"

# --- Assemble the release notes ---
#
# ARS renders `notes` as HTML, so Markdown cannot be handed over as-is: the
# public 10.3.6 download page showed "## What's Changed * fix(api): ..." with
# every marker literal and every newline collapsed. Whatever the source, the
# text is Markdown and gets converted before it is sent.
#
# The source is a hand-written notes file when one is given (-n, or
# ARS_NOTES_FILE), falling back to the GitHub release body. The fallback is
# GitHub's auto-generated list of pull request titles, which is accurate but
# written for us rather than for the administrator reading the download page.
if [ -n "$NOTES_FILE" ]; then
    echo "Using release notes from ${NOTES_FILE}"
    RELEASE_NOTES=$(cat "$NOTES_FILE")
else
    RELEASE_NOTES=$(gh release view "$TAG" --repo "${GH_OWNER}/${GH_REPO}" --json body --jq '.body' 2>/dev/null || echo "")
fi

# No JSON encoding step here any more: the notes travel to the publisher as
# plain text in the environment, and the payload is assembled with
# json_encode on the PHP side.
NOTES_HTML=$(printf '%s' "$RELEASE_NOTES" | php "${SCRIPT_DIR}/render-notes.php")

# --- Upsert the release and its download item ---
#
# Both upserts happen in PHP (src/Release/ArsPublisher.php) rather than here.
# They are create-or-update decisions against a live public download page and
# they report success either way: a lookup that wrongly misses publishes a
# second release for a version that already exists (#37), and a lookup that
# wrongly hits PATCHes over a different one. That is logic which has to be
# exercised by tests instead of by releasing something, and a curl pipeline
# cannot be.
#
# The API quirks those lookups have to respect — bare query parameters rather
# than JSON:API `filter[...]`, `page[limit]` to raise the 20-row default, a
# flat JSON body, the vnd.api+json Accept header — are documented against the
# release-system source in ArsPublisher's class docblock.
#
# Values go over the environment, not argv: the token would otherwise be
# visible to every user on the machine through `ps`.
echo "Publishing release and download item..."

CWM_ARS_TOKEN="$TOKEN" \
CWM_ARS_VERSION="$VERSION" \
CWM_ARS_ALIAS="$ALIAS" \
CWM_ARS_MATURITY="$ARS_MATURITY" \
CWM_ARS_NOTES_HTML="$NOTES_HTML" \
CWM_ARS_RELEASE_DATE="$RELEASE_DATE" \
CWM_ARS_ZIP_NAME="$ZIP_NAME" \
CWM_ARS_DOWNLOAD_URL="$GITHUB_DOWNLOAD_URL" \
CWM_ARS_ITEM_DESCRIPTION="${ITEM_DESCRIPTION:-$EXT_NAME}" \
CWM_ARS_FILESIZE="$FILESIZE" \
CWM_ARS_MD5="$MD5" \
CWM_ARS_SHA1="$SHA1" \
CWM_ARS_SHA256="$SHA256" \
CWM_ARS_SHA384="$SHA384" \
CWM_ARS_SHA512="$SHA512" \
    php "${SCRIPT_DIR}/ars-publish-api.php"

echo ""
echo "Done! ${EXT_NAME} ${VERSION} published to ARS."
echo "  GitHub:      https://github.com/${GH_OWNER}/${GH_REPO}/releases/tag/${TAG}"

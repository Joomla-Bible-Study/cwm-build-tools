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
# Optional ars.* fields:
#   environments     JSON array of ARS environment ids (Joomla / PHP versions)
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

API_BASE="${SITE_URL%/}/api/index.php/v1/ars"
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
if [ -n "${ARS_API_TOKEN:-}" ]; then
    TOKEN="$ARS_API_TOKEN"
    echo "Using ARS_API_TOKEN from environment."
elif command -v op >/dev/null 2>&1; then
    echo "Retrieving API token from 1Password (item: '${TOKEN_ITEM}', vault: '${TOKEN_VAULT}')..."
    TOKEN=$(op item get "$TOKEN_ITEM" --vault "$TOKEN_VAULT" --fields label=credential --reveal 2>/dev/null || echo "")
else
    echo "Error: ARS_API_TOKEN not set and 1Password CLI (op) not installed."
    exit 1
fi

if [ -z "$TOKEN" ]; then
    echo "Error: Could not retrieve API token."
    exit 1
fi

# --- Verify GitHub release exists ---
echo "Verifying GitHub release ${TAG}..."
HTTP_CODE=$(curl -s -o /dev/null -w "%{http_code}" "https://api.github.com/repos/${GH_OWNER}/${GH_REPO}/releases/tags/${TAG}")

if [ "$HTTP_CODE" != "200" ]; then
    echo "Error: GitHub release ${TAG} not found (HTTP ${HTTP_CODE})."
    echo "Create the release first: gh release create ${TAG} ${ZIP_PATH}"
    exit 1
fi

# --- Get release date and asset info from GitHub ---
RELEASE_DATE=$(gh release view "$TAG" --repo "${GH_OWNER}/${GH_REPO}" --json publishedAt --jq '.publishedAt' 2>/dev/null | sed 's/T/ /' | sed 's/Z//' || echo "")

ASSET_INFO=$(gh release view "$TAG" --repo "${GH_OWNER}/${GH_REPO}" --json assets --jq ".assets[] | select(.name==\"${ZIP_NAME}\")")

if [ -z "$ASSET_INFO" ]; then
    echo "Error: Asset ${ZIP_NAME} not found in GitHub release ${TAG}."
    exit 1
fi

FILESIZE=$(echo "$ASSET_INFO" | python3 -c "import json,sys; print(json.load(sys.stdin)['size'])" 2>/dev/null || echo "0")

# --- Compute checksums from local file ---
echo "Computing checksums..."
MD5=$(md5 -q "$ZIP_PATH" 2>/dev/null || md5sum "$ZIP_PATH" 2>/dev/null | cut -d' ' -f1 || echo "")
SHA1=$(shasum -a 1 "$ZIP_PATH" 2>/dev/null | cut -d' ' -f1 || echo "")
SHA256=$(shasum -a 256 "$ZIP_PATH" 2>/dev/null | cut -d' ' -f1 || echo "")
SHA384=$(shasum -a 384 "$ZIP_PATH" 2>/dev/null | cut -d' ' -f1 || echo "")
SHA512=$(shasum -a 512 "$ZIP_PATH" 2>/dev/null | cut -d' ' -f1 || echo "")

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

NOTES_HTML=$(printf '%s' "$RELEASE_NOTES" | php "${SCRIPT_DIR}/render-notes.php")
NOTES_JSON=$(printf '%s' "$NOTES_HTML" | python3 -c 'import json,sys; print(json.dumps(sys.stdin.read()))')

# --- Check if ARS release already exists ---
#
# ARS reads bare query parameters, not JSON:API `filter[...]` syntax — see
# component/api/src/Controller/ReleasesController.php, which maps the input key
# `category_id` onto `filter.category_id`. Sent as `filter[category_id]` it
# arrives as a PHP array named `filter`, the lookup returns null, and the filter
# is silently not applied.
#
# That mattered because the response is also capped at 20 rows by default: this
# read was matching the wanted version against an arbitrary 20-row window of
# every release on the site, in an order that is not id, version or date. A miss
# takes the create branch below and publishes a *second* release for a version
# that already exists, reporting success either way.
#
# `page[limit]` is the parameter that raises the cap; `list[limit]` is ignored.
echo "Checking for existing ARS release..."
EXISTING=$(curl -s \
    -H "X-Joomla-Token: ${TOKEN}" \
    -H "Accept: application/vnd.api+json" \
    "${API_BASE}/releases?category_id=${ARS_CATEGORY_ID}&search=${VERSION}&page%5Blimit%5D=200")

# The exact-version match lives in lib/ars.sh so it can be tested — see #52.
EXISTING_ID=$(echo "$EXISTING" | cwm_ars_find_release_id "$VERSION")

if [ -n "$EXISTING_ID" ]; then
    echo "ARS release already exists (ID: ${EXISTING_ID}). Updating..."
    RELEASE_ID="$EXISTING_ID"

    curl -s -X PATCH \
        -H "X-Joomla-Token: ${TOKEN}" \
        -H "Accept: application/vnd.api+json" \
        -H "Content-Type: application/json" \
        -d "{
            \"id\": ${RELEASE_ID},
            \"category_id\": ${ARS_CATEGORY_ID},
            \"version\": \"${VERSION}\",
            \"alias\": \"${ALIAS}\",
            \"maturity\": \"${ARS_MATURITY}\",
            \"notes\": ${NOTES_JSON},
            \"created\": \"${RELEASE_DATE}\",
            \"published\": 1
        }" \
        "${API_BASE}/releases/${RELEASE_ID}" > /dev/null

    echo "Release updated."
else
    echo "Creating new ARS release..."
    RESPONSE=$(curl -s -X POST \
        -H "X-Joomla-Token: ${TOKEN}" \
        -H "Accept: application/vnd.api+json" \
        -H "Content-Type: application/json" \
        -d "{
            \"category_id\": ${ARS_CATEGORY_ID},
            \"version\": \"${VERSION}\",
            \"alias\": \"${ALIAS}\",
            \"maturity\": \"${ARS_MATURITY}\",
            \"notes\": ${NOTES_JSON},
            \"created\": \"${RELEASE_DATE}\",
            \"published\": 1,
            \"access\": 1,
            \"show_unauth_links\": 0,
            \"redirect_unauth\": \"\",
            \"language\": \"*\"
        }" \
        "${API_BASE}/releases")

    RELEASE_ID=$(echo "$RESPONSE" | python3 -c "import json,sys; print(json.load(sys.stdin)['data']['attributes']['id'])" 2>/dev/null || echo "")

    if [ -z "$RELEASE_ID" ]; then
        echo "Error: Failed to create ARS release."
        echo "$RESPONSE" | python3 -m json.tool 2>/dev/null || echo "$RESPONSE"
        exit 1
    fi

    echo "Release created (ID: ${RELEASE_ID})."
fi

# --- Create or update download item ---
echo "Adding download item..."

# Same correction as the release lookup above: ItemsController maps the bare
# input key `release_id`. Sent as `filter[release_id]` this returned 20 rows
# spanning 19 different releases, and a miss here creates a duplicate download
# item on the release.
EXISTING_ITEM=$(curl -s \
    -H "X-Joomla-Token: ${TOKEN}" \
    -H "Accept: application/vnd.api+json" \
    "${API_BASE}/items?release_id=${RELEASE_ID}&page%5Blimit%5D=200")

# Basename match in lib/ars.sh, same reason.
EXISTING_ITEM_ID=$(echo "$EXISTING_ITEM" | cwm_ars_find_item_id "$ZIP_NAME")

DESCRIPTION_TEXT="${ITEM_DESCRIPTION:-$EXT_NAME}"
DESCRIPTION_JSON=$(printf '%s' "$DESCRIPTION_TEXT" | python3 -c 'import json,sys; print(json.dumps(sys.stdin.read()))')

ITEM_PAYLOAD="{
    \"release_id\": ${RELEASE_ID},
    \"title\": \"${ZIP_NAME%.zip}\",
    \"alias\": \"${ZIP_NAME%.zip}\",
    \"description\": ${DESCRIPTION_JSON},
    \"type\": \"link\",
    \"url\": \"${GITHUB_DOWNLOAD_URL}\",
    \"updatestream\": ${ARS_UPDATE_STREAM_ID},
    \"md5\": \"${MD5}\",
    \"sha1\": \"${SHA1}\",
    \"sha256\": \"${SHA256}\",
    \"sha384\": \"${SHA384}\",
    \"sha512\": \"${SHA512}\",
    \"filesize\": ${FILESIZE},
    \"published\": 1,
    \"access\": 1,
    \"show_unauth_links\": 0,
    \"redirect_unauth\": \"\",
    \"language\": \"*\",
    \"environments\": ${ARS_ENVIRONMENTS}
}"

if [ -n "$EXISTING_ITEM_ID" ]; then
    echo "Item already exists (ID: ${EXISTING_ITEM_ID}). Updating..."
    curl -s -X PATCH \
        -H "X-Joomla-Token: ${TOKEN}" \
        -H "Accept: application/vnd.api+json" \
        -H "Content-Type: application/json" \
        -d "$ITEM_PAYLOAD" \
        "${API_BASE}/items/${EXISTING_ITEM_ID}" > /dev/null
    echo "Item updated."
else
    ITEM_RESPONSE=$(curl -s -X POST \
        -H "X-Joomla-Token: ${TOKEN}" \
        -H "Accept: application/vnd.api+json" \
        -H "Content-Type: application/json" \
        -d "$ITEM_PAYLOAD" \
        "${API_BASE}/items")

    ITEM_ID=$(echo "$ITEM_RESPONSE" | python3 -c "import json,sys; print(json.load(sys.stdin)['data']['attributes']['id'])" 2>/dev/null || echo "")

    if [ -z "$ITEM_ID" ]; then
        echo "Error: Failed to create download item."
        echo "$ITEM_RESPONSE" | python3 -m json.tool 2>/dev/null || echo "$ITEM_RESPONSE"
        exit 1
    fi

    echo "Download item created (ID: ${ITEM_ID})."
fi

echo ""
echo "Done! ${EXT_NAME} ${VERSION} published to ARS."
echo "  ARS Release: ${SITE_URL}/index.php?option=com_ars&view=items&release_id=${RELEASE_ID}"
echo "  GitHub:      https://github.com/${GH_OWNER}/${GH_REPO}/releases/tag/${TAG}"

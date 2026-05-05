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
#   bash scripts/ars-publish.sh -v <version> -f <path-to-zip>
#
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
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

read_config_json() {
    php -r "\$c = json_decode(file_get_contents('${CONFIG_FILE}'), true); \$keys = explode('.', '$1'); \$v = \$c; foreach (\$keys as \$k) { \$v = \$v[\$k] ?? null; if (\$v === null) break; } echo json_encode(\$v ?? null);"
}

# --- Parse args ---
VERSION=""
ZIP_PATH=""
while getopts "v:f:" opt; do
    case "$opt" in
        v) VERSION="$OPTARG" ;;
        f) ZIP_PATH="$OPTARG" ;;
        *) echo "Usage: ars-publish.sh -v <version> -f <path-to-zip>"; exit 1 ;;
    esac
done

if [ -z "$VERSION" ] || [ -z "$ZIP_PATH" ]; then
    echo "Usage: ars-publish.sh -v <version> -f <path-to-zip>"
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
ALIAS_PREFIX="${ALIAS_PREFIX:-$(echo "$EXT_NAME" | sed -E 's/^(pkg_|com_|lib_|plg_|mod_|tpl_)//')}"
TOKEN_ITEM="${TOKEN_ITEM:-CWM ARS API Token}"
TOKEN_VAULT="${TOKEN_VAULT:-CWM}"
ARS_ENVIRONMENTS="${ARS_ENVIRONMENTS:-null}"

API_BASE="${SITE_URL%/}/api/index.php/v1/ars"
TAG="v${VERSION}"
ALIAS=$(echo "${ALIAS_PREFIX}-${VERSION}" | tr '.' '-')
ZIP_NAME=$(basename "$ZIP_PATH")
GITHUB_DOWNLOAD_URL="https://github.com/${GH_OWNER}/${GH_REPO}/releases/download/${TAG}/${ZIP_NAME}"

# Determine ARS maturity from version string
if [[ "$VERSION" == *-alpha* ]]; then
    ARS_MATURITY="alpha"
elif [[ "$VERSION" == *-beta* ]]; then
    ARS_MATURITY="beta"
elif [[ "$VERSION" == *-rc* ]]; then
    ARS_MATURITY="rc"
else
    ARS_MATURITY="stable"
fi

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

# --- Get GitHub release notes ---
RELEASE_NOTES=$(gh release view "$TAG" --repo "${GH_OWNER}/${GH_REPO}" --json body --jq '.body' 2>/dev/null || echo "")

# --- Check if ARS release already exists ---
echo "Checking for existing ARS release..."
EXISTING=$(curl -s \
    -H "X-Joomla-Token: ${TOKEN}" \
    -H "Accept: application/vnd.api+json" \
    "${API_BASE}/releases?filter%5Bcategory_id%5D=${ARS_CATEGORY_ID}&filter%5Bsearch%5D=${VERSION}")

EXISTING_ID=$(echo "$EXISTING" | python3 -c "
import json,sys
d=json.load(sys.stdin)
for r in d.get('data',[]):
    if r['attributes']['version'] == '${VERSION}':
        print(r['attributes']['id'])
        break
" 2>/dev/null || echo "")

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
            \"notes\": $(echo "$RELEASE_NOTES" | python3 -c 'import json,sys; print(json.dumps(sys.stdin.read()))'),
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
            \"notes\": $(echo "$RELEASE_NOTES" | python3 -c 'import json,sys; print(json.dumps(sys.stdin.read()))'),
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

EXISTING_ITEM=$(curl -s \
    -H "X-Joomla-Token: ${TOKEN}" \
    -H "Accept: application/vnd.api+json" \
    "${API_BASE}/items?filter%5Brelease_id%5D=${RELEASE_ID}")

EXISTING_ITEM_ID=$(echo "$EXISTING_ITEM" | python3 -c "
import json,sys
d=json.load(sys.stdin)
for i in d.get('data',[]):
    if i['attributes'].get('url','').endswith('${ZIP_NAME}'):
        print(i['attributes']['id'])
        break
" 2>/dev/null || echo "")

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

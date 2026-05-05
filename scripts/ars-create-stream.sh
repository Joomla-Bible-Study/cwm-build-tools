#!/usr/bin/env bash
#
# Create a new ARS Update Stream under a given category, via the Joomla API.
#
# NOTE: plg_webservices_ars does not register a CRUD route for
# /v1/ars/updatestreams — only /categories, /releases, and /items are
# wired up in beforeAPIRoute(). Every verb on /updatestreams (GET list,
# GET by id, POST, PATCH) therefore returns 404. cwm-ars-publish is
# unaffected because it never touches /updatestreams (it embeds the
# numeric id in the /items payload); only this script and
# `cwm-ars-list streams` need the missing route.
#
# Until plg_webservices_ars adds the route, create the stream via the
# admin UI (System → Manage → Akeeba Release System → Update Streams →
# New) and read its id from the grid (enable the ID column via the
# column picker, or hover the edit link to read it from the URL).
# Paste that id into cwm-build.config.json under ars.updateStreamId.
# This script is kept for the day the API supports creation again — a
# one-line patch to plg_webservices_ars/src/Extension/Ars.php would fix
# it: createCRUDRoutes('v1/ars/updatestreams', 'updatestreams',
# ['component' => 'com_ars']).
#
# A typical Joomla extension package needs at least:
#   - one stream per top-level extension that's installed standalone (e.g.
#     a package gets its own, a library gets its own, etc.)
#
# Reads ars.endpoint, ars.tokenItem, ars.tokenVault from cwm-build.config.json.
# ARS_API_TOKEN env var override supported.
#
# Usage:
#   bash scripts/ars-create-stream.sh \
#     --name "CWM Scripture Package" \
#     --element pkg_cwmscripture \
#     --type package \
#     --category-id 5
#
# Optional:
#   --published 0|1   (default 1)
#
set -euo pipefail

PROJECT_ROOT="$(pwd)"
CONFIG_FILE="${PROJECT_ROOT}/cwm-build.config.json"

if [ ! -f "$CONFIG_FILE" ]; then
    echo "Error: $CONFIG_FILE not found"
    exit 1
fi

read_config() {
    php -r "\$c = json_decode(file_get_contents('${CONFIG_FILE}'), true); \$keys = explode('.', '$1'); \$v = \$c; foreach (\$keys as \$k) { \$v = \$v[\$k] ?? null; if (\$v === null) break; } echo \$v ?? '';"
}

NAME=""
ELEMENT=""
TYPE=""
CATEGORY_ID=""
PUBLISHED="1"

while [ $# -gt 0 ]; do
    case "$1" in
        --name)        NAME="$2"; shift 2 ;;
        --element)     ELEMENT="$2"; shift 2 ;;
        --type)        TYPE="$2"; shift 2 ;;
        --category-id) CATEGORY_ID="$2"; shift 2 ;;
        --published)   PUBLISHED="$2"; shift 2 ;;
        *) echo "Unknown option: $1"; exit 1 ;;
    esac
done

if [ -z "$NAME" ] || [ -z "$ELEMENT" ] || [ -z "$TYPE" ] || [ -z "$CATEGORY_ID" ]; then
    echo "Usage: ars-create-stream.sh --name <name> --element <element> --type <type> --category-id <id>"
    echo "Example:"
    echo "  ars-create-stream.sh --name 'Scripture Package' --element pkg_cwmscripture --type package --category-id 5"
    exit 1
fi

case "$TYPE" in
    package|component|library|plugin|module|template|file) ;;
    *) echo "Error: --type must be one of: package, component, library, plugin, module, template, file"; exit 1 ;;
esac

SITE_URL=$(read_config "ars.endpoint")
TOKEN_ITEM=$(read_config "ars.tokenItem")
TOKEN_VAULT=$(read_config "ars.tokenVault")
TOKEN_ITEM="${TOKEN_ITEM:-CWM ARS API Token}"
TOKEN_VAULT="${TOKEN_VAULT:-CWM}"
API_BASE="${SITE_URL%/}/api/index.php/v1/ars"

# --- Token ---
if [ -n "${ARS_API_TOKEN:-}" ]; then
    TOKEN="$ARS_API_TOKEN"
elif command -v op >/dev/null 2>&1; then
    TOKEN=$(op item get "$TOKEN_ITEM" --vault "$TOKEN_VAULT" --fields label=credential --reveal 2>/dev/null || echo "")
else
    echo "Error: ARS_API_TOKEN not set and 1Password CLI (op) not installed."
    exit 1
fi

if [ -z "$TOKEN" ]; then
    echo "Error: Could not retrieve API token."
    exit 1
fi

echo "Creating update stream:"
echo "  name:        $NAME"
echo "  element:     $ELEMENT"
echo "  type:        $TYPE"
echo "  category_id: $CATEGORY_ID"
echo "  published:   $PUBLISHED"

RESPONSE=$(curl -s -X POST \
    -H "X-Joomla-Token: ${TOKEN}" \
    -H "Accept: application/vnd.api+json" \
    -H "Content-Type: application/json" \
    -d "{
        \"name\": \"${NAME}\",
        \"alias\": \"${ELEMENT}\",
        \"element\": \"${ELEMENT}\",
        \"type\": \"${TYPE}\",
        \"category_id\": ${CATEGORY_ID},
        \"published\": ${PUBLISHED},
        \"access\": 1
    }" \
    "${API_BASE}/updatestreams")

NEW_ID=$(echo "$RESPONSE" | python3 -c "
import json, sys
try:
    d = json.load(sys.stdin)
    if 'data' in d and 'attributes' in d['data']:
        print(d['data']['attributes'].get('id', ''))
    elif 'errors' in d:
        for e in d['errors']:
            print('error:', e.get('title', ''), '-', e.get('detail', ''), file=sys.stderr)
except Exception as e:
    print('parse error:', e, file=sys.stderr)
" 2>&1)

if [[ "$NEW_ID" =~ ^[0-9]+$ ]]; then
    echo ""
    echo "Update stream created:"
    echo "  id: $NEW_ID"
    echo ""
    echo "Set this in cwm-build.config.json:"
    echo "  \"ars\": { \"updateStreamId\": $NEW_ID, ... }"
elif echo "$RESPONSE" | grep -q '"code":[[:space:]]*404'; then
    cat <<'EOF'

Failed: /v1/ars/updatestreams is not registered by plg_webservices_ars
on this site — every verb on that resource returns 404. Other ARS
endpoints (/categories, /releases, /items) work fine, so cwm-ars-publish
is unaffected; only update-stream creation/listing is missing.

Workaround — create the stream in the admin UI:
  System -> Manage -> Akeeba Release System -> Update Streams -> New
Then read the new stream ID from the grid (enable the ID column via the
column picker, or hover the edit link to see it in the URL) and paste
it into cwm-build.config.json under ars.updateStreamId.
EOF
    exit 1
else
    echo ""
    echo "Failed to create update stream."
    echo "API response:"
    echo "$RESPONSE" | python3 -m json.tool 2>/dev/null || echo "$RESPONSE"
    exit 1
fi

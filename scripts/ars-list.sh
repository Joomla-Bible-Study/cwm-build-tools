#!/usr/bin/env bash
#
# List ARS categories, update streams, and (optionally) releases for a
# given site. Useful when configuring cwm-build.config.json for a new
# project — pick the right categoryId / updateStreamId without having
# to log into the admin UI.
#
# Reads ars.endpoint, ars.tokenItem, ars.tokenVault from
# cwm-build.config.json. ARS_API_TOKEN env var override supported.
#
# Usage:
#   bash scripts/ars-list.sh                     # categories + update streams
#   bash scripts/ars-list.sh categories          # categories only
#   bash scripts/ars-list.sh streams             # update streams only
#   bash scripts/ars-list.sh releases <cat-id>   # releases in a category
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

SITE_URL=$(read_config "ars.endpoint")
TOKEN_ITEM=$(read_config "ars.tokenItem")
TOKEN_VAULT=$(read_config "ars.tokenVault")

TOKEN_ITEM="${TOKEN_ITEM:-CWM ARS API Token}"
TOKEN_VAULT="${TOKEN_VAULT:-CWM}"

if [ -z "$SITE_URL" ]; then
    echo "Error: ars.endpoint not configured in cwm-build.config.json"
    exit 1
fi

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

MODE="${1:-all}"

list_categories() {
    echo ""
    echo "ARS Categories @ ${SITE_URL}"
    echo "============================"
    curl -s -H "X-Joomla-Token: ${TOKEN}" -H "Accept: application/vnd.api+json" \
        "${API_BASE}/categories" \
        | python3 -c "
import json, sys
data = json.load(sys.stdin)
rows = data.get('data', [])
if not rows:
    print('  (no categories)')
else:
    print(f'  {\"ID\":>4}  {\"Title\":<40}  {\"Alias\":<25}  Type')
    print(f'  {\"--\":>4}  {\"-----\":<40}  {\"-----\":<25}  ----')
    for r in rows:
        a = r.get('attributes', {})
        rid = a.get('id', '?')
        title = a.get('title', '')[:40]
        alias = a.get('alias', '')[:25]
        ctype = a.get('type', '')
        print(f'  {rid:>4}  {title:<40}  {alias:<25}  {ctype}')
"
}

list_streams() {
    echo ""
    echo "ARS Update Streams @ ${SITE_URL}"
    echo "================================"
    curl -s -H "X-Joomla-Token: ${TOKEN}" -H "Accept: application/vnd.api+json" \
        "${API_BASE}/updatestreams" \
        | python3 -c "
import json, sys
data = json.load(sys.stdin)
rows = data.get('data', [])
if not rows:
    print('  (no update streams)')
else:
    print(f'  {\"ID\":>4}  {\"Name\":<35}  {\"Element\":<25}  {\"Type\":<10}  Cat')
    print(f'  {\"--\":>4}  {\"----\":<35}  {\"-------\":<25}  {\"----\":<10}  ---')
    for r in rows:
        a = r.get('attributes', {})
        sid = a.get('id', '?')
        name = a.get('name', '')[:35]
        element = a.get('element', '')[:25]
        stype = a.get('type', '')[:10]
        cat = a.get('category_id', '?')
        print(f'  {sid:>4}  {name:<35}  {element:<25}  {stype:<10}  {cat}')
"
}

list_releases() {
    local cat_id="${1:-}"
    if [ -z "$cat_id" ]; then
        echo "Usage: ars-list.sh releases <category-id>"
        exit 1
    fi
    echo ""
    echo "ARS Releases in category ${cat_id} @ ${SITE_URL}"
    echo "================================================="
    # The Joomla JSON:API filter[category_id] parameter is honored by
    # categories/items but appears to be ignored by /releases on this
    # ARS install — server returns the full release list regardless of
    # the filter. Fetch with a generous page size and filter client-side
    # so the output is always scoped to the requested category.
    curl -s -H "X-Joomla-Token: ${TOKEN}" -H "Accept: application/vnd.api+json" \
        "${API_BASE}/releases?filter%5Bcategory_id%5D=${cat_id}&limit=200" \
        | CAT_ID="$cat_id" python3 -c "
import json, os, sys
data = json.load(sys.stdin)
target_cat = int(os.environ['CAT_ID'])
rows = [r for r in data.get('data', [])
        if int(r.get('attributes', {}).get('category_id', -1)) == target_cat]
if not rows:
    print('  (no releases)')
else:
    print(f'  {\"ID\":>4}  {\"Version\":<15}  {\"Maturity\":<10}  Published')
    print(f'  {\"--\":>4}  {\"-------\":<15}  {\"--------\":<10}  ---------')
    for r in rows:
        a = r.get('attributes', {})
        rid = a.get('id', '?')
        version = a.get('version', '')[:15]
        maturity = a.get('maturity', '')[:10]
        published = a.get('published', '?')
        print(f'  {rid:>4}  {version:<15}  {maturity:<10}  {published}')
"
}

case "$MODE" in
    categories|cats) list_categories ;;
    streams)         list_streams ;;
    releases)        list_releases "${2:-}" ;;
    all|"")          list_categories; list_streams ;;
    *)
        echo "Unknown mode: $MODE"
        echo "Usage: ars-list.sh [categories|streams|releases <cat-id>]"
        exit 1
        ;;
esac

#!/usr/bin/env bash
#
# Space out an ARS category's `ordering` so the newest release stays the
# latest release, with room to publish into.
#
# ARS reads "latest" as the *lowest* ordering in a category, and cwm-ars-publish
# places each new release one below the current minimum. The column is unsigned,
# so a category numbered contiguously from 1 — which is what dragging a release
# to the top in the ARS backend leaves behind — has exactly one publish of
# headroom before the next one stops with an error. This renumbers newest-first
# in steps so that stops being a concern.
#
# Reads ars.endpoint, ars.categoryId, ars.tokenItem, ars.tokenVault from
# cwm-build.config.json. ARS_API_TOKEN env var override supported.
#
# Plans by default and writes nothing. --apply performs the renumbering.
#
# Usage:
#   bash scripts/ars-reorder.sh                        # plan, config category
#   bash scripts/ars-reorder.sh --category 1           # plan, explicit category
#   bash scripts/ars-reorder.sh --category 1 --apply   # do it
#   bash scripts/ars-reorder.sh --stride 500 --apply   # wider gaps
#
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=lib/optoken.sh
source "${SCRIPT_DIR}/lib/optoken.sh"

PROJECT_ROOT="$(pwd)"
CONFIG_FILE="${PROJECT_ROOT}/cwm-build.config.json"

CATEGORY_ID=""
STRIDE="100"
APPLY="0"

while [ $# -gt 0 ]; do
    case "$1" in
        --category)
            CATEGORY_ID="${2:-}"
            shift 2
            ;;
        --stride)
            STRIDE="${2:-}"
            shift 2
            ;;
        --apply)
            APPLY="1"
            shift
            ;;
        -h|--help)
            sed -n '2,24p' "$0" | sed 's/^# \{0,1\}//'
            exit 0
            ;;
        *)
            echo "Error: unknown argument '$1'. Try --help." >&2
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

SITE_URL=$(read_config "ars.endpoint")
TOKEN_ITEM=$(read_config "ars.tokenItem")
TOKEN_VAULT=$(read_config "ars.tokenVault")

TOKEN_ITEM="${TOKEN_ITEM:-CWM ARS API Token}"
TOKEN_VAULT="${TOKEN_VAULT:-CWM}"

if [ -z "$CATEGORY_ID" ]; then
    CATEGORY_ID=$(read_config "ars.categoryId")
fi

if [ -z "$SITE_URL" ]; then
    echo "Error: ars.endpoint not configured in cwm-build.config.json" >&2
    exit 1
fi

if [ -z "$CATEGORY_ID" ]; then
    echo "Error: no category. Set ars.categoryId in cwm-build.config.json, or pass --category." >&2
    echo "       'composer ars-list -- categories' lists the ids." >&2
    exit 1
fi

# --- Token ---
# Retrieval, and the reason when it fails, live in lib/optoken.sh (#126).
TOKEN="$(cwm_op_token "$TOKEN_ITEM" "$TOKEN_VAULT")" || exit 1

TOOLS_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

CWM_ARS_ENDPOINT="$SITE_URL" \
CWM_ARS_TOKEN="$TOKEN" \
CWM_ARS_CATEGORY_ID="$CATEGORY_ID" \
CWM_ARS_STRIDE="$STRIDE" \
CWM_ARS_APPLY="$APPLY" \
    php "${TOOLS_DIR}/scripts/ars-reorder.php"

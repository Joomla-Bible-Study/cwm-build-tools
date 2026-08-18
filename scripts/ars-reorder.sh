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
            # ⚠️ A written text, not `sed` over this file's own header. That
            # printed whatever happened to be on lines 2-24, which began with a
            # bare `#` -- so the first line of help was blank, and anything
            # checking that help names its command saw nothing.
            cat <<'CWM_HELP'
cwm-ars-reorder — space out an ARS category's ordering.

WHAT IT DOES
  ARS reads "latest" as the *lowest* `ordering` in a category, so a category
  whose lowest value is already taken has no room for the next release, and a
  tie there is what makes the Latest Releases page stick on an old version.

  This renumbers the category with a stride, newest first, so the newest
  release holds the lowest value and there is room to publish below it.

  Plans by default and writes nothing. Pass --apply to make the change.

PREREQUISITES
  - cwm-build.config.json with an `ars.endpoint`.
  - An ARS API token, from 1Password or ARS_API_TOKEN.

USAGE
  composer ars-reorder -- --category 5            # print the plan
  composer ars-reorder -- --category 5 --apply    # make the change

OPTIONS
  --category <id>   The category to renumber.
  --stride <n>      Gap between releases. Default 100.
  --apply           Write the change. Without it, nothing is written.
  -h, --help        This text.

RELATED
  composer ars-list       # find the category id
  composer ars-publish    # what refuses when a category has no room
CWM_HELP
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

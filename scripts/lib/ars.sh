#!/usr/bin/env bash
#
# ARS lookup and naming for the publish pipeline.
#
# Sourceable rather than executable so the logic can be exercised by
# tests/shell — see issue #52. The lookups take the API response on stdin
# and never talk to the network themselves, so a test can feed them
# fixture JSON; the caller owns curl, the token, and the endpoint.
#
# These matchers exist because the surrounding create-or-update flow
# publishes either way: a lookup that misses takes the create branch and
# ships a duplicate release or download item, reporting success (#37).
# They are exactly the code that must not be wrong silently.

# Derive the ARS release-alias prefix from the extension name.
#
# An explicit configuration wins; otherwise the conventional Joomla type
# prefix is stripped, so pkg_proclaim releases get "proclaim-10-3-6"
# aliases rather than "pkg_proclaim-10-3-6".
#
# Arguments:
#   $1  extension.name, e.g. pkg_proclaim
#   $2  configured ars.aliasPrefix, may be empty
#
# Outputs:
#   The prefix on stdout.
cwm_ars_alias_prefix() {
    local ext_name="$1" configured="${2:-}"

    if [ -n "$configured" ]; then
        printf '%s\n' "$configured"

        return 0
    fi

    printf '%s\n' "$ext_name" | sed -E 's/^(pkg_|com_|lib_|plg_|mod_|tpl_)//'
}

# The ARS release alias for a version: prefix-version with dots dashed,
# because the alias becomes a URL slug.
#
# Arguments:
#   $1  alias prefix, e.g. proclaim
#   $2  version, e.g. 10.3.6
#
# Outputs:
#   The alias on stdout, e.g. proclaim-10-3-6
cwm_ars_release_alias() {
    printf '%s-%s\n' "$1" "$2" | tr '.' '-'
}

# Find the ARS release id for an exact version in a releases response.
#
# The API-side filters are advisory at best (`search` substring-matches, so
# 10.3.1 also matches 10.3.10), which is why the match on the exact version
# string happens here, client-side.
#
# Arguments:
#   $1  version to match exactly
#
# Input:
#   The JSON body of GET /releases on stdin.
#
# Outputs:
#   The release id on stdout, or nothing when no release has that version.
#   Malformed JSON also outputs nothing: the caller treats "not found" as
#   "create", which is the safe reading of a response it cannot parse —
#   an update PATCHed at a guessed id would land on the wrong release.
cwm_ars_find_release_id() {
    python3 -c "
import json, sys

version = sys.argv[1]
try:
    d = json.load(sys.stdin)
except ValueError:
    sys.exit(0)
for r in d.get('data', []):
    if r.get('attributes', {}).get('version') == version:
        print(r['attributes']['id'])
        break
" "$1" 2>/dev/null || true
}

# Find the ARS download-item id whose URL points at a zip name, in an
# items response.
#
# Items point at GitHub download URLs; the basename is the stable part,
# the path carries the tag and can be rewritten. The match is on the
# exact basename, not a suffix — endswith("pkg_x-1.0.zip") would also
# claim "other-pkg_x-1.0.zip", the same shape of near-miss the artifact
# selection in lib/artifacts.sh refuses.
#
# Arguments:
#   $1  zip file name, e.g. pkg_proclaim-10.3.6.zip
#
# Input:
#   The JSON body of GET /items on stdin.
#
# Outputs:
#   The item id on stdout, or nothing when no item matches. Malformed
#   JSON also outputs nothing, for the same reason as the release lookup.
cwm_ars_find_item_id() {
    python3 -c "
import json, sys

zip_name = sys.argv[1]
try:
    d = json.load(sys.stdin)
except ValueError:
    sys.exit(0)
for i in d.get('data', []):
    url = i.get('attributes', {}).get('url', '')
    if url.rsplit('/', 1)[-1] == zip_name:
        print(i['attributes']['id'])
        break
" "$1" 2>/dev/null || true
}

# Validate the configured ars.environments value.
#
# An item published with no environments makes ARS emit update XML with
# bogus php_minimum / targetplatform requirements that block the update
# on every real site (Proclaim 10.3.4-10.4.0 shipped invisible to
# Joomla 5 and "requires PHP 8.5" this way). The publish must refuse to
# run rather than ship that.
#
# Arguments:
#   $1  the ars.environments config value as JSON (read_config_json
#       output: a JSON array, or the literal string "null" when unset)
#
# Returns:
#   0 when the value is a non-empty JSON array of ids, 1 otherwise.
cwm_ars_validate_environments() {
    python3 -c "
import json, sys

try:
    v = json.loads(sys.argv[1])
except (ValueError, IndexError):
    sys.exit(1)
ok = isinstance(v, list) and len(v) > 0 \
    and all(isinstance(e, (str, int)) and str(e).strip().isdigit() for e in v)
sys.exit(0 if ok else 1)
" "$1" 2>/dev/null
}

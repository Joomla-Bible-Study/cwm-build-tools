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

# The release and download-item lookups that used to live here now live in
# src/Release/ArsPublisher.php. They are create-or-update decisions against a
# live download page — a miss ships a duplicate release, a false hit PATCHes
# over a different one — and PHP is where they can be exercised against canned
# API responses without publishing anything. See tests/Release/ArsPublisherTest.php,
# which carries the same cases this file's tests did (the 10.3.1 vs 10.3.10
# substring near-miss, the other-pkg_x basename near-miss, unparseable bodies)
# plus the ones the shell could not express: a 403 from the ARS 7.5.0
# authorisation hardening must not read as "no such release".

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

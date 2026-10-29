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

# Whether a local artifact is byte-identical to the asset users will download.
#
# ARS items of `type: link` point at the GitHub release asset, so the bytes a
# site downloads are the asset's — not the file that happened to be in
# build/dist when publish ran. Publishing the local file's checksums when the
# two differ makes Joomla download the asset, compare it against the advertised
# sha512, and refuse the update. The feed stays valid and ARS reports success,
# so the only place the mismatch shows is a site trying to update.
#
# It is not a rare accident. gzip stores an mtime in its header, so any project
# whose build emits `.gz` — the shared rollup config does — produces different
# bytes from identical sources. A rebuild anywhere between building and
# publishing is enough.
#
# Compared here rather than by always downloading, so the common case stays
# cheap: GitHub reports the asset's size and a sha256 digest, and when both
# agree with the local file the local checksums are provably the served ones.
#
# Arguments:
#   $1  Local file size in bytes.
#   $2  Local sha256, lowercase hex.
#   $3  Asset size in bytes, from `gh release view --json assets`.
#   $4  Asset digest, e.g. `sha256:abc…`. May be empty on older releases.
#
# Returns:
#   0  identical — the local checksums describe the served bytes
#   1  different — the asset must be hashed instead
#   2  undecidable — no digest to compare, so the asset must be hashed
cwm_ars_local_matches_asset() {
    local local_size="$1"
    local local_sha256="$2"
    local asset_size="$3"
    local asset_digest="$4"

    # Size is the cheap half and catches the common case on its own: it would
    # have caught pkg_licenseportal 1.5.1 (392410 vs 388404 bytes) for free.
    if [ -n "$asset_size" ] && [ "$asset_size" != "$local_size" ]; then
        return 1
    fi

    # No digest — GitHub added the field relatively recently, and an older
    # release will not carry one. Equal sizes are not proof: 1.5.0 had
    # identical byte counts and different content, which is exactly the case
    # that hid for two releases.
    if [ -z "$asset_digest" ] || [ "$asset_digest" = "null" ]; then
        return 2
    fi

    if [ "${asset_digest#sha256:}" = "$local_sha256" ]; then
        return 0
    fi

    return 1
}

# --- Hosted downloads (`ars.hosting: "ars"`) ---------------------------------
#
# By default an ARS item is a link to the GitHub release asset. A private
# repository serves that asset to nobody, and a link bypasses ARS's own access
# checks, so a commercial package cannot sit behind its download ID that way.
# With `ars.hosting: "ars"` the zip is copied into the category's repository
# directory and the item is a file item: ARS serves it, and the category's
# access level (plus the site's Download ID handling) decides who gets it.

# Validate the hosting settings.
#
# Arguments:
#   $1  ars.hosting ("github" — the default — or "ars")
#   $2  ars.uploadTarget, an scp destination "user@host:path/to/repository"
#
# Returns 0 when usable, 1 (with the reason on stderr) otherwise.
cwm_ars_validate_hosting() {
    local hosting="$1"
    local target="$2"

    case "$hosting" in
        github) return 0 ;;
        ars)
            if [[ "$target" =~ ^[^:/]+:.+ ]]; then
                return 0
            fi
            echo "Error: ars.hosting is \"ars\" but ars.uploadTarget is not an scp destination (user@host:path)." >&2
            echo "  Set it to the ARS category's repository directory, e.g. \"me@example.com:site/arsfiles\"." >&2
            return 1
            ;;
        *)
            echo "Error: ars.hosting must be \"github\" or \"ars\", got \"${hosting}\"." >&2
            return 1
            ;;
    esac
}

# Copy the zip into the ARS repository directory over ssh.
#
# Never replaces a different file of the same name: its checksums may already
# be published, and changing the bytes under them fails every site's update.
# The same bytes already there is success (a re-run). The upload lands under a
# temporary name and is moved into place, then hashed on the server.
#
# Arguments:
#   $1  Local zip path.
#   $2  ars.uploadTarget ("user@host:dir").
#   $3  Local sha256, lowercase hex.
#
# Returns 0 when the server holds exactly these bytes, 1 otherwise.
cwm_ars_upload_hosted() {
    local zip="$1"
    local target="$2"
    local sha="$3"
    local host="${target%%:*}"
    local dir="${target#*:}"
    local remote

    dir="${dir%/}"
    remote="${dir}/$(basename "$zip")"

    # Prints the file's sha256, or ABSENT. Exits 0 whenever ssh connected, so a
    # connection failure is told apart from "not uploaded yet" — and, under the
    # callers' `set -e`, neither case ends the script before it can report.
    local hash_cmd="if [ -e '${remote}' ]; then (sha256sum -- '${remote}' 2>/dev/null || shasum -a 256 -- '${remote}') | cut -d' ' -f1; else echo ABSENT; fi"
    local existing

    if ! existing="$(ssh -o BatchMode=yes "$host" "$hash_cmd")"; then
        echo "Error: could not reach ${host} over ssh to check ${remote}." >&2
        return 1
    fi

    if [ "$existing" = "ABSENT" ]; then
        existing=""
    fi

    if [ -n "$existing" ]; then
        if [ "$existing" = "$sha" ]; then
            echo "  ${remote} is already on ${host} with the same sha256 — not uploading again."
            return 0
        fi

        echo "Error: ${host}:${remote} already exists with different content (sha256 ${existing})." >&2
        echo "  Refusing to replace it: published checksums may describe it. Bump the version instead." >&2
        return 1
    fi

    echo "  Uploading to ${host}:${remote}..."

    if ! scp -q -o BatchMode=yes "$zip" "${host}:${remote}.uploading"; then
        echo "Error: upload to ${host}:${dir} failed." >&2
        return 1
    fi

    if ! ssh -o BatchMode=yes "$host" "mv -- '${remote}.uploading' '${remote}'"; then
        echo "Error: could not move the upload into place on ${host}." >&2
        return 1
    fi

    local after=""
    after="$(ssh -o BatchMode=yes "$host" "$hash_cmd")" || after=""

    if [ "$after" != "$sha" ]; then
        echo "Error: ${host}:${remote} has sha256 ${after:-<unreadable>}, expected ${sha}." >&2
        return 1
    fi

    echo "  Uploaded; sha256 verified on ${host}."
    return 0
}

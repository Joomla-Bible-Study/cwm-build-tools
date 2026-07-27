#!/usr/bin/env bash
#
# Version and tag derivation for the release pipeline.
#
# Sourceable rather than executable so the logic can be exercised by
# tests/shell — see issue #52. Everything here is a pure function of its
# arguments (or of a file it was handed), so a test can run it without a
# network, a git checkout, or a Joomla site.

# Validate a version string for release.
#
# Arguments:
#   $1  version string as typed or passed on the command line
#
# Outputs:
#   Diagnostics on stderr when invalid.
#
# Returns:
#   0 releasable, 1 empty, 2 a -dev version (never releasable; -alpha,
#   -beta and -rc are the pre-release channels).
cwm_validate_release_version() {
    local version="$1"

    if [ -z "$version" ]; then
        echo "Error: No version provided." >&2

        return 1
    fi

    if [[ "$version" == *-dev* ]]; then
        echo "Error: Development versions cannot be released. Use -alpha, -beta, or -rc for testing." >&2

        return 2
    fi

    return 0
}

# The git tag for a version: v-prefixed, nothing else.
#
# Arguments:
#   $1  version, e.g. 10.3.6
#
# Outputs:
#   The tag on stdout, e.g. v10.3.6
cwm_tag_for_version() {
    printf 'v%s\n' "$1"
}

# Whether a version is a pre-release.
#
# Any hyphenated suffix marks a pre-release; validation has already
# rejected -dev before this is asked.
#
# Arguments:
#   $1  version
#
# Returns:
#   0 pre-release, 1 stable.
cwm_is_prerelease() {
    [[ "$1" == *-* ]]
}

# The `gh release create` flag for a version: "--prerelease" or nothing.
#
# Arguments:
#   $1  version
#
# Outputs:
#   The flag on stdout, or nothing for a stable version.
cwm_prerelease_flag() {
    if cwm_is_prerelease "$1"; then
        printf -- '--prerelease\n'
    fi
}

# ARS maturity for a version string.
#
# ARS classifies each release as alpha / beta / rc / stable, and the
# version's own suffix already says which it is.
#
# Arguments:
#   $1  version
#
# Outputs:
#   One of: alpha, beta, rc, stable.
cwm_maturity_for_version() {
    local version="$1"

    case "$version" in
        *-alpha*) echo "alpha" ;;
        *-beta*)  echo "beta" ;;
        *-rc*)    echo "rc" ;;
        *)        echo "stable" ;;
    esac
}

# Read the version from a Joomla manifest XML.
#
# The first <version> element wins — a package manifest lists its member
# extensions' manifests after its own header, and only the header version
# is the package's.
#
# Arguments:
#   $1  path to a manifest XML file
#
# Outputs:
#   The version on stdout; nothing when the file is missing or carries no
#   <version> element.
#
# Returns:
#   0 found, 1 not found.
cwm_version_from_manifest() {
    local manifest="$1" version=""

    if [ -f "$manifest" ]; then
        version=$(grep -oE '<version>[^<]+</version>' "$manifest" | head -1 | sed -E 's|</?version>||g')
    fi

    if [ -z "$version" ]; then
        return 1
    fi

    printf '%s\n' "$version"
}

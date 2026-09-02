#!/usr/bin/env bash
#
# Artifact selection for the release pipeline.
#
# Sourceable rather than executable so the logic can be exercised by
# tests/shell — see issue #52. The entry points in scripts/ are 400-line
# procedural scripts that run on invocation, which is why nothing in them has
# ever been tested, and why every bug found in them was found by reading.
#
# Functions here must stay free of network calls and of anything that writes
# outside a path they were handed, so a test can run them against a temporary
# directory.

# Select the build artifact belonging to a specific version.
#
# The output glob is deliberately version-agnostic (pkg_x-*.zip), so anything
# left in the output directory matches it too — a baseline downloaded by the
# upgrade harness, or simply the previous release. Choosing by glob order picks
# whatever sorts first, and bash sorts lexically, so releasing 10.3.3 next to a
# stale 10.3.2 selects the 10.3.2 zip. Proclaim 10.3.3 shipped exactly that, to
# both GitHub and ARS.
#
# Lexical order is wrong for versions in general: 10.3.10 sorts before 10.3.2.
#
# So match on the version and refuse to guess otherwise. Ambiguity is an error,
# not something to resolve with a heuristic — a release publishes to the world
# and reports success either way.
#
# Arguments:
#   $1  version being released, e.g. 10.3.6
#   $2  glob for candidate artifacts, relative or absolute
#
# Outputs:
#   The selected path on stdout; diagnostics on stderr.
#
# Returns:
#   0 selected, 1 nothing matched the glob, 2 nothing matched the version,
#   3 more than one matched the version.
cwm_select_artifact_for_version() {
    local version="$1"
    local output_glob="$2"
    local all=() selected=() artifact

    shopt -s nullglob
    # shellcheck disable=SC2206  # intentional word-splitting: this is a glob
    all=( $output_glob )
    shopt -u nullglob

    if [ "${#all[@]}" -eq 0 ]; then
        echo "Error: No build artifact matched ${output_glob}" >&2

        return 1
    fi

    for artifact in "${all[@]}"; do
        case "$(basename "$artifact")" in
            *"-${version}".zip) selected+=( "$artifact" ) ;;
        esac
    done

    if [ "${#selected[@]}" -eq 0 ]; then
        {
            echo "Error: No build artifact for version ${version} matched ${output_glob}"
            echo "       Found instead:"
            printf '         %s\n' "${all[@]}"
            echo "       The build step should have produced a file named *-${version}.zip."
        } >&2

        return 2
    fi

    if [ "${#selected[@]}" -gt 1 ]; then
        {
            echo "Error: ${#selected[@]} artifacts match version ${version}; refusing to guess:"
            printf '         %s\n' "${selected[@]}"
        } >&2

        return 3
    fi

    if [ "${#all[@]}" -gt 1 ]; then
        echo "  Note: ${#all[@]} files matched the glob; selected by version." >&2
    fi

    printf '%s\n' "${selected[0]}"
}

# Require that a selected artifact actually exists before publishing it.
#
# Written for cwm-build-tools#160: Proclaim 10.6.0 died at the ARS step with
# "artifact not found" for a file that was demonstrably present — byte-identical
# to the asset uploaded moments earlier, and publishable by hand with the same
# relative path immediately after. The bare `[ -f ]` it replaces could only say
# "not found", with no pwd and no directory listing, which is why that incident
# ended as a report instead of a diagnosis.
#
# Two behaviours follow from that incident:
#
#   - One retry, after a short pause. If a stat can fail transiently (an
#     external volume stalling, an automount hiccup), a second look answers it;
#     and a success on retry is LOUD, because that message is the evidence the
#     next investigation needs.
#   - On real failure, print everything that would have distinguished the
#     possibilities: the path as given, the directory it resolves against
#     (pwd), and what is actually in that directory.
#
# Arguments:
#   $1  path to the artifact, as it will be used (relative or absolute)
#
# Environment:
#   CWM_ARTIFACT_RETRY_DELAY  seconds before the second look (default 2)
#
# Returns:
#   0 the file exists (possibly only on the second look), 1 it does not.
cwm_require_artifact() {
    local path="$1"
    local delay="${CWM_ARTIFACT_RETRY_DELAY:-2}"

    if [ -f "$path" ]; then
        return 0
    fi

    sleep "$delay"

    if [ -f "$path" ]; then
        {
            echo "⚠ Artifact check: ${path} was NOT visible on first stat but appeared"
            echo "  on retry after ${delay}s. The publish continues, but this is the"
            echo "  transient-stat failure suspected in cwm-build-tools#160 — note the"
            echo "  volume this ran from when reporting it."
        } >&2

        return 0
    fi

    {
        echo "Error: artifact not found: ${path}"
        echo "       looked from:  $(pwd)"
        echo "       resolves to:  $(cd "$(dirname "$path")" 2>/dev/null && pwd || echo '(directory itself does not resolve)')/$(basename "$path")"
        echo "       directory contents:"
        ls -la "$(dirname "$path")" 2>/dev/null | sed 's/^/         /' >&2 \
            || echo "         (cannot list $(dirname "$path"))"
    } >&2

    return 1
}

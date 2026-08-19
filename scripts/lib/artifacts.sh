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
# A third argument short-circuits all of that: the exact basename the build
# was configured to produce, which release.sh expands from build.outputName.
# When it is present among the glob's matches it is the answer, because the
# project said so — no inference from the filename at all.
#
# That is what makes a Joomla-shaped name work. `Proclaim_10.5.11-Stable-
# Full_Package.zip` does not end in `-10.5.11.zip`, and the suffix match below
# deliberately cannot be loosened to find it: `*-10.3.6-*` would also match
# `pkg_proclaim-10.3.6-beta1.zip` while releasing 10.3.6, which is the case
# tests/shell/artifacts.test.sh pins in the other direction.
#
# Arguments:
#   $1  version being released, e.g. 10.3.6
#   $2  glob for candidate artifacts, relative or absolute
#   $3  optional exact basename expected for this version
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
    local expected="${3:-}"
    local all=() selected=() artifact

    shopt -s nullglob
    # shellcheck disable=SC2206  # intentional word-splitting: this is a glob
    all=( $output_glob )
    shopt -u nullglob

    if [ "${#all[@]}" -eq 0 ]; then
        echo "Error: No build artifact matched ${output_glob}" >&2

        return 1
    fi

    # The configured name, when it is on disk. Falls through when it is not,
    # so a project whose builder names things its own way is unaffected.
    if [ -n "$expected" ]; then
        for artifact in "${all[@]}"; do
            if [ "$(basename "$artifact")" = "$expected" ]; then
                printf '%s\n' "$artifact"

                return 0
            fi
        done
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
            if [ -n "$expected" ]; then
                echo "       The build step should have produced ${expected}."
            else
                echo "       The build step should have produced a file named *-${version}.zip."
            fi
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

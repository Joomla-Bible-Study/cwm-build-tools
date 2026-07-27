#!/usr/bin/env bash
#
# Release-notes assembly for the release pipeline.
#
# Sourceable rather than executable so the logic can be exercised by
# tests/shell — see issue #52. Pure functions of their arguments and of
# files they are handed; fetching the generated notes from GitHub stays
# with the caller.

# Resolve the hand-written notes file for a version.
#
# The configured pattern carries a {version} placeholder, e.g.
# "build/release-notes-{version}.md". A release nobody wrote notes for is
# normal, so a missing file is not an error — it just means the generated
# notes stand alone.
#
# Arguments:
#   $1  configured pattern, may be empty (feature unconfigured)
#   $2  version
#
# Outputs:
#   The path on stdout when the file exists; nothing otherwise.
#
# Returns:
#   0 found, 1 no file (unconfigured, or nothing written for this version).
cwm_resolve_notes_file() {
    local pattern="$1" version="$2" candidate

    if [ -z "$pattern" ]; then
        return 1
    fi

    candidate="${pattern//\{version\}/$version}"

    if [ ! -f "$candidate" ]; then
        return 1
    fi

    printf '%s\n' "$candidate"
}

# Assemble the release-notes body from a hand-written file and the
# generated notes.
#
# What GitHub generates is a list of pull request titles — accurate, and
# written for us rather than for the person deciding whether to update.
# So the hand-written notes lead when they exist, and the generated list
# is kept beneath a "## Changes" heading so nothing is lost. Without a
# file, the generated notes pass through untouched.
#
# Arguments:
#   $1  path to the hand-written notes file, may be empty
#   $2  the generated notes text
#
# Outputs:
#   The assembled body on stdout.
cwm_assemble_release_notes() {
    local notes_file="$1" generated="$2"

    if [ -z "$notes_file" ] || [ ! -f "$notes_file" ]; then
        printf '%s\n' "$generated"

        return 0
    fi

    printf '%s\n\n## Changes\n\n%s\n' "$(cat "$notes_file")" "$generated"
}

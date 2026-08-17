#!/usr/bin/env bash
#
# Baseline selection for the upgrade test.
#
# An upgrade test needs a "before" state: the released artifact users are
# actually on, installed first so the new build lands on top of it and Joomla
# routes the second install to update(). Which release that should be is a
# decision both consumers were making independently, and differently.
#
# Proclaim read versions.json `current`. CWMLivingWord resolved it from the
# releases that exist, after `current` made the phase skip itself during the
# 5.7.0 release — the gate runs before the bump, so the file describes the
# previous cycle (Joomla-Bible-Study/cwm-build-tools#101). LivingWord's
# strategy is the one that survives contact with a release, so it is the one
# here.
#
# Selection is split from the `gh` call for the same reason lib/artifacts.sh
# and lib/version.sh exist: the entry points in scripts/ run on invocation and
# cannot be reached from a test, which is why every bug in them was found by
# reading. Functions here must stay free of network calls and of writes outside
# a path they were handed.

# Choose the newest usable baseline from a list of releases.
#
# "Usable" is three conditions, and each one exists because a run hit it:
#
#   - strictly older than the target, or the upgrade path never fires;
#   - at or above the floor, because a project's early releases may not
#     install at all (CWMLivingWord#95 — packages before 5.6.0-beta2 assembled
#     their inner extensions into a folder the manifest never pointed at, so
#     Joomla refused them, and such a release cannot serve as a "before"
#     state);
#   - stable, when any stable release qualifies. Most sites are on a stable
#     release, and resolving to a prerelease was the specific shape of the
#     #101 failure. Prereleases are used only when nothing stable qualifies,
#     because a project that has only ever shipped betas still deserves the
#     phase to run.
#
# Comparison is delegated to PHP's version_compare, which orders prerelease
# suffixes correctly (5.7.0-beta4 < 5.7.0). Sorting these as strings puts
# 10.3.10 before 10.3.2 and 5.7.0 before 5.7.0-beta4, both wrong.
#
# Arguments:
#   $1  Target version the baseline must be older than, e.g. 5.7.1.
#   $2  Floor version, or empty for no floor.
#   $3  Releases, one per line, as "<tag><TAB><true|false>" where the second
#       field is whether the release is a prerelease. Tags may carry a leading
#       v; it is stripped.
#
# Outputs:
#   The chosen version on stdout, without a leading v. Nothing when none
#   qualifies.
#
# Returns:
#   0 when a baseline was chosen, 1 when none qualifies.
cwm_select_baseline_version() {
    local target="$1"
    local floor="$2"
    local releases="$3"
    local chosen

    chosen="$(printf '%s\n' "$releases" | php -r '
        $target = $argv[1];
        $floor  = $argv[2];
        $stable = [];
        $any    = [];

        while (($line = fgets(STDIN)) !== false) {
            [$tag, $prerelease] = array_pad(explode("\t", trim($line)), 2, "");

            if ($tag === "") {
                continue;
            }

            $version = ltrim($tag, "v");

            if (version_compare($version, $target, ">=")) {
                continue;
            }

            if ($floor !== "" && version_compare($version, $floor, "<")) {
                continue;
            }

            $any[] = $version;

            if ($prerelease !== "true") {
                $stable[] = $version;
            }
        }

        $pool = $stable !== [] ? $stable : $any;

        if ($pool === []) {
            exit(1);
        }

        usort($pool, "version_compare");

        echo end($pool);
    ' "$target" "$floor")" || return 1

    [ -n "$chosen" ] || return 1

    printf '%s\n' "$chosen"
}

# Derive the released asset's filename for a version from the output glob.
#
# `build.outputGlob` is the one artifact-naming key present in every project
# shape — the package example carries no `outputName` — and it is already the
# source of truth for finding a built artifact (lib/artifacts.sh). A released
# asset is the same file under the same name, so the glob answers this too.
#
# Exactly one `*` is required. A glob with none has no version to substitute,
# and one with several is ambiguous about which position the version occupies;
# guessing would name a file that does not exist and report it as "not found on
# the release", sending the reader after the wrong problem.
#
# Arguments:
#   $1  The glob, e.g. build/dist/pkg_proclaim-*.zip
#   $2  The version to substitute.
#
# Outputs:
#   The asset filename (basename only) on stdout.
#
# Returns:
#   0 on success, 1 when the glob does not carry exactly one `*`.
cwm_baseline_asset_from_glob() {
    local glob="$1"
    local version="$2"
    local base stars

    base="$(basename "$glob")"
    stars="${base//[^\*]/}"

    if [ "${#stars}" -ne 1 ]; then
        echo "Error: build.outputGlob basename must contain exactly one '*' to name a baseline asset (got '${base}')." >&2

        return 1
    fi

    printf '%s\n' "${base/\*/$version}"
}

# The directory a baseline should be downloaded into, from the same glob.
#
# Arguments:
#   $1  The glob, e.g. build/dist/pkg_proclaim-*.zip
#
# Outputs:
#   The directory on stdout, e.g. build/dist. "." when the glob has no path.
cwm_baseline_dir_from_glob() {
    dirname "$1"
}

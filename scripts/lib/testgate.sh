#!/usr/bin/env bash
#
# Release gate: composer test:release as a release pre-condition.
#
# Every silent defect this tool has ever shipped — an uninstallable package,
# a migration missing an index, an admin view 500ing on every request, a REST
# API returning 500 on every single request — looked correct until something
# actually executed it. composer test:release is that execution: clean
# install, upgrade, accessibility, API acceptance, against a disposable site,
# the same suite LivingWord's nightly/PR CI runs. A release built without it
# is trusting exactly the thing the suite exists to not trust.
#
# Not every CWM project has this harness — a library with no install flow
# has nothing for it to verify — so the gate is opt-in by presence: projects
# without a test:release script are left alone rather than blocked on
# tooling they were never given.
#
# Detection lives here, sourceable, so it can be tested without shelling out
# to a real composer process — the same reason lib/artifacts.sh and
# lib/version.sh exist.

# Whether a project's composer.json defines a test:release script.
#
# Arguments:
#   $1  Path to composer.json.
#
# Returns:
#   0 (true) if the script is defined, 1 otherwise — including when the file
#   is missing or not valid JSON.
cwm_has_test_release_script() {
    local composer_json="$1"

    [ -f "$composer_json" ] || return 1

    php -r '
        $c = json_decode(file_get_contents($argv[1]), true);
        exit(isset($c["scripts"]["test:release"]) ? 0 : 1);
    ' "$composer_json" 2>/dev/null
}

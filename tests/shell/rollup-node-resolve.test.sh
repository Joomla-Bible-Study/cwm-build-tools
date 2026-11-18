#!/usr/bin/env bash
#
# The rollup template must work without @rollup/plugin-node-resolve (#178).
#
# The plugin bundles npm packages a source imports. Almost no CWM source imports
# one, yet requiring the plugin up front made every consumer install it, and with
# it @rollup/pluginutils and picomatch, whose ReDoS advisory GHSA-fc5q-39xc-vjpf
# has no fixed release. It is optional now:
#   - installed:     used exactly as before;
#   - not installed: builds that import no package run without it, and a source
#                    that does import one fails the build with the install
#                    command, where rollup alone would warn and leave a global the
#                    browser does not have.
#
# Rollup is a consumer dependency, not one of this repository's, so the template
# is loaded as the config module it is, with stand-in plugins on NODE_PATH. What
# is under test is the config it builds and its onwarn rule, not rollup.

set -uo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=helpers.sh
source "${SCRIPT_DIR}/helpers.sh"

ROOT="$(cd "${SCRIPT_DIR}/../.." && pwd)"
TEMPLATE="${ROOT}/templates/rollup.config.js"

WORK="$(mktemp -d)"
trap 'rm -rf "${WORK}"' EXIT

# --- stand-in plugins ----------------------------------------------------------

stub() { # stub <dir> <package> <module body>
    mkdir -p "$1/$2"
    printf '%s\n' "$3" > "$1/$2/index.js"
}

# Without node-resolve.
stub "${WORK}/without" "@rollup/plugin-terser" "module.exports = () => ({ name: 'terser' });"
stub "${WORK}/without" "rollup-plugin-gzip"    "module.exports = { default: () => ({ name: 'gzip' }) };"

# With it.
cp -R "${WORK}/without" "${WORK}/with"
stub "${WORK}/with" "@rollup/plugin-node-resolve" "module.exports = () => ({ name: 'node-resolve' });"

mkdir -p "${WORK}/project/src"
printf 'export default 1;\n' > "${WORK}/project/src/plain.es6.js"
printf 'export default 2;\n' > "${WORK}/project/src/module.es6.mjs"

# probe <stub dir> <js expression over `configs`> -- prints the result, or "THREW: <message>"
# eval only ever runs the expressions written in this file, against the config
# the template returns; nothing from outside the test reaches it.
probe() {
    (
        cd "${WORK}/project" || exit 1
        NODE_PATH="$1" SOURCE_DIR=src OUTPUT_DIR=out node -e '
            const configs = require(process.argv[1]);
            const warned  = [];
            const warn    = w => warned.push(w.code + ":" + (w.exporter || ""));
            try {
                console.log(String(eval(process.argv[2])));
            } catch (e) {
                console.log("THREW: " + e.message);
            }
        ' "${TEMPLATE}" "$2"
    ) 2>/dev/null
}

plugins='configs.map(c => c.plugins.map(p => p.name).join("+") || "-").join(" ")'

# --- not installed ----------------------------------------------------------------

assert_equals "- terser+gzip - terser+gzip" "$(probe "${WORK}/without" "${plugins}")" \
    "without the plugin: no resolver, the rest of each build unchanged"

result="$(probe "${WORK}/without" 'configs[0].onwarn({ code: "UNRESOLVED_IMPORT", exporter: "chart.js", id: process.cwd() + "/src/plain.es6.js" }, warn)')"
assert_contains "${result}" 'THREW: src/plain.es6.js imports "chart.js", an npm package.' \
    "without the plugin: importing a package fails the build, naming the importer and the package"
assert_contains "${result}" "npm install --save-dev @rollup/plugin-node-resolve" \
    "without the plugin: the failure says how to fix it"

assert_equals "UNRESOLVED_IMPORT:../media/vendor/modal.js" \
    "$(probe "${WORK}/without" 'configs[0].onwarn({ code: "UNRESOLVED_IMPORT", exporter: "../media/vendor/modal.js", id: "x" }, warn), warned.join()')" \
    "without the plugin: a relative import rollup cannot find stays a warning, as before"

assert_equals "CIRCULAR_DEPENDENCY:" \
    "$(probe "${WORK}/without" 'configs[1].onwarn({ code: "CIRCULAR_DEPENDENCY" }, warn), warned.join()')" \
    "without the plugin: other warnings pass through untouched"

# --- installed ------------------------------------------------------------------------

assert_equals "node-resolve node-resolve+terser+gzip node-resolve node-resolve+terser+gzip" \
    "$(probe "${WORK}/with" "${plugins}")" \
    "with the plugin: every build resolves through it, first, as before"

assert_equals "UNRESOLVED_IMPORT:chart.js" \
    "$(probe "${WORK}/with" 'configs[0].onwarn({ code: "UNRESOLVED_IMPORT", exporter: "chart.js", id: "x" }, warn), warned.join()')" \
    "with the plugin: an unresolved import is rollup's own warning again, not a failure"

finish

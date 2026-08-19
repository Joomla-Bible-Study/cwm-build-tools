#!/usr/bin/env bash
#
# vendor:check must not report work the repository cannot do.
#
# A nested Composer project that is a git submodule belongs to another
# repository. Its composer.json is committed there and its dependencies move
# when *that* project updates and releases. Proclaim's weekly report failed on
# six such rows -- lib_cwmscripture and scripturelinks dev dependencies -- none
# of which could be actioned from that checkout.
#
# A report full of un-actionable rows is one people stop reading, which is the
# same failure this toolchain keeps finding elsewhere: a check whose output does
# not mean what it says.
#
# ⚠️ Security advisories are deliberately NOT scoped this way. A vulnerable
# bundled package ships to end users whoever owns the manifest.

set -uo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=helpers.sh
source "${SCRIPT_DIR}/helpers.sh"

ROOT="$(cd "${SCRIPT_DIR}/../.." && pwd)"
CHECK="${ROOT}/templates/vendor-check.js"

WORK="$(mktemp -d)"
trap 'rm -rf "${WORK}"' EXIT

# --- submodulePaths() reads .gitmodules -------------------------------------
#
# Evaluated out of the file rather than mocked: the script is a program, not a
# module, so it cannot be required without running. Pulling the one function out
# tests the real parser.

probe() {
    ROOT_DIR="$1" node -e '
        const fs   = require("fs");
        const path = require("path");
        const ROOT = process.env.ROOT_DIR;
        const src  = fs.readFileSync(process.argv[1], "utf8");
        const from = src.indexOf("function submodulePaths");
        const to   = src.indexOf("\n}", from) + 2;
        eval(src.slice(from, to));
        console.log(JSON.stringify(submodulePaths()));
    ' "${CHECK}"
}

mkdir -p "${WORK}/none"
assert_equals "[]" "$(probe "${WORK}/none")" "no .gitmodules means no submodules"

mkdir -p "${WORK}/two"
cat > "${WORK}/two/.gitmodules" <<'GITMODULES'
[submodule "libraries/lib_cwmscripture"]
	path = libraries/lib_cwmscripture
	url = https://github.com/Joomla-Bible-Study/lib_cwmscripture.git
[submodule "plugins/content/scripturelinks"]
	path = plugins/content/scripturelinks
	url = git@github.com:Joomla-Bible-Study/CWMScriptureLinks.git
GITMODULES

assert_equals \
    '["libraries/lib_cwmscripture","plugins/content/scripturelinks"]' \
    "$(probe "${WORK}/two")" \
    "both submodule paths are read, tabs and all"

# A path= inside a url or name must not be picked up, and neither must a blank.
mkdir -p "${WORK}/odd"
printf '[submodule "a"]\n\tpath = vendor/a\n\turl = https://example.test/path=notthis.git\n' \
    > "${WORK}/odd/.gitmodules"
assert_equals '["vendor/a"]' "$(probe "${WORK}/odd")" "only the path key is read"

# --- the exit code is scoped to what this repository owns --------------------
#
# Source-inspected: exercising the real decision needs installed Composer
# projects with outdated dependencies, which is a network fixture rather than a
# unit test. What is checkable is that a submodule scope does not set the flag
# the exit code reads.

BODY="$(sed -n '/function checkPhpDependencies/,/^}/p' "${CHECK}")"

assert_contains "${BODY}" "submodulePaths()" "the check consults the submodule list"
assert_contains "${BODY}" "owned: !submodules.includes(p)" "nested scopes are marked by ownership"
assert_contains "${BODY}" "if (scope.owned)" "only an owned scope may set hasUpdates"
assert_contains "${BODY}" "ℹ submodule" "submodule rows are still shown, with their own status"

# The root is always owned; a bug there would silence real updates.
assert_contains "${BODY}" "label: '(root)', cwd: ROOT, owned: true" "the root scope is always actionable"

# --- advisories are NOT scoped this way --------------------------------------

SEC="$(sed -n '/function checkSecurity/,/^}/p' "${CHECK}")"

if echo "${SEC}" | grep -q 'owned'; then
    assert_equals "unscoped" "scoped" "security must fail for every scope, submodule or not"
else
    assert_equals "unscoped" "unscoped" "security still fails for every scope"
fi

finish

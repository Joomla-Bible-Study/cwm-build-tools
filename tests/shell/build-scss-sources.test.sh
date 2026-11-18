#!/usr/bin/env bash
#
# build-scss.js must not write the build checkout's path into a source map.
#
# Dart Sass reports every file it loaded as an absolute file: URL. The template
# used to keep them, so release-system committed -- and shipped in com_ars --
# maps reading "file:///Volumes/.../release-system/component/media/css/j5.scss",
# and the same sources built to different maps in every checkout (#179).
#
# Sources are now relative to SOURCE_DIR, the directory sourceRoot leads to, and
# a map for an entry point in a subdirectory gets a sourceRoot one level up per
# level of nesting.
#
# `sass` is a consumer dependency, not one of this repository's, so a stub that
# answers the way Dart Sass does -- absolute file: URLs, plus one non-file
# source -- stands in for it via NODE_PATH. What is under test is what the
# template does with the map, not Sass.

set -uo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=helpers.sh
source "${SCRIPT_DIR}/helpers.sh"

ROOT="$(cd "${SCRIPT_DIR}/../.." && pwd)"
BUILD="${ROOT}/templates/build-scss.js"

WORK="$(mktemp -d)"
trap 'rm -rf "${WORK}"' EXIT

# --- a Dart-Sass-shaped stub -------------------------------------------------

mkdir -p "${WORK}/stub/sass"
cat > "${WORK}/stub/sass/index.js" <<'STUB'
'use strict';
const path              = require('path');
const { pathToFileURL } = require('url');

// Same shape as Dart Sass's compile(): the entry point and a load-path partial
// as absolute file: URLs, and a source that is not a file at all.
exports.compile = (file, options) => ({
    css: 'a{color:red}',
    sourceMap: {
        version:  3,
        sources:  [
            pathToFileURL(file).href,
            pathToFileURL(path.join(options.loadPaths[0], 'sources', '_shared.scss')).href,
            'data:;charset=utf-8,a%7Bcolor%3Ared%7D',
        ],
        names:    [],
        mappings: 'AAAA',
    },
});
STUB

# A media_source-style tree: a top-level entry point, one in a subdirectory, and
# a partials-only directory that must produce no output of its own.
make_tree() {
    mkdir -p "$1/sub" "$1/sources"
    printf 'a { color: red; }\n' > "$1/top.scss"
    printf 'b { color: blue; }\n' > "$1/sub/nested.scss"
    printf '$c: 1;\n' > "$1/sources/_shared.scss"
}

# build <project dir> <SOURCE_DIR> <SOURCE_ROOT, or '' for the default> <OUTPUT_DIR>
# -- run the template the way a consumer's package.json does.
build() {
    (
        cd "$1" || exit 1
        if [ -n "${3:-}" ]; then
            NODE_PATH="${WORK}/stub" SOURCE_DIR="$2" OUTPUT_DIR="$4" SOURCE_ROOT="$3" node "${BUILD}"
        else
            NODE_PATH="${WORK}/stub" SOURCE_DIR="$2" OUTPUT_DIR="$4" node "${BUILD}"
        fi
    ) > /dev/null
}

# field <map file> <sources|sourceRoot>
field() {
    node -e '
        const map = JSON.parse(require("fs").readFileSync(process.argv[1], "utf8"));
        const v   = map[process.argv[2]];
        console.log(Array.isArray(v) ? v.join(",") : v);
    ' "$1" "$2"
}

# --- split layout: build/media_source/css -> media/css -----------------------

P="${WORK}/split"
make_tree "${P}/build/media_source/css"
build "${P}" build/media_source/css "" media/css

assert_equals "top.scss,sources/_shared.scss,data:;charset=utf-8,a%7Bcolor%3Ared%7D" \
    "$(field "${P}/media/css/top.css.map" sources)" \
    "top-level map: sources relative to SOURCE_DIR, non-file source untouched"
assert_equals "../../build/media_source/css/" \
    "$(field "${P}/media/css/top.css.map" sourceRoot)" \
    "top-level map: default sourceRoot unchanged"
assert_equals "sub/nested.scss,sources/_shared.scss,data:;charset=utf-8,a%7Bcolor%3Ared%7D" \
    "$(field "${P}/media/css/sub/nested.css.map" sources)" \
    "nested map: sources still relative to SOURCE_DIR"
assert_equals "../../../build/media_source/css/" \
    "$(field "${P}/media/css/sub/nested.css.map" sourceRoot)" \
    "nested map: sourceRoot climbs one more level"
assert_equals "no" "$([ -e "${P}/media/css/sources" ] && echo yes || echo no)" \
    "a partials-only directory produces no output"

# --- same-dir layout, as release-system builds component/media/css -----------

Q="${WORK}/samedir"
make_tree "${Q}/component/media/css"
build "${Q}" component/media/css ./ component/media/css

assert_equals "top.scss,sources/_shared.scss,data:;charset=utf-8,a%7Bcolor%3Ared%7D" \
    "$(field "${Q}/component/media/css/top.css.map" sources)" \
    "same-dir map: sources relative to the shared directory"
assert_equals "./" "$(field "${Q}/component/media/css/top.css.map" sourceRoot)" \
    "same-dir map: SOURCE_ROOT=./ kept as given"
assert_equals "../" "$(field "${Q}/component/media/css/sub/nested.css.map" sourceRoot)" \
    "same-dir nested map: ./ becomes ../"

# --- no map anywhere names the build directory or a file: URL ----------------

leaks="$(grep -l -e 'file:' -e "${WORK}" -r "${WORK}/split/media" "${WORK}/samedir/component" --include='*.map' 2>/dev/null)"
assert_equals "" "${leaks}" "no map carries a file: URL or the build directory"

finish

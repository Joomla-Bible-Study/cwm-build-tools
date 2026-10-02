"use strict";

/**
 * Shared SCSS compiler for CWM Joomla extensions.
 *
 * Unlike build-css.js (which minifies CSS that is already valid CSS),
 * this compiles Sass entry points to compressed CSS with the `sass` npm
 * package (Dart Sass) — there is no separate "unminified" output, because
 * `style: 'compressed'` IS the build: an .scss source is never valid CSS on
 * its own, so there is nothing meaningful to copy verbatim the way
 * build-css.js copies a plain .css alongside its minified sibling.
 *
 * Walks SOURCE_DIR recursively for *.scss files, skipping partials (any
 * filename starting with `_`, the Sass convention for a file that is only
 * ever pulled in via @use/@forward, never compiled standalone). Each entry
 * point's compiled output goes to OUTPUT_DIR, mirroring the subdirectory it
 * was found in relative to SOURCE_DIR, as <name>.css (the .scss extension is
 * dropped, not replaced with .min.css — see above).
 *
 * SOURCE_DIR may equal OUTPUT_DIR. That is not a media_source/media split
 * (the convention build-css.js and rollup.config.js assume) but a single
 * directory holding both .scss sources and their compiled .css siblings,
 * e.g. ARS's component/media/css/. This works because the scan filters on
 * the .scss extension, so previously-compiled .css/.css.map output already
 * sitting in the same directory is never picked back up as a source, and
 * partials sit in subdirectories that produce no top-level output.
 *
 * `@use`/`@forward` paths relative to the importing file (`./foo`, `../foo`)
 * resolve on their own -- Dart Sass always resolves those relative to the
 * file doing the importing. A load-path-style import (`@use 'sources/x'`)
 * needs SOURCE_DIR in `loadPaths`, which this script always sets.
 *
 * Required env vars:
 *   SOURCE_DIR  Path (relative to CWD) to the directory of .scss sources.
 *               Conventionally build/media_source/css, or -- for a project
 *               that keeps source and compiled output side by side, like
 *               ARS -- the same directory as OUTPUT_DIR.
 *   OUTPUT_DIR  Path (relative to CWD) to write compiled .css and source
 *               maps to. Conventionally media/<extension>/css.
 *
 * Optional env vars:
 *   SOURCE_ROOT Source-map sourceRoot value. Defaults to
 *               '../../build/media_source/css/' for consistency with
 *               build-css.js. A same-dir project should set this to './'.
 *
 * Usage from a consuming project's package.json:
 *   "build:scss": "SOURCE_DIR=build/media_source/css OUTPUT_DIR=media/myext/css node vendor/cwm/build-tools/templates/build-scss.js"
 *
 *   Same-dir (source and output share a directory):
 *   "build:scss": "SOURCE_DIR=component/media/css OUTPUT_DIR=component/media/css SOURCE_ROOT=./ node vendor/cwm/build-tools/templates/build-scss.js"
 */

const fs   = require('fs');
const path = require('path');
const sass = require('sass');

const sourceDir  = path.resolve(process.cwd(), required('SOURCE_DIR'));
const outputDir  = path.resolve(process.cwd(), required('OUTPUT_DIR'));
const sourceRoot = process.env.SOURCE_ROOT || '../../build/media_source/css/';

function required(name) {
    const value = process.env[name];

    if (!value) {
        console.error(`Error: ${name} environment variable is required`);
        process.exit(1);
    }

    return value;
}

function ensureDir(dir) {
    if (!fs.existsSync(dir)) {
        fs.mkdirSync(dir, { recursive: true });
    }
}

function isPartial(filename) {
    return filename.startsWith('_');
}

function compileDir(srcDir, outDir) {
    const entries = fs.readdirSync(srcDir);

    entries.forEach(entry => {
        const srcPath = path.join(srcDir, entry);
        const stat    = fs.statSync(srcPath);

        if (stat.isDirectory()) {
            // Partials-only subdirectories (ARS's sources/) produce no output of
            // their own here; only an entry point found somewhere in the tree does.
            compileDir(srcPath, path.join(outDir, entry));

            return;
        }

        if (!entry.endsWith('.scss') || isPartial(entry)) {
            return;
        }

        ensureDir(outDir);

        const name    = entry.slice(0, -'.scss'.length);
        const outFile = path.join(outDir, `${name}.css`);
        const mapFile = `${outFile}.map`;
        const mapName = path.basename(mapFile);

        const result = sass.compile(srcPath, {
            style:      'compressed',
            sourceMap:  true,
            loadPaths:  [sourceDir],
        });

        const sourceMap      = result.sourceMap;
        sourceMap.sourceRoot = sourceRoot;

        const cssWithMapRef = `${result.css}\n/*# sourceMappingURL=${mapName} */`;

        fs.writeFileSync(outFile, cssWithMapRef);
        fs.writeFileSync(mapFile, JSON.stringify(sourceMap));

        console.log(`Compiled: ${path.relative(sourceDir, srcPath)} -> ${path.relative(outputDir, outFile)} + ${mapName}`);
    });
}

console.log('Starting SCSS compilation...');

if (fs.existsSync(sourceDir)) {
    compileDir(sourceDir, outputDir);
    console.log('SCSS compilation complete.');
} else {
    console.error(`Source directory not found: ${sourceDir}`);
    process.exit(1);
}

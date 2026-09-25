"use strict";

/**
 * Shared plain-JS minifier for CWM Joomla extensions.
 *
 * For extensions whose JS is NOT written as rollup-bundleable *.es6.js /
 * *.es6.mjs modules -- e.g. legacy scripts that build up globals across
 * several independently-loaded <script> tags via `window.ns = window.ns || {}`
 * or a top-level `var` -- rollup.config.js is the wrong tool: it wraps each
 * file in its own IIFE, and a top-level `var akeeba = {}` that relied on
 * becoming `window.akeeba` in a plain <script> tag instead becomes a variable
 * local to that IIFE, silently breaking any OTHER script that reads
 * `window.akeeba`. This script minifies each file IN PLACE with no bundling,
 * no scope change and no import resolution -- the direct replacement for
 * Akeeba's old build-js.mjs (Terser CLI, one file at a time).
 *
 * Use rollup.config.js instead when your source is genuinely modular
 * (imports/exports, or you're adopting the *.es6.mjs / JoomlaDialog pattern
 * -- see docs/javascript-and-joomladialog.md).
 *
 * Walks SOURCE_DIR recursively for *.js files, skipping anything already
 * ending in .min.js. Each file's minified output goes to OUTPUT_DIR,
 * mirroring the subdirectory it was found in, as <name>.min.js + a source map.
 *
 * SOURCE_DIR may equal OUTPUT_DIR -- e.g. ARS's component/media/js/, which
 * keeps plain and minified files side by side. Previously-built .min.js
 * files already there are simply skipped by the .min.js filter, not
 * reprocessed.
 *
 * Required env vars:
 *   SOURCE_DIR  Path (relative to CWD) to the directory of unminified .js
 *               sources. Conventionally build/media_source/js for a
 *               media_source/media split, or the same directory as
 *               OUTPUT_DIR for a project that keeps them side by side.
 *   OUTPUT_DIR  Path (relative to CWD) to write minified output and source
 *               maps to.
 *
 * Optional env vars:
 *   SOURCE_ROOT Source-map sourceRoot value. Defaults to './' (same-dir
 *               friendly, unlike build-css.js/build-scss.js's
 *               '../../build/media_source/.../' default, because this
 *               script's first consumer -- ARS -- is same-dir).
 *
 * Usage from a consuming project's package.json:
 *   "build:js": "SOURCE_DIR=component/media/js OUTPUT_DIR=component/media/js node vendor/cwm/build-tools/templates/minify-js.js"
 */

const fs     = require('fs');
const path   = require('path');
const terser = require('terser');

const sourceDir  = path.resolve(process.cwd(), required('SOURCE_DIR'));
const outputDir  = path.resolve(process.cwd(), required('OUTPUT_DIR'));
const sourceRoot = process.env.SOURCE_ROOT || './';

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

async function minifyDir(srcDir, outDir) {
    const entries = fs.readdirSync(srcDir);

    for (const entry of entries) {
        const srcPath = path.join(srcDir, entry);
        const stat    = fs.statSync(srcPath);

        if (stat.isDirectory()) {
            await minifyDir(srcPath, path.join(outDir, entry));

            continue;
        }

        if (!entry.endsWith('.js') || entry.endsWith('.min.js')) {
            continue;
        }

        ensureDir(outDir);

        const name    = entry.slice(0, -'.js'.length);
        const outFile = path.join(outDir, `${name}.min.js`);
        const mapFile = `${outFile}.map`;
        const mapName = path.basename(mapFile);
        const code    = fs.readFileSync(srcPath, 'utf8');

        // Handing terser a {filename: code} map (rather than a bare string) is what
        // makes it record `entry` -- just the bare filename -- as the source map's
        // "sources" entry, instead of leaking this build machine's absolute path.
        const result = await terser.minify({[entry]: code}, {
            compress:  true,
            mangle:    true,
            format:    {comments: false},
            sourceMap: {
                filename: path.basename(outFile),
                url:      mapName,
                root:     sourceRoot,
            },
        });

        if (result.error) {
            throw result.error;
        }

        fs.writeFileSync(outFile, result.code);
        fs.writeFileSync(mapFile, result.map);

        console.log(`Minified: ${path.relative(sourceDir, srcPath)} -> ${path.relative(outputDir, outFile)} + ${mapName}`);
    }
}

console.log('Starting JS minification...');

if (fs.existsSync(sourceDir)) {
    minifyDir(sourceDir, outputDir)
        .then(() => console.log('JS minification complete.'))
        .catch(err => {
            console.error(err);
            process.exit(1);
        });
} else {
    console.error(`Source directory not found: ${sourceDir}`);
    process.exit(1);
}

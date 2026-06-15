"use strict";

/**
 * Shared Rollup config for CWM Joomla extensions.
 *
 * Lifted from byte-near-identical copies in lib_cwmscripture and Proclaim
 * — only the output directory differed. Now parameterized via env vars.
 *
 * Bundles every *.es6.js / *.es6.mjs in SOURCE_DIR into both an unminified
 * .js and a minified .min.js (with source map) in OUTPUT_DIR. Output filename
 * drops the .es6.js / .es6.mjs suffix (both land on a plain .js).
 *
 * Output format is chosen by source suffix:
 *   *.es6.js   -> IIFE (the historical default). Every import is bundled in
 *                via @rollup/plugin-node-resolve. Register as a normal
 *                <script> asset. Unchanged from earlier versions.
 *   *.es6.mjs  -> ES module (`format: 'es'`). Bare `joomla.*` specifiers
 *                (e.g. `import JoomlaDialog from 'joomla.dialog'`) are marked
 *                EXTERNAL so the import survives to the browser and Joomla's
 *                import-map resolves it at runtime. Register the built file as
 *                a `type="module"` asset in joomla.asset.json. This is the
 *                only way to consume Joomla JS module APIs (JoomlaDialog,
 *                etc.) first-class — an IIFE bundle cannot carry a live
 *                external ESM import.
 *
 * Required env vars:
 *   SOURCE_DIR  Path (relative to CWD) to .es6.js / .es6.mjs source files.
 *               Conventionally build/media_source/js.
 *   OUTPUT_DIR  Path (relative to CWD) to write bundled output.
 *               Conventionally media/<extension>/js.
 *
 * Optional env vars:
 *   MODULE_EXTERNALS  Comma-separated extra bare-specifier prefixes to treat
 *                     as external in *.es6.mjs builds, on top of the built-in
 *                     `joomla.` prefix (e.g. "vue,@vue/"). Ignored for IIFE.
 *
 * Usage from a consuming project's package.json:
 *   "build:js": "SOURCE_DIR=build/media_source/js OUTPUT_DIR=media/myext/js rollup -c vendor/cwm/build-tools/templates/rollup.config.js"
 */

const resolve    = require('@rollup/plugin-node-resolve');
const terser     = require('@rollup/plugin-terser');
const gzipPlugin = require('rollup-plugin-gzip').default;
const fs         = require('fs');
const path       = require('path');

const sourceDir = path.resolve(process.cwd(), required('SOURCE_DIR'));
const outputDir = path.resolve(process.cwd(), required('OUTPUT_DIR'));

function required(name) {
    const value = process.env[name];

    if (!value) {
        console.error(`Error: ${name} environment variable is required`);
        process.exit(1);
    }

    return value;
}

if (!fs.existsSync(outputDir)) {
    fs.mkdirSync(outputDir, { recursive: true });
}

const getSourceFiles = () => {
    if (!fs.existsSync(sourceDir)) {
        console.warn(`Source directory not found: ${sourceDir}`);
        return [];
    }

    return fs.readdirSync(sourceDir)
        .filter(file => file.endsWith('.es6.js') || file.endsWith('.es6.mjs'))
        .map(file => {
            const isModule = file.endsWith('.es6.mjs');

            return {
                name:       file,
                path:       path.join(sourceDir, file),
                outputName: file.replace(isModule ? '.es6.mjs' : '.es6.js', ''),
                isModule,
            };
        });
};

// Bare-specifier prefixes left unbundled in ES-module builds, so Joomla's
// import-map resolves them at runtime. `joomla.` is always external; consumers
// can add more via the MODULE_EXTERNALS env var (comma-separated).
const moduleExternalPrefixes = ['joomla.'].concat(
    (process.env.MODULE_EXTERNALS || '')
        .split(',')
        .map(s => s.trim())
        .filter(Boolean)
);

const isExternalModuleId = id => moduleExternalPrefixes.some(prefix => id.startsWith(prefix));

const sourceFiles = getSourceFiles();

if (sourceFiles.length === 0) {
    console.warn('No .es6.js / .es6.mjs files found to process');
}

module.exports = sourceFiles.flatMap(fileObj => {
    const variableName = fileObj.outputName.replace(/-/g, '_');
    const format       = fileObj.isModule ? 'es' : 'iife';

    // `name` is only meaningful for iife/umd; `external` only for es output.
    const formatOpts   = fileObj.isModule ? {} : { name: variableName };
    const externalOpts = fileObj.isModule ? { external: isExternalModuleId } : {};

    return [
        {
            input: fileObj.path,
            ...externalOpts,
            output: {
                file:      path.join(outputDir, `${fileObj.outputName}.js`),
                format,
                ...formatOpts,
                sourcemap: false,
            },
            plugins: [
                resolve(),
            ],
        },
        {
            input: fileObj.path,
            ...externalOpts,
            output: {
                file:      path.join(outputDir, `${fileObj.outputName}.min.js`),
                format,
                ...formatOpts,
                sourcemap: true,
            },
            plugins: [
                resolve(),
                terser({
                    module: fileObj.isModule,
                    format: { comments: false },
                }),
                gzipPlugin(),
            ],
        },
    ];
});

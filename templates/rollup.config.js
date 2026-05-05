"use strict";

/**
 * Shared Rollup config for CWM Joomla extensions.
 *
 * Lifted from byte-near-identical copies in lib_cwmscripture and Proclaim
 * — only the output directory differed. Now parameterized via env vars.
 *
 * Bundles every *.es6.js in SOURCE_DIR into both an unminified .js and a
 * minified .min.js (with source map) in OUTPUT_DIR. Output filename drops
 * the .es6 suffix.
 *
 * Required env vars:
 *   SOURCE_DIR  Path (relative to CWD) to .es6.js source files.
 *               Conventionally build/media_source/js.
 *   OUTPUT_DIR  Path (relative to CWD) to write bundled output.
 *               Conventionally media/<extension>/js.
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
        .filter(file => file.endsWith('.es6.js'))
        .map(file => ({
            name:       file,
            path:       path.join(sourceDir, file),
            outputName: file.replace('.es6.js', ''),
        }));
};

const sourceFiles = getSourceFiles();

if (sourceFiles.length === 0) {
    console.warn('No .es6.js files found to process');
}

module.exports = sourceFiles.flatMap(fileObj => {
    const variableName = fileObj.outputName.replace(/-/g, '_');

    return [
        {
            input: fileObj.path,
            output: {
                file:      path.join(outputDir, `${fileObj.outputName}.js`),
                format:    'iife',
                name:      variableName,
                sourcemap: false,
            },
            plugins: [
                resolve(),
            ],
        },
        {
            input: fileObj.path,
            output: {
                file:      path.join(outputDir, `${fileObj.outputName}.min.js`),
                format:    'iife',
                name:      variableName,
                sourcemap: true,
            },
            plugins: [
                resolve(),
                terser({
                    format: { comments: false },
                }),
                gzipPlugin(),
            ],
        },
    ];
});

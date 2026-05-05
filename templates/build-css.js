"use strict";

/**
 * Shared CSS minification script for CWM Joomla extensions.
 *
 * Lifted from byte-near-identical copies in lib_cwmscripture and Proclaim
 * — only the output directory differed. Now parameterized via env vars.
 *
 * Required env vars:
 *   SOURCE_DIR  Path (relative to CWD) to the directory of unminified .css
 *               sources. Conventionally build/media_source/css.
 *   OUTPUT_DIR  Path (relative to CWD) to write minified output and source
 *               maps to. Conventionally media/<extension>/css.
 *
 * Optional env vars:
 *   SOURCE_ROOT Source-map sourceRoot value. Defaults to
 *               '../../build/media_source/css/' for backwards compatibility
 *               with the original layout.
 *
 * Usage from a consuming project's package.json:
 *   "build:css": "SOURCE_DIR=build/media_source/css OUTPUT_DIR=media/myext/css node vendor/cwm/build-tools/templates/build-css.js"
 */

const fs   = require('fs');
const path = require('path');
const csso = require('csso');

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

function minifyDir(srcDir, outDir) {
    ensureDir(outDir);
    const files = fs.readdirSync(srcDir);

    files.forEach(file => {
        const srcPath = path.join(srcDir, file);
        const stat    = fs.statSync(srcPath);

        if (stat.isDirectory()) {
            minifyDir(srcPath, path.join(outDir, file));
        } else if (file.endsWith('.css') && !file.endsWith('.min.css')) {
            const css         = fs.readFileSync(srcPath, 'utf8');
            const outFile     = path.join(outDir, file);
            const minFile     = path.join(outDir, file.replace('.css', '.min.css'));
            const mapFile     = minFile + '.map';
            const mapFileName = path.basename(mapFile);

            fs.copyFileSync(srcPath, outFile);

            const result = csso.minify(css, {
                filename:  file,
                sourceMap: true,
            });

            const sourceMap        = JSON.parse(result.map.toString());
            sourceMap.sourceRoot   = sourceRoot;
            sourceMap.sources      = [file];

            const cssWithMapRef = `${result.css}\n/*# sourceMappingURL=${mapFileName} */`;

            fs.writeFileSync(minFile, cssWithMapRef);
            fs.writeFileSync(mapFile, JSON.stringify(sourceMap));

            console.log(`Minified: ${file} -> ${path.basename(minFile)} + ${mapFileName}`);
        }
    });
}

console.log('Starting CSS minification...');

if (fs.existsSync(sourceDir)) {
    console.log('Minifying CSS files...');
    minifyDir(sourceDir, outputDir);
    console.log('CSS minification complete.');
} else {
    console.error(`Source directory not found: ${sourceDir}`);
    process.exit(1);
}

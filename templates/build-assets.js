"use strict";

/**
 * Shared asset-copy script for CWM Joomla extensions.
 *
 * Lifted from a copy in Proclaim — only the package list and output dirs
 * differed. Now parameterized via the `assets` block of cwm-build.config.json.
 *
 * Operations (each section optional):
 *   1. Copy a source images directory into the project's media tree.
 *   2. Mirror manually-managed vendor subdirectories that live outside
 *      node_modules (e.g. an upstream lib not on npm).
 *   3. For each npm package, cherry-pick specific files and directories
 *      out of node_modules — with optional CSS minification, optional
 *      pre-clean, and optional filename glob filter on directory copies.
 *
 * Usage from a consuming project's package.json:
 *   "build:assets": "node vendor/cwm/build-tools/templates/build-assets.js"
 *
 * Config block (`cwm-build.config.json`):
 *   "assets": {
 *       "images":             { "from": "build/media_source/images", "to": "media/myext/images" },
 *       "vendorMediaSource":  { "from": "build/media_source/vendor", "to": "media/myext/vendor" },
 *       "vendorOutputDir":    "media/myext/vendor",
 *       "packages": [
 *           {
 *               "name": "chart.js",
 *               "files": [
 *                   { "from": "dist/chart.umd.min.js", "to": "chart.js/chart.umd.min.js" }
 *               ]
 *           },
 *           {
 *               "name": "@fancyapps/ui",
 *               "cleanDest": "fancybox",
 *               "files": [
 *                   { "from": "dist/fancybox/fancybox.umd.js", "to": "fancybox/fancybox.umd.js" },
 *                   { "from": "dist/fancybox/fancybox.css",    "to": "fancybox/fancybox.css", "minifyCss": true }
 *               ],
 *               "dirs": [
 *                   { "from": "dist/fancybox/l10n", "to": "fancybox/l10n", "match": "*.umd.js" }
 *               ]
 *           }
 *       ]
 *   }
 *
 * Path conventions:
 *   - `images.from` / `images.to`            relative to cwd
 *   - `vendorMediaSource.from` / `to`        relative to cwd
 *   - `vendorOutputDir`                      relative to cwd
 *   - `packages[i].files[j].from`            relative to node_modules/<package.name>/
 *   - `packages[i].files[j].to`              relative to vendorOutputDir
 *   - `packages[i].dirs[j].from` / `to`      same convention
 *   - `packages[i].cleanDest`                relative to vendorOutputDir
 *
 * Optional fields:
 *   - `minifyCss`   on a file entry  — also writes a `.min.css` sibling via csso
 *   - `match`       on a dir entry   — `*.ext` glob restricting which files copy
 *   - `cleanDest`   on a package     — wipes vendorOutputDir/<cleanDest> first
 *
 * `csso` is required from the consumer's node_modules (consumer adds it to
 * devDependencies — same posture as build-css.js).
 */

const fs   = require('fs');
const path = require('path');

const CONFIG_PATH = process.env.CWM_BUILD_CONFIG
    ? path.resolve(process.cwd(), process.env.CWM_BUILD_CONFIG)
    : path.resolve(process.cwd(), 'cwm-build.config.json');

if (!fs.existsSync(CONFIG_PATH)) {
    console.error(`Error: ${CONFIG_PATH} not found.`);
    console.error('Run from the project root, or set CWM_BUILD_CONFIG to the config path.');
    process.exit(1);
}

let config;

try {
    config = JSON.parse(fs.readFileSync(CONFIG_PATH, 'utf8'));
} catch (err) {
    console.error(`Error: ${CONFIG_PATH} is not valid JSON: ${err.message}`);
    process.exit(1);
}

const assets = config.assets;

if (!assets) {
    console.error('Error: cwm-build.config.json has no `assets` block. Nothing to do.');
    process.exit(1);
}

const cwd          = process.cwd();
const nodeModules  = path.resolve(cwd, 'node_modules');

// --- Helpers ------------------------------------------------------------

/**
 * Recursively copy a directory. Mirrors Proclaim's existing behavior:
 * creates the destination if missing, walks every entry, recurses on subdirs.
 *
 * @param {string} src  Absolute source directory.
 * @param {string} dest Absolute destination directory.
 * @param {{match?: RegExp}} [opts]  When `match` is set, only files whose
 *                                   basename tests true against the regex
 *                                   are copied (subdirectories still recurse).
 */
function copyDir(src, dest, opts = {}) {
    if (!fs.existsSync(dest)) {
        fs.mkdirSync(dest, { recursive: true });
    }

    const entries = fs.readdirSync(src, { withFileTypes: true });

    entries.forEach(entry => {
        const srcPath  = path.join(src, entry.name);
        const destPath = path.join(dest, entry.name);

        if (entry.isDirectory()) {
            copyDir(srcPath, destPath, opts);
            return;
        }

        if (opts.match && !opts.match.test(entry.name)) {
            return;
        }

        fs.copyFileSync(srcPath, destPath);
    });
}

/**
 * Copy a single file, creating parent directories as needed.
 */
function copyFile(src, dest) {
    fs.mkdirSync(path.dirname(dest), { recursive: true });
    fs.copyFileSync(src, dest);
}

/**
 * Recursively remove a directory and all its contents.
 * No-op when the directory doesn't exist.
 */
function cleanDir(dir) {
    if (fs.existsSync(dir)) {
        fs.rmSync(dir, { recursive: true, force: true });
    }
}

/**
 * Copy a CSS file and write a `.min.css` sibling alongside it via csso.
 * `csso` is loaded lazily so projects without CSS minification don't need it.
 */
let cssoCache = null;

function copyCssWithMin(src, dest) {
    if (!cssoCache) {
        try {
            cssoCache = require('csso');
        } catch (err) {
            console.error('Error: `csso` is required for `minifyCss: true` entries. Add it to devDependencies.');
            process.exit(1);
        }
    }

    copyFile(src, dest);

    const css     = fs.readFileSync(src, 'utf8');
    const minDest = dest.replace(/\.css$/, '.min.css');

    fs.writeFileSync(minDest, cssoCache.minify(css).css);
}

/**
 * Convert a `*.ext` glob to a RegExp that tests basenames.
 * Only the trailing-`*` form is supported — anything fancier should be
 * handled in JavaScript by the consumer's own build script.
 */
function compileMatch(glob) {
    if (!glob) {
        return null;
    }

    const escaped = glob
        .replace(/[.+?^${}()|[\]\\]/g, '\\$&')   // escape regex meta
        .replace(/\*/g, '.*');                    // expand glob *

    return new RegExp(`^${escaped}$`);
}

// --- Operations ---------------------------------------------------------

// 1. Images
if (assets.images && assets.images.from && assets.images.to) {
    const fromAbs = path.resolve(cwd, assets.images.from);
    const toAbs   = path.resolve(cwd, assets.images.to);

    if (fs.existsSync(fromAbs)) {
        console.log(`Copying images from ${assets.images.from} to ${assets.images.to}...`);
        copyDir(fromAbs, toAbs);
    } else {
        console.log(`Skipping images: source dir ${assets.images.from} not found.`);
    }
}

// 2. Manually-managed vendor mirror (auto-discover subdirs)
if (assets.vendorMediaSource && assets.vendorMediaSource.from && assets.vendorMediaSource.to) {
    const fromAbs = path.resolve(cwd, assets.vendorMediaSource.from);
    const toAbs   = path.resolve(cwd, assets.vendorMediaSource.to);

    if (fs.existsSync(fromAbs)) {
        console.log(`Copying media_source vendor from ${assets.vendorMediaSource.from}...`);

        const subEntries = fs.readdirSync(fromAbs, { withFileTypes: true });

        subEntries.forEach(entry => {
            if (!entry.isDirectory()) {
                return;
            }

            copyDir(
                path.join(fromAbs, entry.name),
                path.join(toAbs, entry.name)
            );
            console.log(`  Copied vendor/${entry.name} (from media_source)`);
        });
    }
}

// 3. npm package cherry-pick
const packages       = Array.isArray(assets.packages) ? assets.packages : [];
const vendorOutDirCfg = assets.vendorOutputDir;

if (packages.length > 0) {
    if (!vendorOutDirCfg) {
        console.error('Error: `assets.packages` is set but `assets.vendorOutputDir` is missing.');
        process.exit(1);
    }

    if (!fs.existsSync(nodeModules)) {
        console.error(`Error: ${nodeModules} not found. Run 'npm install' before build:assets.`);
        process.exit(1);
    }
}

const vendorOutAbs = vendorOutDirCfg ? path.resolve(cwd, vendorOutDirCfg) : null;

console.log('Copying vendor libraries from node_modules...');

packages.forEach(pkg => {
    if (!pkg.name) {
        console.error('Error: every entry in `assets.packages` must have a `name`.');
        process.exit(1);
    }

    const pkgRoot = path.join(nodeModules, pkg.name);

    if (!fs.existsSync(pkgRoot)) {
        console.error(`Error: ${pkg.name} not found in node_modules. Run 'npm install'.`);
        process.exit(1);
    }

    if (pkg.cleanDest) {
        cleanDir(path.join(vendorOutAbs, pkg.cleanDest));
    }

    (pkg.files || []).forEach(file => {
        if (!file.from || !file.to) {
            console.error(`Error: ${pkg.name}: every files[] entry needs both \`from\` and \`to\`.`);
            process.exit(1);
        }

        const src  = path.join(pkgRoot, file.from);
        const dest = path.join(vendorOutAbs, file.to);

        if (file.minifyCss) {
            copyCssWithMin(src, dest);
        } else {
            copyFile(src, dest);
        }
    });

    (pkg.dirs || []).forEach(dir => {
        if (!dir.from || !dir.to) {
            console.error(`Error: ${pkg.name}: every dirs[] entry needs both \`from\` and \`to\`.`);
            process.exit(1);
        }

        const src  = path.join(pkgRoot, dir.from);
        const dest = path.join(vendorOutAbs, dir.to);

        if (!fs.existsSync(src)) {
            return;
        }

        copyDir(src, dest, { match: compileMatch(dir.match) });
    });

    console.log(`  Copied ${pkg.name}`);
});

console.log('Asset copy complete.');

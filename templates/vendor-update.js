"use strict";

/**
 * Vendor Dependency Updater.
 *
 * Updates all bundled vendor libraries (listed in cwm-build.config.json
 * under vendors[]) to their latest npm versions, then re-runs `npm run build`
 * so the updated assets are copied into media/.
 *
 * Does NOT auto-update devDependencies or Composer packages — those can have
 * breaking changes. Run `vendor:check` first to see what's outdated.
 *
 * Reads from <CWD>/cwm-build.config.json:
 *   vendors[]   { npm: "chart.js", label: "chart.js" }
 *
 * Usage from a consuming project's package.json:
 *   "vendor:update": "node vendor/cwm/build-tools/templates/vendor-update.js"
 */

const { execFileSync } = require('child_process');
const fs               = require('fs');
const path             = require('path');

const ROOT       = process.cwd();
const CONFIG     = readJson(path.join(ROOT, 'cwm-build.config.json')) || {};
const VENDORS    = (CONFIG.vendors || []);

function runFile(cmd, args, opts = {}) {
    console.log(`  ${cmd} ${args.join(' ')}`);
    try {
        execFileSync(cmd, args, { cwd: ROOT, stdio: 'inherit', timeout: 120000, ...opts });
        return true;
    } catch {
        console.error(`  FAILED: ${cmd} ${args.join(' ')}`);
        return false;
    }
}

function readJson(filePath) {
    try {
        return JSON.parse(fs.readFileSync(filePath, 'utf8'));
    } catch {
        return null;
    }
}

function npmInstalledVersion(pkg) {
    const pkgJson = readJson(path.join(ROOT, 'node_modules', pkg, 'package.json'));
    return pkgJson ? pkgJson.version : '?';
}

console.log('Vendor Updater');
console.log('==============');

if (VENDORS.length === 0) {
    console.log('\nNo vendors configured in cwm-build.config.json (vendors[] is empty).');
    console.log('Add an entry per bundled library you want kept current, e.g.:');
    console.log('  "vendors": [');
    console.log('    { "npm": "chart.js",       "label": "chart.js" },');
    console.log('    { "npm": "@fancyapps/ui",  "label": "fancybox" }');
    console.log('  ]');
    process.exit(0);
}

const before = {};
VENDORS.forEach(v => { before[v.npm] = npmInstalledVersion(v.npm); });

console.log('\n1. Updating npm vendor packages...');
runFile('npm', ['update', ...VENDORS.map(v => v.npm)]);

const after = {};
VENDORS.forEach(v => { after[v.npm] = npmInstalledVersion(v.npm); });

const changes = [];
VENDORS.forEach(v => {
    if (before[v.npm] !== after[v.npm]) {
        changes.push(`  ${v.label || v.npm}: ${before[v.npm]} -> ${after[v.npm]}`);
    }
});

if (changes.length) {
    console.log('  Updated:');
    changes.forEach(c => console.log(c));
} else {
    console.log('  All vendor packages already up to date.');
}

console.log('\n2. Rebuilding media assets...');
runFile('npm', ['run', 'build']);

console.log('\n' + '='.repeat(50));
if (changes.length) {
    console.log('Summary of updates:');
    changes.forEach(c => console.log(c));
    console.log('\nRemember to test and commit the changes.');
} else {
    console.log('All vendor libraries are already up to date.');
}

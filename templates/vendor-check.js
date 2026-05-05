"use strict";

/**
 * Vendor Dependency Version Checker.
 *
 * Reports the status of all project dependencies in three groups:
 *   1. Vendor Libraries  — bundled libraries listed in cwm-build.config.json
 *   2. Dev Dependencies  — npm build tools (from package.json)
 *   3. PHP Dependencies  — Composer packages (composer outdated)
 *
 * Exit code 0 = all current, 1 = updates available.
 *
 * Reads from <CWD>/cwm-build.config.json:
 *   vendors[]            { npm: "chart.js", label: "chart.js", notes: "..." }
 *
 * If vendors[] is empty or missing, only sections 2 and 3 are reported.
 *
 * Usage from a consuming project's package.json:
 *   "vendor:check": "node vendor/cwm/build-tools/templates/vendor-check.js"
 */

const { execFileSync } = require('child_process');
const fs               = require('fs');
const path             = require('path');

const ROOT        = process.cwd();
const CONFIG      = readJson(path.join(ROOT, 'cwm-build.config.json')) || {};
const VENDOR_LIST = (CONFIG.vendors || []);

function runFile(cmd, args) {
    try {
        return execFileSync(cmd, args, { cwd: ROOT, encoding: 'utf8', timeout: 30000 }).trim();
    } catch {
        return '';
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

function npmLatestVersion(pkg) {
    return runFile('npm', ['view', pkg, 'version']) || '?';
}

function renderTable(headers, rows) {
    const widths = headers.map((h, i) => Math.max(h.length, ...rows.map(r => String(r[i] || '').length)));
    const sep    = '+' + widths.map(w => '-'.repeat(w + 2)).join('+') + '+';
    const fmt    = r => '| ' + r.map((c, i) => String(c || '').padEnd(widths[i])).join(' | ') + ' |';

    console.log(sep);
    console.log(fmt(headers));
    console.log(sep);
    rows.forEach(r => console.log(fmt(r)));
    console.log(sep);
}

function checkVendors() {
    if (VENDOR_LIST.length === 0) {
        return false;
    }

    console.log('\nVendor Libraries (bundled with the extension)');

    let hasUpdates = false;
    const rows = VENDOR_LIST.map(v => {
        const current  = npmInstalledVersion(v.npm);
        const latest   = npmLatestVersion(v.npm);
        const outdated = current !== '?' && latest !== '?' && current !== latest;
        if (outdated) {
            hasUpdates = true;
        }
        return [
            v.label || v.npm,
            current,
            latest,
            outdated ? '✗ Update' : '✓ OK',
            v.notes || '',
        ];
    });

    renderTable(['Library', 'Current', 'Latest', 'Status', 'Release Notes'], rows);
    return hasUpdates;
}

function checkDevDependencies() {
    console.log('\nDev Dependencies (build tools)');

    const json = runFile('npm', ['outdated', '--json']);
    let outdated = {};
    if (json) {
        try {
            outdated = JSON.parse(json);
        } catch {
            // ignore
        }
    }

    const pkgJson    = readJson(path.join(ROOT, 'package.json'));
    const allDevDeps = pkgJson ? Object.keys(pkgJson.devDependencies || {}).sort() : [];

    let hasUpdates = false;
    const rows = [];

    for (const pkg of allDevDeps) {
        const info = outdated[pkg];
        if (info) {
            hasUpdates = true;
            rows.push([pkg, info.current || '?', info.latest || '?', '✗ Update']);
        }
    }

    if (rows.length === 0) {
        rows.push(['(all packages)', '', '', '✓ OK']);
    }

    renderTable(['Package', 'Current', 'Latest', 'Status'], rows);
    return hasUpdates;
}

function checkPhpDependencies() {
    console.log('\nPHP Dependencies (composer)');

    const json = runFile('composer', ['outdated', '--direct', '--format=json']);
    let packages = [];
    if (json) {
        try {
            const data = JSON.parse(json);
            packages = data.installed || [];
        } catch {
            // ignore
        }
    }

    let hasUpdates = false;
    const rows = packages.map(p => {
        hasUpdates = true;
        return [p.name, p.version || '?', p.latest || '?', '✗ Update'];
    });

    if (rows.length === 0) {
        rows.push(['(all packages)', '', '', '✓ OK']);
    }

    renderTable(['Package', 'Current', 'Latest', 'Status'], rows);
    return hasUpdates;
}

console.log('Vendor / Dependency Status');
console.log('==========================');

const vendorUpdates = checkVendors();
const devUpdates    = checkDevDependencies();
const phpUpdates    = checkPhpDependencies();

console.log('');

if (vendorUpdates || devUpdates || phpUpdates) {
    console.log('Some packages have updates available.');
    process.exit(1);
} else {
    console.log('All checked packages are up to date.');
    process.exit(0);
}

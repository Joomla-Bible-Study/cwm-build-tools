"use strict";

/**
 * Vendor Dependency Version Checker.
 *
 * Reports the status of all project dependencies in four groups:
 *   1. Vendor Libraries  — bundled libraries listed in cwm-build.config.json
 *   2. Dev Dependencies  — npm build tools (from package.json)
 *   3. PHP Dependencies  — Composer packages (composer outdated), root + nested projects
 *   4. Security          — known advisories (composer audit + npm audit)
 *
 * Exit codes:
 *   0 = everything current and no advisories
 *   1 = updates available
 *   2 = security advisories found (takes precedence over 1)
 *
 * Reads from <CWD>/cwm-build.config.json:
 *   vendors[]            { npm: "chart.js", label: "chart.js", notes: "..." }
 *   security {}          optional, see below — all keys have working defaults
 *     scanNested         bool, default true. Discover nested Composer projects.
 *     nestedPaths[]      explicit project dirs, relative to root. Set this to
 *                        skip auto-discovery entirely.
 *     maxDepth           int, default 6. Auto-discovery depth limit.
 *     ignore[]           advisory IDs to suppress (e.g. "GHSA-xxxx-xxxx-xxxx").
 *
 * If vendors[] is empty or missing, only sections 2-4 are reported.
 *
 * Why nested projects matter: an extension may bundle its own Composer project
 * (its vendor/ tree committed and shipped to end users). Those dependencies are
 * invisible to a root-level `composer outdated`, so a vulnerable bundled package
 * can ship indefinitely without this tool noticing.
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
const SECURITY    = (CONFIG.security || {});
const IGNORED     = new Set(SECURITY.ignore || []);

const DEFAULT_MAX_DEPTH = 6;
const TIMEOUT_MS        = 60000;

/** Directories never worth descending into when hunting for nested projects. */
const SKIP_DIRS = new Set(['vendor', 'node_modules', 'media', 'dist']);

/** Normalised severity ranking, worst first. Composer says "medium", npm says "moderate". */
const SEVERITY_RANK = { critical: 0, high: 1, moderate: 2, medium: 2, low: 3, info: 4 };

/**
 * Run a command and return its stdout.
 *
 * Several of the tools we shell out to use a non-zero exit status to report
 * *findings* rather than failure — `npm outdated` exits 1 when anything is
 * outdated, `npm audit` and `composer audit` exit non-zero when advisories
 * exist. Their stdout is still the payload we want, so callers that expect
 * that pass allowFailure and we recover it from the thrown error.
 */
function runFile(cmd, args, opts = {}) {
    try {
        return execFileSync(cmd, args, {
            cwd:      opts.cwd || ROOT,
            encoding: 'utf8',
            timeout:  TIMEOUT_MS,
            stdio:    ['ignore', 'pipe', 'ignore'],
        }).trim();
    } catch (err) {
        if (opts.allowFailure && err && typeof err.stdout === 'string') {
            return err.stdout.trim();
        }
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

function parseJson(raw) {
    if (!raw) {
        return null;
    }
    try {
        return JSON.parse(raw);
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

/**
 * Locate Composer projects nested inside the repository.
 *
 * Returns paths relative to ROOT. Does not descend into a nested project's own
 * subtree — its dependencies belong to it, not to a deeper project.
 */
function discoverNestedComposerProjects() {
    if (SECURITY.scanNested === false) {
        return [];
    }

    if (Array.isArray(SECURITY.nestedPaths) && SECURITY.nestedPaths.length > 0) {
        return SECURITY.nestedPaths.filter(p => fs.existsSync(path.join(ROOT, p, 'composer.json')));
    }

    const maxDepth = Number.isInteger(SECURITY.maxDepth) ? SECURITY.maxDepth : DEFAULT_MAX_DEPTH;
    const found    = [];

    (function walk(dir, depth) {
        if (depth > maxDepth) {
            return;
        }

        let entries;
        try {
            entries = fs.readdirSync(dir, { withFileTypes: true });
        } catch {
            return;
        }

        for (const entry of entries) {
            if (!entry.isDirectory() || entry.name.startsWith('.') || SKIP_DIRS.has(entry.name)) {
                continue;
            }

            const full = path.join(dir, entry.name);

            if (fs.existsSync(path.join(full, 'composer.json'))) {
                found.push(path.relative(ROOT, full));
                continue;
            }

            walk(full, depth + 1);
        }
    })(ROOT, 1);

    return found.sort();
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

    // npm outdated exits 1 when anything is outdated — allowFailure keeps the JSON.
    const outdated = parseJson(runFile('npm', ['outdated', '--json'], { allowFailure: true })) || {};

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

function composerOutdated(cwd) {
    const data = parseJson(runFile('composer', ['outdated', '--direct', '--format=json'], { cwd, allowFailure: true }));
    return (data && data.installed) || [];
}

function checkPhpDependencies(nestedProjects) {
    console.log('\nPHP Dependencies (composer)');

    const scopes = [{ label: '(root)', cwd: ROOT }].concat(
        nestedProjects.map(p => ({ label: p, cwd: path.join(ROOT, p) }))
    );

    let hasUpdates = false;
    const rows = [];

    for (const scope of scopes) {
        for (const p of composerOutdated(scope.cwd)) {
            hasUpdates = true;
            rows.push([scope.label, p.name, p.version || '?', p.latest || '?', '✗ Update']);
        }
    }

    if (rows.length === 0) {
        rows.push(['(root)', '(all packages)', '', '', '✓ OK']);
    }

    renderTable(['Scope', 'Package', 'Current', 'Latest', 'Status'], rows);
    return hasUpdates;
}

/**
 * Collect Composer advisories for one project directory.
 *
 * Unlike `composer outdated --direct`, audit covers transitive packages too —
 * which is the point, since bundled vulnerabilities are usually transitive.
 *
 * Audits the lock file rather than the installed tree. Plain `composer audit`
 * inspects vendor/, so a project that has not been installed reports
 * "No packages - skipping audit" and exits 0 — a silent false clean. The lock
 * is also the honest source of truth for what a project ships.
 */
function composerAdvisories(label, cwd) {
    const hasLock      = fs.existsSync(path.join(cwd, 'composer.lock'));
    const hasInstalled = fs.existsSync(path.join(cwd, 'vendor', 'composer', 'installed.php'));

    // Nothing resolved to audit — no lock and nothing installed. That is a
    // skip, not a failure; a project with only a composer.json has no
    // dependency set for audit to reason about.
    if (!fs.existsSync(path.join(cwd, 'composer.json')) || (!hasLock && !hasInstalled)) {
        return { ok: true, findings: [] };
    }

    const args = ['audit', '--format=json'];
    if (hasLock) {
        args.splice(1, 0, '--locked');
    }

    const data = parseJson(runFile('composer', args, { cwd, allowFailure: true }));

    // No parsable output means the audit did not run — composer missing from
    // PATH, a timeout, a broken lock. Report it rather than treating an absent
    // answer as a clean one.
    if (!data || typeof data.advisories !== 'object' || data.advisories === null) {
        return { ok: false, findings: [], scope: label, reason: 'composer audit produced no parsable output' };
    }

    const out = [];
    for (const [pkg, list] of Object.entries(data.advisories)) {
        for (const adv of (Array.isArray(list) ? list : [])) {
            // Every identifier this advisory is known by. Composer's own
            // advisoryId is a PKSA-*; the GHSA lives in sources[].remoteId and
            // is what Dependabot and GitHub display, so prefer it for output
            // and accept any of them in the ignore list.
            const remoteIds = (adv.sources || []).map(s => s.remoteId).filter(Boolean);
            const ids       = [...remoteIds, adv.advisoryId, adv.cve].filter(Boolean);

            if (ids.some(id => IGNORED.has(id))) {
                continue;
            }

            out.push({
                scope:    label,
                pkg,
                severity: String(adv.severity || 'unknown').toLowerCase(),
                id:       remoteIds.find(id => id.startsWith('GHSA-')) || adv.advisoryId || adv.cve || '?',
                title:    adv.title || '',
            });
        }
    }
    return { ok: true, findings: out };
}

function npmAdvisories() {
    // Nothing to audit deterministically without a manifest and a lockfile.
    if (!fs.existsSync(path.join(ROOT, 'package.json'))
        || !fs.existsSync(path.join(ROOT, 'package-lock.json'))) {
        return { ok: true, findings: [] };
    }

    const data = parseJson(runFile('npm', ['audit', '--json'], { allowFailure: true }));

    if (!data || typeof data.vulnerabilities !== 'object' || data.vulnerabilities === null) {
        return { ok: false, findings: [], scope: '(npm)', reason: 'npm audit produced no parsable output' };
    }

    const out = [];
    for (const [pkg, info] of Object.entries(data.vulnerabilities)) {
        // `via` holds either advisory objects or names of packages pulling it in.
        const sources = (info.via || []).filter(v => typeof v === 'object');
        if (sources.length === 0) {
            continue;
        }
        for (const src of sources) {
            const id = src.url ? src.url.split('/').pop() : (src.source ? String(src.source) : '?');
            if (IGNORED.has(id)) {
                continue;
            }
            out.push({
                scope:    '(npm)',
                pkg,
                severity: String(src.severity || info.severity || 'unknown').toLowerCase(),
                id,
                title:    src.title || '',
            });
        }
    }
    return { ok: true, findings: out };
}

/**
 * Report known advisories across every audited scope.
 *
 * Fails closed. A scope whose audit could not run is reported as unverified and
 * treated as a failure, because "we did not find anything" and "we did not
 * look" are not the same answer — and silently conflating them is how a
 * vulnerable tree earns a green check.
 */
function checkSecurity(nestedProjects) {
    console.log('\nSecurity Advisories');

    const results = [
        composerAdvisories('(root)', ROOT),
        ...nestedProjects.map(p => composerAdvisories(p, path.join(ROOT, p))),
        npmAdvisories(),
    ];

    const findings   = results.flatMap(r => r.findings);
    const unverified = results.filter(r => !r.ok);

    if (findings.length === 0) {
        // Don't claim a clean result when some scope never reported.
        renderTable(['Scope', 'Package', 'Severity', 'Advisory', 'Summary'], [
            unverified.length > 0
                ? ['(partial)', '(none found in audited scopes)', '', '', '']
                : ['(all)', '(no known advisories)', '', '', ''],
        ]);
    } else {
        findings.sort((a, b) =>
            (SEVERITY_RANK[a.severity] ?? 9) - (SEVERITY_RANK[b.severity] ?? 9)
            || a.pkg.localeCompare(b.pkg)
        );

        renderTable(
            ['Scope', 'Package', 'Severity', 'Advisory', 'Summary'],
            findings.map(f => [f.scope, f.pkg, f.severity, f.id, truncate(f.title, 60)])
        );
    }

    if (unverified.length > 0) {
        console.log('\n  Could not be audited — treat as unknown, not clean:');
        unverified.forEach(u => console.log(`    ${u.scope}: ${u.reason}`));
    }

    return { hasAdvisories: findings.length > 0, unverified: unverified.length > 0 };
}

function truncate(text, max) {
    const s = String(text || '');
    return s.length > max ? s.slice(0, max - 1) + '…' : s;
}

console.log('Vendor / Dependency Status');
console.log('==========================');

const nestedProjects = discoverNestedComposerProjects();

if (nestedProjects.length > 0) {
    console.log('\nNested Composer projects: ' + nestedProjects.join(', '));
}

const vendorUpdates = checkVendors();
const devUpdates    = checkDevDependencies();
const phpUpdates    = checkPhpDependencies(nestedProjects);
const security      = checkSecurity(nestedProjects);

console.log('');

if (security.hasAdvisories) {
    console.log('Security advisories found — see the table above.');
    process.exit(2);
}

if (security.unverified) {
    console.log('Security status unverified — one or more scopes could not be audited.');
    process.exit(2);
}

if (vendorUpdates || devUpdates || phpUpdates) {
    console.log('Some packages have updates available.');
    process.exit(1);
}

console.log('All checked packages are up to date, with no known advisories.');
process.exit(0);

# CLAUDE.md

Context for AI-assisted work on `cwm/build-tools`. Read this first.

## What this project is

A Composer dev-dependency that consolidates build, release, and CI tooling
for CWM Joomla extensions (Proclaim, lib_cwmscripture, CWMScriptureLinks,
plg_task_cwmscripture). It is **not** end-user software; consumers are
extension authors running `composer setup` / `composer release` / etc.
during development.

Distribution: Composer only (`require-dev`). Never via Akeeba Release
System (ARS is for end-user extensions, not internal tooling).

## Architecture

### Directory layout

| Path | What lives here |
|---|---|
| `bin/` | Thin bash shims published as `vendor/bin/cwm-*`. Each one `exec`s the matching `scripts/*.php` or `scripts/*.sh`. |
| `scripts/` | The actual implementation. PHP for most things, bash for ARS / release pipeline / changelog generation, Python for sync-languages. |
| `src/` | PSR-4 PHP classes under `CWM\BuildTools\` — `Config\ProjectConfig`, `Build\ManifestReader`, `Dev\PropertiesReader`, `Dev\LinkResolver`, `Dev\Linker`, `Dev\ExtensionVerifier`, `Dev\JoomlaInstaller`, `Dev\InstallConfig`, `Release\ArsPublisher` (stub). |
| `templates/` | Files synced into consumer projects via `cwm-sync-configs` or referenced via path (rollup config, ESLint base, etc.). |
| `examples/` | Sample `cwm-build.config.json` per extension shape. |
| `tests/` | PHPUnit 11 unit tests (40 / 100 assertions). Real fixture XML files committed to `tests/fixtures/`. |

### The two-config split (load-bearing — don't conflate)

| File | Committed? | Purpose |
|---|---|---|
| `cwm-build.config.json` | yes | What the project IS — extension layout, manifest paths, ARS endpoint, GitHub repo, `vendors[]`, optional `dev:` block, optional `gitignore.outputPaths[]` / `gitignore.mediaPaths[]`. **No secrets.** Consumed by every cwm-* command. |
| `build.properties` | **NEVER** — gitignored | Where the developer's local Joomla installs live: paths, URLs, target versions, DB credentials, admin credentials. Per-developer. INI-section format (preferred) or Proclaim legacy flat format (`builder.joomla_paths=...`, also supported by `PropertiesReader::fromLegacyFlat()`). |

`PropertiesReader` parses both formats. Comment lines are stripped before
`parse_ini_string` because PHP treats `()[]?!` as reserved even in `#`/`;`
comments (issue #2.1). Absolute `builder.joomla_dir` values are detected
via `looksAbsolute()` and ignored with a stderr warning (issue #2.2 —
Proclaim uses the same key for a separate absolute CMS-source path).

### Managed-block sync strategy

`cwm-sync-configs` writes between explicit markers in `.gitignore`:

```
# === cwm-build-tools: managed (do not edit between markers) ===
.DS_Store
node_modules/
...
# === cwm-build-tools: end managed ===
```

Lines outside the markers are NEVER touched. Multiple block types
supported (e.g. `extension paths` block auto-derived from
`extension.name` + `manifests.extensions[]`). When adding a new managed
block elsewhere, follow the same `start/end marker` shape — see
`scripts/sync-configs.php::upsertBlock()`.

### Relative-only symlinks

`Linker::link()` resolves both ends with `realpath()`, then computes a
relative path so the dev tree stays portable across machines and CI.
Earlier toolchains (Proclaim's pre-`cwm` build scripts) baked absolute
paths in, breaking every machine except the one symlinks were created
on (cwmconnect PRs #88/#89). **Never write absolute symlinks.**

### Auto-derived link rules

`LinkResolver` walks `manifests.extensions[]` and emits the conventional
Joomla layout per type:
- `library` → `libraries/<name>` + `administrator/manifests/libraries/<name>.xml` + `media/lib_<name>` (when present)
- `plugin` → `plugins/<group>/<element>` (group from `<extension group="...">`, element from manifest filename)
- `module` → `modules/<name>` or `administrator/modules/<name>` (per `client="..."` attribute)
- `component` → `admin/`, `site/`, `media/` mirrored

Custom layouts opt out via `dev.deriveLinks: false` and list every link
explicitly. Explicit `dev.links[]` and `dev.internalLinks[]` always merge in
on top of auto-derive (deduped by target).

## Workflow conventions

### Release flow

1. Edit `CHANGELOG.md` — move `## [Unreleased]` content under a new
   `## [vX.Y.Z-alpha] - YYYY-MM-DD` heading.
2. `git commit -m "chore(release): cut vX.Y.Z-alpha"` on `main`.
3. `git tag -a vX.Y.Z-alpha -m "vX.Y.Z-alpha"`.
4. `git push origin main && git push origin vX.Y.Z-alpha`.
5. `gh release create vX.Y.Z-alpha --prerelease --title 'vX.Y.Z-alpha' --notes "..."`.

No `version` field in `composer.json` — Composer reads the git tag.

### Alpha-line versioning

While on `0.x-alpha`:
- Patch bump (`0.4.0-alpha` → `0.4.1-alpha`) — fixes + stub completion.
  Consumers pinned to `^0.4@alpha` pick it up automatically.
- Minor bump (`0.4.x-alpha` → `0.5.0-alpha`) — breaking schema or CLI
  changes. Consumers must update their constraint.

The README explicitly says "the schema and CLI surface can still shift
between minors" while on alpha. Use that latitude — but document the
break in CHANGELOG's `Changed (breaking)` section.

### Dist exclusions (do not ship dev-only files)

Two mechanisms in lockstep:
- `.gitattributes` `export-ignore` — controls what `git archive` produces
  (which is what GitHub builds the dist zip from when Composer downloads).
- `composer.json` `archive.exclude` — Composer's own filter, belt-and-
  suspenders.

When adding a new dev-only file or directory (tests, CI configs, IDE
configs, internal docs like this CLAUDE.md), add it to **both**. Verify
with:

```bash
git archive --worktree-attributes --format=tar HEAD | tar -t | grep '^<path>/'
# empty result = excluded
```

### PHPDoc style

Every function `cwm-build-tools` ships should have a docblock above it.
Lead with one sentence on what the function does and why; follow with
`@param`/`@return` only when types aren't obvious from the signature.
Use `list<...>` and shaped arrays (`array{key: type, ...}`) for nested
structures so static analyzers can verify call sites. The audit pass
(`10b52d8`) backfilled this on every function added in the v0.4.1-alpha
work; new functions should match.

### `--help` text shape

Every dev-env command's `--help` follows this shape (consistent so users
can scan quickly):

```
<name> — one-line summary.

WHAT IT DOES
  ...

PREREQUISITES
  ...

USAGE
  composer <name>           # plain
  composer <name> -- <flag> # with flag

OPTIONS
  -v, --verbose    ...

RELATED
  composer <other>    # one-line description
```

Add `EXIT CODE` when the command's exit status matters for CI gating
(e.g. `cwm-verify`, `cwm-link-check`).

### `cwm-init` philosophy

`cwm-init` is the "make this consumer ready" wizard. It detects what it
can and pre-fills every prompt with the detected default. Three rules:

1. **Idempotent.** Re-running on an already-configured project must not
   duplicate entries (require-dev, repositories[], composer scripts).
   `updateComposerJson()` checks-then-adds.
2. **Conflict-aware.** Existing composer scripts that overlap with cwm-*
   names are NEVER overwritten — they're reported as `scripts-skipped`
   so the consumer migrates by hand. Same posture for
   `eslint.config.mjs` and `package.json` `build:js` / `build:css`.
3. **Y/N before destructive actions.** Stale-artifact removal defaults
   to N. Auto-edits to `composer.json` show the proposed diff before
   applying.

When adding new init-time behaviors, preserve all three.

## Threat model + security guardrails

This is developer tooling. All inputs are author-controlled or
developer-supplied:

| Input | Trust |
|---|---|
| `cwm-build.config.json` | trusted (committed by project author) |
| `build.properties` | trusted (per-developer, gitignored) |
| CLI args | trusted (developer types them) |
| Consumer `composer.json` / `package.json` | trusted (author's own files) |
| Manifest XML files | trusted (project-controlled) |
| `.git/config` | trusted (developer's clone) |
| GitHub releases API responses | semi-trusted (TLS) |
| ARS API responses | semi-trusted (TLS) |
| 1Password CLI output | trusted (developer's vault) |

### Specific guardrails — keep these

- **`proc_open` always uses array-form args** when invoking subscripts
  (e.g. `init.php` calling `sync-configs.php`). Array form does NOT
  spawn a shell — no metachar interpretation. **Never** convert to a
  string-form `proc_open` or `shell_exec` even with `escapeshellarg`.
- **`shell_exec` is forbidden in new code.** When you need git data,
  read `.git/config` and `.git/refs/remotes/origin/` directly instead
  of subshelling out. (`detectGithubOrigin()` in `init.php` is the
  reference implementation.)
- **`simplexml_load_file` is safe on PHP 8.3** (libxml ≥ 2.9 disables
  external entities by default). **Never** pass `LIBXML_NOENT` or
  `LIBXML_DTDLOAD` — those re-enable XXE.
- **`removeDirectory()` checks `is_link()`** before recursing into a
  directory. Symlinked dirs get unlinked, not followed. Preserve this
  guard in any deletion code path; otherwise a malicious symlink could
  escape the project tree.
- **JSON parsing via `json_decode($s, true)`** — always use
  associative-array mode. PHP's `json_decode` is a pure parser (no
  object instantiation), so no deserialization-gadget risk.
- **Heredocs in shell scripts must NOT re-evaluate output as commands.**
  The new `ars-create-stream.sh` 404 workaround uses `cat <<EOF` to
  print, never `eval` or `bash -c`. Keep it that way.
- **Cache files live under `<project_root>/build/.cwm-cache/`,** never
  inside `vendor/cwm/build-tools/scripts/`. The latter gets wiped on
  every `composer install`; the former is gitignored via the managed
  block. (Issue #4.8.)

### What's explicitly NOT a concern

- Command injection in shell scripts: inputs are config files (trusted)
  + developer CLI args (trusted). Don't spend time defending against
  attacker-controlled `ars.endpoint` or `--name`.
- XXE in manifest XML: project-controlled.
- Path traversal in `cwm-build.config.json` paths: author-controlled.

## Testing

```bash
composer test        # phpunit
vendor/bin/phpunit   # equivalent
```

`phpunit.xml` runs in strict mode (`failOnWarning`,
`failOnDeprecation`, `failOnNotice`, `beStrictAboutOutputDuringTests`)
with random execution order. Tests use real fixture XML files committed
to `tests/fixtures/manifests/`; don't generate fixtures on the fly.

When fixing a bug found in the wild, **add a regression test** —
that's how the parse_ini comment-strip fix (#2.1) and the absolute
`joomla_dir` rejection (#2.2) are pinned.

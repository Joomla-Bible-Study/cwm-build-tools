# Changelog

All notable changes to `cwm/build-tools` are documented here.

The format is loosely based on [Keep a Changelog](https://keepachangelog.com/),
and this project adheres to [Semantic Versioning](https://semver.org/).

## [Unreleased]

### Added

- `cwm-build` binary + `scripts/build.php` + `src/Build/PackageBuilder` +
  `src/Build/BuildConfig` — generic Joomla extension zip builder driven by a
  `build:` block in `cwm-build.config.json`. Phase 1 covers the
  lib_cwmscripture build shape: read manifest version, optionally gate on
  presence of `*.min.{js,css}` siblings (`preBuild.mode: "ensure-minified"`),
  walk one or more source directories with a per-source zip-path prefix, apply
  loose `str_contains` excludes. CLI flags `-v`/`--verbose`, `--version <ver>`
  override, `--help`. New schema fields: `build.outputDir`, `outputName`
  (supports `{version}`), `manifest`, `scriptFile`, `sources[]`, `excludes`,
  `preBuild`. Coexists with the existing `build.command` / `build.outputGlob`
  fields used by `cwm-release` — consumers migrate by setting
  `build.command: "cwm-build"`. Strict-mode filtering, vendor pruning, auto-
  run pre-build, and the interactive 3-way version prompt land in subsequent
  PRs (still part of #5). 8 new unit tests / 39 assertions covering the
  end-to-end build, version override, gate pass/fail, and config validation.

- `templates/build-assets.js` — generic version of Proclaim's
  `build/build-assets.js` that copies images, mirrors a manually-managed
  vendor source tree, and cherry-picks files/dirs out of npm packages in
  `node_modules/`. Driven by a new `assets:` block in
  `cwm-build.config.json`; supports CSS minification (`minifyCss: true`),
  pre-clean of a destination subdir (`cleanDest`), and filename glob
  filters on directory copies (`match: "*.umd.js"`). Schema paths are
  source-tree paths so adopting this template doesn't require manifest
  changes — the `<media folder=…>` element keeps controlling install-time
  destination as before. Wire from `package.json` `build:assets`.
  Reported in #7.

- `cwm-joomla-cms-deps` binary + `scripts/joomla-cms-deps.php` — generic
  version of the Proclaim-side `build/joomla-cms-deps.php` that clones
  `joomla-cms` source for unit tests to require directly. New optional
  config fields `testing.joomlaCmsVersion` (defaults to `5.4.3`) and
  `testing.joomlaCmsPath` (defaults to `<cwd-parent>/joomla-cms`); CLI
  flags `-v`/`--version` and `-p`/`--path` override either. Backward-
  compatible with the legacy `tests.joomla_cms_path` key in
  `build.properties` so existing Proclaim setups keep working before
  consumers migrate. Wire from `composer.json` `post-install-cmd`.
  Reported in #7.

- `cwm-article` binary + `scripts/cwm-article.sh` — generic version of the
  Proclaim-side `build/cwm-article.sh` that posts a "<Extension> X.Y.Z
  Released" announcement to christianwebministries.org, features it, and
  un-features the previous featured article. Reads `extension.name`,
  `extension.displayName` (new optional field — falls back to stripping
  `pkg_/com_/lib_/mod_/plg_` from `extension.name` and uppercasing the first
  char), `manifests.package` (version-detection source when no VERSION arg),
  and `github.owner`/`github.repo` from `cwm-build.config.json`. CWM-team
  body copy and `christianwebministries.org` site URL stay hard-coded — this
  binary is for CWM-family releases. Wire into release step 8 by setting
  `announcement.command: "cwm-article"` in `cwm-build.config.json`. Reported
  in #7.
- `cwm-release`: pre-flight steps before the version bump now (a) `git fetch
  origin --prune --tags`, (b) `git pull --ff-only origin <release-branch>` and
  abort with guidance if local has diverged, (c) `git submodule update --init
  --recursive` to match working trees to recorded pointers, and (d) warn when
  a submodule pointer isn't at a tagged release commit (non-blocking — shipping
  an untagged snapshot is sometimes intentional, but should be deliberate).
  Catches the "I forgot to pull" and "submodule working tree on a different
  commit than recorded" failure modes that surfaced during the Proclaim 10.3.2
  release. Reported in #6.

## [0.4.1-alpha] - 2026-05-08

Fixes and scaffolder completion driven by the first end-to-end consumer
adoption pass (Proclaim — see Proclaim PR #1218 / cwm-build-tools issues
#2 and #4). The CLI surface is unchanged from `v0.4.0-alpha`; consumers
pinned to `^0.4@alpha` pick this up automatically.

### Fixed

- `PropertiesReader::installs()` no longer fails on `build.properties` files whose
  comments contain PHP-INI-reserved characters like `()`, `[]`, `!`, or `?`.
  PHP's `parse_ini_*` raises a syntax error on those even when they appear inside
  `#`/`;` comment lines, so consumers shipping stock comments such as
  `# Full path(s) to your install` were tripping `cwm-link-check`, `cwm-verify`,
  and any other command that reaches `installs()`. Comment lines are now stripped
  before parsing. Reported in #2.
- `PropertiesReader::fromLegacyFlat()` now detects an absolute `builder.joomla_dir`
  (POSIX `/foo`, Windows `C:\foo`, UNC `\\server\share`) and ignores it with a
  stderr warning, instead of concatenating it onto each install path and producing
  nonsense like `/Sites/j5-dev/Volumes/.../GitHub/joomla-cms`. Proclaim's existing
  `build.properties` uses the same key as a separate absolute CMS-source path; the
  collision broke `cwm-link-check` / `cwm-verify` until the value was unset.
  Reported in #2.2.
- `release.sh`: `git describe --tags` now runs against `HEAD` (the bump commit we
  just pushed) instead of `HEAD~1`, so the previous-tag lookup resolves correctly
  even on the first release of a session. The step-3 staging now does
  `git add -u` (tracked changes only) before the catch-all `git add -A`, with a
  comment explaining the safety. Pre-checks already gate on a clean tree.
- `bump.php`: validates the package manifest exists before rewriting it (matches
  the existing sub-extension behavior). Drops the unused `PROJECT_ROOT` constant.
- `cwm-ars-create-stream` 404 fallback now echoes the values the user passed
  in (`--name`, `--element`, `--type`, `--category-id`) as a copy-paste-friendly
  form template, plus a deep link straight to the Update Streams admin view at
  the configured `ars.endpoint`. Saves having to scroll back through shell history.
- `ExtensionVerifier::verify()` default `$reconcile` flipped from `true` to
  `false`. The `verify.php` CLI passes the right flag either way; this just makes
  direct callers safer (no automatic INSERTs unless explicitly requested).
- `setup.php`: refuses to run without `cwm-build.config.json` (with a hint
  pointing at `cwm-init`); refuses to run when stdin is not a TTY (CI-safe);
  lowercases install ids so they match what `LinkResolver` / `ExtensionVerifier`
  expect; warns when the configured Joomla path doesn't exist; prints a
  next-steps banner after writing `build.properties`.
- `clean.php`: explicit notice when `cwm-build.config.json` is missing or invalid,
  instead of silently scanning nothing.
- `scripts/sync-languages.py` cache files (`translations.json`, `sources.json`)
  now live under `<project_root>/build/.cwm-cache/` instead of inside the install
  directory at `libraries/vendor/cwm/build-tools/scripts/`. The previous location
  was wiped by every `composer install`, silently destroying the translation cache
  (~1.3 MB in the Proclaim case) and re-billing the API on the next sync. Reported
  in #4.8.

### Added — `cwm-init` is functional

`bin/cwm-init` previously dispatched to a `scripts/init.php` that didn't
exist. It's now a working interactive scaffolder.

- Walks the project tree to detect: package manifest (`build/pkg_*.xml`,
  `pkg_*.xml`), top-level extension type/name (component, library, package
  wrapper), sub-extension manifests (libraries, plugins, modules, including
  symlinked ones — deduped by `realpath()`), GitHub origin from `.git/config`
  (no subprocess), preferred release branch (prefers `main`/`master`,
  falls back to `origin/HEAD`), development branch (prefers
  `development`/`develop`), build command + output directory paired together
  (Proclaim's `build/proclaim_build.php` → `build/packages/`, CWMScripture's
  `build/build-package.php` → `build/dist/`), changelog file, versions.json,
  and announcement command. Every prompt is pre-filled with what was found;
  press Enter to accept or type an override.
- Warns at runtime when `releaseBranch` and `developmentBranch` resolve to
  the same value and offers a re-prompt (release.sh step 7 churns when
  they're identical).
- Manages consumer `composer.json`: adds `cwm/build-tools` to `require-dev`
  with `^0.4@alpha`, adds the VCS `repositories[]` entry for the GitHub repo,
  and wires 15 standard `vendor/bin/cwm-*` composer scripts. Existing entries
  are preserved — the conflict report names them so the consumer can finish
  the migration by hand. Idempotent: re-running won't duplicate any entry.
- Seeds `vendors[]` from `build/check-vendor-versions.js` /
  `build/update-vendors.js` if they exist, falling back to `package.json`
  `dependencies`. Scoped packages (`@vendor/name`) get the leaf as the
  default display label.
- Detects stale runtime artifacts left behind by tools cwm-build-tools now
  provides (`build/__pycache__/`, the old translation/source caches, plus
  10 migrated `build/` scripts) and offers — but never automatically does
  — to remove them. Default is N because deleting tracked files needs
  consumer review.
- Emits a `package.json` migration suggestion block. SOURCE_DIR / OUTPUT_DIR
  are too project-specific to auto-rewrite — the safe path is to surface
  the recommended shape so the consumer can copy-paste while reviewing the
  paths against their own source layout. Path arguments in legacy commands
  are preserved verbatim into the `SOURCE_DIR` / `OUTPUT_DIR` env vars
  where extractable; placeholders are emitted otherwise.
- `--non-interactive` accepts all detected defaults (CI-friendly).
  `--force` overwrites an existing `cwm-build.config.json`.
- After writing the config, runs `cwm-sync-configs` automatically so the
  managed gitignore block lands on the same run.

### Added — `cwm-sync-configs` handlers

- `eslint.config.mjs` handler: writes a starter wrapper that imports
  `templates/eslint.config.base.mjs` from the consumer's vendor-dir
  (resolved from `composer.json` `config.vendor-dir`). When a config
  already exists, it leaves it alone — but prints the exact `import` line
  to add when the existing file doesn't yet extend the shared base.
- `gitignore.outputPaths[]` and `gitignore.mediaPaths[]` config schema
  fields. When present, these REPLACE the auto-derived defaults, letting a
  project with a non-standard layout (Proclaim's `/media/com_proclaim/` +
  `/media/lib_cwmscripture/` mix instead of a single `/media/<stripped>/`)
  not fight the generator. `cwm-init` populates them by walking
  `<project>/media/<x>/(js|css)/` to find dirs that actually receive built
  JS/CSS.
- The auto-derived defaults are now scoped to extension types where the
  convention holds: `library` and `component` map cleanly to
  `/media/<stripped>/`; `package`, `plugin`, and `module` no longer get
  bogus media patterns. Output dir comes from `build.outputGlob`'s
  dirname so `build/packages/` (Proclaim) and `build/dist/` (CWMScripture)
  both render correctly without config.
- New gitignore entry: `/build/.cwm-cache/` (where the relocated
  sync-languages cache now lives).

### Added — testing scaffold

- `phpunit.xml` + `tests/Dev/` with 40 unit tests / 100 assertions:
  `PropertiesReaderTest` (12 tests, including regressions for the
  comment-strip and absolute-`joomla_dir` fixes above), `LinkerTest`
  (13 tests for `relativePath()` edge cases plus check / link / unlink),
  `LinkResolverTest` (9 tests for auto-derivation per extension type and
  explicit dev-link interpolation), `ExtensionVerifierTest` (6 tests for
  `expectedExtensions()` across component, library, plugin manifests).
- Strict-mode `phpunit.xml` (`failOnDeprecation`, `failOnNotice`,
  `failOnWarning`, `beStrictAboutOutputDuringTests`); random execution
  order to surface order-dependent flakes.
- `composer test` script.
- `.gitattributes` `export-ignore` + `composer.json` `archive.exclude`
  for `tests/`, `phpunit.xml`, `.github/`, `.idea/`, `.gitignore`, etc.
  — so the dist zip Composer downloads into every consuming project's
  `vendor/cwm/build-tools/` doesn't ship dev-only files.

### Added — sharper `--help` everywhere

`setup`, `link`, `link-check`, `clean`, `verify`, `joomla-install`, and
`joomla-latest` now follow a consistent `--help` shape: WHAT IT DOES,
PREREQUISITES, USAGE (with concrete examples), OPTIONS, RELATED. `verify`
also documents EXIT CODE for CI gating.

### Documentation

- README roadmap reflects Phase 1 actual state (release pipeline
  mostly done; dev-env commands shipped in `0.4.0-alpha`) and surfaces a
  Testing TODO that this release closes.

## [0.4.0-alpha] - 2026-05-07

### Added — dev-environment commands lifted from Proclaim

- `bin/cwm-setup` + `scripts/setup.php` — interactive wizard that captures one or
  more Joomla install paths, URLs, target versions, DB creds, and admin creds.
  Writes a per-developer `build.properties` (INI sections) in the consuming repo.
- `bin/cwm-link` + `scripts/link.php` — symlinks the project tree into every
  configured Joomla install. Auto-derives the standard set of links from
  `extension.*` and `manifests.extensions[]` (component admin/site/media,
  library `lib_X` → `libraries/X` + manifest mirror, `plugins/<group>/<element>`,
  `modules/[admin]/<name>`); explicit `dev.links[]` and `dev.internalLinks[]`
  entries are merged in. **All symlinks are created with relative paths** so
  the dev tree is portable across machines and CI (cwmconnect PR #88/#89 hit
  the absolute-path footgun directly).
- `bin/cwm-link-check` — verifies every expected symlink without recreating it.
  Exits non-zero on drift so CI can gate on a known-good state.
- `bin/cwm-clean` — removes every dev symlink. Real files / directories at the
  link paths are left alone — only items that are currently symlinks are touched.
- `bin/cwm-verify` — confirms each install has every project sub-extension
  registered in `#__extensions`. Reads each manifest XML to discover
  type/element/folder/namespace; uses PDO so it does not need to bootstrap
  Joomla. Pass `--fix` to reconcile drift (UPDATE state, INSERT missing
  libraries/plugins, run library install SQL). Components are flagged but
  never auto-inserted — install via the Extension Manager so the rest of the
  install lifecycle runs.
- `bin/cwm-joomla-install` — downloads the Joomla full-package release into
  every configured install path. Per-install version comes from
  `build.properties`; pass a positional argument to override globally.
  `--force` wipes the directory first.
- `bin/cwm-joomla-latest` — prints the latest stable tag from the
  `joomla/joomla-cms` releases feed.
- `src/Dev/` — `InstallConfig`, `PropertiesReader`, `LinkResolver`, `Linker`,
  `ExtensionVerifier`, `JoomlaInstaller`. The bash/PHP scripts are thin
  wrappers over these classes.
- `templates/build.properties.tmpl` — copied into a consuming repo as
  `build.properties.tmpl` (or `build.dist.properties`) and committed; each
  developer copies it to `build.properties` (gitignored) and edits.
- `templates/cwm-build.config.json.tmpl` — extended with a `dev:` block
  documenting `internalLinks[]`, `links[]`, and the `deriveLinks: false`
  escape hatch for projects with non-standard layouts.

#### Configuration split

Two files now drive the dev surface:

- `cwm-build.config.json` (committed) — what the project IS. Adds an optional
  `dev:` block describing extra symlinks and repo-internal mirror links.
  No secrets.
- `build.properties` (gitignored, INI sections) — where the developer's local
  Joomla installs live. DB and admin passwords stay out of source control.

The `PropertiesReader` also accepts Proclaim's legacy flat
`builder.joomla_paths=...` / `builder.j5dev.url=...` layout, so projects
migrating from Proclaim can drop the new toolchain in without rewriting their
`build.properties` first.

### Changed (breaking)

- Removed `ars.changelogUrl` from the config schema. Modern Akeeba ARS (verified against v7.4.x source) has no `changelogurl` field on the `#__ars_updatestreams` table — Joomla's changelog mechanism reads `<changelogurl>` from the **installed extension manifest** instead. The PATCH call in `ars-publish.sh` that previously tried to set this on the update stream was a no-op against modern ARS and has been removed. The URL now lives at `changelog.url` (was `ars.changelogUrl`) and is meant to be referenced by the manifest XML, not pushed to ARS.
- Migration: in your `cwm-build.config.json`, move the value from `ars.changelogUrl` to `changelog.url`. Delete the old key. Add a `<changelogurl>...</changelogurl>` element to your top-level extension manifests (next to `<updateservers>`) so Joomla can fetch the changelog when notifying users of updates.

### Added

- `templates/vendor-check.js` and `templates/vendor-update.js` — lifted from Proclaim's `build/check-vendor-versions.js` / `build/update-vendors.js`. Vendor list (previously hardcoded as `chart.js`, `@fancyapps/ui`, `intl-tel-input`, `sortablejs`) is now read from `cwm-build.config.json` under `vendors[]`, so any project that bundles npm libraries can adopt them. Uses `execFileSync` (no shell, no injection surface) for vendor names interpolated into commands.
- `templates/versions.json.tmpl` — template for the dev-version-state file Proclaim uses (current / next.patch / next.minor / next.major / active_development). Already consumed by `release.sh` step 7.
- `bin/cwm-ars-publish` + `scripts/ars-publish.sh` — full ARS publish (Akeeba Release System) implementation, lifted and parameterized from Proclaim's `build/ars-release.sh`. Replaces the previous PHP stub. All site/category/stream/environment/auth values now come from `cwm-build.config.json`. Auth via 1Password CLI with `ARS_API_TOKEN` env override.
- `bin/cwm-changelog` + `scripts/generate-changelog-entry.sh` — Joomla changelog XML generator, lifted from Proclaim's `build/generate-changelog-entry.sh`. Parameterized via `changelog.file`, `changelog.element`, `changelog.type` (defaulting to extension fields when not set).
- `release.sh` step 5 (changelog) and step 6 (ARS publish) now invoke the shared scripts directly instead of project-specific shell-outs.
- `bin/cwm-sync-languages` + `scripts/sync-languages.py` — shared Joomla language sync / Google Translate tool. Project root now defaults to CWD (or pass `--project-root <path>` to override). Lifted from byte-identical copies in `lib_cwmscripture` and `Proclaim`.
- `templates/eslint.config.base.mjs` — base ESLint flat-config for CWM Joomla extensions. Consuming projects extend it via `import baseConfig from '.../vendor/cwm/build-tools/templates/eslint.config.base.mjs'` and add their own globals/files.

### Removed

- `scripts/ars-publish.php` — was a stub. Replaced by `scripts/ars-publish.sh` with a real implementation.

### Phase 1 scaffold (initial)

- Initial repo skeleton with `bin/`, `scripts/`, `src/`, `templates/`, `examples/`.
- `bin/cwm-release`, `cwm-bump`, `cwm-package`, `cwm-sync-configs`, `cwm-sync-languages`, `cwm-init`.
- `scripts/release.sh` — generic 8-step release pipeline (bump, build, push, GH release, changelog, ARS, versions.json, announcement).
- `scripts/bump.php` — multi-manifest version bumper driven by `cwm-build.config.json`.
- `scripts/sync-configs.php` — managed-block syncer (currently `.gitignore`).
- `scripts/ars-publish.php` — stub for ARS API upload (manual upload until Phase 1 completes).
- `templates/gitignore-managed.txt` — universal junk block synced into projects.
- `templates/cwm-build.config.json.tmpl` — per-project config skeleton.
- `examples/package/`, `examples/library/` — reference project configs.
- `.github/workflows/joomla-package-ci.yml` — reusable workflow for Joomla extension CI.
- `src/Config/ProjectConfig.php`, `src/Build/ManifestReader.php`, `src/Release/ArsPublisher.php` — PHP class skeletons for Phase 2+.

### Open before v1.0.0

- ARS API upload implementation in `src/Release/ArsPublisher.php` (currently a stub).
- `scripts/init.php` (the actual interactive scaffolder behind `bin/cwm-init`).
- `.editorconfig` and `.php-cs-fixer.dist.php` sync handlers in `sync-configs.php`.
- Templates: `.editorconfig`, `.php-cs-fixer.base.php`, `phpunit.xml.tmpl`.
- Reusable `joomla-library-ci.yml` workflow.
- Wire CWMScriptureLinks as the first consumer to validate the design end-to-end.

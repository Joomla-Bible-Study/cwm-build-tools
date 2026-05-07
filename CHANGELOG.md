# Changelog

All notable changes to `cwm/build-tools` are documented here.

The format is loosely based on [Keep a Changelog](https://keepachangelog.com/),
and this project adheres to [Semantic Versioning](https://semver.org/).

## [Unreleased]

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

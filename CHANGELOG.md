# Changelog

All notable changes to `cwm/build-tools` are documented here.

The format is loosely based on [Keep a Changelog](https://keepachangelog.com/),
and this project adheres to [Semantic Versioning](https://semver.org/).

## [Unreleased]

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

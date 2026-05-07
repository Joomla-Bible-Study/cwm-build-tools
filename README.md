# cwm-build-tools

Shared build, release, and CI tooling for CWM Joomla extensions.

## Why this exists

Across `Proclaim`, `lib_cwmscripture`, `CWMScriptureLinks`, and `plg_task_cwmscripture` we kept duplicating the same machinery — version-bump scripts, package zip builders, release pipelines, ARS publish steps, CI workflows, lint/format configs. When one project fixed a bug or added a step, the others drifted. This repo is the single source of truth.

## What it provides

| Surface | What | Where |
|---|---|---|
| **CLI tools** | `cwm-release`, `cwm-bump`, `cwm-package`, `cwm-sync-configs`, `cwm-sync-languages`, `cwm-ars-publish`, `cwm-changelog`, `cwm-init`, `cwm-setup`, `cwm-link`, `cwm-link-check`, `cwm-clean`, `cwm-verify`, `cwm-joomla-install`, `cwm-joomla-latest` | `bin/` |
| **Scripts** | Generic 8-step release pipeline, multi-manifest version bumper, config syncer | `scripts/` |
| **PHP library** | `ProjectConfig`, `ManifestReader`, `PackageBuilder`, `ArsPublisher`, `Bumper` | `src/` (PSR-4 `CWM\BuildTools\`) |
| **Reusable GH Actions** | `joomla-package-ci.yml`, `joomla-library-ci.yml` (called via `workflow_call`) | `.github/workflows/` |
| **Synced config templates** | `.gitignore` block, `.editorconfig`, `.php-cs-fixer.base.php`, `phpunit.xml` boilerplate, `eslint.config.base.mjs`, `rollup.config.js`, `build-css.js`, `vendor-check.js`, `vendor-update.js`, `versions.json.tmpl` | `templates/` |

## Distribution

This is a developer-tooling library. It ships as a **Composer dev dependency** (or, optionally, a git submodule for projects without composer). It is **not** distributed via Akeeba Release System — ARS is for end-user Joomla extensions, not internal build tooling.

## How projects consume it

Add as a Composer dev dependency:

```json
{
  "require-dev": { "cwm/build-tools": "^1.0" },
  "scripts": {
    "release":         "vendor/bin/cwm-release",
    "bump-version":    "vendor/bin/cwm-bump",
    "package":         "vendor/bin/cwm-package",
    "sync-configs":    "vendor/bin/cwm-sync-configs",

    "setup":           "vendor/bin/cwm-setup",
    "link":            "vendor/bin/cwm-link",
    "link-check":      "vendor/bin/cwm-link-check",
    "clean":           "vendor/bin/cwm-clean",
    "verify":          "vendor/bin/cwm-verify",
    "joomla-install":  "vendor/bin/cwm-joomla-install",
    "joomla-latest":   "vendor/bin/cwm-joomla-latest"
  }
}
```

**Why `bump-version` and not `bump`:** Composer 2.4+ ships a built-in
`composer bump` command (which bumps composer.json constraints to
match installed versions) that takes priority over user-defined
scripts of the same name. Using `bump-version` (or any other distinct
alias) avoids the silent override.

Drop a `cwm-build.config.json` at the project root describing the extension layout (see `examples/`). Then:

```bash
composer release -- 1.2.0      # full pipeline: bump → build → tag → GH release → ARS publish
composer bump-version -- 1.2.0 # version bump only (NOT 'bump' — that's a built-in Composer command)
composer package               # build zip(s) only
composer sync-configs          # refresh managed config blocks
```

Reusable CI:

```yaml
# .github/workflows/ci.yml in your project
jobs:
  ci:
    uses: Joomla-Bible-Study/cwm-build-tools/.github/workflows/joomla-package-ci.yml@v1
    with:
      php-version: '8.3'
      node-version: '24'
```

## Design principles

### Code lives here, config lives in projects

- **Code** (release pipeline logic, ARS publish, builder, bumper) is shipped as `src/` PHP and `scripts/` bash. Bug fixes ship via `composer update`. Never duplicated.
- **Config** (which manifests to bump, which ARS endpoint, GitHub repo info) lives in each project's `cwm-build.config.json`. Single source of truth per project.
- **Templates** (gitignore, editorconfig, etc.) are pulled into projects via `cwm-sync-configs` using a managed-block strategy that preserves project-specific entries.

### Managed-block sync, not full-file overwrite

For files where projects have legitimate local additions (most notably `.gitignore`), `cwm-sync-configs` writes between explicit markers:

```
# Project-specific entries (sync ignores these)
/local-test-fixtures/

# === cwm-build-tools: managed (do not edit between markers) ===
.DS_Store
node_modules/
vendor/
.php-cs-fixer.cache
# === cwm-build-tools: end managed ===
```

Lines outside the markers are never touched. Multiple block types are supported (e.g. `extension paths`, auto-generated from the project's extension name).

### Inheritance for tooling configs

For `.php-cs-fixer.dist.php` and similar, the project's local file is a thin wrapper that requires the upstream base:

```php
// .php-cs-fixer.dist.php
return require __DIR__ . '/vendor/cwm/build-tools/templates/.php-cs-fixer.base.php';
```

For ESLint, the project's `eslint.config.mjs` imports and extends the base:

```js
// eslint.config.mjs
import baseConfig from './vendor/cwm/build-tools/templates/eslint.config.base.mjs';

export default [
    ...baseConfig,
    {
        files: ['**/*.js', '**/*.mjs', '**/*.es6.js'],
        languageOptions: {
            globals: {
                MyExtension: 'readonly',  // project-specific globals
            },
        },
    },
];
```

Project-specific rule overrides go in the wrapper, not by forking the base.

### Independent versioning

`cwm-build-tools` itself uses semver. Projects pin to a major (`^1.0`). Breaking changes get a major bump. Bug fixes and new pipeline steps land as patches/minors and projects pick them up via `composer update`.

## Local dev environment

`cwm-setup`, `cwm-link`, `cwm-link-check`, `cwm-clean`, `cwm-verify`,
`cwm-joomla-install`, and `cwm-joomla-latest` form the **dev-environment**
surface — separate from the release pipeline. They let a contributor point
the repo at one or more local Joomla installs (J5, J6, J7, ...), keep the
extension symlinked into each one, verify the extensions table is in sync,
and bootstrap fresh Joomla checkouts.

Two files drive this surface:

| File | Committed? | Purpose |
|---|---|---|
| `cwm-build.config.json` | yes | What the project IS — extension layout, manifest paths, optional `dev:` block (link rules, repo-internal mirror links). No secrets. |
| `build.properties` | **no — gitignore it** | Where the developer's local Joomla installs live: paths, URLs, target versions, DB credentials, admin credentials. Per-developer. |

A new contributor's first run looks like:

```bash
cp vendor/cwm/build-tools/templates/build.properties.tmpl build.properties
composer setup            # interactive wizard — fills in real values
composer joomla-install   # optional: download Joomla into each path
composer link             # symlink the project into each install
composer verify            # confirm extensions registered, fix drift
```

Day-to-day:

```bash
composer link-check        # are my dev symlinks still healthy?
composer clean             # remove every dev symlink (clean install test)
composer joomla-latest     # what's the newest Joomla?
```

Symlinks are derived automatically from the project's
`manifests.extensions[]` plus the top-level `extension` block — components
get `admin/` + `site/` + `media/` mirrored, libraries get
`libraries/<name>` + the manifest stub under
`administrator/manifests/libraries/`, plugins land at
`plugins/<group>/<element>`, modules at `modules/[admin]/<name>`. Anything
that doesn't fit those rules (cross-repo libs, language file overrides,
submodules) goes in `dev.links[]` explicitly. Fully custom layouts can set
`dev.deriveLinks: false` and list every link by hand.

**All symlinks are created with relative paths** so the dev tree remains
portable across machines and CI. (Earlier toolchains baked absolute paths
in, which broke every machine except the one the symlinks were originally
created on.)

## Per-project config: `cwm-build.config.json`

```json
{
  "extension": {
    "type": "package",
    "name": "pkg_cwmscripture"
  },
  "manifests": {
    "package": "build/pkg_cwmscripture.xml",
    "extensions": [
      { "type": "library", "path": "libraries/lib_cwmscripture/cwmscripture.xml" },
      { "type": "plugin",  "path": "scripturelinks.xml" },
      { "type": "plugin",  "path": "plg_task_cwmscripture/cwmscripture.xml" }
    ]
  },
  "build": {
    "command": "php build/build-package.php",
    "outputGlob": "build/dist/pkg_cwmscripture-*.zip"
  },
  "ars": {
    "endpoint": "https://www.christianwebministries.org/index.php?option=com_ars",
    "category": "Scripture Library"
  },
  "github": {
    "owner": "Joomla-Bible-Study",
    "repo": "CWMScriptureLinks"
  },
  "dev": {
    "deriveLinks": true,
    "internalLinks": [
      { "source": "yourextension.xml",        "link": "admin/yourextension.xml" },
      { "source": "yourextension.script.php", "link": "admin/yourextension.script.php" }
    ],
    "links": [
      {
        "source": "language/admin/en-GB/en-GB.com_yourextension.ini",
        "target": "{joomlaPath}/administrator/language/en-GB/en-GB.com_yourextension.ini"
      }
    ]
  }
}
```

`dev.*` is consumed by the local-dev commands only — release-pipeline
commands ignore it. `{joomlaPath}` in `dev.links[].target` is substituted at
runtime with each install's path from `build.properties`.

See `examples/` for full per-extension-type setups (library, content-plugin, package).

## Roadmap

### Phase 1 — Release pipeline (in progress)
- [x] Repo skeleton
- [ ] `scripts/release.sh` (parameterized 8-step pipeline)
- [ ] `scripts/bump.php` (config-driven multi-manifest bumper)
- [ ] `src/Release/ArsPublisher.php`
- [ ] Wire CWMScriptureLinks as first consumer

### Phase 2 — Reusable CI workflow
- [ ] `joomla-package-ci.yml` (workflow_call)
- [ ] `joomla-library-ci.yml`
- [ ] Migrate one project's `ci.yml` to use it

### Phase 3 — Config sync
- [ ] `cwm-sync-configs` with managed-block strategy
- [ ] Templates: `.gitignore`, `.editorconfig`, `.php-cs-fixer.base.php`, `phpunit.xml`
- [ ] `cwm-init` to scaffold new projects

### Phase 4 — Generic builder
- [ ] `src/Build/PackageBuilder.php` capable of replacing project-specific `build-package.php`s
- [ ] Migrate at least two projects onto it

## License

GPL-2.0-or-later. Matches the Joomla ecosystem and the consuming extensions.

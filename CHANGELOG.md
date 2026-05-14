# Changelog

All notable changes to `cwm/build-tools` are documented here.

The format is loosely based on [Keep a Changelog](https://keepachangelog.com/),
and this project adheres to [Semantic Versioning](https://semver.org/).

## [Unreleased]

### Added

- New opt-in `versionTracking` block in `cwm-build.config.json` keeps
  `build/versions.json` and `package.json` in lockstep with manifest bumps.
  Closes #23.
  - `cwm-bump <version>` now writes `active_development.version` and
    `package.json:version` (when configured). Skipped when `--component`
    narrows the bump to a single extension type.
  - `cwm-release <version>` (step 7) writes `current.version`, recomputes
    `next.patch` / `next.minor` / `next.major`, and refreshes `_updated`.
    `active_development` is left alone — it stays pointing at whatever the
    last `cwm-bump` set, so developers explicitly advance it when starting
    minor or major work.
  - Schema:
    ```json
    {
      "versionTracking": {
        "versionsJson": "build/versions.json",
        "packageJson":  "package.json"
      }
    }
    ```
  - Either field is optional; an absent block is a no-op (no behaviour
    change for projects that don't opt in).
- New `CWM\BuildTools\Release\VersionTracker` class plus
  `scripts/version-tracker.php` CLI entry. The CLI is what `release.sh`
  step 7 shells out to (replacing the prior inline `python3 -c` heredoc).

### Changed

- `release.sh` step 7 no longer requires `github.developmentBranch`. When
  no dev branch is configured, the versions.json update happens inline on
  the release branch. The dev-branch checkout/commit/push dance still
  runs when configured, for projects with that workflow.

### Deprecated

- Top-level `versionsFile` key in `cwm-build.config.json`. Use
  `versionTracking.versionsJson` instead. The old key still works for
  this minor; will be removed in the next minor bump.

## [0.5.5-alpha] - 2026-05-12

### Added

- `cwm-verify` now validates and fixes Joomla component admin menus. It parses
  `<menu>` and `<submenu>` tags from the component manifest and checks the
  `#__menu` table in the target database. When run with `--fix`, missing menus
  are automatically created using a safe Nested Set append strategy.

## [0.5.4-alpha] - 2026-05-11

Patch release for a CLI-only autoload bug surfaced when Proclaim enabled
`versionPrompt: { enabled: true }` against v0.5.2-alpha. The fix was
described in the 0.5.3-alpha CHANGELOG but landed in #22 *after* the
v0.5.3-alpha tag was cut, so v0.5.4-alpha is the first tag that ships
it.

### Fixed

- `scripts/build.php` and `scripts/package.php` now `require_once`
  `src/Build/Prompt.php`. The CLI entry points are PSR-0-style, loading
  every class manually rather than relying on Composer's autoloader, and
  the `Prompt` class (added in 0.5.0-alpha for the 3-way version prompt)
  was missing from both. The PHPUnit suite did not catch this because
  unit tests instantiate `PackageBuilder` directly and Composer's
  autoloader resolves `Prompt` transparently — only the standalone CLI
  invocation hit the gap. Symptom: `Error: build failed — Class
  "CWM\BuildTools\Build\Prompt" not found` immediately after enabling
  `versionPrompt`. Regression test added that spawns
  `php scripts/build.php` as a child process with `versionPrompt`
  enabled and asserts no class-not-found errors in stderr.

## [0.5.3-alpha] - 2026-05-10

Intended as the patch release for the Prompt.php CLI autoload bug, but
the tag was cut before #22 landed on `main`. The fix described here
actually ships in v0.5.4-alpha; this tag is functionally equivalent to
v0.5.2-alpha. Consumers on `^0.5@alpha` should bump to v0.5.4-alpha to
pick up the fix.

## [0.5.2-alpha] - 2026-05-10

Patch release for the version-threading gap surfaced during the Proclaim
migration to v0.5.0-alpha.

### Changed

- `cwm-package` `self` include now inherits the outer wrapper's version
  for its inner `cwm-build` invocation. Previously the inner build read
  its own manifest's `<version>`; if that drifted from the package
  manifest's `<version>` (e.g. between `cwm-bump` runs, or with
  `cwm-package --version X` overriding only the outer name) the inner
  zip would land at a different version than the wrapper. This was the
  reason the v0.5.0/0.5.1 release notes asked Proclaim to **not** enable
  `versionPrompt: { enabled: true }` — an interactive prompt could
  produce mismatched inner and outer versions. With this fix, the outer
  version (whether from manifest, `--version` CLI override, or future
  prompt result) is threaded into the `self` include's `cwm-build` call
  via `PackageBuilder::build($outerVersion)`. `inline`, `subBuild`, and
  `prebuilt` includes are version-independent (their version sources
  are nested manifests, sub-build script outputs, and pre-built
  artifacts respectively) and remain unchanged. Released this as a
  patch on the alpha line because: (a) no consumer has merged the new
  binaries yet, only PRs are open against them; (b) the prior behavior
  was unintentionally inconsistent with the `self` semantic ("this same
  project") rather than a deliberate API choice. 2 new tests covering
  the threading invariant + the CLI override path; the diagnostic
  output line now includes the inherited version (`-> building self
  (X.zip) at vN.N.N`).

  Once consumers are on `^0.5@alpha` (auto-picks 0.5.2), Proclaim can
  enable `versionPrompt: { enabled: true }` and have the prompt result
  flow consistently from `cwm-package`'s outer manifest read into the
  `self` include's inner build.

## [0.5.1-alpha] - 2026-05-09

Patch release for one bug surfaced during the lib_cwmscripture migration
to v0.5.0-alpha.

### Fixed

- `cwm-build` `preBuild.mode: "ensure-minified"` gate now only checks
  primary asset extensions (`.js`, `.css`). Earlier the gate iterated
  every extension in the configured directories and reported spurious
  failures like `foo.min.js.map → expected foo.min.js.min.map` for
  source-map (`.map`) and gzip (`.gz`) companions of already-minified
  files. The first end-to-end consumer migration (lib_cwmscripture)
  surfaced this immediately; consumers of v0.5.0-alpha that hit the
  same gate failure should bump to `^0.5@alpha` (auto-picks 0.5.1) or
  pin to `0.5.1-alpha` directly. Regression test added.

## [0.5.0-alpha] - 2026-05-09

First minor bump on the alpha line. Consolidates the per-consumer build /
package scripts (Proclaim's `proclaim_build.php`, lib_cwmscripture's
`build-package.php`, CWMScriptureLinks' `build-package.php`) into two
generic binaries — `cwm-build` and `cwm-package` — driven by new
`build:` and `package:` blocks in `cwm-build.config.json`. Adds
`cwm-article`, `cwm-joomla-cms-deps`, and a parameterized
`build-assets.js` template (issue #7), plus pre-flight `git pull` /
submodule sync in `cwm-release` (issue #6).

**Schema is additive**, but `cwm-package` itself changes behavior — see
the migration guide below before bumping. Consumers pinned to `^0.4@alpha`
do **not** auto-pick this up; they must update their constraint to
`^0.5@alpha` (or pin to `0.5.0-alpha`) and adopt the new schema fields.

### Migration guide

| Concern | Old (0.4.x) | New (0.5.0) |
|---|---|---|
| Build a single zip | `"command": "php build/build-package.php"` (project script) | `"command": "cwm-build"` + new `build:` schema fields below |
| Assemble multi-ext package | `"command": "php build/proclaim_build.php package"` (project script) | `"command": "cwm-package"` + new `package:` block |
| `cwm-package` binary | thin `bash -c $build.command` shim | generic assembler reading `package:` block |
| `cwm-build` binary | did not exist | new — see below |

**Required `composer.json` change** (consumers using cwm-build-tools):

```json
{
    "require-dev": {
        "cwm/build-tools": "^0.5@alpha"
    }
}
```

**Required `cwm-build.config.json` additions** to use the new binaries:

- For a single-extension build (lib_cwmscripture-shape):
  ```json
  "build": {
      "command":    "cwm-build",
      "outputGlob": "build/dist/lib_cwmscripture-*.zip",
      "outputDir":  "build/dist",
      "outputName": "lib_cwmscripture-{version}.zip",
      "manifest":   "cwmscripture.xml",
      "scriptFile": "script.php",
      "sources": [
          { "from": "src",                    "to": "lib_cwmscripture/src" },
          { "from": "media/lib_cwmscripture", "to": "media/lib_cwmscripture" }
      ],
      "excludes": [".git", ".DS_Store", "node_modules"],
      "preBuild": {
          "mode": "ensure-minified",
          "dirs": ["media/lib_cwmscripture/js", "media/lib_cwmscripture/css"]
      }
  }
  ```

- For a Proclaim-shape strict build, additionally set `excludeMatchMode:
  "strict"`, `vendorPrune: true`, `includeRoots`, `includeRootExtensions`,
  `excludeExtensions`, `excludePaths`, and `preBuild.mode: "run"` with
  `preBuild.command: "npm install && npm run build"`.

- For a multi-extension package wrapper:
  ```json
  "package": {
      "manifest":     "build/pkg_proclaim.xml",
      "outputDir":    "build/dist",
      "outputName":   "pkg_proclaim-{version}.zip",
      "innerLayout":  "packages-prefix",
      "installer":    "build/script.install.php",
      "languageFiles": [
          { "from": "build/language/en-GB/en-GB.pkg_proclaim.sys.ini",
            "to":   "language/en-GB/en-GB.pkg_proclaim.sys.ini" }
      ],
      "includes": [
          { "type": "self",     "outputName": "com_proclaim.zip" },
          { "type": "subBuild", "path": "libraries/lib_cwmscripture",
            "buildScript": "build/build-package.php",
            "distGlob":    "build/dist/lib_cwmscripture-*.zip",
            "outputName":  "lib_cwmscripture.zip" }
      ]
  }
  ```

After migration the project's own `build/build-package.php` /
`build/proclaim_build.php` scripts can be deleted.

### Added

- `cwm-build` 3-way interactive version prompt — new optional
  `build.versionPrompt: { enabled, timeout }` config field. When enabled
  AND the build is run interactively AND no `--version` override is given,
  cwm-build offers Proclaim's existing 3-way menu before opening the zip:
  (1) keep the manifest version, (2) use a date-stamped pre-release
  (`<version>.YYYYMMDD`), or (3) enter a custom value. Default for `timeout`
  is 10 seconds; choosing nothing within the countdown picks option 1.
  CI / `$CWM_NONINTERACTIVE` short-circuits to manifest version with a
  single diagnostic line. `cwm-release` continues to pass `--version`
  through to the build command, so the release pipeline is unaffected —
  this only fires for ad-hoc local `cwm-build` runs. 5 new tests covering
  the non-interactive bypass, override short-circuit, schema validation,
  and the timeout default. The interactive 3-way path stays manual
  (PHPUnit can't fake a PTY).

- `cwm-package` rewritten — replaces the prior thin shell-pass-through wrapper
  (`bash -c $build.command`) with a generic Joomla multi-extension package
  assembler driven by a new `package:` block in `cwm-build.config.json`. New
  classes: `src/Build/PackageConfig` and `src/Build/Packager`; CLI script
  `scripts/package.php`. Supports four `includes[]` entry types:
  - `self` — invoke `cwm-build` on the project's own `build:` block, then
    bundle the result. Handles Proclaim's "step 3: build com_proclaim".
  - `subBuild` — array-form `proc_open` of `php <buildScript> [args]` inside
    `path`, then glob `distGlob` (relative to `path`) for the produced zip.
    Handles Proclaim's two `passthru('php …/build-package.php …')` calls
    (including the `--plugin-only` arg) during transition while sub-extensions
    still ship their own scripts.
  - `prebuilt` — assume already on disk; glob `distGlob` (project-relative).
    Multiple matches → most-recently modified wins.
  - `inline` — nested `BuildConfig`-shaped block; cwm-build runs in-process
    on it. Handles CSL's `plg_task_cwmscripture` (a sibling directory built
    in-process).

  Other features: `innerLayout` (`"root"` for outer-zip entries at root vs
  `"packages-prefix"` for `packages/<outputName>` paths — Proclaim's layout),
  optional `installer` scriptfile, `languageFiles[]` with explicit `from`/`to`
  paths, opt-in `verify.expectedEntries[]` self-check (CSL's verifyPackage
  feature). Staging dir is a unique scratch dir under `sys_get_temp_dir()`
  cleaned up on success or failure (no shell-driven `rm -rf` calls; native
  PHP recursion with `is_link()` guards per CLAUDE.md).

  20 new unit tests / 79 assertions covering all 4 include types, both inner
  layouts, version override, installer + language file placement, verify
  pass/fail, and config validation (required fields, invalid type, invalid
  layout, subBuild missing path, inline missing nested config). PR D of #5.

- `cwm-build` strict-mode + filtering features for the Proclaim-shape build
  flow. New optional `build:` schema fields (PR C of #5):
  - `excludeMatchMode: "strict"` — Proclaim's 4-mode pattern matching
    (exact / prefix-with-slash / contained-with-slashes / suffix-after-slash)
    that catches `.git` at any depth without over-matching the substring "git"
    inside unrelated filenames. Defaults to `"contains"` (PR B behavior).
  - `excludeExtensions: ["map"]` — bare extension allowlist; `.map` files
    are dropped at any path.
  - `excludePaths: ["media/backup/*.sql"]` — fnmatch glob patterns matched
    against the relative path; covers Proclaim's `media/backup/*.sql` rule.
  - `vendorPrune: true` — drop Composer metadata (`installed.json`,
    `installed.php`) and doc/license files (`README*`, `CHANGELOG*`,
    `BACKERS*`, `AUTHORS*`, `CONTRIBUTING*`, `UPGRADE*`, `SECURITY*`,
    `LICENSE*`, `COPYING*`) inside any `vendor/` subtree.
  - `includeRoots: ["admin/", "media/", ...]` — subdirectory allowlist;
    only files starting with one of these prefixes are included.
  - `includeRootExtensions: ["php", "xml", "txt", "md"]` — root-level
    files (no `/` in path) with one of these extensions are also admitted
    through the include filter (Proclaim's `proclaim.xml`, `LICENSE.txt`,
    `README.md` at project root).
  - `preBuild.mode: "run"` + `preBuild.command` — auto-execute a shell
    command (`passthru`) before the zip walk; non-zero exit aborts.
    Matches Proclaim's `npm install && npm run build` step. Build config
    is trusted (committed by the project author) so shell semantics are
    OK per the threat model.
  11 new tests / 56 assertions covering each of the above plus the
  combined Proclaim-shape (strict + vendor-prune + includeRoots +
  includeRootExtensions). Defaults preserve PR B's behavior; adopting any
  of these is opt-in. The interactive 3-way version prompt
  (`versionPrompt`) is deferred to a small follow-up after PR A
  (`Build\Prompt`) merges.

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

- `CWM\BuildTools\Build\Prompt` — extracted the interactive `ask()` helper
  (with the fixed countdown ANSI redraw — the `\r\033[K` clear-to-EOL that
  prevents stray "0" artifacts when "(10s):" is replaced by "(9s):") from
  Proclaim's `build/proclaim_build.php` into a reusable PSR-4 class. Honors
  `$CI` and `$CWM_NONINTERACTIVE` for CI-safe defaults; uses array-form
  `proc_open` for the `stty` calls (no shell, no metachar interpretation —
  per the project's security guardrails). Foundation for the upcoming
  `cwm-build` / `cwm-package` consolidation (#5). 7 unit tests covering the
  non-interactive paths and `isNonInteractive()` env detection.

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

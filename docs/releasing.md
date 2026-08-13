# Releasing an extension

`cwm-release` runs the whole pipeline for a CWM extension. Everything it does
is driven by `cwm-build.config.json` — the `build`, `ars`, `github`,
`changelog`, `announcement`, and `versionTracking` blocks.

## The one-liner

```bash
# 1. Move CHANGELOG.md "## [Unreleased]" content under a new version heading.
# 2. Then:
composer cwm-release
```

`cwm-release` chains, in order:

1. **Bump** — rewrite `<version>` in every manifest in `manifests`, and sync
   `versions.json` / `package.json` per the profile (`cwm-bump`).
2. **Substitute tokens** — replace `__DEPLOY_VERSION__` placeholders across the
   configured `substituteTokens.paths`.
3. **Build** — produce the installable zip (`cwm-build` / `cwm-package`),
   running the `preBuild` hook (e.g. `npm run build`) and the optional
   [`verifyAssets`](javascript-and-joomladialog.md#72-buildverifyassets-fail-loudly-if-an-asset-didnt-build)
   check first.
4. **Token gate** — unzip the built artifact and refuse to go further if any
   `__DEPLOY_VERSION__` survived step 2. See below.
5. **ARS publish** — push the artifact to Akeeba Release System using the `ars`
   block (token from 1Password).
6. **Finish** — update `versions.json` and push the release commit/tag to
   `github.releaseBranch`.

## The token gate

Step 2 substitutes across `substituteTokens.paths`. That list is hand-maintained
and validated against nothing, so whether a release ships the literal placeholder
depends on someone having remembered every directory that ships. Three releases
proved it does not hold: the installer blind spot (#75), a `paths` entry that
covered one of three sub-extensions (CWMScriptureLinks#27), and a vendored copy
of these tools older than #75's fix (CWMScriptureLinks#29).

So the gate does not read configuration. It reads the artifact:

```bash
php vendor/cwm/build-tools/scripts/verify-artifact-tokens.php -f build/dist/pkg_thing-1.2.3.zip
```

- **Recurses into nested zips.** `pkg_proclaim.zip` → `packages/pkg_cwmscripture.zip`
  → `lib_cwmscripture.zip` is three levels; findings are reported as
  `outer.zip!inner.zip!path/file.php:12`.
- **Ignores `substituteTokens.extensions`** — that defaults to `['php']`, which
  is exactly why a token in a manifest or a JS file goes unnoticed. Binary
  entries are skipped by content, not by extension.
- **Placed between build and push**, so a failure costs a re-run rather than a
  cleanup: nothing is committed, tagged, released or published yet.
- **Reports every occurrence**, not the first.
- Under `--dry-run` it reports without failing — no build ran, so the artifact on
  disk is usually the previous release.
- Projects with no `substituteTokens` block are skipped, and told so.

If it fires, the usual causes are a shipped directory missing from `paths`
(remember paths resolve from the **repo root**, so a sub-extension's own `src/`
is not covered by a bare `src/` entry) or a vendored `cwm/build-tools` older than
the fix for the file in question — `composer.lock` is not evidence the installed
tree is current.

Before any of that, if the project's `composer.json` defines a `test:release`
script, `cwm-release` runs it as a pre-flight gate and stops before touching
anything if it fails. This is opt-in by presence — a project with no
`test:release` script is released exactly as before. Pass `--skip-tests` to
release anyway; `--dry-run` still runs the gate for real, since it verifies
rather than writes.

Always check `--help` for the current flags and any dry-run option:

```bash
composer cwm-release -- --help
```

## Step-by-step (when you don't want the full pipeline)

| Step | Command |
|---|---|
| Bump versions only | `composer cwm-bump -- -v X.Y.Z` |
| Build the zip | `composer cwm-build` |
| Install into local Joomla to smoke-test | `composer cwm-install-zip` |
| Publish to ARS | `composer cwm-ars-publish` |
| Generate the Joomla changelog XML | `composer cwm-changelog` |
| Post the announcement article | `composer cwm-article` |

!!! tip "Don't hand-edit manifest versions"
    `cwm-bump` touches **every** manifest listed in `manifests`. Editing one by
    hand drifts the others — let the bumper do it.

## Before you release

- **CHANGELOG.** Move the `## [Unreleased]` section's content under a new
  `## [X.Y.Z] - YYYY-MM-DD` heading. `cwm-release` / `cwm-changelog` read this.
- **Clean dev links.** `composer cwm-link-check` should be green; the build
  walks the working tree, so stale symlinks or unbuilt assets ship.
- **Lint.** `composer lint-deprecations` for J6/7 readiness if you're touching
  JS.

## Versioning conventions

- **Consumer extensions** follow their own semver line; the version in the
  manifest is the source of truth (`cwm-bump` propagates it).
- **cwm-build-tools itself** is semver and stable as of `v1.0.0`. Pin a major
  (`^1.5`); fixes and new pipeline steps arrive as patches/minors via
  `composer update`. Breaking schema/CLI changes are reserved for the next
  major and documented under `### Changed (breaking)` in the
  [changelog](https://github.com/Joomla-Bible-Study/cwm-build-tools/blob/main/CHANGELOG.md).

## Distribution

CWM internal tooling ships **only** via Composer (`require-dev`) — never via
ARS. ARS is for end-user extensions. The built extension zip is what reaches
end users; `cwm-build-tools` is a dev dependency and is excluded from the
shipped package.

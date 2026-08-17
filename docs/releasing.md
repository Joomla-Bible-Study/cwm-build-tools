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

## The release gate

If the project's `composer.json` defines a `test:release` script, `cwm-release`
runs it as a pre-flight gate and stops before touching anything if it fails.
This is opt-in by presence — a project with no `test:release` script is released
exactly as before. Pass `--skip-tests` to release anyway; `--dry-run` still runs
the gate for real, since it verifies rather than writes.

The gate runs **before the version bump**, so it verifies the commit about to be
released rather than the commit that results from bumping it. That ordering is
deliberate and worth keeping, but it has a consequence: at the moment the gate
runs, nothing on disk names the version about to ship.

`cwm-release` therefore hands the gate that version in the environment:

```bash
CWM_RELEASE_VERSION=1.2.3 composer test:release
```

It is set for that one command, not exported — the bump, the build and every
later step see the environment they always did.

!!! warning "Don't resolve the version under test from `versions.json`"
    Neither field names the release in progress while the gate is running.
    `active_development.version` is written by `cwm-bump`, which has not run
    yet, so it holds the *previous* bump's value. `current.version` is written
    by step 8, after publishing, and only for a stable release, so it holds the
    *previous stable* one.

    An upgrade phase that reads either as "the build under test" and compares it
    against a baseline resolved the same way finds them equal, concludes there
    is nothing to upgrade to, and reports itself not-applicable. The gate then
    passes. A CWMLivingWord 5.7.0 release went out that way with the upgrade
    path — the one every existing site takes, and where install-scriptfile
    postflight repairs live — never exercised, on a release whose headline fix
    was exactly such a repair (#101).

    Read `CWM_RELEASE_VERSION` for the target, and resolve the baseline from
    published artifacts — the newest stable GitHub release older than the target
    — rather than from a file the pipeline has not updated yet.

The variable's presence is itself the signal, because `test:release` runs in two
situations and the right answer differs between them:

| `CWM_RELEASE_VERSION` | Situation | The build under test is |
|---|---|---|
| set | a release is in progress; the bump has not run | the variable |
| unset | nightly, PR or manual run; no release in flight | `active_development.version` |

Outside a release that file is correct — `cwm-bump` wrote it and nothing is
mid-flight — so the fallback is not a workaround, it is the other half of the
contract. Read the variable when it is set and fall back when it is not:

```bash
TARGET="${CWM_RELEASE_VERSION:-$(jq -r .active_development.version versions.json)}"
```

A gate phase that genuinely has nothing to do should still say so loudly enough
to be read as a finding. "Not applicable" printed next to a passing gate is
indistinguishable from "covered", which is what made the above quiet.

### The other end: which release to upgrade *from*

An upgrade phase also needs a "before" state, and `cwm-baseline` resolves and
fetches it:

```bash
composer cwm-baseline            # newest release older than the version under test
composer cwm-baseline -- --print # just say which, download nothing
```

It reads `CWM_RELEASE_VERSION` when a release is in flight, so the baseline is
chosen relative to the version being shipped rather than the stale manifest.
Stable releases are preferred; a pre-release is only chosen when no stable one
qualifies. `baseline.minimum` excludes early releases that cannot install.

It downloads the **released artifact**, not a rebuild of an old tree — the
released zip carries the old `install.sql`, without the newer migrations, so
installing the new build over it genuinely exercises the `ADD COLUMN` /
`CREATE TABLE` update SQL.

!!! note "Exit 3 means *not applicable*, and the caller decides"
    A project's first release has nothing to upgrade from, and neither does one
    whose every candidate sits below `baseline.minimum`. That is not a failure.
    It exits **3** rather than 0 or 1 so the caller can tell it apart from both:
    treating it as fatal blocks the first release of every new project, and
    treating it as success reports an upgrade test that never ran. #101 is the
    story of that distinction being made silently, in the wrong place.

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

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
4. **ARS publish** — push the artifact to Akeeba Release System using the `ars`
   block (token from 1Password).
5. **Finish** — update `versions.json` and push the release commit/tag to
   `github.releaseBranch`.

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

# Command reference

Every command ships as `vendor/bin/cwm-*` and is normally run through the
matching Composer script (`composer cwm-init` → `vendor/bin/cwm-init`).
`cwm-init` wires these scripts into a consumer's `composer.json`.

!!! tip "`--help` is canonical"
    Every command has structured `--help` output (the source of truth for
    flags). Pass it through Composer with `--`:
    ```bash
    composer cwm-release -- --help
    composer lint-deprecations -- --help
    ```

## Setup & scaffolding

| Command | What it does |
|---|---|
| `cwm-init` | Scaffold a `cwm-build.config.json` and run an initial config sync. Detects extension type/layout and pre-fills every prompt. Idempotent. |
| `cwm-setup` | Interactive wizard that writes **`build.properties`** (your local Joomla install paths, URLs, DB/admin creds). Per-developer; never committed. |
| `cwm-sync-configs` | Refresh managed config blocks in the consuming project — the `.gitignore` managed block and `eslint.config.mjs`. Only touches text between explicit markers. |

## Local dev environment

| Command | What it does |
|---|---|
| `cwm-joomla-install` | Download and extract Joomla into every configured install path. |
| `cwm-joomla-latest` | Print the latest stable Joomla release tag (from the GitHub API). |
| `cwm-joomla-cms-deps` | Clone joomla-cms source for unit testing. |
| `cwm-link` | Symlink the project's source into every configured Joomla install. Always **relative** symlinks (portable across machines/CI). |
| `cwm-link-check` | Verify every symlink `cwm-link` would create still resolves. **Exit 1** on any drift — CI-gateable. |
| `cwm-clean` | Remove every dev symlink `cwm-link` created. |
| `cwm-verify` | Confirm each install has every project sub-extension registered in `#__extensions`; detects `manifest_cache` drift. **Exit non-zero** on mismatch. |
| `cwm-install-zip` | Install the built dist zip into every Joomla install. |

See [How to use → everyday commands](how-to-use.md#3-everyday-commands) for the
typical day-to-day loop.

## Build & release

| Command | What it does |
|---|---|
| `cwm-bump` | Bump `<version>` across all manifests listed in `cwm-build.config.json`, and sync `versions.json` / `package.json` per the profile. |
| `cwm-build` | Build one installable extension zip from the `build` block. Runs the `preBuild` hook and the optional [`verifyAssets`](javascript-and-joomladialog.md#72-buildverifyassets-fail-loudly-if-an-asset-didnt-build) check first. |
| `cwm-package` | Assemble a multi-extension Joomla **package** zip from child extension zips. |
| `cwm-release` | Full release pipeline: bump → substitute tokens → build → ARS publish → `versions.json` + git push. |
| `cwm-changelog` | Generate a Joomla changelog XML entry from a GitHub release. |
| `cwm-article` | Post a "&lt;Extension&gt; X.Y.Z Released" announcement article. |

The full release flow is documented in [Releasing](releasing.md).

## Akeeba Release System (ARS)

| Command | What it does |
|---|---|
| `cwm-ars-list` | List ARS categories, update streams, and releases. |
| `cwm-ars-create-stream` | Create a new ARS Update Stream under a category. |
| `cwm-ars-publish` | Push a built artifact to Akeeba Release System. |

ARS endpoint, category, and stream IDs come from the `ars` block in
`cwm-build.config.json`; the API token is read from 1Password (`tokenItem` /
`tokenVault`).

## Quality & maintenance

| Command | What it does |
|---|---|
| `cwm-lint-deprecations` | Flag Joomla 6/7 upgrade blockers (`bootstrap.modal`, `data-bs-toggle=modal`, iframe modal handlers, `Joomla.Modal`, jQuery globals). **Exit 1** on findings; `--warn` to report without failing. See the [JS guide](javascript-and-joomladialog.md#71-cwm-lint-deprecations-find-j67-blockers). |
| `cwm-sync-languages` | Sync and translate Joomla language files for the project. |

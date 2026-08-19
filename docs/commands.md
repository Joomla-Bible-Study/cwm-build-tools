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
| `cwm-sync-configs` | Refresh managed config blocks in the consuming project — the `.gitignore` managed block and `eslint.config.mjs`. Only touches text between explicit markers. `--check` previews and **exits 1** on drift, for CI — only maintained files count, not the seeded-once ones. |

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
| `cwm-reset-testsite` | Remove an extension family from every `role = test` install — extension rows, schema rows, its tables, assets/categories/menus, and installed directories — so the next install starts genuinely clean. Prints the family **and** the retained set every run. **Exit 2** when a retained extension did not survive. |
| `cwm-schema-replay` | Execute every migration file against a scratch schema, from a committed baseline, in the installer's order. Nothing else in the gate *runs* these files: a fresh install applies `install.sql` and Joomla stamps `#__schemas` at the newest version without running a single update. **Exit 1** on a failing statement, or when nothing is configured to replay. See [the baseline](#the-schema-replay-baseline). |
| `cwm-baseline` | Download the released package an upgrade test should upgrade *from* — the newest release older than the version under test, preferring stable. **Exit 3** when no usable baseline exists (a first release), which is a status, not a failure. See [the release gate](releasing.md#the-release-gate). |

See [How to use → everyday commands](how-to-use.md#3-everyday-commands) for the
typical day-to-day loop.

### The schema replay extension root

Joomla resolves `<schemapath>` and `<sql><file>` against `extension_root`, not
against wherever the manifest file sits. Those are the same directory in an
*installed* extension, and often different in a source tree — Proclaim keeps
`proclaim.xml` at the repository root while its `sql/updates/mysql` lives under
`admin/`.

Set `root` when they differ:

```json
"manifest": "proclaim.xml",
"root": "admin"
```

It defaults to the manifest's own directory, which stays correct for the
installed layout.

### The schema replay baseline

`cwm-schema-replay` needs a starting schema, and it is not the extension's own
install SQL. Migrations write to core tables no extension manifest creates —
Proclaim's touch `#__assets`, `#__schemas`, `#__action_log_config` and
`#__action_logs_extensions`. Replaying onto the extension's tables alone fails
on the first of those, for a reason that says nothing about the migration.

So `baseline` takes a list, applied in order: the core schema, then the
extension's install SQL as it stood at the oldest release you still support
upgrades from.

```json
"schemaReplay": {
    "targets": [
        {
            "name": "com_proclaim",
            "manifest": "admin/proclaim.xml",
            "baseline": [
                "build/sql-baselines/joomla-core.sql",
                "build/sql-baselines/com_proclaim-10.0.0.sql"
            ],
            "from": "10.0.0"
        }
    ]
}
```

Produce the core half once, from a site at that version, and commit both files:

```bash
mysqldump --no-data --skip-add-drop-table <db> > core.sql
# then rewrite the site's prefix (jos_, etc.) back to #__
```

Committing them is the point. A baseline pulled out of git history at run time
is not reviewable in a pull request, and this file decides what "every migration
passes" means.

Connection comes from `CWM_TEST_MYSQL_DSN` — the same variable the test suite
uses, and the same server `docker-compose.databases.yml` starts. Tables are
created under a prefix inside the database the DSN names and dropped again; no
database is created or dropped.

## Build & release

| Command | What it does |
|---|---|
| `cwm-bump` | Bump `<version>` across all manifests listed in `cwm-build.config.json`, and sync `versions.json` / `package.json` per the profile. |
| `cwm-build` | Build one installable extension zip from the `build` block. Runs the `preBuild` hook and the optional [`verifyAssets`](javascript-and-joomladialog.md#72-buildverifyassets-fail-loudly-if-an-asset-didnt-build) check first. |
| `cwm-package` | Assemble a multi-extension Joomla **package** zip from child extension zips. |
| `cwm-release` | Full release pipeline: bump → substitute tokens → build → ARS publish → `versions.json` + git push. |
| `cwm-verify-update-stream` | **After** a release: fetch the update stream the package manifest declares and assert the released version is served with `php_minimum` and `targetplatform`. Each of those fails silently in production — published fine, offered to nobody. `cwm-release` runs it as a post-flight step and reports rather than fails. |
| `cwm-changelog` | Generate a Joomla changelog XML entry from a GitHub release. |
| `cwm-article` | Post a "&lt;Extension&gt; X.Y.Z Released" announcement article. |

The full release flow is documented in [Releasing](releasing.md).

## Akeeba Release System (ARS)

| Command | What it does |
|---|---|
| `cwm-ars-list` | List ARS categories, update streams, and releases. |
| `cwm-ars-create-stream` | Create a new ARS Update Stream under a category. |
| `cwm-ars-publish` | Push a built artifact to Akeeba Release System. |
| `cwm-ars-reorder` | Space out a category's `ordering` so the newest release stays the latest, with room to publish into. Plans by default; `--apply` writes. |

ARS endpoint, category, and stream IDs come from the `ars` block in
`cwm-build.config.json`; the API token is read from 1Password (`tokenItem` /
`tokenVault`).

## Quality & maintenance

| Command | What it does |
|---|---|
| `cwm-lint-queries` | Enforce `$db->createQuery()` over `$db->getQuery(true)`. A consistency guard, not a correctness one — both return the same `DatabaseQuery`. **Exit 1** on findings; `--warn` to report without failing. The no-argument `$db->getQuery()` is a different operation and is never flagged. |
| `cwm-lint-workflows` | Flag CI `paths:` filters that match no tracked file. A filter decides whether a job runs; an entry naming a deleted file guards nothing and reads in review exactly like one that works. **Exit 1** on stale `paths`; a stale `paths-ignore` is a notice only, since it fails open and may be a deliberate exclusion of a gitignored directory. |
| `cwm-lint-comments` | Flag issue references written inside code comments — git blame already connects a line to its issue, so the citation adds nothing and goes stale. Only the comment part of a line is searched, so a hex colour or a number in a string is safe. `owner/repo#123` is allowed. **Exit 1** on findings; `--warn` to report without failing. |
| `cwm-lint-deprecations` | Flag Joomla 6/7 upgrade blockers (`bootstrap.modal`, `data-bs-toggle=modal`, iframe modal handlers, `Joomla.Modal`, jQuery globals). **Exit 1** on findings; `--warn` to report without failing. See the [JS guide](javascript-and-joomladialog.md#71-cwm-lint-deprecations-find-j67-blockers). |
| `cwm-sync-languages` | Sync and translate Joomla language files for the project. |

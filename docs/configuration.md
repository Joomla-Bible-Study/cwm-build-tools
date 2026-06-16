# Configuration reference

cwm-build-tools reads **two** files. Keep them separate — conflating them is
the most common setup mistake.

| File | Committed? | Owns |
|---|---|---|
| `cwm-build.config.json` | **yes** | What the project *is* — layout, manifests, ARS endpoint, profile. **No secrets.** |
| `build.properties` | **never** (gitignored) | Where *your* local Joomla installs live — paths, URLs, DB/admin creds. Per-developer. |

---

## `cwm-build.config.json`

Committed; consumed by every `cwm-*` command. Scaffold it with `composer
cwm-init` rather than authoring by hand. Minimal examples live in
[`examples/`](https://github.com/Joomla-Bible-Study/cwm-build-tools/tree/main/examples).

### Top-level keys

| Key | Purpose |
|---|---|
| `extension` | `{ type, name, displayName }`. `type` is the Joomla install type (`component` / `library` / `plugin` / `module` / `package`). |
| `profile` | Archetype that owns the `versionTracking` shape: `component`, `library`, or `package-wrapper`. Independent of `extension.type` — pick the one matching how you bump and ship. See [profiles](how-to-use.md#2-the-profile-system-read-this-before-hand-editing-versiontracking). |
| `manifests` | `{ package?, extensions[] }`. Each `extensions[]` entry is `{ type, path }` — the manifest XML for one sub-extension. Drives auto-derived dev links and `cwm-verify`. |
| `build` | How `cwm-build` / `cwm-release` produce the zip. See [build block](#the-build-block). |
| `ars` | Akeeba Release System target: `endpoint`, `categoryId`, `updateStreamId`, `environments[]`, `tokenItem`, `tokenVault` (1Password). |
| `github` | `{ owner, repo, releaseBranch }` for release + changelog. |
| `changelog` | `{ file, url }` — the Joomla changelog XML path and its raw URL. |
| `announcement` | `{ command, bulletsDir }` for the release announcement article. |
| `versionTracking` | Override layer, deep-merged on top of the profile. `versionsJson`, `packageJson`, `substituteTokens.paths[]`. **Lists replace wholesale.** |
| `assets` | Source-tree asset staging (`images`, `vendorMediaSource`, `packages[]`). Source paths, not install paths. |
| `dev` | Optional dev-link overrides — `deriveLinks`, `links[]`, `internalLinks[]`, `cwmSiblings`. |
| `gitignore` | `{ outputPaths[], mediaPaths[] }` feeding the managed `.gitignore` block. |

### The `build` block

Consumed by `cwm-build` / `cwm-package`.

| Key | Purpose |
|---|---|
| `command` | `cwm-build` (generic builder) or a project script. |
| `outputGlob` | Glob `cwm-release` matches to find the produced zip. |
| `outputDir`, `outputName` | Where the zip lands; `{version}` is substituted. |
| `manifest` | The extension manifest to read the version from + ship. |
| `sources[]` | `{ from, to }` copy pairs (working-tree → zip path). |
| `excludes[]`, `excludeExtensions[]`, `excludePaths[]` | What to drop; `excludeMatchMode` is `contains` or `strict`. |
| `includeRoots[]`, `includeRootExtensions[]` | Whitelist filter for `from: "."` (Proclaim shape). |
| `vendorPrune` | Strip composer metadata/docs from `vendor/` subtrees. |
| `preBuild` | `{ mode: "ensure-minified", dirs[] }` or `{ mode: "run", command }` (e.g. `npm run build`). Runs before zipping. |
| `verifyAssets` | `true` to fail the build if a `joomla.asset.json`-referenced file is missing. See the [JS guide](javascript-and-joomladialog.md#72-buildverifyassets-fail-loudly-if-an-asset-didnt-build). |
| `versionPrompt` | `{ enabled, timeout }` for the interactive 3-way version prompt. |

!!! note "Trust model"
    Every value here is author-controlled (committed by the project author).
    The toolchain treats config + CLI args as trusted; it does **not** defend
    against attacker-controlled config values. Secrets never belong here.

---

## `build.properties`

**Never committed** (gitignored via the managed block). Per-developer; written
by `composer cwm-setup`. Flat Java-properties keys (IDE-friendly; every key
globally unique).

```properties
joomla.version = 5.4.2
builder.installs = j5, j6, j5-test

builder.j5.role        = dev          # dev | test
builder.j5.path        = /path/to/joomla5
builder.j5.url         = https://j5-dev.local
builder.j5.version     = 5.4.2
builder.j5.db_host     = localhost
builder.j5.db_user     =
builder.j5.db_pass     =
builder.j5.db_name     =
builder.j5.admin_user  = admin
builder.j5.admin_pass  = admin
builder.j5.admin_email = admin@example.com
```

- `builder.installs` — comma-separated list of install ids; each id `X` is
  configured by its `builder.X.*` keys.
- `role` — `dev` (symlink target) or `test` (zip-install target).
- `paths.<package>` — flat path keys for cross-package (CWM sibling) resolution.

!!! warning "Format compatibility"
    The reader accepts the canonical **flat** format above and the legacy INI
    **section** format (`[j5]` … ) for backward compatibility. New projects use
    flat keys — Java-properties-aware IDEs (PhpStorm/IntelliJ) flag duplicate
    keys across INI sections, which flat keys avoid. Use `#` comments, not `;`.

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
| `versionTracking` | Override layer, deep-merged on top of the profile. `versionsJson`, `packageJson`, `substituteTokens.paths[]`, `sourceFiles[]`. **Lists replace wholesale.** See [source-file version literals](#versiontrackingsourcefiles--version-literals-in-source) below. |

### `versionTracking.sourceFiles` — version literals in source

For a version hardcoded in code that ships beside the manifest — the shape
`public const VERSION = '1.2.3';` — which nothing else in the toolchain writes,
so it drifts. `cwm-bump` rewrites it along with the manifest:

```json
"versionTracking": {
    "sourceFiles": [
        { "path": "src/LibraryVersion.php",
          "pattern": "public const VERSION = '{version}';" }
    ]
}
```

Why this exists: lib_cwmscripture's `LibraryVersion::VERSION` sat at 1.1.3 while
its manifest said 1.1.4, and `satisfies()` / `needsUpgrade()` read that constant —
so downstream extensions gating on a minimum library version were answered from a
stale number and could refuse a library that was new enough.

**Patterns are literals, not regexes.** The whole string is quoted and only
`{version}` becomes a capture, so you paste the line as it appears in the file
and `$`, `(` or `.` match themselves. That also keeps a pattern reviewable by
someone who does not write regex.

`{version}` matches a semver-ish token including pre-release and build suffixes
(`1.2.3`, `1.2.3-beta1`, `1.2.3-dev`), anchored to digits so a pattern cannot
drift onto arbitrary text. A version-shaped literal on a line the pattern does
not match — an `@since` tag, an unrelated constant — is left alone.

Unlike the JSON trackers, problems here are **fatal rather than warnings**:

| Situation | Result |
|---|---|
| Pattern matches nothing | throws, naming the file and pattern |
| `path` missing on disk | throws |
| `pattern` has no `{version}` | throws |
| Entry missing `path` or `pattern` | throws |
| File already at the target version | no-op, reported as `(no change)` |

That is deliberate. A rewrite that silently does nothing is precisely how the
version drifted in the first place, so a misconfigured entry has to stop the
bump rather than let a stale literal ship.
| `assets` | Source-tree asset staging (`images`, `vendorMediaSource`, `packages[]`). Source paths, not install paths. |
| `dev` | Optional dev-link overrides — `deriveLinks`, `links[]`, `internalLinks[]`, `cwmSiblings`. |
| `gitignore` | `{ outputPaths[], mediaPaths[] }` feeding the managed `.gitignore` block. |
| `vendors` | Bundled npm libraries `vendor:check` reports on — `[{ npm, label?, notes? }]`. |
| `security` | Optional `vendor:check` audit tuning. See [security block](#the-security-block). |

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
| `verifyMediaSources[]` | `{ source, output }` directory pairs. Fails the build when a file in `output` has no matching source in `source` — i.e. build output that outlived its source. See below. |
| `versionPrompt` | `{ enabled, timeout }` for the interactive 3-way version prompt. |

#### `verifyMediaSources` — catch build output that outlived its source

`verifyAssets` catches an asset the manifest references that the build never
produced. This catches the opposite: a file in `media/` that no source can
reproduce.

```json
"verifyMediaSources": [
    { "source": "build/media_source/js",  "output": "media/lib_cwmscripture/js" },
    { "source": "build/media_source/css", "output": "media/lib_cwmscripture/css" }
]
```

Why it exists: minified output is gitignored, so when a source file is renamed or
deleted, its old output is not removed by any checkout, branch switch or pull. It
just sits in `media/`, and the packager ships whatever is there. lib_cwmscripture
shipped `translations-manager.min.js` (plus `.gz` and `.map`) in every release for
months after the source became `bible-translations.es6.js` — the published v1.1.6
zip had 90 files where a fresh build of the same tag had 87. Nothing referenced
them, so there was no error to notice.

The cost of that is not cosmetic: the release artifact stops being a function of
the source and becomes a function of the source *plus that machine's build
history*, so two developers on the same tag produce different packages. Fixing it
after publication also invalidates the checksums the update server recorded at
publish time.

Matching rules:

- `foo.min.js`, `foo.min.js.gz`, `foo.min.js.map` and `foo.js` all trace back to
  `foo` — satisfied by `foo.es6.js`, `foo.esm.js` or `foo.js` in the source dir.
- Files that are not `.js` / `.mjs` / `.css` (or a `.map` / `.gz` of one) are
  ignored: `joomla.asset.json`, `index.html`, licence files.
- Only the top level of each `output` dir is checked. Subdirectories are usually
  copied third-party payloads whose layout has no relationship to `media_source`,
  and flagging those would just teach people to switch the check off.
- A pair whose `output` dir does not exist is skipped; a missing `source` dir is
  an error, since that is a config typo.

Opt-in per project: a tree that keeps hand-maintained files in the same directory
as build output would fail, so point the pairs at the directories your build
actually owns.

### The `security` block

Consumed by `vendor:check` (`templates/vendor-check.js`). Entirely optional —
every key has a working default, so existing configs need no changes.

| Key | Default | Purpose |
|---|---|---|
| `scanNested` | `true` | Discover Composer projects nested inside the repo and audit them too. |
| `nestedPaths[]` | *(unset)* | Explicit project dirs relative to the root. Setting this skips auto-discovery entirely — use it when the walk is too slow or picks up something unwanted. |
| `maxDepth` | `6` | Auto-discovery depth limit. |
| `ignore[]` | `[]` | Advisory IDs to suppress. Matches GHSA, PKSA or CVE. |

**Why nested projects matter.** An extension may bundle its own Composer
project whose `vendor/` tree is committed and ships to end users. Those
dependencies are invisible to a root-level `composer outdated`, so a vulnerable
bundled package can ship indefinitely without the tool noticing. Proclaim hit
exactly this: `vendor:check` reported "all up to date" while the bundled YouTube
addon carried a Guzzle with four open advisories.

Auto-discovery skips `vendor`, `node_modules`, `media`, `dist` and dotfile
directories, and does not descend into a nested project's own subtree.

Audits read the **lock file**, not the installed tree — an uninstalled project
would otherwise report `No packages - skipping audit` and pass silently.

Use `ignore[]` sparingly and only for advisories you have assessed as
inapplicable. Each entry silences a real finding:

```json
{
  "security": {
    "ignore": ["GHSA-xxxx-xxxx-xxxx"]
  }
}
```

**Exit codes:** `0` clean, `1` updates available, `2` advisories found *or
security status unverified* (takes precedence over `1`). Callers that only test
for non-zero are unaffected.

The check **fails closed**. If a scope cannot be audited — `composer` missing
from `PATH`, a timeout, a broken lock — it is reported as unverified and exits
`2` rather than passing. "We found nothing" and "we did not look" are different
answers, and conflating them is how a vulnerable tree earns a green check. A
scope with no `composer.json` (or, for npm, no `package.json` + lockfile) is
skipped rather than failed, since there is genuinely nothing to audit.

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

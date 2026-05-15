# How to use cwm-build-tools

A quick-start for adding `cwm/build-tools` to a Joomla extension project,
and a reference for the most common day-to-day commands.

> **Audience:** human developers and AI assistants helping consumers
> adopt or operate the toolchain. Pair with `CLAUDE.md` (architecture +
> guardrails) and the per-command `--help` output (canonical CLI shape).

---

## 1. Add to a new project (one command)

```bash
composer require --dev cwm/build-tools
composer cwm-init
```

`cwm-init` walks the project tree, detects the extension type and
layout, and writes `cwm-build.config.json` for you. Every prompt is
pre-filled with the detected default — usually you just hit Enter.

If a profile is detected (component / library / package-wrapper), the
wizard pre-fills it and writes `"profile": "<name>"` into the config.
That profile owns the `versionTracking` block at runtime, so you
**don't author** versions.json / package.json / substituteTokens
defaults by hand.

After `cwm-init`:
```bash
composer cwm-sync-configs    # write managed blocks into .gitignore + eslint.config.mjs
composer cwm-verify          # confirm extensions are registered in Joomla
```

---

## 2. The profile system (read this before hand-editing versionTracking)

Three archetypes ship with cwm-build-tools:

| Profile           | Use for                              | Defaults                                                      |
|-------------------|--------------------------------------|---------------------------------------------------------------|
| `component`       | Joomla components (own release line) | `versionsJson` + `packageJson` + `substituteTokens` (admin/site/modules/plugins/language) |
| `library`         | Standalone libraries                 | `packageJson` + `substituteTokens` (src/language)             |
| `package-wrapper` | Top-level package bundles            | `substituteTokens` only (src/language)                        |

Declare one in `cwm-build.config.json`:

```jsonc
{
    "extension": { "type": "library", "name": "lib_x" },
    "profile":   "library"
}
```

That's it for the 90% case. To override per-repo bits, add your own
`versionTracking` key — it deep-merges on top of the profile:

```jsonc
{
    "profile": "package-wrapper",
    "versionTracking": {
        "substituteTokens": {
            "paths": ["libraries/", "src/", "plg_task_x/"]   // lists replace wholesale
        }
    }
}
```

**Merge rules** (also enforced in tests):
- Maps merge recursively.
- Lists **replace** wholesale (so a `paths` override fully redefines the scan roots — no appending).

The profile is **independent of `extension.type`**. Proclaim is packaged
as `"type": "package"` but uses `"profile": "component"` because its
version-tracking shape is component-like (owns its own versions.json
and package.json on a single cadence). Set whichever profile matches
how you bump and ship, not how Joomla installs you.

To opt out of version tracking entirely, omit the `profile` key and
don't add an inline `versionTracking` block.

---

## 3. Everyday commands

| Command                     | What it does                                                                                  |
|-----------------------------|-----------------------------------------------------------------------------------------------|
| `composer cwm-init`         | Scaffold `cwm-build.config.json` for a new consumer.                                          |
| `composer cwm-sync-configs` | Refresh managed blocks in `.gitignore` and write/check `eslint.config.mjs`.                   |
| `composer cwm-link`         | Symlink the project's source into a Joomla install (read paths from `build.properties`).     |
| `composer cwm-link-check`   | Verify all expected symlinks exist + point where they should.                                 |
| `composer cwm-verify`       | Confirm every manifested extension is registered in `#__extensions`.                          |
| `composer cwm-bump -- -v X` | Rewrite `<version>` in every manifest + sync versions.json / package.json per the profile.    |
| `composer cwm-build`        | Build the extension zip(s) per `build` block.                                                 |
| `composer cwm-release`      | Full pipeline: bump → substitute tokens → build → ARS publish → versions.json + git push.    |

Each command supports `--help`:
```bash
composer cwm-link -- --help
composer cwm-release -- --help
```

---

## 4. The two-config split (don't conflate)

| File                     | Committed? | Owns                                                                  |
|--------------------------|------------|-----------------------------------------------------------------------|
| `cwm-build.config.json`  | **yes**    | What the project IS — layout, manifests, ARS endpoint, profile, etc.  |
| `build.properties`       | **never** — gitignored | Where the developer's local Joomla installs live (paths, URLs, DB/admin creds). Per-developer. |

`cwm-build.config.json` carries no secrets. `build.properties` carries
nothing about the extension's shape. If you find yourself wanting to
commit a credential, you're editing the wrong file.

---

## 5. Common AI-assist tasks (cheat sheet)

| Ask                                              | Recommended move                                         |
|--------------------------------------------------|----------------------------------------------------------|
| "Set this project up to use cwm-build-tools"     | `composer require --dev cwm/build-tools && composer cwm-init`. Accept the detected profile. |
| "How do I scan an extra directory for tokens?"   | Add `versionTracking.substituteTokens.paths` override; lists replace, so include the profile's defaults too. |
| "Bump the version"                               | `composer cwm-bump -- -v X.Y.Z`. Don't edit manifests by hand — the bumper touches every one listed.        |
| "Cut a release"                                  | `composer cwm-release`. Make sure CHANGELOG `[Unreleased]` has content first.                                |
| "I have an old inline versionTracking block"     | Run `composer cwm-sync-configs` — it prints a migration hint when an inline block matches profile defaults.  |
| "Why is sync-configs not touching X?"            | It only writes between explicit markers. Anything outside the marked block is preserved verbatim.            |
| "What does this profile resolve to?"             | `php vendor/cwm/build-tools/scripts/resolve-tracking.php <dotted-key>` (prints empty when unset).            |

---

## 6. Migrating a pre-profile consumer

Repos that pinned `^1.0` (v1.0.0 / v1.0.1) authored `versionTracking`
inline. To migrate to v1.0.2 profiles:

1. `composer update cwm/build-tools` (pulls v1.0.2+).
2. `composer cwm-sync-configs` — note the migration hint with the
   suggested profile name.
3. Edit `cwm-build.config.json`:
   - Add `"profile": "<name>"` near the top.
   - Trim the inline `versionTracking` block to **only** the keys that
     differ from the profile defaults. Delete the block entirely if
     nothing differs.
4. `composer cwm-release --dry-run` (or run the bump on a throwaway
   branch) to confirm the resolved tracking still touches the right
   files.

The profile is a behavior change only insofar as the profile's defaults
might add files to the scan that weren't there before — TokenSubstituter
warns on missing paths but doesn't fail, so the worst case is a stderr
note during release. Override `paths` to silence it.

---

## 7. Troubleshooting

- **`Unknown profile '<name>'`** — typo or stale install. Known names are
  `component`, `library`, `package-wrapper`. Update cwm-build-tools and
  re-check.
- **`versionTracking.versionsJson path not found`** — the file's path is
  resolved relative to the project root, not the script. Confirm the
  file exists at the resolved path and that the consumer (not the
  profile) hasn't pointed at a missing override.
- **`shell_exec is forbidden`** — internal guardrail. The toolchain
  avoids subshells for git/composer; use the underlying file readers
  (`.git/config`, `composer.json`) directly.
- **Symlinks point at absolute paths** — never write absolute symlinks.
  `Linker::link()` always resolves with `realpath()` + computes the
  relative path; if you see otherwise, that's a bug.

---

## 8. Where to dig deeper

- **`CLAUDE.md`** — architecture, threat model, security guardrails,
  release flow, dist-exclusion rules. Read this before touching the
  toolchain itself.
- **`examples/`** — minimal `cwm-build.config.json` per archetype.
- **`templates/profiles/<name>.json`** — the canonical versionTracking
  shape for each archetype.
- **`tests/`** — every behavior worth pinning has a test next to it.

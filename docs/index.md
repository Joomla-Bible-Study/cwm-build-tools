# cwm-build-tools

Shared **build, release, and CI tooling** for CWM Joomla extensions —
Proclaim, lib_cwmscripture, CWMScriptureLinks, CWMLivingWord, and friends.

It consolidates the machinery these projects kept duplicating: version
bumping, package-zip assembly, release pipelines, ARS publishing, the local
dev-symlink workflow, config sync, and modern JavaScript builds. One source
of truth, consumed as a Composer dev-dependency.

!!! info "Who this is for"
    Extension **authors** adopting or operating the toolchain — and the AI
    assistants helping them. It is *not* end-user software; consumers run
    `composer cwm-*` commands during development.

## Install

```bash
composer require --dev cwm/build-tools
composer cwm-init        # scaffolds cwm-build.config.json for your project
```

## Start here

<div class="grid cards" markdown>

- :material-rocket-launch: **[How to use](how-to-use.md)**

    Add it to a project, the profile system, everyday commands, the
    two-config split, troubleshooting.

- :material-language-javascript: **[JavaScript & JoomlaDialog](javascript-and-joomladialog.md)**

    Build ESM modules (`*.es6.mjs`), consume `JoomlaDialog`, and migrate the
    Bootstrap-modal / `confirm()` patterns Joomla 6/7 removes.

</div>

## What it provides

| Area | What |
|---|---|
| **CLI tools** | `cwm-init`, `cwm-setup`, `cwm-link`, `cwm-verify`, `cwm-bump`, `cwm-build`, `cwm-package`, `cwm-release`, `cwm-lint-deprecations`, ARS publish, changelog, sync — see the [command reference](how-to-use.md#3-everyday-commands). |
| **Config templates** | `.gitignore` managed block, ESLint base, `rollup.config.js`, build/CSS scripts, `versions.json` template. |
| **Profiles** | `component` / `library` / `package-wrapper` archetypes that own the `versionTracking` shape. |

## Versioning

`cwm-build-tools` follows semver and is stable as of `v1.0.0`. Projects pin a
major (`^1.5`); fixes and new pipeline steps land as patches and minors.
Breaking schema/CLI changes are reserved for the next major and documented in
the [changelog](https://github.com/Joomla-Bible-Study/cwm-build-tools/blob/main/CHANGELOG.md).

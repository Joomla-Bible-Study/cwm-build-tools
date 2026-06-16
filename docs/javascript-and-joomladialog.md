# JavaScript builds & JoomlaDialog (Joomla 6/7-ready)

How to build modern JavaScript with `cwm/build-tools`, consume Joomla's
JS module APIs (`JoomlaDialog` and friends), and migrate the
Bootstrap-modal / `confirm()` patterns that Joomla 6/7 removes.

> **Audience:** CWM extension authors (Proclaim, lib_cwmscripture,
> CWMScriptureLinks, CWMLivingWord) and AI assistants helping them.
> Pair with [How to use](how-to-use.md) (project setup). Requires
> **cwm/build-tools ≥ 1.5.1**.

---

## 1. Why this exists

Joomla 5 shipped two things that change how extension JS should be written:

- **`JoomlaDialog`** — a modal API delivered as an **ES module**
  (`media/system/js/joomla-dialog.js`, `export { JoomlaDialog as default }`).
  There is **no `window.JoomlaDialog` global**; the only way in is
  `import JoomlaDialog from 'joomla.dialog'`.
- An **import map** (`<script type="importmap">`) that resolves bare
  specifiers like `joomla.dialog` at runtime in the browser.

Joomla **6/7** then removes the legacy bridge that older extensions
relied on: the `bootstrap.modal` JS asset, `data-bs-toggle="modal"`
triggers, iframe modal handlers, the `Joomla.Modal` JS API, and the
bundled jQuery global. Code using those breaks on upgrade.

To consume `JoomlaDialog` (or any Joomla JS module API) **first-class**,
your built file has to be a real ES module that keeps the
`import … from 'joomla.dialog'` statement intact so the import map can
resolve it. An IIFE bundle inlines everything and **cannot** carry a
live external import — which is why this needs build-tool support.

---

## 2. Two output formats, chosen by file suffix

`templates/rollup.config.js` (the shared rollup config every consumer
builds through) picks the output format from the **source filename**:

| Source file        | Output format | Imports                                              | Register as            |
|--------------------|---------------|------------------------------------------------------|------------------------|
| `name.es6.js`      | IIFE          | everything bundled in                                | normal `<script>`      |
| `name.es6.mjs`     | ES module     | bare `joomla.*` left **external** (import map resolves) | `type="module"` asset |

Both compile to a plain `name.js` / `name.min.js` in `OUTPUT_DIR` (the
`.es6` suffix is dropped). **`*.es6.js` is unchanged** from before — every
existing bundle keeps building exactly as it did. Adopting `.es6.mjs` is
purely additive.

Need extra bare specifiers treated as external (e.g. `vue`)? Set the
`MODULE_EXTERNALS` env var (comma-separated) on the build command. The
`joomla.` prefix is always external.

```bash
# package.json — unchanged build line handles both suffixes
"build:js": "SOURCE_DIR=build/media_source/js OUTPUT_DIR=media/js rollup -c vendor/cwm/build-tools/templates/rollup.config.js"
```

---

## 3. Writing an ES module that uses JoomlaDialog

### 3.1 Author the source

Create `build/media_source/js/<name>.es6.mjs`:

```js
import JoomlaDialog from 'joomla.dialog';

// JoomlaDialog ships static, Promise-based helpers (identical in J5 & J6):
//   JoomlaDialog.confirm(message, title) -> Promise<boolean>
//   JoomlaDialog.alert(message, title)   -> Promise<void>
const dialog = new JoomlaDialog({ textHeader: 'Title', popupType: 'inline' /* … */ });
dialog.show();
```

### 3.2 Build it

`composer cwm-build` (or `npm run build:js`) emits `media/.../<name>.js`
and `<name>.min.js`, each keeping `import … from 'joomla.dialog'`.

### 3.3 Register it as a module asset

In `joomla.asset.json`, the asset's **`attributes.type` must be
`module`**:

```jsonc
{
  "name": "com_x.my-feature",
  "type": "script",
  "uri": "com_x/my-feature.min.js",
  "dependencies": ["core"],
  "attributes": { "type": "module" }
}
```

> **URI resolution gotcha (not a bug):** a `uri` of
> `com_x/my-feature.min.js` is served from `media/com_x/js/my-feature.min.js`
> — Joomla's Web Asset Manager inserts the `js/` (or `css/`) segment by
> asset type. So the `uri` omits `js/` even though the built file lives
> under `media/<ext>/js/`. This matches every other script asset; don't
> "correct" it to include `js/`.

---

## 4. The JoomlaDialog bridge pattern

Most existing admin JS is IIFE-bundled and can't `import`. Rather than
convert every module to ESM at once, ship **one** small ES-module
*bridge* that exposes Promise-based helpers on `window`, and let the IIFE
modules adopt them one call site at a time. This is the Proclaim
`cwm-dialog.es6.mjs` pattern:

```js
import JoomlaDialog from 'joomla.dialog';

const cwmConfirm = (message, title) => JoomlaDialog.confirm(message, title);
const cwmAlert   = (message, title) => JoomlaDialog.alert(message, title);

window.cwmConfirm = cwmConfirm;   // Promise<boolean>
window.cwmAlert   = cwmAlert;     // Promise<void>

export { cwmConfirm, cwmAlert };
```

**Wire the dependency.** Every consuming module's asset must depend on
the bridge asset, so the Web Asset Manager actually loads it on the page
(otherwise `window.cwmConfirm` is undefined at click time):

```jsonc
{
  "name": "com_x.some-admin-module",
  "dependencies": ["core", "com_x.cwm-dialog"]
}
```

---

## 5. Migrating `confirm()` / `alert()` call sites

Native `confirm()`/`alert()` are **synchronous**; `JoomlaDialog` is
**asynchronous** (Promise-based). So this is **not** a textual swap — the
enclosing function must become `async` and `await` the result:

```js
// before (synchronous; breaks in J6/7)
btn.addEventListener('click', () => {
    if (!confirm(msg)) return;
    doThing();
});

// after (await the dialog)
btn.addEventListener('click', async () => {
    if (!await window.cwmConfirm(msg)) return;
    doThing();
});
```

`alert(msg)` → `await window.cwmAlert(msg)` (or drop the `await` if you
return immediately after).

### ⚠️ The one pattern you cannot naively migrate

If a handler calls `e.preventDefault()` / `e.stopPropagation()` **based on
the synchronous return of `confirm()`** — typically an unsaved-changes
guard on a `hide.bs.modal` event or a capture-phase click — an async
dialog **cannot cancel the event in time**: the event finishes before the
Promise resolves, so the guard silently stops working.

Leave these on native `confirm()` for now and migrate them as part of the
bootstrap-modal removal (the redesign is: always `preventDefault()`,
`await` the dialog, then programmatically re-trigger the action). Mark
them in-code so they're not mistaken for missed work.

---

## 6. Testing migrated modules (Jest)

The bridge module isn't loaded in unit tests, so define the globals in
your Jest setup — Promise-based, mirroring the real helpers:

```js
// tests/js/setup.js
window.cwmConfirm = jest.fn(() => Promise.resolve(true));  // "Yes" path
window.cwmAlert   = jest.fn(() => Promise.resolve());
```

Update any assertions that referenced `global.confirm` / `global.alert`
to the new globals. To exercise the cancel path in a specific test:
`window.cwmConfirm.mockResolvedValueOnce(false)`.

---

## 7. Guard rails: lint + build verification

### 7.1 `cwm-lint-deprecations` — find J6/7 blockers

Scans the source tree and reports `file:line` for the patterns Joomla 6/7
removes. Exits non-zero so CI can gate on it.

```bash
composer lint-deprecations            # scan, exit 1 on findings
composer lint-deprecations -- --warn  # report but exit 0 (don't fail CI yet)
composer lint-deprecations -- admin/  # scan a subtree
```

| Flagged                | Why                                            |
|------------------------|------------------------------------------------|
| `bootstrap.modal`      | Bootstrap modal JS asset — removed             |
| `data-bs-toggle=modal` | Bootstrap modal trigger markup                 |
| `{handler: 'iframe'}`  | Legacy iframe modal links                      |
| `Joomla.Modal`         | Removed JS modal API → use `JoomlaDialog`      |
| jQuery globals         | Bundled jQuery is going away                   |

`vendor/`, `node_modules/`, `build/`, `dist/`, and `*.min.js` are skipped.

### 7.2 `build.verifyAssets` — fail loudly if an asset didn't build

Opt in per project in `cwm-build.config.json`:

```jsonc
{ "build": { "verifyAssets": true } }
```

After the pre-build step, `cwm-build` fails if any file referenced by a
`joomla.asset.json` (`script`/`style` `uri`) is missing. This catches the
**silent-skip** failure: if your `.es6.mjs` didn't compile (JS build
skipped, or a toolchain older than 1.5 that ignores the `.es6.mjs`
suffix), the asset would 404 at runtime and dependent JS would break for
end users — now it's a build failure instead. CDN/absolute URLs are
skipped; resolution is by basename under the manifest's media dir.

---

## 8. Migration playbook (J5 → J6/7 readiness)

1. **Find the blockers:** `composer lint-deprecations -- --warn`.
2. **Add the bridge:** create `cwm-dialog.es6.mjs` (§4), register it as a
   `type=module` asset, add it to each consuming module's `dependencies`.
3. **Bump the toolchain:** `composer require --dev cwm/build-tools:^1.5.1`.
4. **Migrate call sites:** `confirm()`/`alert()` → `await window.cwmConfirm/cwmAlert`,
   making handlers `async` (§5). Skip the `preventDefault` guards (§5 ⚠️).
5. **Update tests:** mock the bridge globals (§6); run the JS suite.
6. **Turn on the safety net:** `"verifyAssets": true` (§7.2).
7. **Build & verify:** `npm run build:js`; confirm in a running Joomla 6
   admin that the asset loads as `type=module`, `window.cwmConfirm` is
   defined, and a migrated control shows a styled JoomlaDialog (not the
   native browser confirm), with Yes/No behaving correctly.

Tackle the remaining `bootstrap.modal` / `data-bs-toggle` markup and the
deferred `preventDefault` guards in a later pass dedicated to the modal
redesign.

---

## 9. Troubleshooting

- **`window.cwmConfirm is not a function`** — the bridge asset isn't on
  the page. Add `com_x.cwm-dialog` to the consuming module's
  `dependencies` in `joomla.asset.json`.
- **Asset 404s at runtime** — the `.es6.mjs` didn't build. Check the
  toolchain is **≥ 1.5** (older versions silently ignore `.es6.mjs`) and
  that `npm run build:js` ran. Turn on `build.verifyAssets` to catch this
  at build time.
- **`import` appears bundled/inlined instead of external** — the source
  is `.es6.js` (IIFE), not `.es6.mjs`. Rename it.
- **Confirm proceeds even when the user cancels** — a handler wasn't made
  `async`, or you didn't `await` `window.cwmConfirm`, so the truthiness
  check ran against a pending Promise (always truthy).
- **Unsaved-changes guard stopped working after migration** — that's the
  `preventDefault` pattern in §5 ⚠️; it must stay synchronous. Revert it
  to native `confirm()` pending the modal redesign.

---

## 10. Where to dig deeper

- **[How to use](how-to-use.md)** — project setup, profiles, everyday commands.
- **[`CHANGELOG.md`](https://github.com/Joomla-Bible-Study/cwm-build-tools/blob/main/CHANGELOG.md)**
  — v1.5.0 (ESM output + `cwm-lint-deprecations`), v1.5.1 (`verifyAssets`).
- **`templates/rollup.config.js`** — the format-by-suffix logic.
- **Per-command help:** `composer lint-deprecations -- --help`.

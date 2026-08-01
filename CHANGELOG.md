# Changelog

All notable changes to `cwm/build-tools` are documented here.

The format is loosely based on [Keep a Changelog](https://keepachangelog.com/),
and this project adheres to [Semantic Versioning](https://semver.org/).

## [Unreleased]

### Added

- **`cwm-release` gates on `composer test:release` when a project defines it.**
  Every release-blocking defect this tool has shipped — an uninstallable
  package, a migration missing an index, a webservices plugin 500ing on every
  request — looked structurally correct and failed only once actually
  executed. `test:release` is that execution, and it previously ran only when
  someone remembered to run it by hand. It's now a pre-flight step: present in
  a project's `composer.json`, it runs before anything is bumped, built, or
  published, and a failure stops the release. Absent, the release runs exactly
  as before — this doesn't require every CWM project to have a test harness.
  `--skip-tests` releases anyway; `--dry-run` still runs the gate for real,
  since it verifies rather than writes.

## [1.13.2] - 2026-07-30

### Fixed

- **`cwm-release` no longer dies when the version bump produces no diff.** Step 4
  ran `git commit` unconditionally; on a tree already at the target version that
  exits non-zero with "nothing to commit", and `set -e` took the whole release
  down — after the build had run, before anything was tagged, with no sign in the
  output that it was fatal.

  Not an edge case: bumping first, running the release gate against that exact
  build, and only then releasing leaves the version already bumped every time.

  Staged changes are committed exactly as before; an already-bumped tree says so
  and carries on to the tag. Dry-run output is unchanged.

## [1.13.1] - 2026-07-30

### Fixed

- **`cwm-changelog` no longer inserts entries inside the changelog's header
  comment.** Placement was `content.find('<changelogs>')`, which matches the
  first occurrence anywhere in the file — and these changelog files carry a
  header comment explaining what the root element is, so the first occurrence
  was usually inside that comment.

  Two failure modes, both silent, because the script reported success either way
  and a changelog that will not parse looks to Joomla exactly like one that is
  empty:

  - The entry is swallowed by the comment. The file still parses; the release
    simply has no changelog entry.
  - The entry's own version-banner comment lines close the enclosing comment at
    their first terminator, and the rest of the document becomes garbage. This
    is what `generate-changelog-entry.sh` emits, so it is the common case.

  Occurrences inside XML comments are now skipped. A root carrying attributes,
  and a root on the final line with no trailing newline, are handled too.

  Placement moved out of the inline heredoc into `scripts/changelog-insert.py`
  so the rule is covered by `tests/python`, alongside the existing
  sync-languages tests (Joomla-Bible-Study/CWMLivingWord#88).

## [1.13.0] - 2026-07-30

### Added

- **`versionTracking.sourceFiles` — cwm-bump rewrites version literals held in
  source.** For a hardcoded constant shipping beside the manifest
  (`public const VERSION = '1.2.3';`) that nothing in the toolchain wrote, so it
  drifted. lib_cwmscripture's `LibraryVersion::VERSION` sat a release behind its
  manifest while `satisfies()` and `needsUpgrade()` read it, telling downstream
  extensions the library was older than it was
  (Joomla-Bible-Study/lib_cwmscripture#15).

  Configured as literal lines with a `{version}` placeholder rather than regexes,
  so an author pastes the line they can see and cannot accidentally turn `$` or
  `(` into syntax. The placeholder accepts pre-release and build suffixes and is
  anchored to digits, so version-shaped text on unmatched lines — `@since` tags,
  unrelated constants — is untouched.

  A pattern that matches nothing throws instead of warning, as do a missing file,
  a missing placeholder and a malformed entry: a rewrite that silently does
  nothing is how the drift happens, so it has to stop the bump.

## [1.12.0] - 2026-07-30

### Added

- **`build.verifyMediaSources` — fail the build on output that outlived its
  source.** `verifyAssets` catches an asset the manifest references that the
  build never produced; this catches the inverse, a file in `media/` no source
  can reproduce. Configured as `{ source, output }` directory pairs, opt-in per
  project.

  lib_cwmscripture shipped `media/lib_cwmscripture/js/translations-manager.min.js`
  (plus its `.gz` and `.map`) in every release for months after the source was
  renamed to `bible-translations.es6.js`. Minified output is gitignored, so no
  checkout, branch switch or pull ever removed it, and the packager ships whatever
  is in `media/`. The published v1.1.6 zip contained 90 files where a fresh build
  of the same tag produced 87.

  Nothing referenced the stale files, so there was no error to notice — it
  surfaced only by comparing the published asset against a build from another
  checkout. The real damage is that the release artifact stops being a function of
  the source and becomes a function of the source *plus that machine's build
  history*: two developers on the same tag produce different packages, and the
  library inside `pkg_proclaim` differed from the published library of the same
  version. Re-publishing a corrected artifact then invalidates the checksums the
  update server recorded at publish time.

  Matching handles every derived form (`.min`, `.map`, `.gz`, `.es6`/`.esm`
  sources), ignores non-build files such as `joomla.asset.json` and `index.html`,
  and checks only the top level of each output dir — subdirectories are usually
  copied third-party payloads whose layout has no relationship to `media_source`.

## [1.11.0] - 2026-07-29

### Added

- **`run-lint-syntax` and `php-extensions` inputs on both reusable workflows.**
  `run-lint` gated the syntax check and PHP CS Fixer together, which assumed
  every project runs both. CWMLivingWord runs only `lint:syntax` — it has style
  debt it has not paid down — so migrating it would have newly enabled the
  fixer and failed CI on unrelated formatting. The two are now independent, and
  the setup-php extension list is configurable rather than fixed.

### Fixed

- **The build steps now pass `GITHUB_TOKEN`.** A `preBuild` hook that pulls
  prebuilt member zips from the GitHub releases API (CWMLivingWord fetches
  three scripture zips this way) was running unauthenticated: 60 requests an
  hour shared across every job on the runner's IP. That is a flaky-CI source
  rather than a hypothetical, and the workflow a project migrates *to* should
  not be worse at this than the one it migrates *from*.

## [1.10.0] - 2026-07-29

### Added

- **`cwm-release --dry-run`.** Walks all nine steps and writes nothing: no
  bump, build, commit, tag, push, GitHub release, ARS publish or announcement
  article. The read-only checks still run, so a malformed version, a stale zip
  the artifact glob would pick up, or divergence from origin all surface before
  anything is irreversible. The branch and clean-tree pre-conditions are
  reported as warnings rather than errors, since inspecting a release before
  tidying up is the normal reason to ask. Parsing and the command wrapper live
  in `scripts/lib/dryrun.sh` where the shell suite exercises them — the case
  that matters being that a described command really does not run.

- **`joomla-library-ci.yml`**, a reusable workflow for repositories that are a
  single library rather than a package. It installs npm dependencies and builds
  the media assets *after* `composer install` (the shared rollup and build-css
  templates live in `vendor/cwm/build-tools`) and *before* packaging, which is
  the ordering `lib_cwmscripture` needs and the package workflow does not
  provide.

- **`.editorconfig`, `.php-cs-fixer.base.php` and `phpunit.xml` templates**,
  with `cwm-sync-configs` handlers for the first two — the entries that had sat
  under a `Future:` heading in `sync-configs.php`. Neither handler will
  overwrite a file the consumer owns: `.editorconfig` is replaced only when it
  carries the managed-file header this tool stamps, and `.php-cs-fixer.dist.php`
  only while it is still the generated one-line wrapper. A wrapper that has
  grown a `setRules` or an `exclude` holds project decisions and is left alone.
  The shared `.editorconfig` matches the four-space PHP the CWM extensions
  already used, so adopting it is a no-op rather than a reformat.

### Changed

- **`src/Release/ArsPublisher.php` is a real implementation, and
  `ars-publish.sh` now delegates to it.** It was a stub that threw, and the
  publish logic lived in a curl pipeline that could only be exercised by
  publishing something. The create-or-update decisions — which ship a duplicate
  release when a lookup wrongly misses (#37), or PATCH over a different one
  when it wrongly hits — now run in PHP against canned responses in
  `tests/Release/ArsPublisherTest.php`. The shell keeps the work that is
  genuinely shell work: config, 1Password, `gh`, and rendering the notes. The
  two lookups it used to own are gone rather than left behind as a second
  implementation.

  Vetted against akeeba/release-system 7.5.0 (`development`; `main` is still
  the Joomla 3 layout and has no `component/api`). Three findings changed the
  code:

  - **A 403 is no longer read as "no such release".** 7.5.0 added
    `AssertApiAccess`: every list and read requires `core.manage` on com_ars,
    and create/edit are gated per-category. A token that published fine against
    7.4.x can start returning 403 after the site upgrades, and treating that as
    an empty result set would publish a duplicate. Any non-2xx read is now a
    hard error, and 401/403 says which permissions to check.
  - **`maturity` is validated before sending.** `ReleaseTable::check()` rewrites
    anything outside alpha/beta/rc/stable to `beta` silently, and Joomla then
    hides the update from sites that have not opted into pre-release updates.
  - **Release notes must be HTML.** ARS renders the field verbatim; Proclaim
    10.3.6's download page shipped with literal `##` and `**` and the changelog
    on one line. Obvious unrendered Markdown is now refused with a pointer to
    `scripts/render-notes.php`.

- **The sub-library step in `joomla-package-ci.yml` prefers
  `composer build:package`.** It only looked for `build/build-package.php`,
  which the libraries deleted when they moved packaging onto `cwm-build` — so
  the step silently did nothing and left the outer build to fail on a missing
  include. A project with neither is now an error rather than a skip.

### Fixed

- **The documented workflow reference `@v1` never resolved.** Both reusable
  workflows advertised it and no such tag existed, so any project that followed
  the README would have failed to start CI at all. `v1` is now a real moving
  tag, re-pointed at each release, which is the CI equivalent of the `^1.0`
  Composer constraint consumers already use — and re-pointing it is now step 6
  of the release flow in CLAUDE.md. Skipping that step leaves every consumer
  running the previous release's pipeline silently, so it is not optional.
  (The README's `@v1.0.0` did resolve, but to a year-old pipeline.)

## [1.9.1] - 2026-07-27

### Fixed

- **`cwm-ars-publish` refuses to publish without `ars.environments`.** (#58)

  `read_config_json` yields the literal `null` when the key is absent, and the
  ARS item payload shipped it verbatim. ARS then derives the update XML's
  requirements from an item with no environments — `php_minimum` 8.5 and a
  Joomla 6.1+/7-only `targetplatform` — so the update is invisible on
  Joomla 5 and blocked everywhere else, with every publish step reporting
  success. Proclaim 10.3.4–10.4.0 all shipped this way and were repaired by
  PATCHing the live items. `ars.environments` is now documented as required
  (a non-empty JSON array of ARS environment ids) and validated by
  `cwm_ars_validate_environments` in `scripts/lib/ars.sh` before any API
  call; the error points at a known-good item's `environments` attribute as
  the place to copy ids from, since the ARS `/environments` endpoint 404s.

## [1.9.0] - 2026-07-27

### Fixed

- **`cwm-release` now selects the build artifact by version, not by glob
  order.** (#51)

  The output glob is version-agnostic, and bash expands globs lexically, so
  releasing alongside a stale artifact picked whatever sorted first — Proclaim
  10.3.3 shipped `pkg_proclaim-10.3.2.zip` as both its GitHub asset and its ARS
  item this way, with every step reporting success. The version being released
  is already known, so the artifact is matched as `*-<version>.zip`; no match
  and ambiguous matches are loud errors rather than guesses. The selection
  lives in `scripts/lib/artifacts.sh` where the shell test suite exercises it,
  including the 10.3.3 scenario verbatim.

- **`npm outdated` results were silently discarded, so dev dependency updates
  were never reported.** `npm outdated` exits 1 when anything *is* outdated,
  and `runFile()` treated any non-zero exit as failure and returned an empty
  string. The Dev Dependencies table therefore printed `(all packages) ✓ OK`
  regardless of actual state — a false clean bill of health. Verified against
  Proclaim, which reported "OK" while carrying 11 outdated dev dependencies.

  `runFile()` gains an `allowFailure` option that recovers stdout from the
  thrown error, for the several tools that use a non-zero exit to signal
  findings rather than failure (`npm outdated`, `npm audit`, `composer audit`).

### Added

- **This repository now tests itself.** (#46, #47) `tests.yml` runs
  `composer test` (PHPUnit + the Python suite) on PHP 8.3 and 8.4 for every
  push and pull request, plus syntax checks over every PHP, shell and Python
  file. Before this, every PR merged with "no checks reported" — and the first
  CI run promptly caught a fixture that had been broken on clean checkouts
  since May.

- **Script decision logic extracted into tested classes.** (#32, #48, #49, #50)
  `Dev\LinkPlanner` (cwm-link's role filter — the v1.6.1 defect, now pinned by
  a test that fails if it is reintroduced), `Build\ManifestVersionWriter`
  (bump's manifest rewriting; first-`<version>`-only behaviour pinned), and
  `Config\ManagedBlock` / `Config\GitignorePaths` (sync-configs' `.gitignore`
  handling; idempotence verified byte-for-byte). Suite grew from 247 to 306
  PHP tests.

- **Shell behavioural tests and shellcheck.** (#52, #53) `scripts/lib/` holds
  sourceable functions the entry-point scripts call, `tests/shell/` exercises
  them, and CI runs both plus `shellcheck -S warning` across `scripts/`.

- **Spellcheck in CI.** (#54) codespell over docs, comments and templates,
  with each ignore named and justified in the workflow.


- **`vendor-check.js` now audits for security advisories.** A new *Security
  Advisories* section runs `composer audit` and `npm audit`, reporting package,
  severity, advisory ID and summary. Advisory IDs are displayed as GHSA where
  available (Composer's native `advisoryId` is a `PKSA-*`, but the GHSA in
  `sources[].remoteId` is what Dependabot and GitHub show).

  Audits run against the **lock file** (`composer audit --locked`) rather than
  the installed tree. Plain `composer audit` inspects `vendor/`, so a project
  that has not been installed reports `No packages - skipping audit` and exits
  0 — a silent false clean. The lock is also the honest record of what a
  project ships.

- **Nested Composer projects are now discovered and checked.** An extension may
  bundle its own Composer project with a committed `vendor/` tree that ships to
  end users. Those dependencies are invisible to a root-level `composer
  outdated`, so a vulnerable bundled package can ship indefinitely unnoticed.
  Auto-discovery walks the repo (skipping `vendor`, `node_modules`, `media`,
  `dist` and dotfiles) and the *PHP Dependencies* table gains a `Scope` column.

- **New optional `security` block in `cwm-build.config.json`** — `scanNested`,
  `nestedPaths[]`, `maxDepth`, `ignore[]`. All have working defaults; existing
  configs need no changes. `ignore[]` matches GHSA, PKSA or CVE identifiers.

- **New exit code 2** for "security advisories found *or* security status
  unverified", taking precedence over exit 1 ("updates available"). Exit 0
  still means clean. Callers that only test for non-zero are unaffected.

- **The security check fails closed.** A scope whose audit could not run —
  `composer` missing from `PATH`, a timeout, a broken lock — is reported as
  unverified and exits 2 rather than passing silently. Without this the section
  had the exact flaw it was written to remove: with `composer` unavailable, a
  tree carrying four known advisories reported *"All checked packages are up to
  date, with no known advisories"* and exit 0. "We found nothing" and "we did
  not look" are different answers. Scopes with no manifest to audit are skipped
  rather than failed.


## [1.8.3] - 2026-07-26

### Fixed

- **`cwm-sync-languages` no longer writes a translation that changed a
  placeholder.** (#43, #44)

  `translate_text` and `translate_batch` posted the whole string to Google
  Translate with nothing protecting the parts that must not change. Joomla
  language values carry `{placeholder}` tokens, inline HTML and `%s`-style
  specifiers, and all three were translated as ordinary words:

      en-GB  User <a href='{accountlink}'>{username}</a> updated {type} ...
      nl-NL  Gebruiker <a href='{accountlink}'>{gebruikersnaam}> heeft {type} ...

  `{username}` became `{gebruikersnaam}`, which never matches the substitution
  Joomla performs when rendering a log row, so the literal token reached the
  user. hu-HU lost a closing brace, giving the unmatchable `{username</a>`;
  cs-CZ closed anchors with `<a>`, which also survives the `str_replace('</a>')`
  Joomla uses to strip links from notification emails. Twenty-one strings across
  three languages shipped this way — all valid INI, all silently wrong, with no
  build step that would notice.

  Two defences now apply. `mask_protected`/`unmask_protected` swap the protected
  fragments for sentinels around the API call, restoring them highest-index-first
  so `ZQX1ZQX` is not mistaken for part of `ZQX10ZQX`, and tolerating the case
  changes and injected spaces engines introduce. `translation_is_safe` then
  compares placeholders and specifiers as sorted multisets and anchors by count:
  reordering for grammar passes, renaming, dropping, duplicating or unbalancing
  does not. **A rejected translation falls back to the English source** — Joomla
  already handles that per key, and an untranslated string is strictly better
  than one whose placeholders no longer resolve.

  Masking covers any `%<letter>`, not only the specifiers `sprintf` understands.
  Real files also use `%d`, `%1$d`, `%%` and `%t`, the last filled in by the
  component rather than by `sprintf`, as in `"Migrating %d of %t files..."`.

### Added

- **Python tests, run from `composer test`.** `composer test` now runs
  `test:php` (PHPUnit) and `test:python` (stdlib `unittest`, no new dependency).
  The 25 new tests are driven by corruption actually observed rather than
  invented, and the fix was additionally validated by round-tripping every real
  string in Proclaim — 29,273 across 159 language files — which is what caught a
  false rejection of legitimately empty values (`KEY=""`).

  A first foothold against #32, which notes `scripts/` has no coverage at all.

## [1.8.2] - 2026-07-26

### Fixed

- **`cwm-release` now stages only the file each step produces**, instead of
  committing whatever happens to be in the working tree. (#38, #41)

  Three of the four `git add -A` calls were paired with a `git diff --quiet`
  guard, and the two disagree: the guard inspects only tracked files while the
  action stages everything, untracked included. The question asked was "did a
  tracked file change?"; the answer acted on was "commit the entire working
  tree".

  Steps 6 and 8 run after the release is pushed, so anything left lying around —
  a scratch script, a downloaded artifact, a dumped token — was committed and
  pushed. Step 6 force-moves the tag onto its commit, so strays landed in the
  published tag. Step 8 is worse: `git stash` does not take untracked files, so
  they follow the checkout onto the development branch and are committed there.

  Each site now stages exactly what its step writes — the configured
  `changelog.file`, and the resolved `versionsJson` path, which is the only file
  `VersionTracker::updateForRelease` touches.

  The guard is now `git status --porcelain -- <path>` rather than
  `git diff --quiet -- <path>`: the latter reports no change for a file that is
  new and untracked, so a changelog created rather than edited would have been
  silently skipped.

  Step 4 keeps `git add -A`, where it is safe — the step 1 pre-check uses
  `git status --porcelain`, which covers untracked files, so the tree was empty
  of them before the run began. It now lists what it is about to add, since
  "produced by the build" is an inference spanning three steps.

## [1.8.1] - 2026-07-26

### Fixed

- **`cwm-ars-publish` now uses the query parameters ARS actually reads**, so it
  reliably finds an existing release or download item instead of publishing a
  duplicate. (#37, #39)

  Both create-vs-update lookups sent JSON:API `filter[...]` syntax. ARS reads
  bare input keys — `ReleasesController::displayList` and
  `ItemsController::displayList` map `category_id`, `search` and `release_id`
  onto their filter state. Sent as `filter[category_id]` the value arrives as a
  PHP array named `filter`, the lookup returns null, and the filter is silently
  not applied.

  That was load-bearing, because the response is also capped at 20 rows by
  default. Both lookups were matching against an arbitrary 20-row window of
  every release and item on the site, ordered by neither id, version nor date —
  on christianwebministries.org the item lookup returned 20 rows spanning 19
  different releases. A miss takes the create branch and publishes a second
  release, or a second download item, reporting success either way. The risk
  grew with every release added.

  With the bare names both queries return exactly one row. `page[limit]` is
  passed as well, because the 20-row cap is real and independent of the
  parameter bug (`list[limit]` is not honoured). The client-side exact match on
  version is kept: `search` is a LIKE, so `10.3.1` could in principle also match
  a future `10.3.10`.

  Also corrects the note in `ars-list.sh` that blamed "this ARS install" for
  ignoring the category filter. It was the parameter name, not the build.

## [1.8.0] - 2026-07-26

### Fixed

- **Release notes are now converted to HTML before they are published to ARS.**
  ARS stores a release's `notes` as an HTML fragment and echoes it into the
  public download page without any Markdown processing, but `ars-publish.sh`
  was posting the GitHub release body verbatim — and that body is Markdown. The
  published Proclaim 10.3.6 page therefore read

      ## What's Changed * fix(api): make the API switchable ... **Full
      Changelog**: https://...

  with every marker literal and the whole changelog collapsed onto one line,
  because HTML folds newlines into spaces. That fragment is the only changelog
  a site administrator following a Joomla update link ever sees.

  Conversion is handled by the new `Release\ReleaseNotesFormatter` — a small,
  dependency-free subset covering what release notes actually contain
  (headings, lists, emphasis, code spans, Markdown and bare links) rather than
  a Markdown engine, because this runs mid-release. Source text is escaped
  before any markup is added, so a GitHub release body cannot inject HTML into
  the site, and links are stashed before emphasis is applied so a URL
  containing underscores is not mangled into `<em>`. Notes are treated as
  Markdown: wrapped lines are joined, and only a blank line starts a new block
  or ends a list.

  `scripts/render-notes.php` exposes the same conversion as a filter
  (Markdown on stdin, HTML on stdout).

### Added

- **`release.notesFile` — hand-written release notes, used by both surfaces.**
  What GitHub generates is a list of pull request titles: accurate, written for
  the maintainers, and close to useless to someone deciding whether to update.
  Point `release.notesFile` at a path with a `{version}` placeholder (e.g.
  `build/release-notes-{version}.md`) and `cwm-release` will lead the release
  notes with that file, keeping the generated list beneath it, so the GitHub
  release and the ARS download page carry the same text.

- **`cwm-ars-publish -n <file>`** (or `ARS_NOTES_FILE`) publishes notes on their
  own, without a full release — which is also how an already-published release
  gets its notes corrected, since the publisher PATCHes an existing entry.

  Both are optional. With neither configured, behaviour is unchanged apart from
  the notes now being valid HTML.

## [1.7.0] - 2026-07-26

### Changed

- **`build.properties.tmpl` now ships two install examples instead of four**, one
  of each role, with a note that consumers should add as many as they need — one
  per Joomla version supported, or several of the same version. Four examples
  (two dev, two test) read as a fixed set to reproduce rather than a pattern to
  copy, and left every consumer carrying blocks for installs they do not run.
  Ids are arbitrary labels; nothing keys off the name.

- **The role documentation now leads with symlinked vs not**, because that is the
  consequential difference and it was buried in a three-line aside. `role = dev`
  is symlinked at the working repo; `role = test` is a real file-backed install
  that the release harness wipes and reinstalls. The warning against pointing a
  `role = test` install at a symlink is stated in the roles block rather than on
  one install example, and names both existing backstops (v1.6.1 `cwm-link`
  filtering, v1.6.2 reset-harness link stripping) as backstops rather than
  permission.

## [1.6.2] - 2026-07-25

### Fixed

- **`cwm-sync-configs` now guards `build.dist.properties` against leaked credentials
  and silent data loss.** That file is committed by every consumer while per-machine
  values belong in the gitignored `build.properties` — the two names differ by four
  characters, and the wrong one gets edited. Because sync overwrites the consumer's
  copy from the template, it also quietly disposed of the evidence: credentials
  vanished from the working tree while remaining in any commit that had already
  captured them.

  Three checks now run before the write, via the new
  `Config\DistPropertiesInspector`:

  - **Refuses to sync** if the cwm-build-tools template itself carries populated
    credential values — that would propagate to every consumer.
  - **Warns** when the consumer's existing file has real values in credential keys,
    naming them and pointing at `git log -p -- build.dist.properties` so the
    developer can check whether they reached history.
  - **Reports keys about to be removed** because the template lacks them. Usually
    stale hand-edits, but occasionally the consumer is ahead of the shared schema
    and silently deleting that is unhelpful. Found in practice: Proclaim's 11
    `builder.j6-test.*` keys would have been dropped without a word.

  Matching is on key name (`db_user`, `db_pass`, `db_name`, `password`, `secret`,
  `token`, `api_key`) rather than value, since local credentials rarely look
  distinctive. The template's documented `admin_pass = admin` placeholder is
  deliberately outside the pattern.

## [1.6.1] - 2026-07-25

### Fixed

- **`cwm-link` no longer symlinks `role=test` installs (data-loss hazard).**
  `scripts/link.php` selected every configured install via
  `PropertiesReader::installs()` instead of filtering to `role=dev`, so
  `composer symlink` linked test installs too. That contradicts the documented
  contract in `InstallConfig` — `role=dev` is *the* symlink target, while a
  `role=test` install is a real install target for the built zip.

  The consequence was severe. A linked test site points its extension
  directories back at the consumer's working repo, and reset/teardown harnesses
  delete those directories to get a clean slate. Because `is_dir()` returns true
  for a symlink pointing at a directory, such a harness walks *through* the link
  and empties its target — deleting repo source. This was found in Proclaim with
  12 links present on `j6-test` (caught before any harness ran; no data lost).

  `scripts/link-check.php` had the same unfiltered selection, reporting every
  expected link as `MISSING` on a deliberately file-backed test install.
  `scripts/install-zip.php` was already correct (`ROLE_TEST`), and
  `scripts/clean.php` is safe either way since `Linker::unlink()` guards on
  `is_link()` — leaving it unfiltered usefully strips stray links.

  Consumers with a recursive-delete helper should also ensure it checks
  `is_link()` on the path it is *given*, not only on that path's children.

## [1.5.1] - 2026-06-16

### Added (non-breaking, opt-in)

- **`cwm-build` asset-reference verification (`build.verifyAssets`).** When
  `build.verifyAssets: true` is set in `cwm-build.config.json`, `cwm-build`
  now fails — after the pre-build step, before zipping — if any file
  referenced by a `joomla.asset.json` (`type: script`/`style` with a local
  `uri`) is absent from the source tree. This closes the silent-skip gap from
  v1.5.0: if a `*.es6.mjs` module didn't build (JS build skipped, or an older
  toolchain that ignores the `.es6.mjs` suffix), the asset would 404 at
  runtime and any dependent JS would break for end users — now it's a loud
  build failure instead. Resolution is by basename under the manifest's media
  directory (a `uri` like `com_proclaim/foo.min.js` is served from a media
  root that doesn't mirror the repo's `media/js/` layout); CDN / absolute
  URLs are skipped; only manifests that would actually ship are checked.
  Defaults to `false`, so existing consumers are unaffected until they opt in.

## [1.5.0] - 2026-06-15

### Added (non-breaking)

- **ESM output from the shared Rollup config — unblocks first-class
  `JoomlaDialog`.** `templates/rollup.config.js` now chooses output format
  by source suffix: `*.es6.js` still emits an IIFE bundle (unchanged — every
  existing consumer keeps building exactly as before), while a new
  `*.es6.mjs` source emits an ES module (`format: 'es'`). In module builds,
  bare `joomla.*` specifiers (e.g. `import JoomlaDialog from 'joomla.dialog'`)
  are marked **external** so the import survives to the browser and Joomla's
  import-map resolves it at runtime — an IIFE bundle inlines everything and
  structurally cannot carry a live external ESM import, which is why
  consuming `JoomlaDialog` (or any Joomla JS module API) was previously
  impossible without hand-writing an unbundled module. Register the built
  file as a `type="module"` asset in `joomla.asset.json`. Extra externals
  (e.g. `vue`) can be added via the `MODULE_EXTERNALS` env var.

- **`cwm-lint-deprecations` — flags Joomla 6/7 upgrade blockers in source.**
  New CI-gating scanner (`composer lint-deprecations`) that walks the project
  tree and reports `file:line` for `bootstrap.modal` assets,
  `data-bs-toggle="modal"` markup, legacy `{handler: 'iframe'}` modal links,
  the removed `Joomla.Modal` JS API, and jQuery globals. Exits non-zero on
  any finding (or `--warn` to report without failing). `vendor/`,
  `node_modules/`, `build/`, `dist/` and `*.min.js` are skipped. `cwm-init`
  now offers to wire the `lint-deprecations` script into consumers.

### Migration

- No action required for existing builds. To adopt `JoomlaDialog`: name the
  source `<thing>.es6.mjs`, `import JoomlaDialog from 'joomla.dialog'`, and
  register the built `<thing>.js` as `"type": "module"` in
  `joomla.asset.json`. Run `composer lint-deprecations` to find remaining
  Bootstrap-modal usages to migrate.

## [1.4.1] - 2026-06-04

### Fixed

- **Component media now links from `media/<name>` when assets are
  namespaced.** The dev linker always sourced a component's media from
  `<root>/media`, which only fits the `<media folder="media">` layout
  (assets directly under `media/`, e.g. Proclaim). For the
  `<media folder="media/com_x">` layout (assets under `media/com_x/`,
  e.g. `com_cwmconnect`, `com_livingword`), `cwm-link` pointed the
  install's `media/<name>` symlink one level too high — the component
  CSS/JS 404'd and `cwm-verify` flagged the *correct* symlink as a
  conflict. The resolver now prefers `media/<name>` when that subdir
  exists and falls back to `media/` otherwise (the same `is_dir()`
  convention the module derivation already used). Targets are unchanged;
  only the source path is corrected.

## [1.4.0] - 2026-05-15

### Changed (non-breaking — both formats still parse)

- **`build.properties` switched from INI sections to flat keys.** Every
  field is now a globally unique key (e.g. `builder.j5.role`,
  `paths.cwm/sibling`) so Java-properties-aware IDEs (PhpStorm,
  IntelliJ) don't flag "duplicate property key" warnings on `role`,
  `path`, `db_host`, etc. across sections. Same content, same semantics,
  zero IDE noise.

  New canonical shape:
  ```properties
  joomla.version = 5.4.2
  builder.installs = j5, j6, j5-test

  builder.j5.role        = dev
  builder.j5.path        = /path/to/joomla5
  builder.j5.url         = https://j5-dev.local
  builder.j5.version     = 5.4.2
  builder.j5.db_host     = localhost
  builder.j5.admin_email = admin@example.com
  # ... etc

  builder.j5-test.role = test
  builder.j5-test.path = /path/to/joomla5-test

  paths.joomla-bible-study/lib-cwmscripture = /Users/you/GitHub/lib_cwmscripture
  ```

- Comment marker switched from `;` to `#` to satisfy Java-properties
  parsers too. PHP's `parse_ini_string` accepts both via
  `PropertiesReader`'s pre-strip step.

### Internal

- `PropertiesReader::fromLegacyFlat()` renamed to `fromFlat()` (with the
  old name retained as a private alias for any external caller still
  using it). The "flat" format is the canonical v1.4 shape, not legacy.
  - Extended to honor explicit `builder.installs = ...` lists.
  - Auto-discovers ids from `builder.<id>.path` keys (preferred) before
    falling back to `builder.<id>.url` (Proclaim legacy).
  - Parses `builder.<id>.role`, `builder.<id>.version`, and modern
    `admin_user/pass/email` key names alongside Proclaim's legacy
    `username/password/email`.
- `PropertiesReader::paths()` now reads flat `paths.<package>` keys
  (preferred). Still falls back to the `[paths]` INI section for any
  existing v1.2/v1.3 files.
- `PropertiesReader::write()` and `writePaths()` now emit flat keys.
- `PropertiesReader::fromSections()` retained as a reader-only path for
  backward compatibility with any developer's existing INI-style
  `build.properties` from v1.0–v1.3. Next `composer setup` rewrites
  the file in the new flat shape.

### Migration

Per consumer:
1. `composer require --dev cwm/build-tools:^1.4`
2. `composer cwm-sync-configs` refreshes `build.dist.properties` to
   the new flat-key template.
3. Developers with an existing INI `build.properties` keep working
   thanks to `fromSections()` backward-compat. Running `composer
   setup` will migrate the file to the flat shape on next save.

## [1.3.0] - 2026-05-15

### Added

- **`cwm-verify` detects and rebuilds stale `manifest_cache`.** Each row in
  `#__extensions` carries a `manifest_cache` JSON column that Joomla
  populates on install and update. When an extension's install
  scriptfile `update()` doesn't run cleanly, that column drifts away
  from the source manifest XML — visible to users as
  `mb_strtolower(null)` deprecation warnings in Joomla 6's manage view.
  - `ExtensionVerifier::parseManifestXml()` reproduces Joomla's
    `Installer::parseXMLInstallFile()` shape so cwm-verify can build
    the canonical cache JSON.
  - `ExtensionVerifier::compareManifestCache()` checks the four fields
    the manage view actually reads (`name`, `version`, `description`,
    `author`) — other fields are informational and not worth surfacing
    as drift.
  - `cwm-verify --target test` now reports `STALE: <name> manifest_cache
    — version: 'old' → 'new'` per drifted extension.
  - `cwm-verify --target test --fix` UPDATEs the row with rebuilt JSON
    directly — no extension reinstall required.
  - Applies to self extensions AND CWM dep extensions installed via
    path repositories (`expectedFromPackages()` resolves the source
    manifest from each dep's joomlaLinks tuple).
- **`cwm-sync-configs` distributes `build.dist.properties` to consumers.**
  Each consuming project gets an auto-managed copy of cwm-build-tools'
  `templates/build.properties.tmpl`, headed by a marker comment telling
  developers to edit the upstream template rather than the local file.
  Schema changes to `build.properties` (the `role` field added in
  v1.1.0, the `[paths]` block added in v1.2.0) now propagate to every
  consumer on the next `composer cwm-sync-configs` run rather than
  silently drifting.

### Migration

Per-repo:
1. `composer require --dev cwm/build-tools:^1.3`
2. `composer cwm-sync-configs` — refreshes `build.dist.properties`
   from the upstream template (committed change, review the diff).
3. Optionally `composer cwm-verify --target test --fix` against a
   live install with stale extension caches.

## [1.2.2] - 2026-05-15

### Fixed

- **`DevTargetVerifier::satisfies()` now understands Composer stability
  qualifiers.** Constraints like `@dev`, `*@dev`, and bare `*` are stability
  / wildcard expressions, not semver ranges; the previous implementation
  fell through to literal-match and reported `out of range` for every
  path-repo dep pinned with `@dev` (which is the common shape for CWM
  sibling deps). Now treats `*` / `@dev` / `*@dev` as any-version,
  strips trailing `@<stability>` qualifiers from caret/tilde constraints,
  and matches `dev-<branch>` literally against the installed version.

## [1.2.1] - 2026-05-15

### Fixed

- **`InstalledPackageReader` now honors `composer.json`'s
  `config.vendor-dir` override.** Consumers with a non-default vendor
  directory (e.g. CWMLivingWord and Proclaim both ship with
  `"vendor-dir": "libraries/vendor"`) had the cross-package machinery
  silently degrade to zero deps — `installed.json` was being looked up
  at `<project>/vendor/composer/installed.json` regardless of the
  configured location. `cwm-link` and `cwm-verify --target dev` now
  resolve `installed.json` against the actual vendor dir, so the CWM
  dependencies section appears as designed.

## [1.2.0] - 2026-05-15

### Added

- **`dev.cwmSiblings` declaration in `cwm-build.config.json`** — a project
  now declares the list of CWM sibling packages it expects to find as
  Composer path repositories:
  ```json
  {
    "dev": {
      "cwmSiblings": [
        "joomla-bible-study/lib-cwmscripture",
        "cwm/scripture-links"
      ]
    }
  }
  ```
  This is the project-level "I depend on these siblings via local
  checkouts" declaration — committed, shared across all developers.
- **`[paths]` block in `build.properties`** — per-developer mapping
  from each declared CWM sibling to its absolute path on the local
  machine:
  ```ini
  [paths]
  joomla-bible-study/lib-cwmscripture = /Users/brent/GitHub/lib_cwmscripture
  cwm/scripture-links                 = /Users/brent/GitHub/CWMScriptureLinks
  ```
  Gitignored (per-developer). Companion to the existing per-install
  configuration sections.
  - `PropertiesReader::paths(): array<string, string>` reads the block.
  - `PropertiesReader::writePaths(array $paths)` rewrites it while
    preserving every install section.
  - `PropertiesReader::write()` reciprocally preserves any existing
    `[paths]` block when re-emitting installs — neither path can
    accidentally drop the other's state.
- **`cwm-setup` CWM-siblings flow** — after the Joomla install
  configuration step, the wizard now:
  1. Reads `dev.cwmSiblings` from `cwm-build.config.json`.
  2. For each sibling, surfaces the existing `[paths]` entry,
     or auto-detects a sibling checkout at `../<name>` next to
     the project root.
  3. Prompts to confirm or change the path per sibling.
  4. Writes the resulting paths to `build.properties [paths]`.
  5. Synchronises `composer.json`'s `repositories[]` block so each
     declared sibling has a matching `{"type":"path","url":"..."}`
     entry pointing at the developer's local checkout.

  Subsequent `composer install` / `composer update` picks up the
  configured paths automatically — no hand-editing of `composer.json`.

### Changed (non-breaking)

- `PropertiesReader::fromSections()` now excludes `[paths]` from the
  install auto-discovery branch (when `installs =` is omitted), so
  a paths-only or paths-plus-sections file parses correctly.

### Migration

Consumers using v1.1.0 path repositories (e.g. CWMLivingWord) can
adopt the new schema by:

1. Adding `dev.cwmSiblings` to `cwm-build.config.json` listing each
   Composer package consumed via a local path repo.
2. Running `composer setup` — the wizard prompts for each sibling's
   local path, writes them to `build.properties`, and rewrites
   `composer.json`'s `repositories[]` block accordingly.
3. Re-running `composer install`.

## [1.1.0] - 2026-05-15

### Added

- **Cross-component link awareness via `extra.cwm-build-tools.joomlaLinks`.**
  Each CWM Composer package can now declare its Joomla install footprint
  in its own `composer.json`:
  ```json
  {
    "extra": {
      "cwm-build-tools": {
        "joomlaLinks": [
          { "type": "library",  "name": "cwmscripturelinks" },
          { "type": "plugin",   "group": "content", "element": "cwmsl_autolink" },
          { "type": "module",   "name": "mod_cwm_widget", "client": "site" },
          { "type": "component","name": "com_cwmthing" }
        ]
      }
    }
  }
  ```
  Consumers' `cwm-link` and `cwm-verify` discover these declarations via
  `vendor/composer/installed.json` and operate on them automatically —
  no per-consumer wiring required.
  - New `CWM\BuildTools\Config\InstalledPackageReader` parses
    `installed.json` directly (Laravel-style — `\Composer\InstalledVersions`
    strips `extra` from its accessors), surfacing each CWM dep's version,
    install path, path-repo source path (if installed via a path
    repository), and validated joomlaLinks tuples.
  - New value object `CWM\BuildTools\Config\CwmPackage`.
- **`cwm-link` walks CWM Composer deps** in addition to the consumer's own
  extensions. Per-dep links land at the same canonical Joomla paths
  (`libraries/<name>`, `plugins/<group>/<element>`, etc.) — re-running
  `cwm-link` from a different consuming repo is idempotent on shared
  targets.
  - Conflict detection: when a symlink already exists at the expected
    target but points somewhere other than this run's source, the
    conflict is reported and skipped (exit 1) rather than silently
    overwritten. New `--force` flag reinstates overwrite for the rare
    case where a developer actually wants to replace someone else's
    link.
  - `Linker::check()` return shape gains `existingRealpath` for ok/wrong/
    broken statuses so callers can compare without re-reading the link.
- **`build.properties` install role.** Each install can now declare
  `role = dev` (the default — symlink-style working install where
  `cwm-link` deploys) or `role = test` (artifact-style install for the
  new `cwm-install-zip` command). Multiple installs may share a role
  (e.g. `j5` and `j6` both as dev). Legacy flat Proclaim-style
  `build.properties` files default every install to `role = dev`.
  - New `PropertiesReader::installsFor(string $role)` filters by role.
  - `InstallConfig` gains a `role` constructor argument and
    `ROLE_DEV` / `ROLE_TEST` constants.
- **`cwm-install-zip` command.** New companion to `cwm-link`: builds nothing
  itself, but takes the most recent dist zip produced by `cwm-build`
  (matched via `build.outputGlob`) and installs it into every Joomla
  install with `role = test` by invoking the bundled Joomla CLI:
  ```
  php <joomlaRoot>/cli/joomla.php extension:install --path=<zip>
  ```
  Re-running on an existing extension triggers Joomla's upgrade path —
  install scriptfile `update()` runs, manifest `update.sql` migrations
  apply. Use this to exercise the SHIPPED artifact end-to-end (install
  scriptfile, dist exclusions, schema migrations) before a release,
  separately from the symlink-style dev workflow.
  - Implementation: new `src/Dev/ExtensionInstaller.php` and
    `src/Dev/InstallResult.php`; thin bin wrapper at
    `bin/cwm-install-zip`.
  - `--zip <path>` flag to override the `outputGlob` resolution.
  - `proc_open` is invoked array-form (no shell) per CLAUDE.md guardrails.
- **`cwm-verify --target <role>` flag.** Filters which installs are
  verified. Without `--target`, every install is verified per its
  declared role.
  - For `role = dev` installs, the new
    `CWM\BuildTools\Dev\DevTargetVerifier` checks: every expected
    symlink is in place (self + every CWM dep); each installed dep
    version satisfies the constraint in the consuming project's
    `composer.json`; each path-repo dep has a clean working tree
    (`git -C <source> status --porcelain`).
  - For `role = test` installs, the existing `ExtensionVerifier` now
    also walks each CWM dep's declared joomlaLinks and confirms each
    one is registered in `#__extensions` at the right `(type,
    element, folder, client_id)` tuple.
  - New public method `ExtensionVerifier::expectedFromPackages(list<CwmPackage>): list<array>`
    folds dep declarations into the existing expected-extension shape.
  - `lookup()` now filters by `client_id` when supplied, so two modules
    with the same element in different clients (site vs administrator)
    resolve unambiguously.

### Changed (non-breaking)

- `Linker::check()` return shape now includes an `existingRealpath` key
  for `ok`, `wrong`, and `broken` statuses. Existing callers reading
  only `status` and `message` are unaffected.
- `ExtensionVerifier::verify()` signature gains an optional
  `array $packages = []` parameter. Existing callers passing
  `(InstallConfig, bool)` continue to work unchanged.
- `templates/build.properties.tmpl` documents the `role` field and ships
  with a commented-out `[j5-test]` block for the new artifact-target
  install.

### Migration

Consumers pin to `^1.1`. Per-repo migration:

1. `composer require --dev cwm/build-tools:^1.1`
2. Add `extra.cwm-build-tools.joomlaLinks` to your `composer.json`,
   derived from your `manifests.extensions[]` in
   `cwm-build.config.json` (one tuple per declared extension).
3. (Optional) Add a `[j5-test]` block with `role = test` to your
   `build.properties` to enable `cwm-install-zip` and
   `cwm-verify --target test`.
4. Re-run `composer cwm-link` — output now shows the deps section;
   existing links are reported as idempotent.
5. Re-run `composer cwm-verify` — output now shows per-dep version,
   link state, and (for path-repo deps) working-tree cleanliness.

## [1.0.2] - 2026-05-14

### Added

- **versionTracking profiles** — consumers can now declare
  `"profile": "component" | "library" | "package-wrapper"` in
  `cwm-build.config.json` instead of hand-authoring the entire
  `versionTracking` block. Profile defaults live in
  `templates/profiles/<name>.json` here and resolve through
  `CWM\BuildTools\Config\ProfileResolver` at every read site
  (`cwm-bump`, `cwm-release`, `substitute-tokens`).
  - The consumer's `versionTracking` key is still honored as an
    override layer: maps deep-merge, lists replace wholesale.
  - `cwm-init` detects the right profile from `extension.type` and
    pre-fills the prompt; consumers usually just hit Enter.
  - `cwm-sync-configs` prints a migration hint when a consumer has an
    inline `versionTracking` block but no `profile` declared, and a
    "safe to delete" hint when an inline block exactly matches the
    profile defaults. Never auto-rewrites `cwm-build.config.json`.

## [1.0.1] - 2026-05-14

### Added

- `cwm-release` now substitutes a placeholder token (default
  `__DEPLOY_VERSION__`) with the release version across configured source
  paths, between the manifest bump and the package build. Closes #24.
  - Opt-in via a new `substituteTokens` subkey under `versionTracking` in
    `cwm-build.config.json`:
    ```json
    {
      "versionTracking": {
        "substituteTokens": {
          "token":      "__DEPLOY_VERSION__",
          "paths":      ["admin/", "site/", "libraries/", "modules/", "plugins/"],
          "extensions": ["php"]
        }
      }
    }
    ```
  - Solves the "I don't know which version my code will ship in" problem
    for `@since` PHPDoc tags. Devs write `@since __DEPLOY_VERSION__` on
    in-flight code; the release pipeline substitutes the real version
    at cut time. Matches the convention used throughout `joomla-cms`.
  - Substitution runs only during `cwm-release` — `cwm-bump` standalone
    leaves placeholders intact so dev branches between releases stay
    free to accumulate `__DEPLOY_VERSION__` markers.
  - Always skips `vendor/`, `node_modules/`, and `.git/` directories
    regardless of configured paths (defensive — those should never be
    rewritten).
  - Files without the token are not rewritten (no spurious mtime bumps).
- New `CWM\BuildTools\Release\TokenSubstituter` class plus
  `scripts/substitute-tokens.php` CLI entry.

### Changed

- `cwm-release` pipeline is now 9 steps (was 8). The new step 2
  ("Substitute `__DEPLOY_VERSION__` placeholder") sits between bump and
  build. Steps 3-9 are the prior 2-8 renumbered. No behavior change for
  projects that don't configure `substituteTokens`.

## [1.0.0] - 2026-05-14

First stable release. The 0.x-alpha line is closed; the release pipeline,
generic builder, local-dev linker, ARS publisher, and CI workflow are all
production-ready and have shipped real releases through Proclaim,
CWMScriptureLinks, and lib_cwmscripture.

**Consumer migration:** update your `composer.json` constraint from
`"^0.5@alpha"` (or any `^0.x@alpha`) to `"^1.0"`. The CLI surface and
`cwm-build.config.json` schema are unchanged from `0.5.5-alpha` — the
1.0 cut is a stability marker, not a breaking change.

### Added

- New opt-in `versionTracking` block in `cwm-build.config.json` keeps
  `build/versions.json` and `package.json` in lockstep with manifest bumps.
  Closes #23.
  - `cwm-bump <version>` now writes `active_development.version` and
    `package.json:version` (when configured). Skipped when `--component`
    narrows the bump to a single extension type.
  - `cwm-release <version>` (step 7) writes `current.version`, recomputes
    `next.patch` / `next.minor` / `next.major`, and refreshes `_updated`.
    `active_development` is left alone — it stays pointing at whatever the
    last `cwm-bump` set, so developers explicitly advance it when starting
    minor or major work.
  - Schema:
    ```json
    {
      "versionTracking": {
        "versionsJson": "build/versions.json",
        "packageJson":  "package.json"
      }
    }
    ```
  - Either field is optional; an absent block is a no-op (no behaviour
    change for projects that don't opt in).
- New `CWM\BuildTools\Release\VersionTracker` class plus
  `scripts/version-tracker.php` CLI entry. The CLI is what `release.sh`
  step 7 shells out to (replacing the prior inline `python3 -c` heredoc).

### Changed

- `release.sh` step 7 no longer requires `github.developmentBranch`. When
  no dev branch is configured, the versions.json update happens inline on
  the release branch. The dev-branch checkout/commit/push dance still
  runs when configured, for projects with that workflow.

### Deprecated

- Top-level `versionsFile` key in `cwm-build.config.json`. Use
  `versionTracking.versionsJson` instead. The old key still works for
  this minor; will be removed in the next minor bump.

## [0.5.5-alpha] - 2026-05-12

### Added

- `cwm-verify` now validates and fixes Joomla component admin menus. It parses
  `<menu>` and `<submenu>` tags from the component manifest and checks the
  `#__menu` table in the target database. When run with `--fix`, missing menus
  are automatically created using a safe Nested Set append strategy.

## [0.5.4-alpha] - 2026-05-11

Patch release for a CLI-only autoload bug surfaced when Proclaim enabled
`versionPrompt: { enabled: true }` against v0.5.2-alpha. The fix was
described in the 0.5.3-alpha CHANGELOG but landed in #22 *after* the
v0.5.3-alpha tag was cut, so v0.5.4-alpha is the first tag that ships
it.

### Fixed

- `scripts/build.php` and `scripts/package.php` now `require_once`
  `src/Build/Prompt.php`. The CLI entry points are PSR-0-style, loading
  every class manually rather than relying on Composer's autoloader, and
  the `Prompt` class (added in 0.5.0-alpha for the 3-way version prompt)
  was missing from both. The PHPUnit suite did not catch this because
  unit tests instantiate `PackageBuilder` directly and Composer's
  autoloader resolves `Prompt` transparently — only the standalone CLI
  invocation hit the gap. Symptom: `Error: build failed — Class
  "CWM\BuildTools\Build\Prompt" not found` immediately after enabling
  `versionPrompt`. Regression test added that spawns
  `php scripts/build.php` as a child process with `versionPrompt`
  enabled and asserts no class-not-found errors in stderr.

## [0.5.3-alpha] - 2026-05-10

Intended as the patch release for the Prompt.php CLI autoload bug, but
the tag was cut before #22 landed on `main`. The fix described here
actually ships in v0.5.4-alpha; this tag is functionally equivalent to
v0.5.2-alpha. Consumers on `^0.5@alpha` should bump to v0.5.4-alpha to
pick up the fix.

## [0.5.2-alpha] - 2026-05-10

Patch release for the version-threading gap surfaced during the Proclaim
migration to v0.5.0-alpha.

### Changed

- `cwm-package` `self` include now inherits the outer wrapper's version
  for its inner `cwm-build` invocation. Previously the inner build read
  its own manifest's `<version>`; if that drifted from the package
  manifest's `<version>` (e.g. between `cwm-bump` runs, or with
  `cwm-package --version X` overriding only the outer name) the inner
  zip would land at a different version than the wrapper. This was the
  reason the v0.5.0/0.5.1 release notes asked Proclaim to **not** enable
  `versionPrompt: { enabled: true }` — an interactive prompt could
  produce mismatched inner and outer versions. With this fix, the outer
  version (whether from manifest, `--version` CLI override, or future
  prompt result) is threaded into the `self` include's `cwm-build` call
  via `PackageBuilder::build($outerVersion)`. `inline`, `subBuild`, and
  `prebuilt` includes are version-independent (their version sources
  are nested manifests, sub-build script outputs, and pre-built
  artifacts respectively) and remain unchanged. Released this as a
  patch on the alpha line because: (a) no consumer has merged the new
  binaries yet, only PRs are open against them; (b) the prior behavior
  was unintentionally inconsistent with the `self` semantic ("this same
  project") rather than a deliberate API choice. 2 new tests covering
  the threading invariant + the CLI override path; the diagnostic
  output line now includes the inherited version (`-> building self
  (X.zip) at vN.N.N`).

  Once consumers are on `^0.5@alpha` (auto-picks 0.5.2), Proclaim can
  enable `versionPrompt: { enabled: true }` and have the prompt result
  flow consistently from `cwm-package`'s outer manifest read into the
  `self` include's inner build.

## [0.5.1-alpha] - 2026-05-09

Patch release for one bug surfaced during the lib_cwmscripture migration
to v0.5.0-alpha.

### Fixed

- `cwm-build` `preBuild.mode: "ensure-minified"` gate now only checks
  primary asset extensions (`.js`, `.css`). Earlier the gate iterated
  every extension in the configured directories and reported spurious
  failures like `foo.min.js.map → expected foo.min.js.min.map` for
  source-map (`.map`) and gzip (`.gz`) companions of already-minified
  files. The first end-to-end consumer migration (lib_cwmscripture)
  surfaced this immediately; consumers of v0.5.0-alpha that hit the
  same gate failure should bump to `^0.5@alpha` (auto-picks 0.5.1) or
  pin to `0.5.1-alpha` directly. Regression test added.

## [0.5.0-alpha] - 2026-05-09

First minor bump on the alpha line. Consolidates the per-consumer build /
package scripts (Proclaim's `proclaim_build.php`, lib_cwmscripture's
`build-package.php`, CWMScriptureLinks' `build-package.php`) into two
generic binaries — `cwm-build` and `cwm-package` — driven by new
`build:` and `package:` blocks in `cwm-build.config.json`. Adds
`cwm-article`, `cwm-joomla-cms-deps`, and a parameterized
`build-assets.js` template (issue #7), plus pre-flight `git pull` /
submodule sync in `cwm-release` (issue #6).

**Schema is additive**, but `cwm-package` itself changes behavior — see
the migration guide below before bumping. Consumers pinned to `^0.4@alpha`
do **not** auto-pick this up; they must update their constraint to
`^0.5@alpha` (or pin to `0.5.0-alpha`) and adopt the new schema fields.

### Migration guide

| Concern | Old (0.4.x) | New (0.5.0) |
|---|---|---|
| Build a single zip | `"command": "php build/build-package.php"` (project script) | `"command": "cwm-build"` + new `build:` schema fields below |
| Assemble multi-ext package | `"command": "php build/proclaim_build.php package"` (project script) | `"command": "cwm-package"` + new `package:` block |
| `cwm-package` binary | thin `bash -c $build.command` shim | generic assembler reading `package:` block |
| `cwm-build` binary | did not exist | new — see below |

**Required `composer.json` change** (consumers using cwm-build-tools):

```json
{
    "require-dev": {
        "cwm/build-tools": "^0.5@alpha"
    }
}
```

**Required `cwm-build.config.json` additions** to use the new binaries:

- For a single-extension build (lib_cwmscripture-shape):
  ```json
  "build": {
      "command":    "cwm-build",
      "outputGlob": "build/dist/lib_cwmscripture-*.zip",
      "outputDir":  "build/dist",
      "outputName": "lib_cwmscripture-{version}.zip",
      "manifest":   "cwmscripture.xml",
      "scriptFile": "script.php",
      "sources": [
          { "from": "src",                    "to": "lib_cwmscripture/src" },
          { "from": "media/lib_cwmscripture", "to": "media/lib_cwmscripture" }
      ],
      "excludes": [".git", ".DS_Store", "node_modules"],
      "preBuild": {
          "mode": "ensure-minified",
          "dirs": ["media/lib_cwmscripture/js", "media/lib_cwmscripture/css"]
      }
  }
  ```

- For a Proclaim-shape strict build, additionally set `excludeMatchMode:
  "strict"`, `vendorPrune: true`, `includeRoots`, `includeRootExtensions`,
  `excludeExtensions`, `excludePaths`, and `preBuild.mode: "run"` with
  `preBuild.command: "npm install && npm run build"`.

- For a multi-extension package wrapper:
  ```json
  "package": {
      "manifest":     "build/pkg_proclaim.xml",
      "outputDir":    "build/dist",
      "outputName":   "pkg_proclaim-{version}.zip",
      "innerLayout":  "packages-prefix",
      "installer":    "build/script.install.php",
      "languageFiles": [
          { "from": "build/language/en-GB/en-GB.pkg_proclaim.sys.ini",
            "to":   "language/en-GB/en-GB.pkg_proclaim.sys.ini" }
      ],
      "includes": [
          { "type": "self",     "outputName": "com_proclaim.zip" },
          { "type": "subBuild", "path": "libraries/lib_cwmscripture",
            "buildScript": "build/build-package.php",
            "distGlob":    "build/dist/lib_cwmscripture-*.zip",
            "outputName":  "lib_cwmscripture.zip" }
      ]
  }
  ```

After migration the project's own `build/build-package.php` /
`build/proclaim_build.php` scripts can be deleted.

### Added

- `cwm-build` 3-way interactive version prompt — new optional
  `build.versionPrompt: { enabled, timeout }` config field. When enabled
  AND the build is run interactively AND no `--version` override is given,
  cwm-build offers Proclaim's existing 3-way menu before opening the zip:
  (1) keep the manifest version, (2) use a date-stamped pre-release
  (`<version>.YYYYMMDD`), or (3) enter a custom value. Default for `timeout`
  is 10 seconds; choosing nothing within the countdown picks option 1.
  CI / `$CWM_NONINTERACTIVE` short-circuits to manifest version with a
  single diagnostic line. `cwm-release` continues to pass `--version`
  through to the build command, so the release pipeline is unaffected —
  this only fires for ad-hoc local `cwm-build` runs. 5 new tests covering
  the non-interactive bypass, override short-circuit, schema validation,
  and the timeout default. The interactive 3-way path stays manual
  (PHPUnit can't fake a PTY).

- `cwm-package` rewritten — replaces the prior thin shell-pass-through wrapper
  (`bash -c $build.command`) with a generic Joomla multi-extension package
  assembler driven by a new `package:` block in `cwm-build.config.json`. New
  classes: `src/Build/PackageConfig` and `src/Build/Packager`; CLI script
  `scripts/package.php`. Supports four `includes[]` entry types:
  - `self` — invoke `cwm-build` on the project's own `build:` block, then
    bundle the result. Handles Proclaim's "step 3: build com_proclaim".
  - `subBuild` — array-form `proc_open` of `php <buildScript> [args]` inside
    `path`, then glob `distGlob` (relative to `path`) for the produced zip.
    Handles Proclaim's two `passthru('php …/build-package.php …')` calls
    (including the `--plugin-only` arg) during transition while sub-extensions
    still ship their own scripts.
  - `prebuilt` — assume already on disk; glob `distGlob` (project-relative).
    Multiple matches → most-recently modified wins.
  - `inline` — nested `BuildConfig`-shaped block; cwm-build runs in-process
    on it. Handles CSL's `plg_task_cwmscripture` (a sibling directory built
    in-process).

  Other features: `innerLayout` (`"root"` for outer-zip entries at root vs
  `"packages-prefix"` for `packages/<outputName>` paths — Proclaim's layout),
  optional `installer` scriptfile, `languageFiles[]` with explicit `from`/`to`
  paths, opt-in `verify.expectedEntries[]` self-check (CSL's verifyPackage
  feature). Staging dir is a unique scratch dir under `sys_get_temp_dir()`
  cleaned up on success or failure (no shell-driven `rm -rf` calls; native
  PHP recursion with `is_link()` guards per CLAUDE.md).

  20 new unit tests / 79 assertions covering all 4 include types, both inner
  layouts, version override, installer + language file placement, verify
  pass/fail, and config validation (required fields, invalid type, invalid
  layout, subBuild missing path, inline missing nested config). PR D of #5.

- `cwm-build` strict-mode + filtering features for the Proclaim-shape build
  flow. New optional `build:` schema fields (PR C of #5):
  - `excludeMatchMode: "strict"` — Proclaim's 4-mode pattern matching
    (exact / prefix-with-slash / contained-with-slashes / suffix-after-slash)
    that catches `.git` at any depth without over-matching the substring "git"
    inside unrelated filenames. Defaults to `"contains"` (PR B behavior).
  - `excludeExtensions: ["map"]` — bare extension allowlist; `.map` files
    are dropped at any path.
  - `excludePaths: ["media/backup/*.sql"]` — fnmatch glob patterns matched
    against the relative path; covers Proclaim's `media/backup/*.sql` rule.
  - `vendorPrune: true` — drop Composer metadata (`installed.json`,
    `installed.php`) and doc/license files (`README*`, `CHANGELOG*`,
    `BACKERS*`, `AUTHORS*`, `CONTRIBUTING*`, `UPGRADE*`, `SECURITY*`,
    `LICENSE*`, `COPYING*`) inside any `vendor/` subtree.
  - `includeRoots: ["admin/", "media/", ...]` — subdirectory allowlist;
    only files starting with one of these prefixes are included.
  - `includeRootExtensions: ["php", "xml", "txt", "md"]` — root-level
    files (no `/` in path) with one of these extensions are also admitted
    through the include filter (Proclaim's `proclaim.xml`, `LICENSE.txt`,
    `README.md` at project root).
  - `preBuild.mode: "run"` + `preBuild.command` — auto-execute a shell
    command (`passthru`) before the zip walk; non-zero exit aborts.
    Matches Proclaim's `npm install && npm run build` step. Build config
    is trusted (committed by the project author) so shell semantics are
    OK per the threat model.
  11 new tests / 56 assertions covering each of the above plus the
  combined Proclaim-shape (strict + vendor-prune + includeRoots +
  includeRootExtensions). Defaults preserve PR B's behavior; adopting any
  of these is opt-in. The interactive 3-way version prompt
  (`versionPrompt`) is deferred to a small follow-up after PR A
  (`Build\Prompt`) merges.

- `cwm-build` binary + `scripts/build.php` + `src/Build/PackageBuilder` +
  `src/Build/BuildConfig` — generic Joomla extension zip builder driven by a
  `build:` block in `cwm-build.config.json`. Phase 1 covers the
  lib_cwmscripture build shape: read manifest version, optionally gate on
  presence of `*.min.{js,css}` siblings (`preBuild.mode: "ensure-minified"`),
  walk one or more source directories with a per-source zip-path prefix, apply
  loose `str_contains` excludes. CLI flags `-v`/`--verbose`, `--version <ver>`
  override, `--help`. New schema fields: `build.outputDir`, `outputName`
  (supports `{version}`), `manifest`, `scriptFile`, `sources[]`, `excludes`,
  `preBuild`. Coexists with the existing `build.command` / `build.outputGlob`
  fields used by `cwm-release` — consumers migrate by setting
  `build.command: "cwm-build"`. Strict-mode filtering, vendor pruning, auto-
  run pre-build, and the interactive 3-way version prompt land in subsequent
  PRs (still part of #5). 8 new unit tests / 39 assertions covering the
  end-to-end build, version override, gate pass/fail, and config validation.

- `CWM\BuildTools\Build\Prompt` — extracted the interactive `ask()` helper
  (with the fixed countdown ANSI redraw — the `\r\033[K` clear-to-EOL that
  prevents stray "0" artifacts when "(10s):" is replaced by "(9s):") from
  Proclaim's `build/proclaim_build.php` into a reusable PSR-4 class. Honors
  `$CI` and `$CWM_NONINTERACTIVE` for CI-safe defaults; uses array-form
  `proc_open` for the `stty` calls (no shell, no metachar interpretation —
  per the project's security guardrails). Foundation for the upcoming
  `cwm-build` / `cwm-package` consolidation (#5). 7 unit tests covering the
  non-interactive paths and `isNonInteractive()` env detection.

- `templates/build-assets.js` — generic version of Proclaim's
  `build/build-assets.js` that copies images, mirrors a manually-managed
  vendor source tree, and cherry-picks files/dirs out of npm packages in
  `node_modules/`. Driven by a new `assets:` block in
  `cwm-build.config.json`; supports CSS minification (`minifyCss: true`),
  pre-clean of a destination subdir (`cleanDest`), and filename glob
  filters on directory copies (`match: "*.umd.js"`). Schema paths are
  source-tree paths so adopting this template doesn't require manifest
  changes — the `<media folder=…>` element keeps controlling install-time
  destination as before. Wire from `package.json` `build:assets`.
  Reported in #7.

- `cwm-joomla-cms-deps` binary + `scripts/joomla-cms-deps.php` — generic
  version of the Proclaim-side `build/joomla-cms-deps.php` that clones
  `joomla-cms` source for unit tests to require directly. New optional
  config fields `testing.joomlaCmsVersion` (defaults to `5.4.3`) and
  `testing.joomlaCmsPath` (defaults to `<cwd-parent>/joomla-cms`); CLI
  flags `-v`/`--version` and `-p`/`--path` override either. Backward-
  compatible with the legacy `tests.joomla_cms_path` key in
  `build.properties` so existing Proclaim setups keep working before
  consumers migrate. Wire from `composer.json` `post-install-cmd`.
  Reported in #7.

- `cwm-article` binary + `scripts/cwm-article.sh` — generic version of the
  Proclaim-side `build/cwm-article.sh` that posts a "<Extension> X.Y.Z
  Released" announcement to christianwebministries.org, features it, and
  un-features the previous featured article. Reads `extension.name`,
  `extension.displayName` (new optional field — falls back to stripping
  `pkg_/com_/lib_/mod_/plg_` from `extension.name` and uppercasing the first
  char), `manifests.package` (version-detection source when no VERSION arg),
  and `github.owner`/`github.repo` from `cwm-build.config.json`. CWM-team
  body copy and `christianwebministries.org` site URL stay hard-coded — this
  binary is for CWM-family releases. Wire into release step 8 by setting
  `announcement.command: "cwm-article"` in `cwm-build.config.json`. Reported
  in #7.
- `cwm-release`: pre-flight steps before the version bump now (a) `git fetch
  origin --prune --tags`, (b) `git pull --ff-only origin <release-branch>` and
  abort with guidance if local has diverged, (c) `git submodule update --init
  --recursive` to match working trees to recorded pointers, and (d) warn when
  a submodule pointer isn't at a tagged release commit (non-blocking — shipping
  an untagged snapshot is sometimes intentional, but should be deliberate).
  Catches the "I forgot to pull" and "submodule working tree on a different
  commit than recorded" failure modes that surfaced during the Proclaim 10.3.2
  release. Reported in #6.

## [0.4.1-alpha] - 2026-05-08

Fixes and scaffolder completion driven by the first end-to-end consumer
adoption pass (Proclaim — see Proclaim PR #1218 / cwm-build-tools issues
#2 and #4). The CLI surface is unchanged from `v0.4.0-alpha`; consumers
pinned to `^0.4@alpha` pick this up automatically.

### Fixed

- `PropertiesReader::installs()` no longer fails on `build.properties` files whose
  comments contain PHP-INI-reserved characters like `()`, `[]`, `!`, or `?`.
  PHP's `parse_ini_*` raises a syntax error on those even when they appear inside
  `#`/`;` comment lines, so consumers shipping stock comments such as
  `# Full path(s) to your install` were tripping `cwm-link-check`, `cwm-verify`,
  and any other command that reaches `installs()`. Comment lines are now stripped
  before parsing. Reported in #2.
- `PropertiesReader::fromLegacyFlat()` now detects an absolute `builder.joomla_dir`
  (POSIX `/foo`, Windows `C:\foo`, UNC `\\server\share`) and ignores it with a
  stderr warning, instead of concatenating it onto each install path and producing
  nonsense like `/Sites/j5-dev/Volumes/.../GitHub/joomla-cms`. Proclaim's existing
  `build.properties` uses the same key as a separate absolute CMS-source path; the
  collision broke `cwm-link-check` / `cwm-verify` until the value was unset.
  Reported in #2.2.
- `release.sh`: `git describe --tags` now runs against `HEAD` (the bump commit we
  just pushed) instead of `HEAD~1`, so the previous-tag lookup resolves correctly
  even on the first release of a session. The step-3 staging now does
  `git add -u` (tracked changes only) before the catch-all `git add -A`, with a
  comment explaining the safety. Pre-checks already gate on a clean tree.
- `bump.php`: validates the package manifest exists before rewriting it (matches
  the existing sub-extension behavior). Drops the unused `PROJECT_ROOT` constant.
- `cwm-ars-create-stream` 404 fallback now echoes the values the user passed
  in (`--name`, `--element`, `--type`, `--category-id`) as a copy-paste-friendly
  form template, plus a deep link straight to the Update Streams admin view at
  the configured `ars.endpoint`. Saves having to scroll back through shell history.
- `ExtensionVerifier::verify()` default `$reconcile` flipped from `true` to
  `false`. The `verify.php` CLI passes the right flag either way; this just makes
  direct callers safer (no automatic INSERTs unless explicitly requested).
- `setup.php`: refuses to run without `cwm-build.config.json` (with a hint
  pointing at `cwm-init`); refuses to run when stdin is not a TTY (CI-safe);
  lowercases install ids so they match what `LinkResolver` / `ExtensionVerifier`
  expect; warns when the configured Joomla path doesn't exist; prints a
  next-steps banner after writing `build.properties`.
- `clean.php`: explicit notice when `cwm-build.config.json` is missing or invalid,
  instead of silently scanning nothing.
- `scripts/sync-languages.py` cache files (`translations.json`, `sources.json`)
  now live under `<project_root>/build/.cwm-cache/` instead of inside the install
  directory at `libraries/vendor/cwm/build-tools/scripts/`. The previous location
  was wiped by every `composer install`, silently destroying the translation cache
  (~1.3 MB in the Proclaim case) and re-billing the API on the next sync. Reported
  in #4.8.

### Added — `cwm-init` is functional

`bin/cwm-init` previously dispatched to a `scripts/init.php` that didn't
exist. It's now a working interactive scaffolder.

- Walks the project tree to detect: package manifest (`build/pkg_*.xml`,
  `pkg_*.xml`), top-level extension type/name (component, library, package
  wrapper), sub-extension manifests (libraries, plugins, modules, including
  symlinked ones — deduped by `realpath()`), GitHub origin from `.git/config`
  (no subprocess), preferred release branch (prefers `main`/`master`,
  falls back to `origin/HEAD`), development branch (prefers
  `development`/`develop`), build command + output directory paired together
  (Proclaim's `build/proclaim_build.php` → `build/packages/`, CWMScripture's
  `build/build-package.php` → `build/dist/`), changelog file, versions.json,
  and announcement command. Every prompt is pre-filled with what was found;
  press Enter to accept or type an override.
- Warns at runtime when `releaseBranch` and `developmentBranch` resolve to
  the same value and offers a re-prompt (release.sh step 7 churns when
  they're identical).
- Manages consumer `composer.json`: adds `cwm/build-tools` to `require-dev`
  with `^0.4@alpha`, adds the VCS `repositories[]` entry for the GitHub repo,
  and wires 15 standard `vendor/bin/cwm-*` composer scripts. Existing entries
  are preserved — the conflict report names them so the consumer can finish
  the migration by hand. Idempotent: re-running won't duplicate any entry.
- Seeds `vendors[]` from `build/check-vendor-versions.js` /
  `build/update-vendors.js` if they exist, falling back to `package.json`
  `dependencies`. Scoped packages (`@vendor/name`) get the leaf as the
  default display label.
- Detects stale runtime artifacts left behind by tools cwm-build-tools now
  provides (`build/__pycache__/`, the old translation/source caches, plus
  10 migrated `build/` scripts) and offers — but never automatically does
  — to remove them. Default is N because deleting tracked files needs
  consumer review.
- Emits a `package.json` migration suggestion block. SOURCE_DIR / OUTPUT_DIR
  are too project-specific to auto-rewrite — the safe path is to surface
  the recommended shape so the consumer can copy-paste while reviewing the
  paths against their own source layout. Path arguments in legacy commands
  are preserved verbatim into the `SOURCE_DIR` / `OUTPUT_DIR` env vars
  where extractable; placeholders are emitted otherwise.
- `--non-interactive` accepts all detected defaults (CI-friendly).
  `--force` overwrites an existing `cwm-build.config.json`.
- After writing the config, runs `cwm-sync-configs` automatically so the
  managed gitignore block lands on the same run.

### Added — `cwm-sync-configs` handlers

- `eslint.config.mjs` handler: writes a starter wrapper that imports
  `templates/eslint.config.base.mjs` from the consumer's vendor-dir
  (resolved from `composer.json` `config.vendor-dir`). When a config
  already exists, it leaves it alone — but prints the exact `import` line
  to add when the existing file doesn't yet extend the shared base.
- `gitignore.outputPaths[]` and `gitignore.mediaPaths[]` config schema
  fields. When present, these REPLACE the auto-derived defaults, letting a
  project with a non-standard layout (Proclaim's `/media/com_proclaim/` +
  `/media/lib_cwmscripture/` mix instead of a single `/media/<stripped>/`)
  not fight the generator. `cwm-init` populates them by walking
  `<project>/media/<x>/(js|css)/` to find dirs that actually receive built
  JS/CSS.
- The auto-derived defaults are now scoped to extension types where the
  convention holds: `library` and `component` map cleanly to
  `/media/<stripped>/`; `package`, `plugin`, and `module` no longer get
  bogus media patterns. Output dir comes from `build.outputGlob`'s
  dirname so `build/packages/` (Proclaim) and `build/dist/` (CWMScripture)
  both render correctly without config.
- New gitignore entry: `/build/.cwm-cache/` (where the relocated
  sync-languages cache now lives).

### Added — testing scaffold

- `phpunit.xml` + `tests/Dev/` with 40 unit tests / 100 assertions:
  `PropertiesReaderTest` (12 tests, including regressions for the
  comment-strip and absolute-`joomla_dir` fixes above), `LinkerTest`
  (13 tests for `relativePath()` edge cases plus check / link / unlink),
  `LinkResolverTest` (9 tests for auto-derivation per extension type and
  explicit dev-link interpolation), `ExtensionVerifierTest` (6 tests for
  `expectedExtensions()` across component, library, plugin manifests).
- Strict-mode `phpunit.xml` (`failOnDeprecation`, `failOnNotice`,
  `failOnWarning`, `beStrictAboutOutputDuringTests`); random execution
  order to surface order-dependent flakes.
- `composer test` script.
- `.gitattributes` `export-ignore` + `composer.json` `archive.exclude`
  for `tests/`, `phpunit.xml`, `.github/`, `.idea/`, `.gitignore`, etc.
  — so the dist zip Composer downloads into every consuming project's
  `vendor/cwm/build-tools/` doesn't ship dev-only files.

### Added — sharper `--help` everywhere

`setup`, `link`, `link-check`, `clean`, `verify`, `joomla-install`, and
`joomla-latest` now follow a consistent `--help` shape: WHAT IT DOES,
PREREQUISITES, USAGE (with concrete examples), OPTIONS, RELATED. `verify`
also documents EXIT CODE for CI gating.

### Documentation

- README roadmap reflects Phase 1 actual state (release pipeline
  mostly done; dev-env commands shipped in `0.4.0-alpha`) and surfaces a
  Testing TODO that this release closes.

## [0.4.0-alpha] - 2026-05-07

### Added — dev-environment commands lifted from Proclaim

- `bin/cwm-setup` + `scripts/setup.php` — interactive wizard that captures one or
  more Joomla install paths, URLs, target versions, DB creds, and admin creds.
  Writes a per-developer `build.properties` (INI sections) in the consuming repo.
- `bin/cwm-link` + `scripts/link.php` — symlinks the project tree into every
  configured Joomla install. Auto-derives the standard set of links from
  `extension.*` and `manifests.extensions[]` (component admin/site/media,
  library `lib_X` → `libraries/X` + manifest mirror, `plugins/<group>/<element>`,
  `modules/[admin]/<name>`); explicit `dev.links[]` and `dev.internalLinks[]`
  entries are merged in. **All symlinks are created with relative paths** so
  the dev tree is portable across machines and CI (cwmconnect PR #88/#89 hit
  the absolute-path footgun directly).
- `bin/cwm-link-check` — verifies every expected symlink without recreating it.
  Exits non-zero on drift so CI can gate on a known-good state.
- `bin/cwm-clean` — removes every dev symlink. Real files / directories at the
  link paths are left alone — only items that are currently symlinks are touched.
- `bin/cwm-verify` — confirms each install has every project sub-extension
  registered in `#__extensions`. Reads each manifest XML to discover
  type/element/folder/namespace; uses PDO so it does not need to bootstrap
  Joomla. Pass `--fix` to reconcile drift (UPDATE state, INSERT missing
  libraries/plugins, run library install SQL). Components are flagged but
  never auto-inserted — install via the Extension Manager so the rest of the
  install lifecycle runs.
- `bin/cwm-joomla-install` — downloads the Joomla full-package release into
  every configured install path. Per-install version comes from
  `build.properties`; pass a positional argument to override globally.
  `--force` wipes the directory first.
- `bin/cwm-joomla-latest` — prints the latest stable tag from the
  `joomla/joomla-cms` releases feed.
- `src/Dev/` — `InstallConfig`, `PropertiesReader`, `LinkResolver`, `Linker`,
  `ExtensionVerifier`, `JoomlaInstaller`. The bash/PHP scripts are thin
  wrappers over these classes.
- `templates/build.properties.tmpl` — copied into a consuming repo as
  `build.properties.tmpl` (or `build.dist.properties`) and committed; each
  developer copies it to `build.properties` (gitignored) and edits.
- `templates/cwm-build.config.json.tmpl` — extended with a `dev:` block
  documenting `internalLinks[]`, `links[]`, and the `deriveLinks: false`
  escape hatch for projects with non-standard layouts.

#### Configuration split

Two files now drive the dev surface:

- `cwm-build.config.json` (committed) — what the project IS. Adds an optional
  `dev:` block describing extra symlinks and repo-internal mirror links.
  No secrets.
- `build.properties` (gitignored, INI sections) — where the developer's local
  Joomla installs live. DB and admin passwords stay out of source control.

The `PropertiesReader` also accepts Proclaim's legacy flat
`builder.joomla_paths=...` / `builder.j5dev.url=...` layout, so projects
migrating from Proclaim can drop the new toolchain in without rewriting their
`build.properties` first.

### Changed (breaking)

- Removed `ars.changelogUrl` from the config schema. Modern Akeeba ARS (verified against v7.4.x source) has no `changelogurl` field on the `#__ars_updatestreams` table — Joomla's changelog mechanism reads `<changelogurl>` from the **installed extension manifest** instead. The PATCH call in `ars-publish.sh` that previously tried to set this on the update stream was a no-op against modern ARS and has been removed. The URL now lives at `changelog.url` (was `ars.changelogUrl`) and is meant to be referenced by the manifest XML, not pushed to ARS.
- Migration: in your `cwm-build.config.json`, move the value from `ars.changelogUrl` to `changelog.url`. Delete the old key. Add a `<changelogurl>...</changelogurl>` element to your top-level extension manifests (next to `<updateservers>`) so Joomla can fetch the changelog when notifying users of updates.

### Added

- `templates/vendor-check.js` and `templates/vendor-update.js` — lifted from Proclaim's `build/check-vendor-versions.js` / `build/update-vendors.js`. Vendor list (previously hardcoded as `chart.js`, `@fancyapps/ui`, `intl-tel-input`, `sortablejs`) is now read from `cwm-build.config.json` under `vendors[]`, so any project that bundles npm libraries can adopt them. Uses `execFileSync` (no shell, no injection surface) for vendor names interpolated into commands.
- `templates/versions.json.tmpl` — template for the dev-version-state file Proclaim uses (current / next.patch / next.minor / next.major / active_development). Already consumed by `release.sh` step 7.
- `bin/cwm-ars-publish` + `scripts/ars-publish.sh` — full ARS publish (Akeeba Release System) implementation, lifted and parameterized from Proclaim's `build/ars-release.sh`. Replaces the previous PHP stub. All site/category/stream/environment/auth values now come from `cwm-build.config.json`. Auth via 1Password CLI with `ARS_API_TOKEN` env override.
- `bin/cwm-changelog` + `scripts/generate-changelog-entry.sh` — Joomla changelog XML generator, lifted from Proclaim's `build/generate-changelog-entry.sh`. Parameterized via `changelog.file`, `changelog.element`, `changelog.type` (defaulting to extension fields when not set).
- `release.sh` step 5 (changelog) and step 6 (ARS publish) now invoke the shared scripts directly instead of project-specific shell-outs.
- `bin/cwm-sync-languages` + `scripts/sync-languages.py` — shared Joomla language sync / Google Translate tool. Project root now defaults to CWD (or pass `--project-root <path>` to override). Lifted from byte-identical copies in `lib_cwmscripture` and `Proclaim`.
- `templates/eslint.config.base.mjs` — base ESLint flat-config for CWM Joomla extensions. Consuming projects extend it via `import baseConfig from '.../vendor/cwm/build-tools/templates/eslint.config.base.mjs'` and add their own globals/files.

### Removed

- `scripts/ars-publish.php` — was a stub. Replaced by `scripts/ars-publish.sh` with a real implementation.

### Phase 1 scaffold (initial)

- Initial repo skeleton with `bin/`, `scripts/`, `src/`, `templates/`, `examples/`.
- `bin/cwm-release`, `cwm-bump`, `cwm-package`, `cwm-sync-configs`, `cwm-sync-languages`, `cwm-init`.
- `scripts/release.sh` — generic 8-step release pipeline (bump, build, push, GH release, changelog, ARS, versions.json, announcement).
- `scripts/bump.php` — multi-manifest version bumper driven by `cwm-build.config.json`.
- `scripts/sync-configs.php` — managed-block syncer (currently `.gitignore`).
- `scripts/ars-publish.php` — stub for ARS API upload (manual upload until Phase 1 completes).
- `templates/gitignore-managed.txt` — universal junk block synced into projects.
- `templates/cwm-build.config.json.tmpl` — per-project config skeleton.
- `examples/package/`, `examples/library/` — reference project configs.
- `.github/workflows/joomla-package-ci.yml` — reusable workflow for Joomla extension CI.
- `src/Config/ProjectConfig.php`, `src/Build/ManifestReader.php`, `src/Release/ArsPublisher.php` — PHP class skeletons for Phase 2+.

### Open before v1.0.0

- ARS API upload implementation in `src/Release/ArsPublisher.php` (currently a stub).
- `scripts/init.php` (the actual interactive scaffolder behind `bin/cwm-init`).
- `.editorconfig` and `.php-cs-fixer.dist.php` sync handlers in `sync-configs.php`.
- Templates: `.editorconfig`, `.php-cs-fixer.base.php`, `phpunit.xml.tmpl`.
- Reusable `joomla-library-ci.yml` workflow.
- Wire CWMScriptureLinks as the first consumer to validate the design end-to-end.

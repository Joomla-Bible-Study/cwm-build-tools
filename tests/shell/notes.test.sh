#!/usr/bin/env bash
#
# Tests for scripts/lib/notes.sh.

set -uo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=helpers.sh
source "${SCRIPT_DIR}/helpers.sh"
# shellcheck source=../../scripts/lib/notes.sh
source "${SCRIPT_DIR}/../../scripts/lib/notes.sh"

WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT

# --- Resolving the notes file ------------------------------------------------
cwm_resolve_notes_file "" 10.3.6 >/dev/null
assert_equals "1" "$?" "an unconfigured pattern returns 1"

printf '## Highlights\n' > "$WORK/release-notes-10.3.6.md"

got="$(cwm_resolve_notes_file "$WORK/release-notes-{version}.md" 10.3.6)"
assert_equals "$WORK/release-notes-10.3.6.md" "$got" "{version} is substituted and the file found"

cwm_resolve_notes_file "$WORK/release-notes-{version}.md" 10.3.7 >/dev/null
assert_equals "1" "$?" "a version nobody wrote notes for returns 1"

# A pattern without a placeholder is legal — a project with one evergreen
# notes file — and resolves to itself.
got="$(cwm_resolve_notes_file "$WORK/release-notes-10.3.6.md" 10.3.6)"
assert_equals "$WORK/release-notes-10.3.6.md" "$got" "a literal path resolves to itself"

# --- Assembling the body -----------------------------------------------------
GENERATED='## What'\''s Changed
* fix(api): a PR title by @someone in #99'

got="$(cwm_assemble_release_notes "" "$GENERATED")"
assert_equals "$GENERATED" "$got" "no notes file: the generated notes pass through"

got="$(cwm_assemble_release_notes "$WORK/absent.md" "$GENERATED")"
assert_equals "$GENERATED" "$got" "a vanished notes file degrades to the generated notes"

cat > "$WORK/notes.md" <<'MD'
This release repairs podcast feeds.
MD

got="$(cwm_assemble_release_notes "$WORK/notes.md" "$GENERATED")"
expected='This release repairs podcast feeds.

## Changes

## What'\''s Changed
* fix(api): a PR title by @someone in #99'
assert_equals "$expected" "$got" "hand-written notes lead; the generated list is kept beneath ## Changes"

# The hand-written notes must come first: the ARS download page shows the
# top of the notes, and the PR-title list is written for us, not for the
# administrator deciding whether to update.
case "$got" in
    "This release repairs podcast feeds."*) PASS=$((PASS + 1)) ;;
    *) FAIL=$((FAIL + 1)); echo "  FAIL: the hand-written notes should open the body" ;;
esac

# --- Whether hand-written notes still need their changelog entry ---------------

cwm_changelog_needs_entry "" 1.2.3
assert_equals "1" "$?" "no configured changelog file means nothing to warn about"

cwm_changelog_needs_entry "$WORK/missing-changelog.xml" 1.2.3
assert_equals "1" "$?" "a missing changelog file means nothing to warn about (step 6 skips it too)"

printf '<changelogs>\n    <changelog><version>1.2.3</version></changelog>\n</changelogs>\n' > "$WORK/changelog.xml"
cwm_changelog_needs_entry "$WORK/changelog.xml" 1.2.3
assert_equals "1" "$?" "a version already in the file needs nothing"

cwm_changelog_needs_entry "$WORK/changelog.xml" 1.2.4
assert_equals "0" "$?" "a version missing from the file needs its entry"

printf '<changelogs>\n    <changelog><version>1.2.30</version></changelog>\n</changelogs>\n' > "$WORK/near.xml"
cwm_changelog_needs_entry "$WORK/near.xml" 1.2.3
assert_equals "0" "$?" "1.2.30 is not an entry for 1.2.3"

finish

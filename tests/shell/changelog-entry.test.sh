#!/usr/bin/env bash
#
# Tests for scripts/generate-changelog-entry.sh: turning a GitHub release body
# into a Joomla changelog entry.
#
# `gh` is a stub that answers with the release body below, so the real script
# runs, --dry-run, without a network or a published release. Release notes
# wrap their bullets; the generator used to take each bullet's first line as
# the whole item, so every changelog entry Joomla showed was cut off
# mid-sentence (Proclaim 10.5.8 to 10.7.4).
set -uo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=helpers.sh
source "${SCRIPT_DIR}/helpers.sh"

ROOT="$(cd "${SCRIPT_DIR}/../.." && pwd)"
WORK="$(mktemp -d)"
trap 'rm -rf "${WORK}"' EXIT
mkdir -p "${WORK}/bin"

cat > "${WORK}/body.md" <<'MD'
## Fixed

- Where records share an alias, the one the address already showed keeps it:
  the published record with the lowest ID.
- A message can't be saved with an alias another message
already has, and the same goes for series.
- The template's **Email when comments
  entered** setting sends again.

This paragraph is not part of the bullet above it.

- A single-line bullet stays as it is.
## Changes
- Checks are `cached` for two minutes,
  shared by every visitor.
MD

# gh release view <tag> --repo <r> --json <field> --jq <expr>
cat > "${WORK}/bin/gh" <<EOF
#!/usr/bin/env bash
case "\$*" in
    *"--json body"*)        cat "${WORK}/body.md" ;;
    *"--json publishedAt"*) echo "2026-11-13T12:00:00Z" ;;
    *)                      exit 1 ;;
esac
EOF
chmod +x "${WORK}/bin/gh"

cat > "${WORK}/cwm-build.config.json" <<'JSON'
{
    "extension": { "name": "pkg_demo", "type": "package" },
    "github": { "owner": "o", "repo": "r" },
    "changelog": { "file": "build/demo-changelog.xml" }
}
JSON

OUT="$(cd "${WORK}" && PATH="${WORK}/bin:${PATH}" bash "${ROOT}/scripts/generate-changelog-entry.sh" 1.2.3 --dry-run 2>&1)"
assert_equals "0" "$?" "the dry run succeeds"

assert_contains "$OUT" \
    "<item>Where records share an alias, the one the address already showed keeps it: the published record with the lowest ID.</item>" \
    "an indented continuation line is joined to its bullet"
assert_contains "$OUT" \
    "<item>A message can&#x27;t be saved with an alias another message already has, and the same goes for series.</item>" \
    "an unindented (lazy) continuation line is joined too"
assert_contains "$OUT" \
    "<item>The template&#x27;s Email when comments entered setting sends again.</item>" \
    "bold that spans the wrap is stripped once the lines are joined"
assert_contains "$OUT" \
    "<item>A single-line bullet stays as it is.</item>" \
    "a blank line ends the item, so the paragraph after it is not appended"
assert_contains "$OUT" \
    "<item>Checks are cached for two minutes, shared by every visitor.</item>" \
    "a heading ends the item before it, and code markers are stripped"

case "$OUT" in
    *"This paragraph"*) assert_equals "absent" "present" "a paragraph outside any bullet is not an item" ;;
    *)                  assert_equals "absent" "absent" "a paragraph outside any bullet is not an item" ;;
esac

FIX_BLOCK="$(printf '%s\n' "$OUT" | sed -n '/<fix>/,/<\/fix>/p')"
assert_equals "4" "$(printf '%s\n' "$FIX_BLOCK" | grep -c '<item>')" "the four Fixed bullets land in <fix>"
CHANGE_BLOCK="$(printf '%s\n' "$OUT" | sed -n '/<change>/,/<\/change>/p')"
assert_equals "1" "$(printf '%s\n' "$CHANGE_BLOCK" | grep -c '<item>')" "the Changes bullet lands in <change>"

finish

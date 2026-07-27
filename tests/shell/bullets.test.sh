#!/usr/bin/env bash
#
# Tests for scripts/lib/bullets.sh.
#
# The bullets file is hand-written moments before a release, so the
# parser's job is to be forgiving about markers and whitespace while
# never emitting broken HTML into a published article.

set -uo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=helpers.sh
source "${SCRIPT_DIR}/helpers.sh"
# shellcheck source=../../scripts/lib/bullets.sh
source "${SCRIPT_DIR}/../../scripts/lib/bullets.sh"

# --- Escaping ----------------------------------------------------------------
assert_equals '&amp; &lt; &gt;' "$(printf '& < >' | cwm_html_escape)" \
    "ampersand, less-than, greater-than are escaped"
assert_equals '&amp;lt;' "$(printf '&lt;' | cwm_html_escape)" \
    "a pre-existing entity is escaped, not passed through (ampersand first)"

# --- Basic bullets -----------------------------------------------------------
got="$(printf 'Visual layout editor\n' | cwm_bullets_to_li)"
assert_equals '<li>Visual layout editor</li>' "$got" "a plain line becomes an <li>"

got="$(printf -- '- Dashed bullet\n* Starred bullet\n' | cwm_bullets_to_li)"
assert_equals '<li>Dashed bullet</li>
<li>Starred bullet</li>' "$got" "leading - and * markers are trimmed"

got="$(printf 'First\n\n   \nSecond\n' | cwm_bullets_to_li)"
assert_equals '<li>First</li>
<li>Second</li>' "$got" "blank and whitespace-only lines are skipped"

got="$(printf '   padded   \n' | cwm_bullets_to_li)"
assert_equals '<li>padded</li>' "$got" "surrounding whitespace is trimmed"

# --- Inline markdown ---------------------------------------------------------
got="$(printf '**Media chapters** and copying\n' | cwm_bullets_to_li)"
assert_equals '<li><strong>Media chapters</strong> and copying</li>' "$got" \
    "**bold** renders as <strong>"

got="$(printf 'A & B improvements\n' | cwm_bullets_to_li)"
assert_equals '<li>A &amp; B improvements</li>' "$got" "text content is HTML-escaped"

# The documented <url> form. Before the extraction this never worked: the
# escape ran first, the literal-<…> rule could no longer match, and the
# bare-URL rule dragged the trailing &gt; into the href.
got="$(printf 'See <https://example.com/docs> for details\n' | cwm_bullets_to_li)"
assert_equals '<li>See <a href="https://example.com/docs">https://example.com/docs</a> for details</li>' \
    "$got" "an angle-bracket link renders as an anchor, without the trailing &gt;"

# A query string's & arrives escaped; it belongs inside the URL, while the
# closing &gt; does not.
got="$(printf '<https://example.com/?a=1&b=2>\n' | cwm_bullets_to_li)"
assert_equals '<li><a href="https://example.com/?a=1&amp;b=2">https://example.com/?a=1&amp;b=2</a></li>' \
    "$got" "an escaped query-string ampersand stays in the URL"

got="$(printf 'Docs at https://example.com/wiki now\n' | cwm_bullets_to_li)"
assert_equals '<li>Docs at <a href="https://example.com/wiki">https://example.com/wiki</a> now</li>' \
    "$got" "a bare URL renders as an anchor"

# Two angle links on one line must not merge into a single greedy match.
got="$(printf '<https://a.example> and <https://b.example>\n' | cwm_bullets_to_li)"
assert_equals '<li><a href="https://a.example">https://a.example</a> and <a href="https://b.example">https://b.example</a></li>' \
    "$got" "two angle links on one line stay separate"

# --- The mixed realistic case ------------------------------------------------
got="$(printf -- '- **YouTube OAuth** integration\n- See <https://example.com/notes>\n' | cwm_bullets_to_li)"
assert_equals '<li><strong>YouTube OAuth</strong> integration</li>
<li>See <a href="https://example.com/notes">https://example.com/notes</a></li>' \
    "$got" "markers, bold and links compose"

finish

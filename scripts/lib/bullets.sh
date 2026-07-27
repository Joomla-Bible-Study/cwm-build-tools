#!/usr/bin/env bash
#
# Bullets-to-HTML parsing for the release announcement article.
#
# Sourceable rather than executable so the logic can be exercised by
# tests/shell — see issue #52. Filters over stdin or arguments; nothing
# here touches the network or the filesystem.
#
# The input format is the bullets file: one bullet per line, blank lines
# ignored, an optional leading "-" or "*" marker tolerated, with minimal
# inline markdown — **bold**, <https://…> links, bare https://… links.

# Escape &, <, > for safe inclusion as HTML text content. Filter: stdin
# to stdout. Ampersand first, or it would re-escape the entities the
# other two produce.
cwm_html_escape() {
    sed 's/&/\&amp;/g; s/</\&lt;/g; s/>/\&gt;/g'
}

# Convert one already-escaped line of minimal markdown to inline HTML:
#   **text**          → <strong>text</strong>
#   &lt;https://…&gt; → <a href="…">…</a>
#   bare https://…    → <a href="…">…</a>
#
# The input has been through cwm_html_escape, so an angle-bracket link
# arrives as &lt;…&gt; — that is the form to match. (The pre-extraction
# version of this matched literal <…>, which the escape had already
# rewritten, so the documented <url> syntax never worked: the bare-URL
# rule claimed it instead and dragged the trailing &gt; into the href.)
#
# A URL may contain &amp; (an escaped query separator); any other entity
# ends it, which is what anchors the closing &gt; of an angle link.
#
# Arguments:
#   $1  one escaped line
#
# Outputs:
#   The rendered line on stdout.
cwm_inline_md() {
    local s="$1"

    # **bold**
    s=$(echo "$s" | sed -E 's@\*\*([^*]+)\*\*@<strong>\1</strong>@g')
    # &lt;https://…&gt; → proper link
    s=$(echo "$s" | sed -E 's@&lt;(https?://(&amp;|[^&[:space:]])+)&gt;@<a href="\1">\1</a>@g')
    # bare URLs — best-effort; the leading (^|space) keeps this off the
    # hrefs and link texts the previous rule just produced.
    s=$(echo "$s" | sed -E 's@(^|[[:space:]])(https?://[^[:space:]<]+)@\1<a href="\2">\2</a>@g')
    echo "$s"
}

# Build <li>…</li> entries from a bullets file.
#
# One bullet per non-empty line; leading list markers and surrounding
# whitespace are trimmed, then each line is HTML-escaped and rendered.
#
# Input:
#   Raw bullets text on stdin.
#
# Outputs:
#   One <li> line per bullet on stdout; nothing when every line is blank.
cwm_bullets_to_li() {
    local line esc

    while IFS= read -r line; do
        # Trim a leading "- " / "* " marker and surrounding whitespace.
        line=$(echo "$line" | sed -E 's@^[[:space:]]*[-*][[:space:]]+@@; s@^[[:space:]]+@@; s@[[:space:]]+$@@')
        [ -z "$line" ] && continue
        esc=$(printf '%s' "$line" | cwm_html_escape)
        printf '<li>%s</li>\n' "$(cwm_inline_md "$esc")"
    done
}

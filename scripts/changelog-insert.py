#!/usr/bin/env python3
"""
Insert a generated <changelog> entry at the top of a Joomla changelog file.

Split out of generate-changelog-entry.sh so the placement rule is testable:

    python3 -m unittest discover -s tests/python -v

The rule is "insert immediately after the opening <changelogs> tag", and the
subtlety is which occurrence of that string counts. A naive substring search
matches the first one anywhere in the file — including inside the header
comment, where these files routinely explain what the root element is. When
that happened the entry was spliced into the middle of the comment, truncating
it and leaving a document that no longer parsed. Silently: the script still
reported success, and a changelog that will not parse looks to Joomla exactly
like one that is empty.

So occurrences inside XML comments are skipped.

Usage:
    changelog-insert.py <changelog-file>

The entry is read from the CHANGELOG_ENTRY environment variable.
"""

import os
import re
import sys

ROOT_TAG = 'changelogs'


def find_root_open_tag(content, root_tag=ROOT_TAG):
    """
    Return the index of the opening <root_tag> that starts the document body,
    ignoring any occurrence inside an XML comment.

    Returns -1 when the file has no such tag outside a comment.
    """
    commented = [
        (m.start(), m.end())
        for m in re.finditer(r'<!--.*?-->', content, re.S)
    ]

    for match in re.finditer(r'<%s(?:\s[^>]*)?>' % re.escape(root_tag), content):
        start = match.start()

        if any(lo <= start < hi for lo, hi in commented):
            continue

        return start

    return -1


def insert_entry(content, entry, root_tag=ROOT_TAG):
    """
    Return `content` with `entry` inserted on the line after the opening tag.

    Raises ValueError when no opening tag exists outside a comment.
    """
    pos = find_root_open_tag(content, root_tag)

    if pos == -1:
        raise ValueError(
            'Could not find a <%s> root element outside of comments.' % root_tag
        )

    line_end = content.find('\n', pos)

    # A file whose root tag is on the last line, with no trailing newline.
    if line_end == -1:
        return content + '\n\n' + entry + '\n'

    insert_at = line_end + 1

    return content[:insert_at] + '\n' + entry + '\n' + content[insert_at:]


def main(argv):
    if len(argv) != 2:
        print('Usage: changelog-insert.py <changelog-file>', file=sys.stderr)
        return 2

    changelog_file = argv[1]
    entry = os.environ.get('CHANGELOG_ENTRY', '')

    if not entry:
        print('Error: CHANGELOG_ENTRY is empty.', file=sys.stderr)
        return 1

    with open(changelog_file, 'r') as handle:
        content = handle.read()

    try:
        updated = insert_entry(content, entry)
    except ValueError as error:
        print('Error: %s' % error, file=sys.stderr)
        return 1

    with open(changelog_file, 'w') as handle:
        handle.write(updated)

    return 0


if __name__ == '__main__':
    sys.exit(main(sys.argv))

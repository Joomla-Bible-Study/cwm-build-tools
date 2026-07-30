"""
Tests for the entry placement in scripts/changelog-insert.py.

The regression these exist for shipped silently. The insertion used to be a
plain content.find('<changelogs>'), which matches the first occurrence
anywhere in the file — and these changelog files carry a header comment that
explains what the root element is, so the first occurrence was usually inside
that comment. The entry was spliced into the middle of the comment, truncating
it and leaving XML that no longer parsed, while the script still reported
success. A changelog that will not parse looks to Joomla exactly like one that
is empty, so nothing surfaced it.

Stdlib unittest, no dependencies:

    python3 -m unittest discover -s tests/python -v
"""

import importlib.util
import os
import unittest
import xml.etree.ElementTree as ET

# Hyphenated filename, so it is not importable as a module; load it by path.
_SCRIPT = os.path.join(
    os.path.dirname(os.path.dirname(os.path.dirname(os.path.abspath(__file__)))),
    'scripts', 'changelog-insert.py'
)
_spec = importlib.util.spec_from_file_location('changelog_insert', _SCRIPT)
changelog_insert = importlib.util.module_from_spec(_spec)
_spec.loader.exec_module(changelog_insert)


ENTRY = """    <changelog>
        <element>pkg_example</element>
        <type>package</type>
        <version>2.0.0</version>
        <date>2026-07-30</date>
        <fix>
            <item>Something was fixed.</item>
        </fix>
    </changelog>"""


PLAIN = """<?xml version="1.0" encoding="UTF-8"?>
<changelogs>

    <changelog>
        <element>pkg_example</element>
        <type>package</type>
        <version>1.0.0</version>
        <date>2026-01-01</date>
    </changelog>

</changelogs>
"""

# The shape that broke: the header comment names the root element.
COMMENT_NAMES_ROOT = """<?xml version="1.0" encoding="UTF-8"?>
<!--
    Joomla update changelog for pkg_example.

    This file must exist with a <changelogs> root before a release runs.
-->
<changelogs>

    <changelog>
        <element>pkg_example</element>
        <type>package</type>
        <version>1.0.0</version>
        <date>2026-01-01</date>
    </changelog>

</changelogs>
"""


def versions(xml_text):
    root = ET.fromstring(xml_text)
    return [c.findtext('version') for c in root.findall('changelog')]


class FindRootOpenTag(unittest.TestCase):
    def test_finds_a_bare_root(self):
        pos = changelog_insert.find_root_open_tag(PLAIN)
        self.assertEqual('<changelogs>', PLAIN[pos:pos + len('<changelogs>')])

    def test_skips_an_occurrence_inside_a_comment(self):
        pos = changelog_insert.find_root_open_tag(COMMENT_NAMES_ROOT)
        self.assertGreater(
            pos,
            COMMENT_NAMES_ROOT.index('-->'),
            'matched the root named inside the header comment',
        )

    def test_accepts_attributes_on_the_root(self):
        content = '<changelogs xmlns:foo="urn:x">\n</changelogs>\n'
        self.assertEqual(0, changelog_insert.find_root_open_tag(content))

    def test_reports_a_missing_root(self):
        self.assertEqual(-1, changelog_insert.find_root_open_tag('<other/>\n'))

    def test_a_root_only_ever_mentioned_in_a_comment_is_not_a_root(self):
        content = '<!-- the <changelogs> element -->\n<other/>\n'
        self.assertEqual(-1, changelog_insert.find_root_open_tag(content))


class InsertEntry(unittest.TestCase):
    def test_inserts_at_the_top_and_stays_parseable(self):
        got = changelog_insert.insert_entry(PLAIN, ENTRY)
        self.assertEqual(['2.0.0', '1.0.0'], versions(got))

    def test_does_not_splice_into_the_header_comment(self):
        got = changelog_insert.insert_entry(COMMENT_NAMES_ROOT, ENTRY)

        # The regression: this raised ParseError, because the entry landed
        # inside the comment and truncated it.
        self.assertEqual(['2.0.0', '1.0.0'], versions(got))

        # And the comment itself survives intact.
        self.assertIn('This file must exist with a <changelogs> root', got)

    def test_preserves_existing_entries_verbatim(self):
        got = changelog_insert.insert_entry(COMMENT_NAMES_ROOT, ENTRY)
        self.assertIn('<version>1.0.0</version>', got)

    def test_handles_a_root_on_the_final_line(self):
        got = changelog_insert.insert_entry(
            '<changelogs></changelogs>', ENTRY
        )
        self.assertIn('<version>2.0.0</version>', got)

    def test_raises_when_there_is_no_root(self):
        with self.assertRaises(ValueError):
            changelog_insert.insert_entry('<other/>\n', ENTRY)


if __name__ == '__main__':
    unittest.main()
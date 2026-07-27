"""
Tests for the placeholder/markup protection in scripts/sync-languages.py.

Every corruption asserted here was produced by a real translation run against
Proclaim's action-log strings, and shipped as valid INI that failed silently at
runtime. Joomla substitutes {placeholder} tokens when it renders each log row,
so a renamed token never matches and the literal text reaches the user.

Stdlib unittest, no dependencies:

    python3 -m unittest discover -s tests/python -v
"""

import importlib.util
import os
import unittest

# The script is not an importable module (hyphenated, and it runs work at
# import time only under __main__), so load it by path.
_SCRIPT = os.path.join(
    os.path.dirname(os.path.dirname(os.path.dirname(os.path.abspath(__file__)))),
    'scripts', 'sync-languages.py'
)
_spec = importlib.util.spec_from_file_location('sync_languages', _SCRIPT)
sync = importlib.util.module_from_spec(_spec)
_spec.loader.exec_module(sync)


# The en-GB source that was corrupted in the wild.
ACTION_LOG = (
    "User <a href='{accountlink}'>{username}</a> updated {type} "
    "<a href='{itemlink}'>{title}</a> ({origin})"
)


class MaskProtectedTest(unittest.TestCase):
    def test_placeholders_are_replaced_by_sentinels(self):
        masked, tokens = sync.mask_protected('Hello {username}')

        self.assertNotIn('{username}', masked)
        self.assertIn('{username}', tokens)

    def test_html_tags_are_protected(self):
        masked, tokens = sync.mask_protected("<a href='{link}'>x</a>")

        self.assertNotIn('<a', masked)
        self.assertNotIn('</a>', masked)
        self.assertIn('</a>', tokens)

    def test_printf_specifiers_are_protected(self):
        _, tokens = sync.mask_protected('Copied %1$s files in %s seconds')

        self.assertIn('%1$s', tokens)
        self.assertIn('%s', tokens)

    def test_every_specifier_style_in_the_real_files_is_protected(self):
        """
        A survey of Proclaim's en-GB files turned up %s, %d, %1$d..%4$d, %2$s,
        %t and %%. An earlier pattern covered only %s and %d, which would have
        left "Migrating %d of %t files..." half-protected.
        """
        source = 'Migrating %d of %t files, %1$d done, %2$s left, 100%% sure'
        masked, tokens = sync.mask_protected(source)

        for spec in ('%d', '%t', '%1$d', '%2$s', '%%'):
            self.assertIn(spec, tokens, f'{spec} must be protected')

        self.assertEqual(source, sync.unmask_protected(masked, tokens))

    def test_real_migrating_string_round_trips(self):
        source = 'Migrating %d of %t files...'
        masked, tokens = sync.mask_protected(source)

        self.assertEqual(source, sync.unmask_protected(masked, tokens))

    def test_translatable_words_survive_masking(self):
        masked, _ = sync.mask_protected(ACTION_LOG)

        for word in ('User', 'updated'):
            self.assertIn(word, masked)

    def test_round_trip_is_lossless(self):
        masked, tokens = sync.mask_protected(ACTION_LOG)

        self.assertEqual(ACTION_LOG, sync.unmask_protected(masked, tokens))

    def test_round_trip_with_ten_or_more_tokens(self):
        """ZQX1ZQX must not be mistaken for part of ZQX10ZQX."""
        text = ' '.join('{p%d}' % i for i in range(12))
        masked, tokens = sync.mask_protected(text)

        self.assertEqual(text, sync.unmask_protected(masked, tokens))

    def test_sentinel_recovered_despite_case_change(self):
        masked, tokens = sync.mask_protected('Hello {username}')

        self.assertIn('{username}', sync.unmask_protected(masked.lower(), tokens))

    def test_sentinel_recovered_despite_injected_spaces(self):
        masked, tokens = sync.mask_protected('Hello {username}')
        mangled = masked.replace('ZQX', 'Z Q X')

        self.assertIn('{username}', sync.unmask_protected(mangled, tokens))


class TranslationIsSafeTest(unittest.TestCase):
    def test_faithful_translation_is_accepted(self):
        translated = (
            "Benutzer <a href='{accountlink}'>{username}</a> aktualisierte {type} "
            "<a href='{itemlink}'>{title}</a> ({origin})"
        )

        self.assertTrue(sync.translation_is_safe(ACTION_LOG, translated))

    def test_reordered_placeholders_are_accepted(self):
        """Word order differs per language; only the set must match."""
        translated = (
            "<a href='{accountlink}'>{username}</a> felhasznalo frissitette {type} "
            "<a href='{itemlink}'>{title}</a> ({origin})"
        )

        self.assertTrue(sync.translation_is_safe(ACTION_LOG, translated))

    def test_translated_placeholder_name_is_rejected(self):
        """The nl-NL failure: {username} -> {gebruikersnaam}, never substitutes."""
        translated = (
            "Gebruiker <a href='{accountlink}'>{gebruikersnaam}</a> heeft {type} "
            "<a href='{itemlink}'>{titel}</a> bijgewerkt ({origin})"
        )

        self.assertFalse(sync.translation_is_safe(ACTION_LOG, translated))

    def test_eaten_closing_brace_is_rejected(self):
        """The hu-HU failure: {username</a> is an unmatchable token."""
        translated = (
            "<a href='{accountlink}'>{username</a> felhasznalo frissitette {type} "
            "<a href='{itemlink}'>{title</a> ({origin})"
        )

        self.assertFalse(sync.translation_is_safe(ACTION_LOG, translated))

    def test_unbalanced_anchor_is_rejected(self):
        """The cs-CZ failure: <a> used as a closer, leaving tags open."""
        translated = (
            "Uzivatel <a href='{accountlink}'>{username}<a> aktualizoval {type} "
            "<a href='{itemlink}'>{title}<a> ({origin})"
        )

        self.assertFalse(sync.translation_is_safe(ACTION_LOG, translated))

    def test_closing_tag_reduced_to_angle_bracket_is_rejected(self):
        """Also seen in nl-NL: </a> collapsed to >."""
        translated = (
            "Gebruiker <a href='{accountlink}'>{username}> heeft {type} "
            "<a href='{itemlink}'>{title}> bijgewerkt ({origin})"
        )

        self.assertFalse(sync.translation_is_safe(ACTION_LOG, translated))

    def test_dropped_placeholder_is_rejected(self):
        self.assertFalse(sync.translation_is_safe('Hello {username}', 'Hallo'))

    def test_duplicated_placeholder_is_rejected(self):
        self.assertFalse(
            sync.translation_is_safe('Hello {username}', 'Hallo {username} {username}')
        )

    def test_changed_printf_specifier_is_rejected(self):
        self.assertFalse(sync.translation_is_safe('Copied %1$s files', 'Kopierte %2$s Dateien'))

    def test_dropped_custom_token_is_rejected(self):
        """%t is filled in by the component, and breaks the same way if lost."""
        self.assertFalse(
            sync.translation_is_safe('Migrating %d of %t files...', 'Migration von %d Dateien...')
        )

    def test_reordered_specifiers_are_accepted(self):
        self.assertTrue(
            sync.translation_is_safe('Copied %1$s of %2$s', 'Von %2$s wurden %1$s kopiert')
        )

    def test_empty_translation_is_rejected(self):
        self.assertFalse(sync.translation_is_safe('Hello {username}', ''))
        self.assertFalse(sync.translation_is_safe('Hello {username}', None))
        self.assertFalse(sync.translation_is_safe('Save', '   '))

    def test_empty_source_may_stay_empty(self):
        """
        Language files legitimately contain KEY="". Rejecting those would have
        logged a spurious failure for every one — found by round-tripping all
        4145 real en-GB strings, where this was the only reported problem.
        """
        self.assertTrue(sync.translation_is_safe('', ''))
        self.assertTrue(sync.translation_is_safe('  ', ''))

    def test_plain_string_with_no_protected_parts_is_accepted(self):
        self.assertTrue(sync.translation_is_safe('Save', 'Speichern'))


class RealWorldRegressionTest(unittest.TestCase):
    """
    Each of these shipped. The mask/unmask cycle must reproduce the source
    exactly, so that a well-behaved engine returns something acceptable and a
    misbehaving one is caught rather than written.
    """

    CASES = [
        ACTION_LOG,
        "User <a href='{accountlink}'>{username}</a> deleted {type} {title} ({origin})",
        "<b><u>Embedded code:</u></b> Paste embed code. Use {mp3remote}-{/mp3remote}.",
        'Plain text with no placeholders at all',
        '{onlyplaceholder}',
    ]

    def test_all_round_trip(self):
        for source in self.CASES:
            with self.subTest(source=source[:40]):
                masked, tokens = sync.mask_protected(source)
                restored = sync.unmask_protected(masked, tokens)

                self.assertEqual(source, restored)
                self.assertTrue(sync.translation_is_safe(source, restored))


if __name__ == '__main__':
    unittest.main()
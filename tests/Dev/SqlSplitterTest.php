<?php

declare(strict_types=1);

namespace CWM\BuildTools\Tests\Dev;

use CWM\BuildTools\Dev\SqlSplitter;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The two ports of Joomla installer behaviour, pinned.
 *
 * Both methods are copies of Joomla code, and the value of a copy is that it
 * keeps behaving like the original. These cases are chosen to be the ones where
 * an "obvious" reimplementation diverges — `explode(';')` for splitting,
 * `str_replace('#__', ...)` for the prefix — so a future tidy-up that reaches
 * for either fails here rather than in a consumer's release.
 */
final class SqlSplitterTest extends TestCase
{
    #[Test]
    public function it_splits_on_semicolons(): void
    {
        self::assertSame(
            ['SELECT 1;', 'SELECT 2;'],
            SqlSplitter::split("SELECT 1;\nSELECT 2;"),
        );
    }

    #[Test]
    public function it_ignores_a_semicolon_inside_a_string_literal(): void
    {
        // explode(';') returns three fragments here, two of them not SQL.
        self::assertSame(
            ["INSERT INTO `#__x` (a) VALUES ('one; two');"],
            SqlSplitter::split("INSERT INTO `#__x` (a) VALUES ('one; two');"),
        );
    }

    #[Test]
    public function it_ignores_a_semicolon_inside_a_double_quoted_literal(): void
    {
        self::assertSame(
            ['INSERT INTO `#__x` (a) VALUES ("one; two");'],
            SqlSplitter::split('INSERT INTO `#__x` (a) VALUES ("one; two");'),
        );
    }

    #[Test]
    public function it_handles_an_escaped_quote_inside_a_literal(): void
    {
        self::assertSame(
            ["INSERT INTO `#__x` (a) VALUES ('it\\'s; fine');"],
            SqlSplitter::split("INSERT INTO `#__x` (a) VALUES ('it\\'s; fine');"),
        );
    }

    #[Test]
    public function it_strips_a_dash_comment(): void
    {
        self::assertSame(['SELECT 1;'], SqlSplitter::split("-- drop this; really\nSELECT 1;"));
    }

    #[Test]
    public function it_strips_a_block_comment(): void
    {
        self::assertSame(['SELECT 1;'], SqlSplitter::split("/* multi\nline; comment */\nSELECT 1;"));
    }

    #[Test]
    public function it_strips_a_hash_comment(): void
    {
        self::assertSame(['SELECT 1;'], SqlSplitter::split("# a comment; here\nSELECT 1;"));
    }

    #[Test]
    public function a_table_prefix_placeholder_is_not_a_hash_comment(): void
    {
        // `#` opens a comment but `#__` does not. Treating it as one would
        // swallow the rest of the line on nearly every statement in a Joomla
        // migration — the single most destructive way to get this wrong.
        self::assertSame(
            ['SELECT * FROM `#__bsms_studies`;'],
            SqlSplitter::split('SELECT * FROM `#__bsms_studies`;'),
        );
    }

    #[Test]
    public function it_keeps_a_mysql_conditional_comment(): void
    {
        // /*! ... */ is executable SQL, not a comment. Stripping it changes
        // what the server runs.
        self::assertSame(
            ['/*!40101 SET NAMES utf8 */;'],
            SqlSplitter::split('/*!40101 SET NAMES utf8 */;'),
        );
    }

    #[Test]
    public function it_keeps_an_optimizer_hint(): void
    {
        self::assertSame(
            ['SELECT /*+ MAX_EXECUTION_TIME(1000) */ 1;'],
            SqlSplitter::split('SELECT /*+ MAX_EXECUTION_TIME(1000) */ 1;'),
        );
    }

    #[Test]
    public function it_terminates_a_final_statement_that_has_no_semicolon(): void
    {
        self::assertSame(['SELECT 1;'], SqlSplitter::split('SELECT 1'));
    }

    #[Test]
    public function it_returns_nothing_for_empty_or_comment_only_input(): void
    {
        self::assertSame([], SqlSplitter::split(''));
        self::assertSame([], SqlSplitter::split(null));
        self::assertSame([], SqlSplitter::split("-- nothing\n# nothing\n"));
    }

    #[Test]
    public function it_keeps_the_can_fail_marker_attached_to_its_statement(): void
    {
        // The marker is a block comment, so it would ordinarily be stripped.
        // The splitter deliberately puts it back, because it is the only signal
        // that the installer tolerates this statement failing.
        $statements = SqlSplitter::split('ALTER TABLE `#__x` DROP INDEX i /** CAN FAIL **/;');

        self::assertStringEndsWith('/** CAN FAIL **/;', $statements[0]);
    }

    #[Test]
    public function it_recognises_a_can_fail_statement(): void
    {
        self::assertTrue(SqlSplitter::canFail('ALTER TABLE `#__x` DROP INDEX i /** CAN FAIL **/;'));
    }

    #[Test]
    public function can_fail_is_case_insensitive(): void
    {
        // Matching Joomla, which upper-cases before comparing.
        self::assertTrue(SqlSplitter::canFail('ALTER TABLE `#__x` DROP INDEX i /** can fail **/;'));
    }

    #[Test]
    public function an_ordinary_statement_may_not_fail(): void
    {
        self::assertFalse(SqlSplitter::canFail('ALTER TABLE `#__x` DROP INDEX i;'));
    }

    #[Test]
    public function stripping_removes_the_marker_and_leaves_runnable_sql(): void
    {
        self::assertSame(
            'ALTER TABLE `#__x` DROP INDEX i ;',
            SqlSplitter::strip('ALTER TABLE `#__x` DROP INDEX i /** CAN FAIL **/;'),
        );
    }

    #[Test]
    public function stripping_leaves_an_ordinary_statement_alone(): void
    {
        self::assertSame('SELECT 1;', SqlSplitter::strip('SELECT 1;'));
    }

    #[Test]
    public function it_substitutes_the_prefix(): void
    {
        self::assertSame(
            'ALTER TABLE `cwmreplay_bsms_studies` ADD x INT;',
            SqlSplitter::replacePrefix('ALTER TABLE `#__bsms_studies` ADD x INT;', 'cwmreplay_'),
        );
    }

    #[Test]
    public function it_leaves_a_prefix_placeholder_inside_a_string_literal_alone(): void
    {
        // str_replace('#__', $prefix, $sql) rewrites this one; Joomla does not.
        // A migration inserting a menu link or a params blob that mentions #__
        // would otherwise be replayed as SQL no site has ever run.
        self::assertSame(
            "INSERT INTO `cwmreplay_menu` (link) VALUES ('index.php?x=#__notatable');",
            SqlSplitter::replacePrefix(
                "INSERT INTO `#__menu` (link) VALUES ('index.php?x=#__notatable');",
                'cwmreplay_',
            ),
        );
    }

    #[Test]
    public function it_leaves_a_prefix_placeholder_inside_a_json_blob_alone(): void
    {
        self::assertSame(
            'UPDATE `cwmreplay_extensions` SET params = \'{"t":"#__bsms"}\' WHERE id = 1;',
            SqlSplitter::replacePrefix(
                'UPDATE `#__extensions` SET params = \'{"t":"#__bsms"}\' WHERE id = 1;',
                'cwmreplay_',
            ),
        );
    }

    #[Test]
    public function it_substitutes_on_both_sides_of_a_literal(): void
    {
        self::assertSame(
            "UPDATE `cwmreplay_a` SET b = 'keep #__this' WHERE c IN (SELECT d FROM `cwmreplay_e`);",
            SqlSplitter::replacePrefix(
                "UPDATE `#__a` SET b = 'keep #__this' WHERE c IN (SELECT d FROM `#__e`);",
                'cwmreplay_',
            ),
        );
    }
}

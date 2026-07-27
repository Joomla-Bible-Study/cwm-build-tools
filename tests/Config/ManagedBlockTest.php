<?php

declare(strict_types=1);

namespace CWM\BuildTools\Tests\Config;

use CWM\BuildTools\Config\ManagedBlock;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * This rewrites a consumer's .gitignore — a file they own and have edited. The
 * failure modes are losing their content or appending a duplicate block on
 * every sync. Neither is loud, and neither was covered before #32.
 */
class ManagedBlockTest extends TestCase
{
    private const ID = 'managed';

    private function wrapped(string $body, string $id = self::ID): string
    {
        return ManagedBlock::startMarker($id) . "\n" . $body . "\n" . ManagedBlock::endMarker($id) . "\n";
    }

    #[Test]
    public function a_block_is_appended_when_absent(): void
    {
        $out = ManagedBlock::upsert("/vendor/\n/node_modules/\n", self::ID, "/build/dist/");

        self::assertStringContainsString('/vendor/', $out, "The consumer's own entries survive");
        self::assertStringContainsString('/node_modules/', $out);
        self::assertStringContainsString($this->wrapped('/build/dist/'), $out);
    }

    #[Test]
    public function an_existing_block_is_replaced_not_duplicated(): void
    {
        $first  = ManagedBlock::upsert("/vendor/\n", self::ID, '/build/dist/');
        $second = ManagedBlock::upsert($first, self::ID, '/build/out/');

        self::assertSame(1, substr_count($second, ManagedBlock::startMarker(self::ID)));
        self::assertStringContainsString('/build/out/', $second);
        self::assertStringNotContainsString('/build/dist/', $second);
    }

    /**
     * Syncing twice with the same body must not change the file, or every sync
     * produces a diff and people stop reading them.
     */
    #[Test]
    public function upserting_the_same_body_twice_is_idempotent(): void
    {
        $once  = ManagedBlock::upsert("/vendor/\n", self::ID, '/build/dist/');
        $twice = ManagedBlock::upsert($once, self::ID, '/build/dist/');

        self::assertSame($once, $twice);
    }

    /**
     * The block must be replaced where it stands. Moving it to the end on each
     * sync would produce a spurious diff every time.
     */
    #[Test]
    public function a_replaced_block_keeps_its_position(): void
    {
        $content = "# top\n" . $this->wrapped('/old/') . "\n# bottom\n";

        $out = ManagedBlock::upsert($content, self::ID, '/new/');

        self::assertLessThan(
            strpos($out, '# bottom'),
            strpos($out, '/new/'),
            'Block should stay above the trailing content'
        );
        self::assertStringContainsString('# top', $out);
        self::assertStringContainsString('# bottom', $out);
    }

    #[Test]
    public function an_empty_body_removes_an_existing_block(): void
    {
        $content = ManagedBlock::upsert("/vendor/\n", self::ID, '/build/dist/');

        $out = ManagedBlock::upsert($content, self::ID, '');

        self::assertStringNotContainsString(ManagedBlock::startMarker(self::ID), $out);
        self::assertStringNotContainsString('/build/dist/', $out);
        self::assertStringContainsString('/vendor/', $out, "The consumer's entries must survive removal");
    }

    #[Test]
    public function an_empty_body_on_content_with_no_block_changes_nothing(): void
    {
        self::assertSame('/vendor/', ManagedBlock::upsert('/vendor/', self::ID, ''));
    }

    /**
     * Two blocks are maintained in .gitignore — 'managed' and 'extension
     * paths'. Editing one must not disturb the other.
     */
    #[Test]
    public function blocks_with_different_ids_do_not_interfere(): void
    {
        $out = ManagedBlock::upsert("/vendor/\n", 'managed', '/build/dist/');
        $out = ManagedBlock::upsert($out, 'extension paths', '/media/foo/');

        self::assertTrue(ManagedBlock::has($out, 'managed'));
        self::assertTrue(ManagedBlock::has($out, 'extension paths'));

        $out = ManagedBlock::upsert($out, 'managed', '/build/other/');

        self::assertStringContainsString('/media/foo/', $out, 'The other block must be untouched');
        self::assertStringContainsString('/build/other/', $out);
    }

    /**
     * Removing one of two adjacent blocks must not swallow the other. The
     * pattern is non-greedy, which is what makes this work — a greedy one would
     * match from the first start marker to the last end marker.
     */
    #[Test]
    public function removing_one_of_two_adjacent_blocks_leaves_the_other(): void
    {
        $out = ManagedBlock::upsert('', 'managed', '/a/');
        $out = ManagedBlock::upsert($out, 'extension paths', '/b/');

        $out = ManagedBlock::upsert($out, 'managed', '');

        self::assertFalse(ManagedBlock::has($out, 'managed'));
        self::assertTrue(ManagedBlock::has($out, 'extension paths'));
        self::assertStringContainsString('/b/', $out);
    }

    /**
     * A block id is interpolated into a regex. preg_quote is what stops one
     * containing metacharacters from turning the search into a wildcard.
     */
    #[Test]
    public function a_block_id_containing_regex_metacharacters_is_matched_literally(): void
    {
        $id = 'weird.id(*)';

        $out = ManagedBlock::upsert("/vendor/\n", $id, '/x/');
        $out = ManagedBlock::upsert($out, $id, '/y/');

        self::assertSame(1, substr_count($out, ManagedBlock::startMarker($id)));
        self::assertStringContainsString('/y/', $out);
        self::assertStringContainsString('/vendor/', $out);
    }

    #[Test]
    public function a_multi_line_body_round_trips(): void
    {
        $body = "/build/dist/\n/media/js/\n/media/css/";

        $out = ManagedBlock::upsert('', self::ID, $body);

        self::assertStringContainsString($body, $out);

        // And replacing it with a shorter body leaves no remnant of the longer.
        $shorter = ManagedBlock::upsert($out, self::ID, '/build/dist/');

        self::assertStringNotContainsString('/media/css/', $shorter);
    }

    #[Test]
    public function surrounding_blank_lines_do_not_accumulate(): void
    {
        $out = "/vendor/\n";

        for ($i = 0; $i < 5; $i++) {
            $out = ManagedBlock::upsert($out, self::ID, '/build/dist/');
        }

        self::assertStringNotContainsString("\n\n\n", $out, 'Repeated syncs must not add blank lines');
    }

    #[Test]
    public function has_reports_presence_accurately(): void
    {
        self::assertFalse(ManagedBlock::has('/vendor/', self::ID));
        self::assertTrue(ManagedBlock::has(ManagedBlock::upsert('', self::ID, '/x/'), self::ID));
    }
}

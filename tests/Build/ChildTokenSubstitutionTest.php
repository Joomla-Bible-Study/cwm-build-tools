<?php

declare(strict_types=1);

namespace CWM\BuildTools\Tests\Build;

use CWM\BuildTools\Build\ChildTokenSubstitution;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ChildTokenSubstitutionTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/cwm-child-subst-' . bin2hex(random_bytes(6));
        mkdir($this->tmpDir, 0o777, true);
    }

    protected function tearDown(): void
    {
        $this->rrmdir($this->tmpDir);
    }

    // -----------------------------------------------------------------
    // the case this exists for
    // -----------------------------------------------------------------

    /**
     * The child is a submodule, so its root carries a `.git` FILE. Substituting
     * it is correct here — this stands in for the child's own release, which is
     * the only thing that knows the child's version.
     *
     * It works because `TokenSubstituter`'s submodule guard is an iterator
     * filter: it sees entries encountered during descent, never the walk root.
     * If that is ever hardened to check the root as well, this test fails —
     * which is the point. Without it, hardening would make
     * `ChildTokenSubstitution` silently substitute nothing and every other test
     * here would still pass, because a non-submodule fixture cannot tell the
     * difference.
     */
    #[Test]
    public function substitutes_a_child_whose_root_is_a_submodule(): void
    {
        $this->seedChild(version: '1.2.5');
        file_put_contents($this->tmpDir . '/.git', "gitdir: ../../.git/modules/child\n");

        $rewritten = $this->runQuiet(fn () => (new ChildTokenSubstitution($this->tmpDir))->apply());

        self::assertGreaterThan(0, $rewritten, 'A submodule child must still be substituted.');
        self::assertStringContainsString('@since 1.2.5', $this->read('src/Thing.php'));
    }

    /**
     * The child releases on its own cadence, so it gets ITS version — not the
     * wrapper's. Proclaim 10.4.1 stamping 10.4.1 into a plugin whose own version
     * was 1.1.5 is the bug on the other side of this line (#1704).
     */
    #[Test]
    public function uses_the_childs_own_version_not_the_parents(): void
    {
        $this->seedChild(version: '1.2.5');

        $this->runQuiet(fn () => (new ChildTokenSubstitution($this->tmpDir))->apply());

        self::assertStringContainsString('@since 1.2.5', $this->read('src/Thing.php'));
        self::assertStringNotContainsString('__DEPLOY_VERSION__', $this->read('src/Thing.php'));
    }

    /**
     * The 10 occurrences that actually failed in pkg_cwmscripture 1.2.5 were in
     * `build/script.install.php`, which is not in `paths` — it is folded in from
     * `package.installer` (#75). Substituting only the declared paths would fix
     * nothing that mattered.
     */
    #[Test]
    public function covers_the_package_installer_which_is_not_in_paths(): void
    {
        $this->seedChild(version: '1.2.5');

        $this->runQuiet(fn () => (new ChildTokenSubstitution($this->tmpDir))->apply());

        self::assertStringContainsString('@since 1.2.5', $this->read('build/script.install.php'));
    }

    /** A submodule nested inside the child is that repo's business, not ours. */
    #[Test]
    public function leaves_a_submodule_nested_inside_the_child_alone(): void
    {
        $this->seedChild(version: '1.2.5');
        mkdir($this->tmpDir . '/src/libraries/inner', 0o777, true);
        file_put_contents($this->tmpDir . '/src/libraries/inner/.git', "gitdir: elsewhere\n");
        file_put_contents(
            $this->tmpDir . '/src/libraries/inner/Deep.php',
            "<?php\n/** @since __DEPLOY_VERSION__ */\n"
        );

        $this->runQuiet(fn () => (new ChildTokenSubstitution($this->tmpDir))->apply());

        self::assertStringContainsString(
            '__DEPLOY_VERSION__',
            $this->read('src/libraries/inner/Deep.php'),
            "A nested submodule's placeholder belongs to its own release."
        );
    }

    // -----------------------------------------------------------------
    // restore — the child is a submodule, so leaving it dirty is drift
    // -----------------------------------------------------------------

    #[Test]
    public function restores_every_file_byte_for_byte(): void
    {
        $this->seedChild(version: '1.2.5');

        $before = [
            'src/Thing.php'           => $this->read('src/Thing.php'),
            'build/script.install.php' => $this->read('build/script.install.php'),
        ];

        $subst = new ChildTokenSubstitution($this->tmpDir);
        $this->runQuiet(fn () => $subst->apply());
        self::assertStringNotContainsString('__DEPLOY_VERSION__', $this->read('src/Thing.php'));

        $subst->restore();

        foreach ($before as $rel => $contents) {
            self::assertSame($contents, $this->read($rel), "$rel was not restored exactly.");
        }
    }

    /**
     * `Packager::resolveSubBuild()` restores from a `finally`, which fires on
     * paths where nothing was substituted — a child that never opted in, or one
     * whose build blew up before anything happened.
     */
    #[Test]
    public function restore_is_safe_when_nothing_was_substituted(): void
    {
        $subst = new ChildTokenSubstitution($this->tmpDir);

        $subst->restore();
        $subst->restore();

        self::assertTrue(true, 'restore() must be callable with no apply() and callable twice.');
    }

    // -----------------------------------------------------------------
    // opt-in and failure modes
    // -----------------------------------------------------------------

    #[Test]
    public function a_child_with_no_config_is_left_alone(): void
    {
        file_put_contents($this->tmpDir . '/whatever.php', "<?php\n// __DEPLOY_VERSION__\n");

        self::assertSame(0, $this->runQuiet(fn () => (new ChildTokenSubstitution($this->tmpDir))->apply()));
        self::assertStringContainsString('__DEPLOY_VERSION__', $this->read('whatever.php'));
    }

    #[Test]
    public function a_child_that_has_not_opted_into_substitution_is_left_alone(): void
    {
        $this->seedChild(version: '1.2.5');
        file_put_contents(
            $this->tmpDir . '/cwm-build.config.json',
            json_encode(['package' => ['manifest' => 'build/manifest.xml']])
        );

        self::assertSame(0, $this->runQuiet(fn () => (new ChildTokenSubstitution($this->tmpDir))->apply()));
        self::assertStringContainsString('__DEPLOY_VERSION__', $this->read('src/Thing.php'));
    }

    /**
     * Opted in but unresolvable: throwing is the point. Skipping quietly would
     * reproduce the exact bug this class exists to prevent, invisibly.
     */
    #[Test]
    public function throws_when_an_opted_in_child_has_no_readable_manifest(): void
    {
        $this->seedChild(version: '1.2.5');
        unlink($this->tmpDir . '/build/manifest.xml');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/own version cannot be resolved/');

        $this->runQuiet(fn () => (new ChildTokenSubstitution($this->tmpDir))->apply());
    }

    // -----------------------------------------------------------------
    // helpers
    // -----------------------------------------------------------------

    private function seedChild(string $version): void
    {
        mkdir($this->tmpDir . '/src', 0o777, true);
        mkdir($this->tmpDir . '/build', 0o777, true);

        file_put_contents($this->tmpDir . '/src/Thing.php', "<?php\n/** @since __DEPLOY_VERSION__ */\n");
        file_put_contents(
            $this->tmpDir . '/build/script.install.php',
            "<?php\n/** @since __DEPLOY_VERSION__ */\n"
        );
        file_put_contents(
            $this->tmpDir . '/build/manifest.xml',
            "<?xml version=\"1.0\"?>\n<extension><version>$version</version></extension>\n"
        );
        file_put_contents($this->tmpDir . '/cwm-build.config.json', json_encode([
            'package' => [
                'manifest'  => 'build/manifest.xml',
                'installer' => 'build/script.install.php',
            ],
            'versionTracking' => [
                'substituteTokens' => ['paths' => ['src/'], 'extensions' => ['php']],
            ],
        ]));
    }

    private function runQuiet(callable $fn): mixed
    {
        ob_start();

        try {
            return $fn();
        } finally {
            ob_end_clean();
        }
    }

    private function read(string $relative): string
    {
        return (string) file_get_contents($this->tmpDir . '/' . $relative);
    }

    private function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $dir . '/' . $entry;

            is_dir($path) ? $this->rrmdir($path) : @unlink($path);
        }

        @rmdir($dir);
    }
}

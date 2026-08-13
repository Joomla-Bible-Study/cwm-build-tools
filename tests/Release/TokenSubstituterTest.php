<?php

declare(strict_types=1);

namespace CWM\BuildTools\Tests\Release;

use CWM\BuildTools\Release\TokenSubstituter;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class TokenSubstituterTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/cwm-token-substituter-' . bin2hex(random_bytes(6));
        mkdir($this->tmpDir, 0o777, true);
    }

    protected function tearDown(): void
    {
        $this->rrmdir($this->tmpDir);
    }

    // -----------------------------------------------------------------
    // nested repositories — submodules own their own version
    // -----------------------------------------------------------------

    /**
     * Proclaim 10.4.1 stamped `@since 10.4.1` into a plugin whose own version was
     * 1.1.5, because its `plugins/` path contains a submodule. The submodule was
     * left dirty too, so the wrong values could be committed to that repository
     * later by accident.
     */
    #[Test]
    public function skips_a_submodule_checkout(): void
    {
        $this->seedFile('plugins/mine/Plugin.php', "<?php\n/** @since  __DEPLOY_VERSION__ */\n");
        $this->seedFile('plugins/theirs/Plugin.php', "<?php\n/** @since  __DEPLOY_VERSION__ */\n");

        // A submodule checkout carries a `.git` FILE pointing at the parent's
        // modules directory — not a directory, which is why the existing
        // ALWAYS_SKIP list never caught it.
        file_put_contents(
            $this->tmpDir . '/plugins/theirs/.git',
            "gitdir: ../../.git/modules/plugins/theirs\n"
        );

        $touched = $this->runQuiet(fn () => $this->substituter(['paths' => ['plugins/']])->substitute('10.4.1'));

        self::assertCount(1, $touched, 'Only our own plugin should be rewritten.');
        self::assertStringContainsString('@since  10.4.1', $this->read('plugins/mine/Plugin.php'));
        self::assertStringContainsString(
            '__DEPLOY_VERSION__',
            $this->read('plugins/theirs/Plugin.php'),
            "A submodule's placeholder is its own release's business."
        );
    }

    #[Test]
    public function skips_a_plain_nested_clone(): void
    {
        $this->seedFile('libraries/ours/File.php', "<?php\n/** @since  __DEPLOY_VERSION__ */\n");
        $this->seedFile('libraries/nested/File.php', "<?php\n/** @since  __DEPLOY_VERSION__ */\n");

        // Same hazard as a submodule, undeclared: a clone sitting inside the tree
        // has a `.git` directory. ALWAYS_SKIP would drop the `.git` dir itself but
        // still walk into the clone's source.
        mkdir($this->tmpDir . '/libraries/nested/.git', 0o777, true);

        $touched = $this->runQuiet(fn () => $this->substituter(['paths' => ['libraries/']])->substitute('10.4.1'));

        self::assertCount(1, $touched);
        self::assertStringContainsString('__DEPLOY_VERSION__', $this->read('libraries/nested/File.php'));
    }

    #[Test]
    public function still_substitutes_when_the_project_root_itself_is_a_repository(): void
    {
        // The guard must not fire on the project being released: its own root has
        // a .git, and skipping it would make the whole feature a no-op.
        mkdir($this->tmpDir . '/.git', 0o777, true);
        $this->seedFile('src/Model.php', "<?php\n/** @since  __DEPLOY_VERSION__ */\n");

        $touched = $this->runQuiet(fn () => $this->substituter(['paths' => ['src/']])->substitute('1.2.3'));

        self::assertCount(1, $touched, 'A repository root is not a nested repository.');
        self::assertStringContainsString('@since  1.2.3', $this->read('src/Model.php'));
    }

    #[Test]
    public function replaces_default_token_in_php_files_under_configured_paths(): void
    {
        $this->seedFile('src/Model.php', "<?php\n/**\n * @since  __DEPLOY_VERSION__\n */\nclass Model {}\n");
        $this->seedFile('src/View.php',  "<?php\n/**\n * @since  __DEPLOY_VERSION__\n */\nclass View {}\n");

        $touched = $this->runQuiet(fn () => $this->substituter(['paths' => ['src/']])->substitute('1.2.3'));

        self::assertCount(2, $touched);
        self::assertStringContainsString('@since  1.2.3', $this->read('src/Model.php'));
        self::assertStringContainsString('@since  1.2.3', $this->read('src/View.php'));
        self::assertStringNotContainsString('__DEPLOY_VERSION__', $this->read('src/Model.php'));
    }

    #[Test]
    public function leaves_files_without_token_untouched(): void
    {
        $this->seedFile('src/NoToken.php', "<?php\n/**\n * @since  1.0.0\n */\nclass NoToken {}\n");
        $before = filemtime($this->tmpDir . '/src/NoToken.php');

        clearstatcache();
        sleep(1);

        $touched = $this->runQuiet(fn () => $this->substituter(['paths' => ['src/']])->substitute('1.2.3'));

        self::assertSame([], $touched);

        $after = filemtime($this->tmpDir . '/src/NoToken.php');
        self::assertSame($before, $after);
    }

    #[Test]
    public function honours_custom_token(): void
    {
        $this->seedFile('src/Model.php', "<?php\n// @since  {{VERSION}}\nclass Model {}\n");

        $this->runQuiet(fn () => $this->substituter([
            'paths' => ['src/'],
            'token' => '{{VERSION}}',
        ])->substitute('1.2.3'));

        self::assertStringContainsString('@since  1.2.3', $this->read('src/Model.php'));
    }

    #[Test]
    public function honours_extensions_filter(): void
    {
        $this->seedFile('src/Model.php',     "<?php\n// __DEPLOY_VERSION__\n");
        $this->seedFile('src/template.tpl',  "// __DEPLOY_VERSION__\n");
        $this->seedFile('src/script.js',     "// __DEPLOY_VERSION__\n");

        $this->runQuiet(fn () => $this->substituter([
            'paths'      => ['src/'],
            'extensions' => ['php', 'tpl'],
        ])->substitute('1.2.3'));

        self::assertStringContainsString('1.2.3', $this->read('src/Model.php'));
        self::assertStringContainsString('1.2.3', $this->read('src/template.tpl'));
        // .js is not in the filter
        self::assertStringContainsString('__DEPLOY_VERSION__', $this->read('src/script.js'));
    }

    #[Test]
    public function recurses_into_subdirectories(): void
    {
        $this->seedFile('admin/src/Controller.php',                "<?php\n// __DEPLOY_VERSION__\n");
        $this->seedFile('admin/src/View/Items/HtmlView.php',       "<?php\n// __DEPLOY_VERSION__\n");
        $this->seedFile('admin/src/View/Items/Tmpl/default.php',   "<?php\n// __DEPLOY_VERSION__\n");

        $touched = $this->runQuiet(fn () => $this->substituter(['paths' => ['admin/']])->substitute('1.2.3'));

        self::assertCount(3, $touched);
    }

    #[Test]
    public function always_skips_vendor_node_modules_and_git_directories(): void
    {
        $this->seedFile('src/Model.php',                 "<?php\n// __DEPLOY_VERSION__\n");
        $this->seedFile('src/vendor/dep/Lib.php',        "<?php\n// __DEPLOY_VERSION__\n");
        $this->seedFile('src/node_modules/pkg/index.js', "// __DEPLOY_VERSION__\n");
        // Nested one level down rather than at `src/` itself: a `.git` directly
        // inside the walk root makes that root a repository, and #92's check
        // now skips such a root wholesale — which would skip everything here.
        //
        // Note this line does not pin ALWAYS_SKIP's `.git` entry, and the
        // original `src/.git/HEAD` fixture did not either: `HEAD` fails the
        // extension filter anyway. The two rules also overlap — any `.git`
        // *directory* makes its parent look like a nested repository, so the
        // submodule filter skips it whether or not `.git` is in ALWAYS_SKIP.
        // What this asserts is the outcome that matters: nothing under it is
        // rewritten. Kept as a `.php` file so the extension filter is not
        // what produces the result.
        $this->seedFile('src/inner/.git/hooks/pre-commit.php', "<?php\n// __DEPLOY_VERSION__\n");

        $touched = $this->runQuiet(fn () => $this->substituter([
            'paths'      => ['src/'],
            'extensions' => ['php', 'js'],
        ])->substitute('1.2.3'));

        self::assertCount(1, $touched);
        self::assertStringContainsString('__DEPLOY_VERSION__', $this->read('src/vendor/dep/Lib.php'));
        self::assertStringContainsString('__DEPLOY_VERSION__', $this->read('src/node_modules/pkg/index.js'));
    }

    #[Test]
    public function missing_path_warns_but_does_not_throw(): void
    {
        $touched = $this->runQuiet(fn () => $this->substituter(['paths' => ['nonexistent/']])->substitute('1.2.3'));

        self::assertSame([], $touched);
    }

    #[Test]
    public function empty_paths_list_is_noop(): void
    {
        $this->seedFile('src/Model.php', "<?php\n// __DEPLOY_VERSION__\n");

        $touched = $this->runQuiet(fn () => $this->substituter(['paths' => []])->substitute('1.2.3'));

        self::assertSame([], $touched);
        self::assertStringContainsString('__DEPLOY_VERSION__', $this->read('src/Model.php'));
    }

    #[Test]
    public function replaces_multiple_occurrences_per_file(): void
    {
        $this->seedFile('src/Model.php',
            "<?php\n"
            . "/**\n * @since  __DEPLOY_VERSION__\n */\n"
            . "class Model {\n"
            . "    /** @since  __DEPLOY_VERSION__ */\n"
            . "    public function foo() {}\n"
            . "    /** @since  __DEPLOY_VERSION__ */\n"
            . "    public function bar() {}\n"
            . "}\n");

        $this->runQuiet(fn () => $this->substituter(['paths' => ['src/']])->substitute('1.2.3'));

        $contents = $this->read('src/Model.php');
        self::assertSame(3, substr_count($contents, '@since  1.2.3'));
        self::assertStringNotContainsString('__DEPLOY_VERSION__', $contents);
    }

    #[Test]
    public function single_file_path_works(): void
    {
        $this->seedFile('build/script.php', "<?php\n// __DEPLOY_VERSION__\n");

        $touched = $this->runQuiet(fn () => $this->substituter([
            'paths' => ['build/script.php'],
        ])->substitute('1.2.3'));

        self::assertCount(1, $touched);
        self::assertStringContainsString('1.2.3', $this->read('build/script.php'));
    }

    // --- helpers ----------------------------------------------------------

    /**
     * @param array{token?: string, paths?: list<string>, extensions?: list<string>} $config
     */
    private function substituter(array $config): TokenSubstituter
    {
        return new TokenSubstituter($this->tmpDir, $config);
    }

    /**
     * The 1.16.0 guard is an iterator filter, and a filter never sees the root
     * it was handed — so naming a submodule *directly* in `paths` walked
     * straight into it and stamped another repo's source with this repo's
     * version (#92). `libraries/` was safe only because descent found the
     * submodule one level down.
     */
    #[Test]
    public function skips_a_submodule_named_directly_in_paths(): void
    {
        $this->seedFile('libraries/lib_thing/src/Thing.php', "<?php\n/** @since  __DEPLOY_VERSION__ */\n");
        file_put_contents($this->tmpDir . '/libraries/lib_thing/.git', "gitdir: ../../.git/modules/lib_thing\n");

        $touched = $this->runQuiet(
            fn () => $this->substituter(['paths' => ['libraries/lib_thing/']])->substitute('10.5.8')
        );

        self::assertSame([], $touched, 'A submodule path root must not be substituted.');
        self::assertStringContainsString(
            '__DEPLOY_VERSION__',
            $this->read('libraries/lib_thing/src/Thing.php'),
            "That placeholder belongs to the submodule's own release."
        );
    }

    /** The skip is about submodules, not about deep paths — ordinary roots still work. */
    #[Test]
    public function still_substitutes_a_path_root_that_is_not_a_submodule(): void
    {
        $this->seedFile('libraries/mine/src/Thing.php', "<?php\n/** @since  __DEPLOY_VERSION__ */\n");

        $touched = $this->runQuiet(
            fn () => $this->substituter(['paths' => ['libraries/mine/']])->substitute('10.5.8')
        );

        self::assertCount(1, $touched);
        self::assertStringContainsString('@since  10.5.8', $this->read('libraries/mine/src/Thing.php'));
    }

    /**
     * `pathsWithInstaller()` adds a FILE as a path root. The submodule check
     * must not swallow it — that file is where #75's tokens live.
     */
    #[Test]
    public function a_file_path_root_is_unaffected_by_the_submodule_check(): void
    {
        $this->seedFile('build/script.install.php', "<?php\n/** @since  __DEPLOY_VERSION__ */\n");

        $touched = $this->runQuiet(
            fn () => $this->substituter(['paths' => ['build/script.install.php']])->substitute('10.5.8')
        );

        self::assertCount(1, $touched);
        self::assertStringContainsString('@since  10.5.8', $this->read('build/script.install.php'));
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

    private function seedFile(string $relative, string $contents): void
    {
        $absolute = $this->tmpDir . '/' . $relative;
        $dir      = dirname($absolute);

        if (!is_dir($dir)) {
            mkdir($dir, 0o777, true);
        }

        file_put_contents($absolute, $contents);
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

            $path = "$dir/$entry";

            if (is_link($path) || !is_dir($path)) {
                @unlink($path);
            } else {
                $this->rrmdir($path);
            }
        }

        @rmdir($dir);
    }

    public function testInstallerIsFoldedIntoThePaths(): void
    {
        // The shape that shipped __DEPLOY_VERSION__ for every release: build/ is
        // deliberately absent from paths, and the installer lives there.
        $paths = TokenSubstituter::pathsWithInstaller(
            ['paths' => ['admin/', 'site/']],
            ['package' => ['installer' => 'build/script.install.php']],
        );

        $this->assertSame(['admin/', 'site/', 'build/script.install.php'], $paths);
    }

    public function testBuildScriptFileIsAlsoFoldedIn(): void
    {
        $paths = TokenSubstituter::pathsWithInstaller(
            ['paths' => ['src/']],
            ['build' => ['scriptFile' => 'script.php']],
        );

        $this->assertSame(['src/', 'script.php'], $paths);
    }

    public function testInstallerIsNotAddedTwiceWhenAlreadyListed(): void
    {
        $paths = TokenSubstituter::pathsWithInstaller(
            ['paths' => ['build/script.install.php']],
            ['package' => ['installer' => 'build/script.install.php']],
        );

        $this->assertSame(['build/script.install.php'], $paths);
    }

    public function testNoInstallerConfiguredLeavesPathsAlone(): void
    {
        $this->assertSame(['admin/'], TokenSubstituter::pathsWithInstaller(['paths' => ['admin/']], []));
    }
}

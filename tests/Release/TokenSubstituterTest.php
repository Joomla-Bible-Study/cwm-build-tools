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
        $this->seedFile('src/.git/HEAD',                 "ref: __DEPLOY_VERSION__\n");

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
}

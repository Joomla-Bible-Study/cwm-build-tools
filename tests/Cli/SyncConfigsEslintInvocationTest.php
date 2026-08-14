<?php

declare(strict_types=1);

namespace CWM\BuildTools\Tests\Cli;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * sync-configs warns when a consumer's lint:js does not pin its ESLint config.
 *
 * Run through the real CLI rather than by calling the function: scripts/ has no
 * autoloader and loads everything by path, which is the gap PackageCliTest
 * exists to cover. A handler that is never reached from main() would pass any
 * unit test and still do nothing.
 *
 * Always with --dry-run: the script writes .gitignore and friends, and a test
 * that mutates its own fixture tells you less each time it runs.
 */
final class SyncConfigsEslintInvocationTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/cwm-sync-eslint-' . bin2hex(random_bytes(6));
        mkdir($this->tmpDir, 0o777, true);

        // Minimal project: the script needs a config file to run at all.
        file_put_contents(
            $this->tmpDir . '/cwm-build.config.json',
            json_encode(['extension' => ['type' => 'component', 'name' => 'com_example']]),
        );
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tmpDir . '/*') ?: [] as $f) {
            @unlink($f);
        }

        @rmdir($this->tmpDir);
    }

    /**
     * @return array<string, array{0: string, 1: bool}>
     */
    public static function scripts(): array
    {
        return [
            // The three forms actually in use across the consuming repos.
            'unpinned (CWMLivingWord)'   => ['eslint --max-warnings=0 .', true],
            'no-config-lookup (Proclaim)' => ['eslint --no-config-lookup -c eslint.config.mjs --max-warnings=0 .', false],
            '-c (lib_cwmscripture)'      => ['eslint -c build/eslint.config.mjs --max-warnings=0 .', false],
            // --config is the long form of -c and pins just as well.
            'long --config'              => ['eslint --config eslint.config.mjs .', false],
        ];
    }

    #[Test]
    #[DataProvider('scripts')]
    public function warns_only_when_the_config_is_not_pinned(string $lintScript, bool $expectWarning): void
    {
        file_put_contents(
            $this->tmpDir . '/package.json',
            json_encode(['name' => 'example', 'scripts' => ['lint:js' => $lintScript]]),
        );

        $output = $this->runSync();

        if ($expectWarning) {
            self::assertStringContainsString('lint:js does not pin an ESLint config', $output);
            // The warning is only useful if it says what to write instead.
            self::assertStringContainsString('--no-config-lookup', $output);
        } else {
            self::assertStringNotContainsString('lint:js does not pin', $output);
        }
    }

    #[Test]
    public function stays_quiet_when_the_project_has_no_package_json(): void
    {
        self::assertStringNotContainsString('lint:js', $this->runSync());
    }

    #[Test]
    public function stays_quiet_when_package_json_is_malformed(): void
    {
        // A broken package.json is another handler's problem to report, and a
        // crash here would take the whole sync down with it.
        file_put_contents($this->tmpDir . '/package.json', '{ not json');

        $output = $this->runSync();

        self::assertStringNotContainsString('lint:js', $output);
        self::assertStringNotContainsString('Fatal error', $output);
    }

    private function runSync(): string
    {
        $script = \dirname(__DIR__, 2) . '/scripts/sync-configs.php';
        $cmd    = 'cd ' . escapeshellarg($this->tmpDir)
            . ' && php ' . escapeshellarg($script) . ' --dry-run 2>&1';

        return (string) shell_exec($cmd);
    }
}

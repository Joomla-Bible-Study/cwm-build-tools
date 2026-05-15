<?php

declare(strict_types=1);

namespace CWM\BuildTools\Tests\Dev;

use CWM\BuildTools\Dev\ExtensionInstaller;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * ExtensionInstaller shells out to the bundled Joomla CLI. We can't
 * mock proc_open cleanly, so the strategy is: build a stand-in
 * `cli/joomla.php` script that captures its argv to disk, then assert
 * the captured argv matches the expected shape. That validates both
 * that we used the documented command + flag form and that proc_open
 * received array-form arguments (string-form would lose distinct
 * tokens after shell parsing).
 */
final class ExtensionInstallerTest extends TestCase
{
    private string $tmpDir;
    private string $joomlaRoot;
    private string $capturePath;

    protected function setUp(): void
    {
        $this->tmpDir      = sys_get_temp_dir() . '/cwm-ext-installer-' . bin2hex(random_bytes(6));
        $this->joomlaRoot  = $this->tmpDir . '/joomla';
        $this->capturePath = $this->tmpDir . '/argv.json';

        mkdir($this->joomlaRoot . '/cli', 0o777, true);

        // Stand-in Joomla CLI that captures its arguments and exits 0
        // unless argv contains "--simulate-failure".
        $stub = <<<'PHP'
        #!/usr/bin/env php
        <?php
        $capturePath = getenv('CWM_TEST_CAPTURE_PATH');
        file_put_contents($capturePath, json_encode($argv) . "\n", FILE_APPEND);

        if (in_array('--simulate-failure', $argv, true)) {
            fwrite(STDERR, "stub: simulated failure\n");
            exit(7);
        }

        echo "stub: extension installed (extension_id=812)\n";
        exit(0);
        PHP;

        file_put_contents($this->joomlaRoot . '/cli/joomla.php', $stub);
    }

    protected function tearDown(): void
    {
        $this->rrmdir($this->tmpDir);
    }

    #[Test]
    public function install_passes_the_expected_extension_install_command(): void
    {
        $zipPath = $this->tmpDir . '/example.zip';
        file_put_contents($zipPath, 'zip-bytes');

        putenv('CWM_TEST_CAPTURE_PATH=' . $this->capturePath);

        $result = (new ExtensionInstaller())->install($zipPath, $this->joomlaRoot);

        self::assertTrue($result->ok, "stderr: {$result->stderr}");
        self::assertSame(0, $result->exitCode);
        self::assertStringContainsString('extension_id=812', $result->stdout);

        $captured = $this->readCapturedArgv();
        // argv[0] is the stub's own path; argv[1..] is what we passed.
        self::assertSame('extension:install', $captured[1] ?? null);
        self::assertSame('--path=' . $zipPath, $captured[2] ?? null);
        self::assertSame('--no-interaction', $captured[3] ?? null);
    }

    #[Test]
    public function install_uses_named_path_flag_not_positional(): void
    {
        $zipPath = $this->tmpDir . '/example.zip';
        file_put_contents($zipPath, 'zip-bytes');

        putenv('CWM_TEST_CAPTURE_PATH=' . $this->capturePath);

        (new ExtensionInstaller())->install($zipPath, $this->joomlaRoot);

        $captured = $this->readCapturedArgv();
        // Joomla 5+ requires --path=... (verified against joomla-cms 5.4-dev source).
        // The zip path must NEVER appear as a bare positional argument.
        $positional = array_values(array_filter(
            array_slice($captured, 2),
            static fn (string $a): bool => !str_starts_with($a, '--'),
        ));
        self::assertSame([], $positional, 'No positional args beyond the command name');
    }

    #[Test]
    public function install_does_not_use_legacy_extension_installfile_command(): void
    {
        // Anti-pattern guard: `extension:installfile` doesn't exist in
        // modern Joomla (5.x/6.x). Older tutorials sometimes reference it.
        $zipPath = $this->tmpDir . '/example.zip';
        file_put_contents($zipPath, 'zip-bytes');

        putenv('CWM_TEST_CAPTURE_PATH=' . $this->capturePath);

        (new ExtensionInstaller())->install($zipPath, $this->joomlaRoot);

        $captured = $this->readCapturedArgv();
        self::assertNotContains('extension:installfile', $captured);
    }

    #[Test]
    public function install_returns_failure_when_joomla_cli_missing(): void
    {
        $zipPath = $this->tmpDir . '/example.zip';
        file_put_contents($zipPath, 'zip-bytes');

        $result = (new ExtensionInstaller())->install($zipPath, $this->tmpDir . '/no-joomla-here');

        self::assertFalse($result->ok);
        self::assertSame(127, $result->exitCode);
        self::assertStringContainsString('Joomla CLI not found', $result->stderr);
    }

    #[Test]
    public function install_returns_failure_when_zip_missing(): void
    {
        $result = (new ExtensionInstaller())->install($this->tmpDir . '/nope.zip', $this->joomlaRoot);

        self::assertFalse($result->ok);
        self::assertSame(2, $result->exitCode);
        self::assertStringContainsString('Extension zip not found', $result->stderr);
    }

    #[Test]
    public function install_propagates_non_zero_exit_from_joomla_cli(): void
    {
        $zipPath = $this->tmpDir . '/example.zip';
        file_put_contents($zipPath, 'zip-bytes');

        putenv('CWM_TEST_CAPTURE_PATH=' . $this->capturePath);

        // The stub honours --simulate-failure even though
        // ExtensionInstaller doesn't pass it — so we'll inject it
        // by swapping the stub. Cheapest path is overwriting the stub
        // for this test only.
        file_put_contents(
            $this->joomlaRoot . '/cli/joomla.php',
            "#!/usr/bin/env php\n<?php fwrite(STDERR, \"install adapter: invalid manifest\\n\"); exit(1);\n",
        );

        $result = (new ExtensionInstaller())->install($zipPath, $this->joomlaRoot);

        self::assertFalse($result->ok);
        self::assertSame(1, $result->exitCode);
        self::assertStringContainsString('install adapter: invalid manifest', $result->stderr);
    }

    #[Test]
    public function uninstall_passes_extension_id_as_positional_arg(): void
    {
        putenv('CWM_TEST_CAPTURE_PATH=' . $this->capturePath);

        (new ExtensionInstaller())->uninstall(812, $this->joomlaRoot);

        $captured = $this->readCapturedArgv();
        self::assertSame('extension:remove', $captured[1] ?? null);
        // extension:remove takes the int extension_id as a positional arg
        // (verified in joomla-cms ExtensionRemoveCommand source).
        self::assertSame('812', $captured[2] ?? null);
        self::assertSame('--no-interaction', $captured[3] ?? null);
    }

    /**
     * @return list<string>
     */
    private function readCapturedArgv(): array
    {
        self::assertFileExists($this->capturePath, 'stub should have captured argv');

        $lines = array_values(array_filter(
            explode("\n", (string) file_get_contents($this->capturePath)),
            static fn (string $l): bool => trim($l) !== '',
        ));
        $last  = end($lines);
        self::assertIsString($last);

        $decoded = json_decode($last, true);
        self::assertIsArray($decoded);

        return array_map(static fn ($a): string => (string) $a, $decoded);
    }

    private function rrmdir(string $path): void
    {
        if (!is_dir($path) && !is_link($path)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $entry) {
            if ($entry->isDir() && !$entry->isLink()) {
                @rmdir($entry->getPathname());
            } else {
                @unlink($entry->getPathname());
            }
        }

        @rmdir($path);
    }
}

<?php

declare(strict_types=1);

namespace CWM\BuildTools\Tests\Cli;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ZipArchive;

/**
 * Runs `scripts/package.php` as a real subprocess.
 *
 * Every other test in this suite loads classes through Composer's autoloader.
 * The CLI entry points do not have one — they `require_once` each class by
 * path — so a class the packager reaches only on some code path can be missing
 * from that list and nothing fails until a user runs the command.
 *
 * That is not hypothetical: 1.18.0 shipped `ChildTokenSubstitution` wired into
 * `Packager::resolveSubBuild()` but never required in `scripts/package.php`, so
 * `cwm-package` fataled with "Class ... not found" on every project with a
 * subBuild include, while the unit suite stayed entirely green.
 */
final class PackageCliTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/cwm-package-cli-' . bin2hex(random_bytes(6));
        mkdir($this->tmpDir, 0o777, true);
    }

    protected function tearDown(): void
    {
        $this->rrmdir($this->tmpDir);
    }

    /**
     * The subBuild path is the one that regressed, and it is the one that
     * reaches the most classes — so it is the shape worth running for real.
     */
    #[Test]
    public function packages_a_project_with_a_subbuild_child_through_the_real_cli(): void
    {
        $this->seedOuter();
        $this->seedChild('1.2.5');

        [$exit, $output] = $this->runCli();

        self::assertSame(0, $exit, "cwm-package failed:\n$output");
        self::assertFileExists($this->tmpDir . '/dist/pkg-1.0.0.zip', $output);

        // The child was substituted with its own version on the way through —
        // proving ChildTokenSubstitution actually loaded, not just that the
        // command exited 0.
        $staged = $this->nestedEntry($this->tmpDir . '/dist/pkg-1.0.0.zip', 'lib.zip', 'src/Thing.php');
        self::assertStringContainsString('@since 1.2.5', $staged);
        self::assertStringNotContainsString('__DEPLOY_VERSION__', $staged);

        // ...and the child's tree was put back.
        self::assertStringContainsString(
            '__DEPLOY_VERSION__',
            (string) file_get_contents($this->tmpDir . '/lib/src/Thing.php')
        );
    }

    /**
     * @return array{0: int, 1: string}
     */
    private function runCli(): array
    {
        $script = dirname(__DIR__, 2) . '/scripts/package.php';

        $proc = proc_open(
            ['php', $script],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $this->tmpDir
        );

        if (!is_resource($proc)) {
            self::fail('Could not spawn scripts/package.php');
        }

        $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($proc), (string) $output];
    }

    private function seedOuter(): void
    {
        mkdir($this->tmpDir . '/build', 0o777, true);

        file_put_contents(
            $this->tmpDir . '/build/pkg.xml',
            "<?xml version=\"1.0\"?>\n<extension><version>1.0.0</version></extension>\n"
        );

        file_put_contents($this->tmpDir . '/cwm-build.config.json', json_encode([
            'extension' => ['name' => 'pkg'],
            'package'   => [
                'manifest'   => 'build/pkg.xml',
                'outputDir'  => 'dist',
                'outputName' => 'pkg-{version}.zip',
                'includes'   => [[
                    'type'        => 'subBuild',
                    'path'        => 'lib',
                    'buildScript' => 'build.php',
                    'distGlob'    => 'dist/lib-*.zip',
                    'outputName'  => 'lib.zip',
                ]],
            ],
        ], JSON_PRETTY_PRINT));
    }

    private function seedChild(string $version): void
    {
        mkdir($this->tmpDir . '/lib/src', 0o777, true);
        mkdir($this->tmpDir . '/lib/dist', 0o777, true);
        mkdir($this->tmpDir . '/lib/build', 0o777, true);

        file_put_contents($this->tmpDir . '/lib/src/Thing.php', "<?php\n/** @since __DEPLOY_VERSION__ */\n");
        file_put_contents(
            $this->tmpDir . '/lib/build/manifest.xml',
            "<?xml version=\"1.0\"?>\n<extension><version>$version</version></extension>\n"
        );
        file_put_contents($this->tmpDir . '/lib/cwm-build.config.json', json_encode([
            'package'         => ['manifest' => 'build/manifest.xml'],
            'versionTracking' => ['substituteTokens' => ['paths' => ['src/'], 'extensions' => ['php']]],
        ]));

        file_put_contents($this->tmpDir . '/lib/build.php', <<<'PHP'
<?php
$out = __DIR__ . '/dist/lib-1.2.5.zip';
$zip = new ZipArchive();
$zip->open($out, ZipArchive::CREATE | ZipArchive::OVERWRITE);
$zip->addFile(__DIR__ . '/src/Thing.php', 'src/Thing.php');
$zip->close();
echo "  produced $out\n";
PHP);
    }

    private function nestedEntry(string $outerZip, string $childName, string $entry): string
    {
        $outer = new ZipArchive();
        $outer->open($outerZip);
        $childBytes = $outer->getFromName($childName);
        $outer->close();

        $tmp = tempnam(sys_get_temp_dir(), 'cwm-cli-nested-');
        file_put_contents($tmp, $childBytes);

        $child = new ZipArchive();
        $child->open($tmp);
        $contents = (string) $child->getFromName($entry);
        $child->close();
        @unlink($tmp);

        return $contents;
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

<?php

declare(strict_types=1);

namespace CWM\BuildTools\Tests\Cli;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Runs `scripts/link.php` as a real subprocess with `--install`.
 *
 * The role rule lives in LinkPlanner and is unit-tested there, but the v1.6.1
 * defect was in this script calling the wrong method, and 214 tests passed
 * while it shipped. Only running the script proves the flag reaches the rule.
 */
final class LinkInstallCliTest extends TestCase
{
    private string $tmp;

    private string $project;

    protected function setUp(): void
    {
        $this->tmp     = (string) realpath(sys_get_temp_dir()) . '/cwm-link-cli-' . bin2hex(random_bytes(6));
        $this->project = $this->tmp . '/proj';

        mkdir($this->project . '/admin', 0o777, true);
        mkdir($this->project . '/site', 0o777, true);
        foreach (['dev1', 'dev2', 'test1'] as $site) {
            mkdir($this->tmp . '/sites/' . $site . '/administrator/components', 0o777, true);
            mkdir($this->tmp . '/sites/' . $site . '/components', 0o777, true);
        }

        file_put_contents($this->project . '/demo.xml', '<?xml version="1.0"?><extension type="component"><name>com_demo</name></extension>');
        file_put_contents($this->project . '/cwm-build.config.json', json_encode([
            'extension' => ['type' => 'component', 'name' => 'com_demo'],
            'manifests' => ['extensions' => [['type' => 'component', 'path' => 'demo.xml']]],
        ]));
        file_put_contents($this->project . '/build.properties', <<<PROPS
            builder.installs=dev1, dev2, test1
            builder.dev1.role=dev
            builder.dev1.path={$this->tmp}/sites/dev1
            builder.dev2.role=dev
            builder.dev2.path={$this->tmp}/sites/dev2
            builder.test1.role=test
            builder.test1.path={$this->tmp}/sites/test1
            PROPS);
    }

    protected function tearDown(): void
    {
        $this->rrmdir($this->tmp);
    }

    /**
     * @param  list<string>  $args
     *
     * @return array{int, string, string}
     */
    private function link(array $args): array
    {
        $cmd     = array_merge([PHP_BINARY, \dirname(__DIR__, 2) . '/scripts/link.php'], $args);
        $process = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $this->project);
        $this->assertIsResource($process);

        $out = (string) stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($process), $out, $err];
    }

    private function linked(string $site): bool
    {
        return is_link($this->tmp . '/sites/' . $site . '/administrator/components/com_demo');
    }

    #[Test]
    public function installLinksOnlyTheNamedDevSite(): void
    {
        [$exit, $out, $err] = $this->link(['--install', 'dev2']);

        $this->assertSame(0, $exit, $out . $err);
        $this->assertTrue($this->linked('dev2'));
        $this->assertFalse($this->linked('dev1'), 'the other dev site is left alone');
        $this->assertFalse($this->linked('test1'));
    }

    #[Test]
    public function withoutInstallEveryDevSiteIsLinkedAndStillNoTestSite(): void
    {
        [$exit, $out, $err] = $this->link([]);

        $this->assertSame(0, $exit, $out . $err);
        $this->assertTrue($this->linked('dev1'));
        $this->assertTrue($this->linked('dev2'));
        $this->assertFalse($this->linked('test1'), 'the v1.6.1 defect: a test install must never be linked');
    }

    #[Test]
    public function namingATestSiteIsRefusedAndNothingIsLinked(): void
    {
        [$exit, , $err] = $this->link(['--install', 'test1']);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('role=test', $err);
        $this->assertFalse($this->linked('test1'));
        $this->assertFalse($this->linked('dev1'));
        $this->assertFalse($this->linked('dev2'));
    }

    #[Test]
    public function anUnknownIdListsTheKnownOnes(): void
    {
        [$exit, , $err] = $this->link(['--install', 'nope']);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('Known installs: dev1, dev2, test1', $err);
    }

    private function rrmdir(string $dir): void
    {
        if (!is_dir($dir) && !is_link($dir)) {
            return;
        }

        if (is_link($dir)) {
            @unlink($dir);

            return;
        }

        foreach (array_diff(scandir($dir) ?: [], ['.', '..']) as $entry) {
            $this->rrmdir($dir . '/' . $entry);
        }

        @rmdir($dir);
    }
}

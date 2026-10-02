<?php

declare(strict_types=1);

namespace CWM\BuildTools\Tests\Site;

use CWM\BuildTools\Site\DdevConfig;
use CWM\BuildTools\Site\Mount;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class DdevConfigTest extends TestCase
{
    private DdevConfig $config;

    protected function setUp(): void
    {
        $this->config = new DdevConfig();
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function names(): array
    {
        return [
            'already valid'     => ['proclaim-j6', 'proclaim-j6'],
            'uppercase'         => ['Proclaim_J6', 'proclaim-j6'],
            'spaces and dots'   => ['my site.v2', 'my-site-v2'],
            'edges are trimmed' => ['--j6--', 'j6'],
        ];
    }

    #[Test]
    #[DataProvider('names')]
    public function projectNamesAreHostnameSafe(string $id, string $expected): void
    {
        $this->assertSame($expected, $this->config->projectName($id));
    }

    #[Test]
    public function aSiteIdWithNoUsableCharactersIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->config->projectName('___');
    }

    #[Test]
    public function configArgsPinTheDatabasePortAndTheVersions(): void
    {
        $args = $this->config->configArgs('proclaim-j6', '8.3', 33061);

        $this->assertSame('config', $args[0]);
        $this->assertContains('--project-type=joomla', $args);
        $this->assertContains('--php-version=8.3', $args);
        $this->assertContains('--database=mariadb:11.4', $args);
        $this->assertContains('--host-db-port=33061', $args);
        $this->assertContains('--project-name=proclaim-j6', $args);
        $this->assertContains('--docroot=.', $args);
    }

    #[Test]
    public function aMalformedPhpVersionIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->config->configArgs('x', '8', 33061);
    }

    #[Test]
    public function aPrivilegedDatabasePortIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->config->configArgs('x', '8.3', 80);
    }

    #[Test]
    public function theComposeOverrideListsEveryMountAndExplainsIt(): void
    {
        $yaml = $this->config->composeOverride([
            new Mount('/Volumes/X/GitHub/Proclaim', '/var/GitHub/Proclaim', 'source where the relative symlinks resolve'),
            new Mount('/Volumes/X/Sites/dev', '/Volumes/X/Sites/dev', 'site at its host path'),
        ]);

        $this->assertStringContainsString("services:\n  web:\n    volumes:\n", $yaml);
        $this->assertStringContainsString('      - "/Volumes/X/GitHub/Proclaim:/var/GitHub/Proclaim:cached"', $yaml);
        $this->assertStringContainsString('      - "/Volumes/X/Sites/dev:/Volumes/X/Sites/dev:cached"', $yaml);
        $this->assertStringContainsString('# /var/GitHub/Proclaim: source where the relative symlinks resolve', $yaml);
    }

    #[Test]
    public function theComposeOverrideIsNotMarkedAsDdevGenerated(): void
    {
        $this->assertStringNotContainsString('#ddev-generated', $this->config->composeOverride([]));
    }

    #[Test]
    public function aPathWithAQuoteOrSpaceIsQuotedSafely(): void
    {
        $yaml = $this->config->composeOverride([new Mount('/Users/a b/"x"', '/var/x', 'r')]);

        $this->assertStringContainsString('      - "/Users/a b/\\"x\\":/var/x:cached"', $yaml);
    }

    /**
     * @return array<string, array{string, bool}>
     */
    public static function globalConfigs(): array
    {
        return [
            'missing file'             => ['', false],
            'only the commented hint'  => ["# instrumentation_opt_in: true # or false\n", false],
            'opted in'                 => ["instrumentation_opt_in: true\n", true],
            'opted out'                => ["router: traefik\ninstrumentation_opt_in: false\n", true],
            'unrelated keys only'      => ["router: traefik\n", false],
        ];
    }

    #[Test]
    #[DataProvider('globalConfigs')]
    public function telemetryDecidedOnlyWhenTheKeyHasAValue(string $yaml, bool $expected): void
    {
        $this->assertSame($expected, $this->config->telemetryDecided($yaml));
    }
}

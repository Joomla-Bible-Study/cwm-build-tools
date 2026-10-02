<?php

declare(strict_types=1);

namespace CWM\BuildTools\Tests\Seed;

use CWM\BuildTools\Dev\PropertiesReader;
use CWM\BuildTools\Seed\SeedException;
use CWM\BuildTools\Seed\SeedTarget;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class SeedTargetTest extends TestCase
{
    private string $tmp;

    private string $props;

    protected function setUp(): void
    {
        $this->tmp   = (string) realpath(sys_get_temp_dir()) . '/cwm-seedtarget-' . bin2hex(random_bytes(6));
        $this->props = $this->tmp . '/build.properties';

        foreach (['dev1', 'test1', 'test2'] as $d) {
            mkdir($this->tmp . '/sites/' . $d, 0o777, true);
        }
    }

    protected function tearDown(): void
    {
        @unlink($this->props);

        foreach (['dev1', 'test1', 'test2'] as $d) {
            @rmdir($this->tmp . '/sites/' . $d);
        }

        @rmdir($this->tmp . '/sites');
        @rmdir($this->tmp);
    }

    private function reader(string $extra = ''): PropertiesReader
    {
        file_put_contents($this->props, <<<PROPS
            builder.installs=dev1, test1, test2, gone
            builder.dev1.role=dev
            builder.dev1.path={$this->tmp}/sites/dev1
            builder.test1.role=test
            builder.test1.path={$this->tmp}/sites/test1
            builder.test2.role=test
            builder.test2.path={$this->tmp}/sites/test2
            builder.gone.role=test
            builder.gone.path={$this->tmp}/sites/gone
            {$extra}
            PROPS);

        return new PropertiesReader($this->props);
    }

    /**
     * @param  list<\CWM\BuildTools\Dev\InstallConfig>  $installs
     *
     * @return list<string>
     */
    private function ids(array $installs): array
    {
        return array_map(static fn ($i): string => $i->id, $installs);
    }

    #[Test]
    public function withNoNameEveryExistingTestSiteIsATargetAndADevSiteIsNot(): void
    {
        $this->assertSame(['test1', 'test2'], $this->ids(SeedTarget::select($this->reader(), null)));
    }

    #[Test]
    public function aTestSiteWhoseFolderIsGoneIsSkippedNotSeeded(): void
    {
        $this->assertNotContains('gone', $this->ids(SeedTarget::select($this->reader(), null)));
    }

    #[Test]
    public function aDevSiteIsSeededOnlyWhenNamed(): void
    {
        $this->assertSame(['dev1'], $this->ids(SeedTarget::select($this->reader(), 'dev1')));
    }

    #[Test]
    public function anUnknownNameListsTheKnownInstalls(): void
    {
        try {
            SeedTarget::select($this->reader(), 'prod');
            $this->fail('expected a SeedException');
        } catch (SeedException $e) {
            $this->assertStringContainsString('No install "prod"', $e->getMessage());
            $this->assertStringContainsString('dev1, test1, test2, gone', $e->getMessage());
        }
    }

    #[Test]
    public function aNamedInstallWhoseFolderIsGoneIsRefused(): void
    {
        $this->expectException(SeedException::class);
        $this->expectExceptionMessage('does not exist');

        SeedTarget::select($this->reader(), 'gone');
    }

    #[Test]
    public function withNoTestSiteItSaysHowToSeedADevSiteOnPurpose(): void
    {
        file_put_contents($this->props, "builder.dev1.role=dev\nbuilder.dev1.path={$this->tmp}/sites/dev1\n");

        try {
            SeedTarget::select(new PropertiesReader($this->props), null);
            $this->fail('expected a SeedException');
        } catch (SeedException $e) {
            $this->assertStringContainsString('only when you name it', $e->getMessage());
            $this->assertStringContainsString('--install <id>', $e->getMessage());
        }
    }

    #[Test]
    public function aMissingBuildPropertiesIsExplained(): void
    {
        $this->expectException(SeedException::class);
        $this->expectExceptionMessage('build.properties not found');

        SeedTarget::select(new PropertiesReader($this->tmp . '/nope.properties'), null);
    }
}

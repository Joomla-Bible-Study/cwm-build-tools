<?php

declare(strict_types=1);

namespace CWM\BuildTools\Tests\Site;

use CWM\BuildTools\Site\RegisteredSite;
use CWM\BuildTools\Site\SiteException;
use CWM\BuildTools\Site\SiteLookup;
use CWM\BuildTools\Site\SiteRegistrar;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class SiteLookupTest extends TestCase
{
    private string $tmp;

    private string $props;

    protected function setUp(): void
    {
        $this->tmp   = (string) realpath(sys_get_temp_dir()) . '/cwm-lookup-' . bin2hex(random_bytes(6));
        $this->props = $this->tmp . '/build.properties';
        mkdir($this->tmp, 0o777, true);
    }

    protected function tearDown(): void
    {
        @unlink($this->props);
        @rmdir($this->tmp);
    }

    private function register(string $id, string $role = 'dev'): void
    {
        (new SiteRegistrar())->register(
            $this->props,
            new RegisteredSite($id, $role, '/sites/' . $id, 'https://x', '6.1.4', '127.0.0.1:33061', 'db', 'db', 'db', 'admin', 'S3cretPassw0rd', 'a@example.com')
        );
    }

    #[Test]
    public function findsASiteThisToolRecordedWithItsRoleAndPath(): void
    {
        $this->register('ddev6', 'test');

        $install = (new SiteLookup())->find($this->props, 'ddev6');

        $this->assertSame('ddev6', $install->id);
        $this->assertSame('test', $install->role);
        $this->assertSame('/sites/ddev6', $install->path);
        $this->assertSame('127.0.0.1:33061', $install->dbHost());
    }

    #[Test]
    public function anUnknownIdListsWhatThisToolMade(): void
    {
        $this->register('ddev6');
        $this->register('ddev7');

        try {
            (new SiteLookup())->find($this->props, 'nope');
            $this->fail('expected a SiteException');
        } catch (SiteException $e) {
            $this->assertStringContainsString('No site "nope"', $e->getMessage());
            $this->assertStringContainsString('Known: ddev6, ddev7.', $e->getMessage());
        }
    }

    #[Test]
    public function aSiteWrittenByHandIsNotEligibleAndTheMessageSaysWhy(): void
    {
        file_put_contents($this->props, "builder.mine.role=test\nbuilder.mine.path=/sites/mine\n");

        try {
            (new SiteLookup())->find($this->props, 'mine');
            $this->fail('expected a SiteException');
        } catch (SiteException $e) {
            $this->assertStringContainsString('did not record it', $e->getMessage());
            $this->assertStringContainsString('Remove it by hand', $e->getMessage());
        }
    }

    #[Test]
    public function aMissingFileTellsYouToRunFromTheProject(): void
    {
        $this->expectException(SiteException::class);
        $this->expectExceptionMessage('Run this from the project that created the site');

        (new SiteLookup())->find($this->tmp . '/nope/build.properties', 'ddev6');
    }

    #[Test]
    public function withNothingRecordedItSaysNoneHaveBeen(): void
    {
        file_put_contents($this->props, "builder.mine.path=/x\n");

        $this->expectException(SiteException::class);
        $this->expectExceptionMessage('None have been.');

        (new SiteLookup())->find($this->props, 'ddev6');
    }
}

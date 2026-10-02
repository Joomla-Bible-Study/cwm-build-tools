<?php

declare(strict_types=1);

namespace CWM\BuildTools\Tests\Site;

use CWM\BuildTools\Dev\PropertiesReader;
use CWM\BuildTools\Site\RegisteredSite;
use CWM\BuildTools\Site\SiteException;
use CWM\BuildTools\Site\SiteRegistrar;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class SiteRegistrarTest extends TestCase
{
    private string $tmp;

    /** A developer's file in the legacy flat format, with comments and keys the writer does not know. */
    private const EXISTING = <<<'PROPS'
# Local build properties
builder.joomla_paths=/sites/j5-dev,/sites/j6-dev

# Explicit install list. Required once any builder.<id>.path key exists below.
builder.installs=j5dev, j6dev, j62

joomla.version=5.4.2

# Joomla 5 development site
builder.j5dev.role=dev
builder.j5dev.path=/sites/j5-dev
builder.j5dev.url=https://j5-dev.local:8890
builder.j5dev.db_host=localhost:8889
builder.j5dev.db_user=root
builder.j5dev.db_pass=secret
builder.j5dev.db_name=j5_dev

builder.j6dev.role=dev
builder.j6dev.path=/sites/j6-dev
builder.j6dev.url=https://j6-dev.local:8890
builder.j6dev.db_name=j6_dev

builder.j62.role=dev
builder.j62.path=/sites/j62
PROPS;

    protected function setUp(): void
    {
        $this->tmp = (string) realpath(sys_get_temp_dir()) . '/cwm-registrar-' . bin2hex(random_bytes(6));
        mkdir($this->tmp, 0o777, true);
        mkdir($this->tmp . '/sites/ddev', 0o777, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tmp . '/*') ?: [] as $f) {
            is_dir($f) ? @rmdir($f . '/ddev') : @unlink($f);
            @rmdir($f);
        }

        @rmdir($this->tmp);
    }

    private function site(string $id = 'ddev6', string $role = 'dev'): RegisteredSite
    {
        return new RegisteredSite($id, $role, $this->tmp . '/sites/ddev', 'https://ddev6.ddev.site', '6.1.4', '127.0.0.1:33061', 'db', 'db', 'db', 'admin', 'S3cretPassw0rd', 'admin@example.com');
    }

    #[Test]
    public function everyExistingLineSurvivesAndTheSiteIsAppended(): void
    {
        $new = (new SiteRegistrar())->apply(self::EXISTING, $this->site());

        foreach (explode("\n", self::EXISTING) as $line) {
            if ($line !== 'builder.installs=j5dev, j6dev, j62') {
                $this->assertStringContainsString($line, $new, 'kept: ' . $line);
            }
        }

        $this->assertStringContainsString('builder.ddev6.path=' . $this->tmp . '/sites/ddev', $new);
        $this->assertStringContainsString('# === cwm-build-tools: site ddev6', $new);
    }

    #[Test]
    public function theIdIsAddedToTheInstallsListInPlace(): void
    {
        $new = (new SiteRegistrar())->apply(self::EXISTING, $this->site());

        $this->assertStringContainsString("builder.installs=j5dev, j6dev, j62, ddev6\n", $new);
        $this->assertSame(1, substr_count($new, 'builder.installs='));
    }

    #[Test]
    public function theRealParserReadsTheSiteBackWithTheOthersIntact(): void
    {
        file_put_contents($this->tmp . '/build.properties', self::EXISTING);
        (new SiteRegistrar())->register($this->tmp . '/build.properties', $this->site());

        $installs = [];

        foreach ((new PropertiesReader($this->tmp . '/build.properties'))->installs() as $i) {
            $installs[$i->id] = $i;
        }

        $this->assertSame(['j5', 'j6', 'j62', 'ddev6'], array_keys($installs), 'PropertiesReader reports Proclaim\'s j5dev as j5');
        $this->assertSame('dev', $installs['ddev6']->role);
        $this->assertSame($this->tmp . '/sites/ddev', $installs['ddev6']->path);
        $this->assertSame('127.0.0.1:33061', $installs['ddev6']->dbHost());
        $this->assertSame('S3cretPassw0rd', $installs['ddev6']->adminPass());
        $this->assertSame('secret', $installs['j5']->dbPass(), 'the other sites\' credentials are untouched');
    }

    #[Test]
    public function runningItAgainReplacesTheBlockInsteadOfDuplicatingIt(): void
    {
        $registrar = new SiteRegistrar();
        $once      = $registrar->apply(self::EXISTING, $this->site());
        $twice     = $registrar->apply($once, $this->site());

        $this->assertSame($once, $twice);
        $this->assertSame(1, substr_count($twice, 'builder.ddev6.path='));
        $this->assertSame(1, preg_match_all('/builder\.installs=.*\bddev6\b/', $twice));
        $this->assertStringNotContainsString('ddev6, ddev6', $twice);
    }

    #[Test]
    public function changedSettingsReplaceTheOldBlockInPlace(): void
    {
        $registrar = new SiteRegistrar();
        $first     = $registrar->apply(self::EXISTING, $this->site());
        $changed   = new RegisteredSite('ddev6', 'test', $this->tmp . '/sites/ddev', 'https://ddev6.ddev.site', '6.2.0', '127.0.0.1:33099', 'db', 'db', 'db', 'admin', 'AnotherPassw0rd', 'a@example.com');

        $second = $registrar->apply($first, $changed);

        $this->assertStringContainsString('builder.ddev6.db_host=127.0.0.1:33099', $second);
        $this->assertStringNotContainsString('127.0.0.1:33061', $second);
        $this->assertStringContainsString('builder.ddev6.role=test', $second);
        $this->assertSame(1, substr_count($second, 'builder.ddev6.role='));
    }

    #[Test]
    public function anIdDefinedByHandIsRefusedNotDuplicated(): void
    {
        $this->expectException(SiteException::class);
        $this->expectExceptionMessage('already defines "j62" by hand');

        (new SiteRegistrar())->apply(self::EXISTING, $this->site('j62'));
    }

    #[Test]
    public function aCommentThatMentionsTheIdIsNotMistakenForADefinition(): void
    {
        $content = self::EXISTING . "\n# builder.ddev6.path=/old/path was removed\n";

        $new = (new SiteRegistrar())->apply($content, $this->site());

        $this->assertStringContainsString('builder.ddev6.path=' . $this->tmp, $new);
    }

    #[Test]
    public function withoutAnInstallsListNothingIsInventedAndDiscoveryStillWorks(): void
    {
        $new = (new SiteRegistrar())->apply("builder.j5dev.path=/sites/j5-dev\n", $this->site());

        $this->assertStringNotContainsString("\nbuilder.installs", $new);
    }

    #[Test]
    public function anIdAlreadyListedIsNotListedTwice(): void
    {
        $this->assertSame(
            "builder.installs = a, b\n",
            (new SiteRegistrar())->withInstallListed("builder.installs = a, b\n", 'b')
        );
    }

    #[Test]
    public function unregisteringRemovesTheBlockAndTheListedIdAndNothingElse(): void
    {
        $registrar  = new SiteRegistrar();
        $registered = $registrar->apply(self::EXISTING, $this->site());

        $back = $registrar->withoutSite($registered, 'ddev6');

        $this->assertSame(rtrim(self::EXISTING), rtrim($back), 'back to what the developer had, apart from trailing blank lines');
        $this->assertStringNotContainsString('ddev6', $back);
    }

    #[Test]
    public function unregisteringLeavesOtherSitesBlocksAndHandWrittenKeysAlone(): void
    {
        $registrar = new SiteRegistrar();
        $both      = $registrar->apply($registrar->apply(self::EXISTING, $this->site('ddev6')), $this->site('ddev7'));

        $after = $registrar->withoutSite($both, 'ddev6');

        $this->assertStringNotContainsString('builder.ddev6.', $after);
        $this->assertStringContainsString('builder.ddev7.path=', $after);
        $this->assertStringContainsString("builder.installs=j5dev, j6dev, j62, ddev7\n", $after);
        $this->assertStringContainsString('builder.j62.path=/sites/j62', $after);
    }

    #[Test]
    public function unregisteringAnIdThatIsNotThereChangesNothing(): void
    {
        $this->assertSame(self::EXISTING . "\n", (new SiteRegistrar())->withoutSite(self::EXISTING . "\n", 'ghost'));
    }

    #[Test]
    public function unregisteringNeverTouchesAHandDefinedSiteWithTheSameId(): void
    {
        $after = (new SiteRegistrar())->withoutSite(self::EXISTING, 'j62');

        $this->assertStringContainsString('builder.j62.path=/sites/j62', $after, 'no marker block, so nothing of ours to remove');
    }

    #[Test]
    public function recordedIdsAreOnlyTheOnesThisToolWrote(): void
    {
        $registrar = new SiteRegistrar();
        $content   = $registrar->apply($registrar->apply(self::EXISTING, $this->site('ddev6')), $this->site('ddev7'));

        $this->assertSame(['ddev6', 'ddev7'], $registrar->recordedIds($content));
        $this->assertSame([], $registrar->recordedIds(self::EXISTING), 'hand-written installs are not ours');
    }

    #[Test]
    public function unregisterRewritesTheFileKeepingItsPermissions(): void
    {
        $path = $this->tmp . '/build.properties';
        file_put_contents($path, self::EXISTING);
        chmod($path, 0o640);
        $registrar = new SiteRegistrar();
        $registrar->register($path, $this->site());

        $registrar->unregister($path, 'ddev6');

        $this->assertStringNotContainsString('ddev6', (string) file_get_contents($path));
        $this->assertSame('640', substr(sprintf('%o', fileperms($path)), -3));
        $this->assertSame([], glob($path . '.cwm-tmp-*') ?: []);
    }

    #[Test]
    public function unregisterOnAMissingFileSaysSo(): void
    {
        $this->expectException(SiteException::class);
        $this->expectExceptionMessage('no record to remove');

        (new SiteRegistrar())->unregister($this->tmp . '/nope.properties', 'ddev6');
    }

    #[Test]
    public function aMissingFileIsCreatedPrivate(): void
    {
        $path = $this->tmp . '/build.properties';

        (new SiteRegistrar())->register($path, $this->site());

        $this->assertFileExists($path);
        $this->assertSame('600', substr(sprintf('%o', fileperms($path)), -3), 'it holds credentials');
        $this->assertSame([], glob($this->tmp . '/build.properties.cwm-tmp-*') ?: [], 'no temp file left behind');
    }

    #[Test]
    public function anExistingFilesPermissionsAreKept(): void
    {
        $path = $this->tmp . '/build.properties';
        file_put_contents($path, self::EXISTING);
        chmod($path, 0o640);

        (new SiteRegistrar())->register($path, $this->site());

        $this->assertSame('640', substr(sprintf('%o', fileperms($path)), -3));
    }

    #[Test]
    public function aValueWithALineBreakIsRefusedBecauseItWouldInjectKeys(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('line break');

        new RegisteredSite('ddev6', 'dev', '/p', 'https://x', null, '127.0.0.1:1', 'db', "pw\nbuilder.evil.role=dev", 'db', 'admin', 'S3cretPassw0rd', 'a@example.com');
    }

    #[Test]
    public function anIdThatCouldBreakTheKeyIsRefused(): void
    {
        foreach (['has.dot', 'has space', '', '-lead', 'a=b', 'mydev', 'dev'] as $bad) {
            try {
                $this->site($bad);
                $this->fail('accepted ' . var_export($bad, true));
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    #[Test]
    public function anIdEndingInDevIsRefusedWithTheNameItWouldBeReportedUnder(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('would be reported as "my-site-"');

        $this->site('my-site-dev');
    }

    #[Test]
    public function aRoleOtherThanDevOrTestIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->site('ddev6', 'prod');
    }
}

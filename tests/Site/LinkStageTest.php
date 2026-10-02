<?php

declare(strict_types=1);

namespace CWM\BuildTools\Tests\Site;

use CWM\BuildTools\Site\CommandResult;
use CWM\BuildTools\Site\DdevConfig;
use CWM\BuildTools\Site\DdevEnvironment;
use CWM\BuildTools\Site\LinkStage;
use CWM\BuildTools\Site\MountPlanner;
use CWM\BuildTools\Site\SiteException;
use CWM\BuildTools\Site\SiteSpec;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class LinkStageTest extends TestCase
{
    private function spec(): SiteSpec
    {
        return new SiteSpec('j6', '/sites/j6', '8.3', 33061, ['/work/proj']);
    }

    private function stage(FakeRunner $runner): LinkStage
    {
        $env = new DdevEnvironment($runner, new DdevConfig(), new MountPlanner(), '/none.yaml', static function (): void {
        });

        return new LinkStage($runner, $env, '/tools/scripts/link.php', '/usr/bin/php');
    }

    private function quiet(): callable
    {
        return static function (): void {
        };
    }

    #[Test]
    public function runsCwmLinkForThatOneSiteFromTheProjectRoot(): void
    {
        $runner = new FakeRunner();

        $this->stage($runner)->run($this->spec(), 'j6', '/work/proj', $this->quiet());

        $this->assertSame(['/usr/bin/php', '/tools/scripts/link.php', '--install', 'j6'], $runner->calls[0]['command']);
        $this->assertSame('/work/proj', $runner->calls[0]['cwd']);
    }

    #[Test]
    public function flushesTheSyncBeforeLookingForBrokenLinks(): void
    {
        $runner = new FakeRunner();

        $this->stage($runner)->run($this->spec(), 'j6', '/work/proj', $this->quiet());

        $lines = $runner->commandLines();

        $this->assertSame('ddev mutagen sync', $lines[1]);
        $this->assertStringContainsString("'find' '.' '-xtype' 'l'", $lines[2]);
    }

    #[Test]
    public function aFailedCwmLinkShowsItsOutput(): void
    {
        $runner = new FakeRunner(['/usr/bin/php /tools/scripts/link.php' => new CommandResult(1, '', 'Install "j6" is role=test.')]);

        try {
            $this->stage($runner)->run($this->spec(), 'j6', '/work/proj', $this->quiet());
            $this->fail('expected a SiteException');
        } catch (SiteException $e) {
            $this->assertStringContainsString('cwm-link failed (exit 1)', $e->getMessage());
            $this->assertStringContainsString('Install "j6" is role=test.', $e->getMessage());
        }

        $this->assertCount(1, $runner->calls, 'nothing else ran after the failure');
    }

    #[Test]
    public function linksThatDoNotResolveInTheContainerAreListedWithTheLikelyCause(): void
    {
        $runner = new FakeRunner([], null, static function (string $line): ?CommandResult {
            return str_contains($line, "'find'")
                ? new CommandResult(0, "./components/com_x\n./administrator/components/com_x\n")
                : null;
        });

        try {
            $this->stage($runner)->run($this->spec(), 'j6', '/work/proj', $this->quiet());
            $this->fail('expected a SiteException');
        } catch (SiteException $e) {
            $this->assertStringContainsString('do not resolve inside the container', $e->getMessage());
            $this->assertStringContainsString('./components/com_x', $e->getMessage());
            $this->assertStringContainsString('not mounted where the links climb to', $e->getMessage());
            $this->assertStringContainsString('/sites/j6/.ddev/docker-compose.cwm.yaml', $e->getMessage());
        }
    }

    #[Test]
    public function aLongListOfBrokenLinksIsTruncated(): void
    {
        $many   = implode("\n", array_map(static fn (int $i): string => './m/' . $i, range(1, 25))) . "\n";
        $runner = new FakeRunner([], null, static fn (string $line): ?CommandResult => str_contains($line, "'find'") ? new CommandResult(0, $many) : null);

        try {
            $this->stage($runner)->run($this->spec(), 'j6', '/work/proj', $this->quiet());
            $this->fail('expected a SiteException');
        } catch (SiteException $e) {
            $this->assertStringContainsString('... and 15 more', $e->getMessage());
            $this->assertStringNotContainsString('./m/11', $e->getMessage());
        }
    }

    #[Test]
    public function scratchFoldersAreExcludedFromTheBrokenLinkSearch(): void
    {
        $runner = new FakeRunner();

        $this->stage($runner)->run($this->spec(), 'j6', '/work/proj', $this->quiet());

        $find = $runner->commandLines()[2];

        foreach (['./tmp/*', './cache/*', './administrator/cache/*', './.ddev/*'] as $skipped) {
            $this->assertStringContainsString("'" . $skipped . "'", $find);
        }
    }

    #[Test]
    public function thePlanNamesTheSite(): void
    {
        $plan = $this->stage(new FakeRunner())->plan('j6');

        $this->assertStringContainsString('cwm-link --install j6', $plan[0]);
    }
}

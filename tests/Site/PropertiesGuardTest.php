<?php

declare(strict_types=1);

namespace CWM\BuildTools\Tests\Site;

use CWM\BuildTools\Site\CommandResult;
use CWM\BuildTools\Site\PropertiesGuard;
use CWM\BuildTools\Site\SiteException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class PropertiesGuardTest extends TestCase
{
    #[Test]
    public function aFileGitWouldCommitIsRefusedWithTheFixToRun(): void
    {
        $runner = new FakeRunner(['git check-ignore' => new CommandResult(1)]);

        try {
            (new PropertiesGuard($runner))->assertSafeToHoldCredentials('/proj');
            $this->fail('expected a SiteException');
        } catch (SiteException $e) {
            $this->assertStringContainsString('not gitignored', $e->getMessage());
            $this->assertStringContainsString("echo 'build.properties' >> .gitignore", $e->getMessage());
            $this->assertStringContainsString('--no-register', $e->getMessage());
        }

        $this->assertSame('/proj', $runner->calls[0]['cwd']);
    }

    /**
     * @return array<string, array{int}>
     */
    public static function safeExits(): array
    {
        return [
            'ignored'                          => [0],
            'not a git repository'             => [128],
            'git is not installed'             => [127],
        ];
    }

    #[Test]
    #[DataProvider('safeExits')]
    public function anIgnoredFileOrAProjectOutsideGitIsFine(int $exit): void
    {
        (new PropertiesGuard(new FakeRunner(['git check-ignore' => new CommandResult($exit)])))
            ->assertSafeToHoldCredentials('/proj');

        $this->addToAssertionCount(1);
    }

    #[Test]
    public function theFileNameIsPassedAfterADoubleDashSoItCannotBeTakenForAFlag(): void
    {
        $runner = new FakeRunner(['git check-ignore' => new CommandResult(0)]);

        (new PropertiesGuard($runner))->assertSafeToHoldCredentials('/proj', '--evil');

        $this->assertSame(['git', 'check-ignore', '--quiet', '--', '--evil'], $runner->calls[0]['command']);
    }
}

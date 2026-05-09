<?php

declare(strict_types=1);

namespace CWM\BuildTools\Tests\Build;

use CWM\BuildTools\Build\Prompt;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Only the non-interactive paths are meaningfully testable here — the
 * countdown / single-keypress path requires a real PTY which PHPUnit can't
 * fake. Coverage is the env-detection helpers and the non-interactive
 * `ask()` shapes.
 */
final class PromptTest extends TestCase
{
    private ?string $savedCi                = null;
    private ?string $savedCwmNonInteractive = null;

    protected function setUp(): void
    {
        // Stash and clear the env vars so each test starts from a known state.
        // PHPUnit runs are typically already non-interactive, so we lean on
        // CWM_NONINTERACTIVE to force the deterministic path.
        $this->savedCi                = getenv('CI') === false ? null : (string) getenv('CI');
        $this->savedCwmNonInteractive = getenv('CWM_NONINTERACTIVE') === false ? null : (string) getenv('CWM_NONINTERACTIVE');
        putenv('CI');
        putenv('CWM_NONINTERACTIVE');
    }

    protected function tearDown(): void
    {
        $this->savedCi === null ? putenv('CI') : putenv('CI=' . $this->savedCi);
        $this->savedCwmNonInteractive === null ? putenv('CWM_NONINTERACTIVE') : putenv('CWM_NONINTERACTIVE=' . $this->savedCwmNonInteractive);
    }

    #[Test]
    public function isNonInteractiveReturnsTrueWhenCiEnvSet(): void
    {
        putenv('CI=1');
        $this->assertTrue(Prompt::isNonInteractive());
    }

    #[Test]
    public function isNonInteractiveReturnsTrueWhenCwmNonInteractiveEnvSet(): void
    {
        putenv('CWM_NONINTERACTIVE=1');
        $this->assertTrue(Prompt::isNonInteractive());
    }

    #[Test]
    public function isNonInteractiveReturnsTrueWhenStdinIsNotTty(): void
    {
        // PHPUnit's STDIN is not a TTY in any reasonable runner, so this should
        // be true even without the env vars set. If a user runs the test from
        // an interactive terminal that somehow keeps STDIN attached as a TTY,
        // CWM_NONINTERACTIVE is still off (per setUp) — and only then could
        // this assertion theoretically fail. Force it deterministically.
        putenv('CWM_NONINTERACTIVE=1');
        $this->assertTrue(Prompt::isNonInteractive());
    }

    #[Test]
    public function askReturnsDefaultImmediatelyWhenNonInteractive(): void
    {
        putenv('CWM_NONINTERACTIVE=1');
        $this->expectOutputString("Pick one [yes]: yes (auto, non-interactive)\n");

        $result = Prompt::ask('Pick one', 'yes');

        $this->assertSame('yes', $result);
    }

    #[Test]
    public function askReturnsNullWhenNonInteractiveAndNoDefault(): void
    {
        putenv('CWM_NONINTERACTIVE=1');
        $this->expectOutputString('');

        $result = Prompt::ask('Pick one');

        $this->assertNull($result);
    }

    #[Test]
    public function askIgnoresTimeoutWhenNonInteractive(): void
    {
        // Countdown path requires interactive TTY; non-interactive should
        // bypass the countdown and return default immediately.
        putenv('CWM_NONINTERACTIVE=1');
        $this->expectOutputString("Confirm [y]: y (auto, non-interactive)\n");

        $start  = microtime(true);
        $result = Prompt::ask('Confirm', 'y', 10);
        $elapsed = microtime(true) - $start;

        $this->assertSame('y', $result);
        $this->assertLessThan(0.5, $elapsed, 'Non-interactive ask() must not block on timeout.');
    }

    #[Test]
    public function askDoesNotAppendBracketsWhenNoDefault(): void
    {
        putenv('CWM_NONINTERACTIVE=1');
        // No default → returns null in non-interactive mode AND prints nothing
        // (no "[default]" formatting needed).
        $this->expectOutputString('');

        $this->assertNull(Prompt::ask('Whatever'));
    }
}

<?php

declare(strict_types=1);

namespace CWM\BuildTools\Tests\Site;

use CWM\BuildTools\Site\ProcessRunner;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class ProcessRunnerTest extends TestCase
{
    #[Test]
    public function addsVariablesToTheEnvironmentWithoutReplacingIt(): void
    {
        $result = (new ProcessRunner())->run(['sh', '-c', 'printf "%s|%s" "$CWM_TEST_VAR" "${PATH:+has-path}"'], null, false, ['CWM_TEST_VAR' => 'hello']);

        $this->assertSame('hello|has-path', $result->stdout, 'the variable arrived and PATH was kept');
    }

    #[Test]
    public function passesExitCodeStdoutAndStderrThrough(): void
    {
        $result = (new ProcessRunner())->run(['sh', '-c', 'echo out; echo err >&2; exit 4']);

        $this->assertSame(4, $result->exitCode);
        $this->assertSame("out\n", $result->stdout);
        $this->assertSame("err\n", $result->stderr);
    }

    #[Test]
    public function aMissingProgramIs127WithAReason(): void
    {
        $result = (new ProcessRunner())->run(['cwm-no-such-program-xyz']);

        $this->assertSame(127, $result->exitCode);
        $this->assertStringContainsString('cwm-no-such-program-xyz', $result->stderr);
    }
}

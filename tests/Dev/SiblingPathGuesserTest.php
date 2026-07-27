<?php

declare(strict_types=1);

namespace CWM\BuildTools\Tests\Dev;

use CWM\BuildTools\Dev\SiblingPathGuesser;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class SiblingPathGuesserTest extends TestCase
{
    private SiblingPathGuesser $guesser;

    private string $tmp;

    protected function setUp(): void
    {
        $this->guesser = new SiblingPathGuesser();
        $this->tmp     = sys_get_temp_dir() . '/cwm-sibling-' . bin2hex(random_bytes(6));
        mkdir($this->tmp, 0o777, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tmp . '/*') ?: [] as $d) {
            @rmdir($d);
        }
        @rmdir($this->tmp);
    }

    #[Test]
    public function aDashedComposerNameFindsAnUnderscoredCheckout(): void
    {
        // The CWM norm: composer "cwm/lib-cwmscripture", directory
        // "lib_cwmscripture".
        mkdir($this->tmp . '/lib_cwmscripture');

        $this->assertSame(
            $this->tmp . '/lib_cwmscripture',
            $this->guesser->guess($this->tmp, 'cwm/lib-cwmscripture')
        );
    }

    #[Test]
    public function anExactBasenameMatchWins(): void
    {
        mkdir($this->tmp . '/CWMScriptureLinks');

        $this->assertSame(
            $this->tmp . '/CWMScriptureLinks',
            $this->guesser->guess($this->tmp, 'cwm/CWMScriptureLinks')
        );
    }

    #[Test]
    public function noCandidateOnDiskMeansNull(): void
    {
        $this->assertNull($this->guesser->guess($this->tmp, 'cwm/absent-package'));
    }
}

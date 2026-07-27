<?php

declare(strict_types=1);

namespace CWM\BuildTools\Tests\Cli;

use CWM\BuildTools\Cli\Flags;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class FlagsTest extends TestCase
{
    #[Test]
    public function spaceSeparatedValuesAreFound(): void
    {
        $this->assertSame('test', Flags::value(['script', '--target', 'test'], '--target'));
    }

    #[Test]
    public function equalsSeparatedValuesAreFound(): void
    {
        $this->assertSame('test', Flags::value(['script', '--target=test'], '--target'));
    }

    #[Test]
    public function anAbsentFlagIsNull(): void
    {
        $this->assertNull(Flags::value(['script', '-v'], '--target'));
    }

    #[Test]
    public function aFlagWithNoValueIsNull(): void
    {
        $this->assertNull(Flags::value(['script', '--target'], '--target'));
    }

    #[Test]
    public function aPrefixDoesNotMatchALongerFlag(): void
    {
        // --targeted=x must not satisfy --target.
        $this->assertNull(Flags::value(['script', '--targeted=x'], '--target'));
    }

    #[Test]
    public function switchesMatchAnyAlias(): void
    {
        $this->assertTrue(Flags::has(['script', '-v'], ['-v', '--verbose']));
        $this->assertTrue(Flags::has(['script', '--verbose'], ['-v', '--verbose']));
        $this->assertFalse(Flags::has(['script', '--version'], ['-v', '--verbose']));
    }
}
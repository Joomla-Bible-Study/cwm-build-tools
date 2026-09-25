<?php

declare(strict_types=1);

namespace CWM\BuildTools\Tests\Release;

use CWM\BuildTools\Release\DateTokenExpander;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class DateTokenExpanderTest extends TestCase
{
    #[Test]
    public function bare_date_placeholder_defaults_to_ymd(): void
    {
        $at = new \DateTimeImmutable('2026-08-19');

        self::assertSame('20260819', DateTokenExpander::expand('{date}', $at));
    }

    #[Test]
    public function formatted_date_placeholder_uses_the_given_format(): void
    {
        $at = new \DateTimeImmutable('2026-08-19');

        self::assertSame('2026-08-19', DateTokenExpander::expand('{date:Y-m-d}', $at));
    }

    #[Test]
    public function placeholder_can_sit_inside_a_larger_string(): void
    {
        $at = new \DateTimeImmutable('2026-08-19');

        self::assertSame('-20260819-dev', DateTokenExpander::expand('-{date}-dev', $at));
        self::assertSame('-2026-08-19-dev', DateTokenExpander::expand('-{date:Y-m-d}-dev', $at));
    }

    #[Test]
    public function a_string_with_no_date_placeholder_is_returned_unchanged(): void
    {
        self::assertSame('-dev', DateTokenExpander::expand('-dev', new \DateTimeImmutable('2026-08-19')));
    }
}

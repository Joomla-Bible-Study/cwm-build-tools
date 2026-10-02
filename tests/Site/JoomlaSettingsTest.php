<?php

declare(strict_types=1);

namespace CWM\BuildTools\Tests\Site;

use CWM\BuildTools\Site\JoomlaSettings;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class JoomlaSettingsTest extends TestCase
{
    private function make(string $password = 'longenoughpassword', string $prefix = 'j1a2_', string $email = 'a@example.com'): JoomlaSettings
    {
        return new JoomlaSettings(null, 'Site', 'admin', $password, $email, $prefix);
    }

    #[Test]
    public function aShortPasswordIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('12 characters');

        $this->make('short');
    }

    #[Test]
    public function aPrefixMustStartWithALetterAndEndWithAnUnderscore(): void
    {
        foreach (['1abc_', 'abc', 'ab-c_', '_', 'Abc_'] as $bad) {
            try {
                $this->make('longenoughpassword', $bad);
                $this->fail('accepted ' . $bad);
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    #[Test]
    public function aBadEmailIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->make('longenoughpassword', 'j1a2_', 'not-an-email');
    }

    #[Test]
    public function generatedValuesAlwaysSatisfyTheRules(): void
    {
        for ($i = 0; $i < 200; $i++) {
            $settings = $this->make(JoomlaSettings::randomPassword(), JoomlaSettings::randomPrefix());

            $this->assertMatchesRegularExpression('/^[A-Za-z0-9]{16}$/', $settings->adminPassword);
            $this->assertMatchesRegularExpression('/^j[0-9a-f]{4}_$/', $settings->dbPrefix);
        }
    }

    #[Test]
    public function twoGeneratedPasswordsDiffer(): void
    {
        $this->assertNotSame(JoomlaSettings::randomPassword(), JoomlaSettings::randomPassword());
    }
}

<?php

declare(strict_types=1);

namespace CWM\BuildTools\Tests\Dev;

use CWM\BuildTools\Dev\InstallConfig;
use CWM\BuildTools\Dev\InstallIntake;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The role-preservation tests pin the defect this extraction fixed:
 * scripts/setup.php rebuilt each InstallConfig without `role`, so
 * re-running `composer setup` silently flipped a `role = test` install
 * back to `dev` — and a dev-role site is what `cwm-link` symlinks, which
 * is the precondition for the teardown-deletes-the-repo incident role
 * separation exists to prevent.
 */
class InstallIntakeTest extends TestCase
{
    private InstallIntake $intake;

    protected function setUp(): void
    {
        $this->intake = new InstallIntake();
    }

    #[Test]
    public function reRunningSetupPreservesATestRole(): void
    {
        $existing = new InstallConfig(
            id: 'j6-test',
            path: '/sites/j6-test',
            role: InstallConfig::ROLE_TEST,
        );

        // The user pressed Enter through every prompt — no explicit answers.
        $rebuilt = $this->intake->assemble('j6-test', ['path' => '/sites/j6-test'], $existing);

        $this->assertSame(
            InstallConfig::ROLE_TEST,
            $rebuilt->role,
            'Re-running setup must never quietly demote a test install to dev'
        );
    }

    #[Test]
    public function anExplicitRoleAnswerOverridesTheExistingRole(): void
    {
        $existing = new InstallConfig(id: 'j6', path: '/sites/j6', role: InstallConfig::ROLE_TEST);

        $rebuilt = $this->intake->assemble(
            'j6',
            ['path' => '/sites/j6', 'role' => InstallConfig::ROLE_DEV],
            $existing
        );

        $this->assertSame(InstallConfig::ROLE_DEV, $rebuilt->role);
    }

    #[Test]
    public function aNewInstallDefaultsToDev(): void
    {
        $config = $this->intake->assemble('j7', ['path' => '/sites/j7']);

        $this->assertSame(InstallConfig::ROLE_DEV, $config->role);
    }

    #[Test]
    public function idsAreNormalisedToLowercase(): void
    {
        // Section names in build.properties are lowercase by convention;
        // LinkResolver and ExtensionVerifier match against the lowercase id.
        $this->assertSame('j5', $this->intake->normaliseId('J5'));
    }

    #[Test]
    public function idsWithInvalidCharactersAreRejected(): void
    {
        $this->assertNull($this->intake->normaliseId('j5 dev'));
        $this->assertNull($this->intake->normaliseId('-j5'));
        $this->assertNull($this->intake->normaliseId(''));
    }

    #[Test]
    public function dashAndUnderscoreIdsAreAccepted(): void
    {
        $this->assertSame('j6-test', $this->intake->normaliseId('j6-test'));
        $this->assertSame('j6_alt', $this->intake->normaliseId('j6_alt'));
    }

    #[Test]
    public function rolesAreValidatedAndNormalised(): void
    {
        $this->assertSame('dev', $this->intake->normaliseRole(' DEV '));
        $this->assertSame('test', $this->intake->normaliseRole('test'));
        $this->assertNull($this->intake->normaliseRole('production'));
        $this->assertNull($this->intake->normaliseRole(''));
    }

    #[Test]
    public function blankAnswersFallBackToTheDocumentedDefaults(): void
    {
        $config = $this->intake->assemble('j5', ['path' => '/sites/j5']);

        $this->assertNull($config->url);
        $this->assertSame('localhost', $config->dbHost());
        $this->assertSame('admin', $config->adminUser());
        $this->assertSame('admin@example.com', $config->adminEmail());
    }

    #[Test]
    public function givenAnswersWinOverDefaults(): void
    {
        $config = $this->intake->assemble('j5', [
            'path'      => '/sites/j5',
            'url'       => 'https://j5.local',
            'dbHost'    => 'db:3307',
            'adminUser' => 'root-admin',
        ]);

        $this->assertSame('https://j5.local', $config->url);
        $this->assertSame('db:3307', $config->dbHost());
        $this->assertSame('root-admin', $config->adminUser());
    }
}

<?php

declare(strict_types=1);

namespace CWM\BuildTools\Tests\Config;

use CWM\BuildTools\Config\DistPropertiesInspector;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DistPropertiesInspectorTest extends TestCase
{
    private DistPropertiesInspector $inspector;

    protected function setUp(): void
    {
        $this->inspector = new DistPropertiesInspector();
    }

    /**
     * The case that prompted this: a developer's local database credentials in
     * the committed template rather than the gitignored build.properties.
     */
    public function testDetectsLocalDatabaseCredentials(): void
    {
        $properties = <<<'PROPS'
        builder.j5.db_host     = localhost
        builder.j5.db_user     = root
        builder.j5.db_pass     = root
        builder.j5.db_name     = j5_dev
        PROPS;

        self::assertSame(
            ['builder.j5.db_user', 'builder.j5.db_pass', 'builder.j5.db_name'],
            $this->inspector->populatedSecretKeys($properties)
        );
    }

    /**
     * The shipped template has these keys present but blank. Flagging them would
     * make the guard fire on every clean sync and train people to ignore it.
     */
    public function testBlankCredentialKeysAreNotFlagged(): void
    {
        $properties = <<<'PROPS'
        builder.j5.db_user     =
        builder.j5.db_pass     =
        builder.j5.db_name     =
        PROPS;

        self::assertSame([], $this->inspector->populatedSecretKeys($properties));
    }

    /**
     * `admin_pass = admin` ships in the template as a documented placeholder for
     * a throwaway local install, so it must stay outside the pattern.
     */
    public function testDocumentedPlaceholdersAreNotFlagged(): void
    {
        $properties = <<<'PROPS'
        builder.j5.admin_user  = admin
        builder.j5.admin_pass  = admin
        builder.j5.admin_email = admin@example.com
        builder.j5.url         = https://j5-dev.local
        PROPS;

        self::assertSame([], $this->inspector->populatedSecretKeys($properties));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function nonAssignmentLineProvider(): array
    {
        return [
            'hash comment'      => ['# builder.j5.db_pass = notreal'],
            'semicolon comment' => ['; builder.j5.db_pass = notreal'],
            'section header'    => ['[paths]'],
            'no assignment'     => ['builder.j5.db_pass'],
            'blank'             => ['   '],
        ];
    }

    /**
     * Commented-out credentials are not live values — the template ships whole
     * sections commented out, and flagging those would be noise.
     */
    #[DataProvider('nonAssignmentLineProvider')]
    public function testNonAssignmentLinesAreIgnored(string $line): void
    {
        self::assertSame([], $this->inspector->populatedSecretKeys($line));
    }

    public function testEmptyInputYieldsNothing(): void
    {
        self::assertSame([], $this->inspector->populatedSecretKeys(''));
        self::assertSame([], $this->inspector->keysMissingFromTemplate('', ''));
    }

    /**
     * The consumer being ahead of the shared schema is the case worth reporting:
     * a project that added a role=test install before the template caught up
     * would otherwise have it silently deleted on the next sync.
     */
    public function testReportsKeysTheTemplateWouldRemove(): void
    {
        $existing = <<<'PROPS'
        builder.installs = j5, j6-test
        builder.j6-test.role = test
        builder.j6-test.path = /path/to/joomla6-test
        PROPS;

        $template = <<<'PROPS'
        builder.installs = j5
        PROPS;

        self::assertSame(
            ['builder.j6-test.role', 'builder.j6-test.path'],
            $this->inspector->keysMissingFromTemplate($existing, $template)
        );
    }

    /**
     * A key the template also defines is being updated, not removed.
     */
    public function testKeysPresentInBothAreNotReported(): void
    {
        $properties = 'builder.j5.db_host = localhost';

        self::assertSame(
            [],
            $this->inspector->keysMissingFromTemplate($properties, $properties)
        );
    }

    /**
     * Guards the real artifact: whatever ships in the repo must be clean, or
     * every consumer inherits the leak on their next sync.
     */
    public function testShippedTemplateContainsNoPopulatedSecrets(): void
    {
        $template = \dirname(__DIR__, 2) . '/templates/build.properties.tmpl';

        self::assertFileExists($template);

        self::assertSame(
            [],
            $this->inspector->populatedSecretKeys((string) file_get_contents($template)),
            'templates/build.properties.tmpl must never ship populated credential values'
        );
    }
}
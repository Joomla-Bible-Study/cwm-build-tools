<?php

declare(strict_types=1);

namespace CWM\BuildTools\Tests\Release;

use CWM\BuildTools\Release\VersionTracker;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class VersionTrackerTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/cwm-version-tracker-' . bin2hex(random_bytes(6));
        mkdir($this->tmpDir, 0o777, true);
        mkdir($this->tmpDir . '/build', 0o777, true);
    }

    protected function tearDown(): void
    {
        $this->rrmdir($this->tmpDir);
    }

    #[Test]
    public function update_for_bump_writes_active_development_and_package_json(): void
    {
        $this->seedVersionsJson(['active_development' => ['version' => '10.3.0']]);
        $this->seedPackageJson('10.3.0');

        $tracker = $this->tracker(['versionsJson' => 'build/versions.json', 'packageJson' => 'package.json']);
        $touched = $this->runQuiet(fn () => $tracker->updateForBump('10.3.3'));

        self::assertCount(2, $touched);

        $versions = $this->readJson('build/versions.json');
        $pkg      = $this->readJson('package.json');

        self::assertSame('10.3.3', $versions['active_development']['version']);
        self::assertSame('10.3.3', $pkg['version']);
    }

    #[Test]
    public function update_for_bump_only_writes_configured_files(): void
    {
        $this->seedVersionsJson(['active_development' => ['version' => '10.3.0']]);

        // package.json exists but is NOT in the config — should be left alone.
        $this->seedPackageJson('10.3.0');

        $tracker = $this->tracker(['versionsJson' => 'build/versions.json']);
        $touched = $this->runQuiet(fn () => $tracker->updateForBump('10.3.3'));

        self::assertCount(1, $touched);
        self::assertSame('10.3.0', $this->readJson('package.json')['version']);
    }

    #[Test]
    public function update_for_release_computes_patch_minor_major_and_sets_date(): void
    {
        $this->seedVersionsJson([
            'current' => ['version' => '10.3.1'],
            'next'    => ['patch' => '10.3.2', 'minor' => '10.4.0', 'major' => '11.0.0'],
        ]);

        $tracker = $this->tracker(['versionsJson' => 'build/versions.json']);
        $this->runQuiet(fn () => $tracker->updateForRelease('10.3.2', '2026-05-15'));

        $v = $this->readJson('build/versions.json');

        self::assertSame('10.3.2', $v['current']['version']);
        self::assertSame('10.3.3', $v['next']['patch']);
        self::assertSame('10.4.0', $v['next']['minor']);
        self::assertSame('11.0.0', $v['next']['major']);
        self::assertSame('2026-05-15', $v['_updated']);
    }

    #[Test]
    public function update_for_release_strips_prerelease_suffix_when_computing_nexts(): void
    {
        $this->seedVersionsJson(['current' => ['version' => '10.3.1']]);

        $tracker = $this->tracker(['versionsJson' => 'build/versions.json']);
        $this->runQuiet(fn () => $tracker->updateForRelease('10.3.2-beta1', '2026-05-15'));

        $v = $this->readJson('build/versions.json');

        self::assertSame('10.3.2-beta1', $v['current']['version']);
        self::assertSame('10.3.3', $v['next']['patch']);
        self::assertSame('10.4.0', $v['next']['minor']);
        self::assertSame('11.0.0', $v['next']['major']);
    }

    #[Test]
    public function update_for_release_leaves_active_development_alone(): void
    {
        $this->seedVersionsJson([
            'current'            => ['version' => '10.3.1'],
            'active_development' => ['version' => '10.4.0'],
        ]);

        $tracker = $this->tracker(['versionsJson' => 'build/versions.json']);
        $this->runQuiet(fn () => $tracker->updateForRelease('10.3.2'));

        $v = $this->readJson('build/versions.json');

        // Release-time updates current.* and next.*, but never active_development —
        // that's the dev-side pointer set explicitly by cwm-bump.
        self::assertSame('10.4.0', $v['active_development']['version']);
    }

    #[Test]
    public function bump_is_idempotent_when_already_at_target_version(): void
    {
        $this->seedVersionsJson(['active_development' => ['version' => '10.3.3']]);
        $before = filemtime($this->tmpDir . '/build/versions.json');

        // Ensure clock advances by at least 1s so mtime comparison is meaningful.
        clearstatcache();
        sleep(1);

        $tracker = $this->tracker(['versionsJson' => 'build/versions.json']);
        $touched = $this->runQuiet(fn () => $tracker->updateForBump('10.3.3'));

        // @phpstan-ignore-next-line  $touched is array<string>
        self::assertSame([], $touched);

        $after = filemtime($this->tmpDir . '/build/versions.json');
        self::assertSame($before, $after);
    }

    #[Test]
    public function missing_file_warns_but_does_not_throw(): void
    {
        $tracker = $this->tracker(['versionsJson' => 'build/missing.json']);

        $touched = $this->runQuiet(fn () => $tracker->updateForBump('10.3.3'));

        self::assertSame([], $touched);
    }

    #[Test]
    public function malformed_json_throws(): void
    {
        file_put_contents($this->tmpDir . '/build/versions.json', '{ not valid json');

        $tracker = $this->tracker(['versionsJson' => 'build/versions.json']);

        $this->expectException(\RuntimeException::class);
        $this->runQuiet(fn () => $tracker->updateForBump('10.3.3'));
    }

    #[Test]
    public function release_with_non_semver_version_throws(): void
    {
        $this->seedVersionsJson(['current' => ['version' => '10.3.1']]);

        $tracker = $this->tracker(['versionsJson' => 'build/versions.json']);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('not semver');
        $this->runQuiet(fn () => $tracker->updateForRelease('not-a-version'));
    }

    #[Test]
    public function package_json_is_written_with_two_space_indent(): void
    {
        $this->seedPackageJson('10.3.0');

        $tracker = $this->tracker(['packageJson' => 'package.json']);
        $this->runQuiet(fn () => $tracker->updateForBump('10.3.3'));

        // npm convention is 2-space; lib re-indents from PHP's hardcoded 4.
        $raw = file_get_contents($this->tmpDir . '/package.json');
        self::assertStringContainsString("  \"version\":", $raw);
        self::assertStringNotContainsString("    \"version\":", $raw);
    }

    #[Test]
    public function absent_active_development_key_is_created_on_bump(): void
    {
        // Older versions.json files may not have the active_development block yet.
        $this->seedVersionsJson(['current' => ['version' => '10.3.1']]);

        $tracker = $this->tracker(['versionsJson' => 'build/versions.json']);
        $this->runQuiet(fn () => $tracker->updateForBump('10.3.3'));

        $v = $this->readJson('build/versions.json');

        self::assertSame('10.3.3', $v['active_development']['version']);
        self::assertSame('Use this for @since tags and migrations', $v['active_development']['description']);
    }

    // --- helpers ----------------------------------------------------------

    /**
     * @param array{versionsJson?: string, packageJson?: string} $config
     */
    private function tracker(array $config): VersionTracker
    {
        return new VersionTracker($this->tmpDir, $config);
    }

    /**
     * Run a callable while swallowing stdout. VersionTracker prints progress
     * lines as it works; tests assert on file state, not console output, and
     * phpunit strict mode fails on any unexpected output.
     */
    private function runQuiet(callable $fn): mixed
    {
        ob_start();
        try {
            return $fn();
        } finally {
            ob_end_clean();
        }
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function seedVersionsJson(array $overrides = []): void
    {
        $defaults = [
            '_updated' => '2026-01-01',
            'current'  => ['version' => '10.3.0', 'description' => 'Last stable release'],
            'next'     => ['patch' => '10.3.1', 'minor' => '10.4.0', 'major' => '11.0.0'],
        ];

        file_put_contents(
            $this->tmpDir . '/build/versions.json',
            json_encode(array_replace_recursive($defaults, $overrides), JSON_PRETTY_PRINT) . "\n",
        );
    }

    private function seedPackageJson(string $version): void
    {
        file_put_contents(
            $this->tmpDir . '/package.json',
            json_encode(['name' => 'fixture', 'version' => $version], JSON_PRETTY_PRINT) . "\n",
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function readJson(string $relative): array
    {
        $raw = file_get_contents($this->tmpDir . '/' . $relative);
        return json_decode((string) $raw, true);
    }

    private function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = "$dir/$entry";

            if (is_link($path) || !is_dir($path)) {
                @unlink($path);
            } else {
                $this->rrmdir($path);
            }
        }

        @rmdir($dir);
    }
}

<?php

declare(strict_types=1);

namespace CWM\BuildTools\Tests\Config;

use CWM\BuildTools\Config\ProfileResolver;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ProfileResolverTest extends TestCase
{
    /** Tools root used for production profile lookups in these tests. */
    private string $toolsRoot;

    /** Scratch dir for fixture profiles when we want to test merge edge cases. */
    private string $tmpToolsRoot;

    protected function setUp(): void
    {
        $this->toolsRoot    = \dirname(__DIR__, 2);
        $this->tmpToolsRoot = sys_get_temp_dir() . '/cwm-profile-resolver-' . bin2hex(random_bytes(6));

        mkdir($this->tmpToolsRoot . '/templates/profiles', 0o777, true);
    }

    protected function tearDown(): void
    {
        $this->rrmdir($this->tmpToolsRoot);
    }

    #[Test]
    public function resolve_returns_null_when_neither_profile_nor_inline_block_present(): void
    {
        $this->assertNull(ProfileResolver::resolve([]));
        $this->assertNull(ProfileResolver::resolve(['extension' => ['type' => 'component']]));
    }

    #[Test]
    public function resolve_returns_profile_defaults_verbatim_when_no_overrides(): void
    {
        $resolved = ProfileResolver::resolve(['profile' => 'library'], $this->toolsRoot);

        $this->assertIsArray($resolved);
        $this->assertSame('package.json', $resolved['packageJson']);
        $this->assertSame('__DEPLOY_VERSION__', $resolved['substituteTokens']['token']);
    }

    #[Test]
    public function resolve_returns_inline_block_when_no_profile_declared(): void
    {
        $inline = [
            'versionsJson'     => 'custom/path/versions.json',
            'substituteTokens' => ['token' => 'CUSTOM', 'paths' => ['src/'], 'extensions' => ['php']],
        ];

        $resolved = ProfileResolver::resolve(['versionTracking' => $inline]);

        $this->assertSame($inline, $resolved);
    }

    #[Test]
    public function resolve_deep_merges_consumer_overrides_on_top_of_profile(): void
    {
        $this->writeFixtureProfile('demo', [
            'versionTracking' => [
                'versionsJson'     => 'build/versions.json',
                'packageJson'      => 'package.json',
                'substituteTokens' => [
                    'token'      => '__DEPLOY_VERSION__',
                    'paths'      => ['src/'],
                    'extensions' => ['php'],
                ],
            ],
        ]);

        $resolved = $this->resolveWithFixtureProfile([
            'profile'         => 'demo',
            'versionTracking' => [
                'versionsJson' => 'override/versions.json',
            ],
        ]);

        $this->assertSame('override/versions.json', $resolved['versionsJson']);
        $this->assertSame('package.json', $resolved['packageJson']);
        $this->assertSame('__DEPLOY_VERSION__', $resolved['substituteTokens']['token']);
    }

    #[Test]
    public function resolve_replaces_lists_wholesale_instead_of_appending(): void
    {
        $this->writeFixtureProfile('demo', [
            'versionTracking' => [
                'substituteTokens' => [
                    'token'      => '__DEPLOY_VERSION__',
                    'paths'      => ['admin/', 'site/'],
                    'extensions' => ['php'],
                ],
            ],
        ]);

        $resolved = $this->resolveWithFixtureProfile([
            'profile'         => 'demo',
            'versionTracking' => [
                'substituteTokens' => [
                    'paths' => ['libraries/', 'src/', 'plg_task_cwmscripture/'],
                ],
            ],
        ]);

        $this->assertSame(['libraries/', 'src/', 'plg_task_cwmscripture/'], $resolved['substituteTokens']['paths']);
        $this->assertSame('__DEPLOY_VERSION__', $resolved['substituteTokens']['token']);
        $this->assertSame(['php'], $resolved['substituteTokens']['extensions']);
    }

    #[Test]
    public function resolve_throws_on_unknown_profile_name(): void
    {
        $this->expectException(InvalidArgumentException::class);
        ProfileResolver::resolve(['profile' => 'made-up-profile']);
    }

    #[Test]
    public function detect_maps_known_extension_types_to_profiles(): void
    {
        $this->assertSame('component',       ProfileResolver::detect(['extension' => ['type' => 'component']]));
        $this->assertSame('library',         ProfileResolver::detect(['extension' => ['type' => 'library']]));
        $this->assertSame('package-wrapper', ProfileResolver::detect(['extension' => ['type' => 'package']]));
        $this->assertSame('package-wrapper', ProfileResolver::detect(['extension' => ['type' => 'file']]));
    }

    #[Test]
    public function detect_returns_null_for_unknown_or_missing_type(): void
    {
        $this->assertNull(ProfileResolver::detect([]));
        $this->assertNull(ProfileResolver::detect(['extension' => ['type' => 'plugin']]));
        $this->assertNull(ProfileResolver::detect(['extension' => ['type' => 'module']]));
    }

    #[Test]
    public function known_lists_all_shipped_profiles(): void
    {
        $known = ProfileResolver::known();

        $this->assertContains('component',       $known);
        $this->assertContains('library',         $known);
        $this->assertContains('package-wrapper', $known);
    }

    #[Test]
    public function shipped_profile_templates_load_without_error(): void
    {
        foreach (ProfileResolver::known() as $name) {
            $resolved = ProfileResolver::resolve(['profile' => $name], $this->toolsRoot);

            $this->assertIsArray($resolved, "profile '{$name}' must resolve to an array");
            $this->assertArrayHasKey('substituteTokens', $resolved, "profile '{$name}' should ship substituteTokens");
        }
    }

    /** @param array<string, mixed> $payload */
    private function writeFixtureProfile(string $name, array $payload): void
    {
        $path = $this->tmpToolsRoot . '/templates/profiles/' . $name . '.json';
        file_put_contents($path, json_encode($payload, JSON_PRETTY_PRINT));
    }

    /**
     * Resolve using a fixture profile under $this->tmpToolsRoot. We can't add
     * names to ProfileResolver::KNOWN at runtime, so we monkey around the
     * registry by writing fixture profiles whose names *are* in the known list.
     * To avoid clobbering the real templates, the fixture overrides KNOWN by
     * reusing one of the real names ('library') as a scratch slot — callers
     * pass payloads under whatever profile they like, and we map it to
     * 'library' transparently.
     *
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    private function resolveWithFixtureProfile(array $config): array
    {
        $requested = $config['profile'] ?? null;

        if (is_string($requested) && !in_array($requested, ProfileResolver::known(), true)) {
            // Rename fixture profile on disk so the resolver can find it under
            // a name that passes the KNOWN allowlist.
            $allowed = ProfileResolver::known()[0];
            rename(
                $this->tmpToolsRoot . '/templates/profiles/' . $requested . '.json',
                $this->tmpToolsRoot . '/templates/profiles/' . $allowed . '.json'
            );
            $config['profile'] = $allowed;
        }

        $resolved = ProfileResolver::resolve($config, $this->tmpToolsRoot);

        $this->assertIsArray($resolved);

        return $resolved;
    }

    private function rrmdir(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $full = $path . '/' . $entry;

            if (!is_link($full) && is_dir($full)) {
                $this->rrmdir($full);
                continue;
            }

            unlink($full);
        }

        rmdir($path);
    }
}

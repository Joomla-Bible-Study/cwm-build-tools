<?php

declare(strict_types=1);

namespace CWM\BuildTools\Tests\Seed;

use CWM\BuildTools\Seed\SeedConfig;
use CWM\BuildTools\Seed\SeedException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class SeedConfigTest extends TestCase
{
    private string $tmp;

    protected function setUp(): void
    {
        $this->tmp = (string) realpath(sys_get_temp_dir()) . '/cwm-seedcfg-' . bin2hex(random_bytes(6));
        mkdir($this->tmp . '/build/seed', 0o777, true);

        foreach (['content', 'scenarios', 'volume'] as $f) {
            file_put_contents($this->tmp . '/build/seed/' . $f . '.php', '<?php');
        }
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tmp . '/build/seed/*') ?: [] as $f) {
            @unlink($f);
        }

        @rmdir($this->tmp . '/build/seed');
        @rmdir($this->tmp . '/build');
        @rmdir($this->tmp);
    }

    /**
     * @param  array<string, mixed>  $overrides
     *
     * @return array<string, mixed>
     */
    private function config(array $overrides = []): array
    {
        return ['seed' => array_replace([
            'marker'         => 'cwmseed-',
            'defaultProfile' => 'test',
            'layers'         => [
                ['name' => 'content', 'script' => 'build/seed/content.php', 'description' => 'baseline'],
                ['name' => 'scenarios', 'script' => 'build/seed/scenarios.php'],
                ['name' => 'volume', 'script' => 'build/seed/volume.php'],
            ],
            'profiles' => ['test' => ['content', 'scenarios'], 'bulk' => ['content', 'scenarios', 'volume']],
        ], $overrides)];
    }

    private function load(array $overrides = []): SeedConfig
    {
        return SeedConfig::fromProjectConfig($this->config($overrides), $this->tmp);
    }

    #[Test]
    public function readsLayersProfilesAndMarker(): void
    {
        $config = $this->load();

        $this->assertSame('cwmseed-', $config->marker);
        $this->assertSame(['content', 'scenarios', 'volume'], array_keys($config->layers));
        $this->assertSame('baseline', $config->layers['content']->description);
        $this->assertSame(['content', 'scenarios', 'volume'], $config->profiles['bulk']);
    }

    #[Test]
    public function theMarkerDefaultsWhenNotGiven(): void
    {
        $raw = $this->config();
        unset($raw['seed']['marker']);

        $this->assertSame('cwmseed-', SeedConfig::fromProjectConfig($raw, $this->tmp)->marker);
    }

    #[Test]
    public function aProjectWithNoSeedBlockSaysWhereToAddOne(): void
    {
        $this->expectException(SeedException::class);
        $this->expectExceptionMessage('no "seed" block');

        SeedConfig::fromProjectConfig(['extension' => []], $this->tmp);
    }

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function badConfigs(): array
    {
        return [
            'marker with a space'      => [['marker' => 'bad marker'], 'seed.marker'],
            'marker too short'         => [['marker' => 'x'], 'seed.marker'],
            'no layers'                => [['layers' => []], 'seed.layers is empty'],
            'uppercase layer name'     => [['layers' => [['name' => 'Content', 'script' => 'build/seed/content.php']]], 'must start with a lowercase'],
            'duplicate layer'          => [['layers' => [['name' => 'a', 'script' => 'build/seed/content.php'], ['name' => 'a', 'script' => 'build/seed/volume.php']]], 'declared twice'],
            'absolute script'          => [['layers' => [['name' => 'a', 'script' => '/etc/passwd.php']]], 'inside the project'],
            'script climbs out'        => [['layers' => [['name' => 'a', 'script' => '../x.php']]], 'inside the project'],
            'windows drive'            => [['layers' => [['name' => 'a', 'script' => 'C:\\x.php']]], 'inside the project'],
            'not php'                  => [['layers' => [['name' => 'a', 'script' => 'build/seed/content.sh']]], 'must be a .php file'],
            'script missing'           => [['layers' => [['name' => 'a', 'script' => 'build/seed/nope.php']]], 'does not exist'],
            'profile names a ghost'    => [['profiles' => ['t' => ['content', 'ghost']]], 'names layer "ghost"'],
            'bad profile name'         => [['profiles' => ['Bad Name' => ['content']]], 'profile name'],
            'default is not a profile' => [['defaultProfile' => 'nope'], 'not a declared profile'],
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    #[Test]
    #[DataProvider('badConfigs')]
    public function aMistakeInTheConfigFailsAtOnceNamingTheProblem(array $overrides, string $expected): void
    {
        $this->expectException(SeedException::class);
        $this->expectExceptionMessage($expected);

        $this->load($overrides);
    }

    #[Test]
    public function selectingAProfileReturnsItsLayersInDeclaredOrder(): void
    {
        // Typed in a different order in the profile; declared order wins so dependencies hold.
        $config = $this->load(['profiles' => ['t' => ['scenarios', 'content']], 'defaultProfile' => null]);

        $this->assertSame(['content', 'scenarios'], array_map(static fn ($l) => $l->name, $config->select('t', [])));
    }

    #[Test]
    public function layersCanBeNamedDirectlyAndStillRunInDeclaredOrder(): void
    {
        $this->assertSame(['content', 'volume'], array_map(static fn ($l) => $l->name, $this->load()->select(null, ['volume', 'content'])));
    }

    #[Test]
    public function noRequestUsesTheDefaultProfile(): void
    {
        $this->assertSame(['content', 'scenarios'], array_map(static fn ($l) => $l->name, $this->load()->select(null, [])));
    }

    #[Test]
    public function noRequestAndNoDefaultListsTheChoices(): void
    {
        $raw = $this->config();
        unset($raw['seed']['defaultProfile']);
        $config = SeedConfig::fromProjectConfig($raw, $this->tmp);

        try {
            $config->select(null, []);
            $this->fail('expected a SeedException');
        } catch (SeedException $e) {
            $this->assertStringContainsString('Profiles: test, bulk', $e->getMessage());
            $this->assertStringContainsString('Layers: content, scenarios, volume', $e->getMessage());
        }
    }

    #[Test]
    public function aProfileAndLayersTogetherAreAmbiguous(): void
    {
        $this->expectException(SeedException::class);
        $this->expectExceptionMessage('not both');

        $this->load()->select('test', ['content']);
    }

    #[Test]
    public function anUnknownProfileOrLayerListsWhatExists(): void
    {
        try {
            $this->load()->select('nope', []);
            $this->fail('expected a SeedException');
        } catch (SeedException $e) {
            $this->assertStringContainsString('Profiles: test, bulk', $e->getMessage());
        }

        $this->expectException(SeedException::class);
        $this->expectExceptionMessage('No layer "ghost"');

        $this->load()->select(null, ['ghost']);
    }
}

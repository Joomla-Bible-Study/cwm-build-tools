<?php

declare(strict_types=1);

namespace CWM\BuildTools\Tests\Config;

use CWM\BuildTools\Config\ComposerPathRepoSync;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * This class rewrites a consuming project's composer.json — the highest-
 * consequence thing cwm-setup does — so the matching rules and the
 * leave-everything-else-alone guarantee are pinned here.
 */
class ComposerPathRepoSyncTest extends TestCase
{
    private ComposerPathRepoSync $sync;

    protected function setUp(): void
    {
        $this->sync = new ComposerPathRepoSync();
    }

    #[Test]
    public function aNewSiblingGetsAPathRepoWithSymlinkOption(): void
    {
        $result = $this->sync->sync(
            ['name' => 'cwm/consumer'],
            ['cwm/lib-cwmscripture' => '/Users/dev/GitHub/lib_cwmscripture']
        );

        $this->assertTrue($result['changed']);
        $this->assertSame(
            [
                'type'    => 'path',
                'url'     => '/Users/dev/GitHub/lib_cwmscripture',
                'options' => ['symlink' => true],
            ],
            $result['data']['repositories'][0]
        );
    }

    #[Test]
    public function anExistingEntryIsMatchedAcrossDashUnderscoreVariants(): void
    {
        // Composer name uses dashes; the checkout (and the existing repo
        // URL) use underscores. The entry must be updated, not duplicated.
        $data = [
            'repositories' => [
                ['type' => 'path', 'url' => '../lib_cwmscripture', 'options' => ['symlink' => true]],
            ],
        ];

        $result = $this->sync->sync($data, ['cwm/lib-cwmscripture' => '/abs/lib_cwmscripture']);

        $this->assertTrue($result['changed']);
        $this->assertCount(1, $result['data']['repositories']);
        $this->assertSame('/abs/lib_cwmscripture', $result['data']['repositories'][0]['url']);
    }

    #[Test]
    public function anAlreadyCorrectEntryReportsNoChange(): void
    {
        $data = [
            'repositories' => [
                ['type' => 'path', 'url' => '/abs/lib_cwmscripture', 'options' => ['symlink' => true]],
            ],
        ];

        $result = $this->sync->sync($data, ['cwm/lib-cwmscripture' => '/abs/lib_cwmscripture']);

        $this->assertFalse($result['changed'], 'A byte-identical rewrite would churn composer.json for nothing');
    }

    #[Test]
    public function nonPathRepositoriesAreNeverTouched(): void
    {
        $vcs = ['type' => 'vcs', 'url' => 'https://github.com/Joomla-Bible-Study/cwm-build-tools.git'];

        $data = ['repositories' => [$vcs]];

        $result = $this->sync->sync($data, ['cwm/lib-cwmscripture' => '/abs/lib_cwmscripture']);

        $this->assertSame($vcs, $result['data']['repositories'][0], 'The VCS entry must survive untouched');
        $this->assertCount(2, $result['data']['repositories']);
    }

    #[Test]
    public function aMissingRepositoriesBlockIsCreated(): void
    {
        $result = $this->sync->sync([], ['cwm/x' => '/abs/x']);

        $this->assertTrue($result['changed']);
        $this->assertCount(1, $result['data']['repositories']);
    }

    #[Test]
    public function anUnrecognisableRepositoriesValueIsLeftAlone(): void
    {
        // repositories as a non-array is somebody's deliberate exotic setup;
        // guessing at it risks destroying what we do not understand.
        $result = $this->sync->sync(['repositories' => 'https://packages.example.com'], ['cwm/x' => '/abs/x']);

        $this->assertFalse($result['changed']);
        $this->assertSame('https://packages.example.com', $result['data']['repositories']);
    }

    #[Test]
    public function multipleSiblingsResolveIndependently(): void
    {
        $data = [
            'repositories' => [
                ['type' => 'path', 'url' => '../lib_cwmscripture'],
                ['type' => 'vcs', 'url' => 'https://example.com/repo.git'],
            ],
        ];

        $result = $this->sync->sync($data, [
            'cwm/lib-cwmscripture'   => '/abs/lib_cwmscripture',
            'cwm/cwmscripturelinks'  => '/abs/CWMScriptureLinks',
        ]);

        $this->assertTrue($result['changed']);
        $this->assertCount(3, $result['data']['repositories']);
        $this->assertSame('/abs/lib_cwmscripture', $result['data']['repositories'][0]['url']);
        $this->assertSame('/abs/CWMScriptureLinks', $result['data']['repositories'][2]['url']);
    }
}

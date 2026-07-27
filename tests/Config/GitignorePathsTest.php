<?php

declare(strict_types=1);

namespace CWM\BuildTools\Tests\Config;

use CWM\BuildTools\Config\GitignorePaths;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Getting these wrong is quiet in both directions: too narrow and build output
 * is committed, too broad and hand-written files silently stop being tracked.
 */
class GitignorePathsTest extends TestCase
{
    #[Test]
    public function output_path_is_derived_from_the_build_glob(): void
    {
        $config = ['build' => ['outputGlob' => 'build/dist/pkg_example-*.zip']];

        self::assertSame(['/build/dist/'], GitignorePaths::outputPaths($config));
    }

    #[Test]
    public function a_nested_output_glob_keeps_its_full_directory(): void
    {
        $config = ['build' => ['outputGlob' => 'dist/packages/out/*.zip']];

        self::assertSame(['/dist/packages/out/'], GitignorePaths::outputPaths($config));
    }

    #[Test]
    public function a_missing_glob_falls_back_to_the_conventional_directory(): void
    {
        self::assertSame(['/build/dist/'], GitignorePaths::outputPaths([]));
        self::assertSame(['/build/dist/'], GitignorePaths::outputPaths(['build' => ['outputGlob' => '']]));
    }

    /**
     * A glob with no directory part would otherwise derive '/' and ignore the
     * entire repository.
     */
    #[Test]
    public function a_bare_glob_falls_back_rather_than_ignoring_everything(): void
    {
        self::assertSame(['/build/dist/'], GitignorePaths::outputPaths(['build' => ['outputGlob' => '*.zip']]));
        self::assertSame(['/build/dist/'], GitignorePaths::outputPaths(['build' => ['outputGlob' => './*.zip']]));
    }

    #[Test]
    public function an_explicit_output_override_wins(): void
    {
        $config = [
            'build'     => ['outputGlob' => 'build/dist/*.zip'],
            'gitignore' => ['outputPaths' => ['/out/', '/other/']],
        ];

        self::assertSame(['/out/', '/other/'], GitignorePaths::outputPaths($config));
    }

    #[Test]
    public function an_explicit_empty_override_means_no_output_paths(): void
    {
        $config = [
            'build'     => ['outputGlob' => 'build/dist/*.zip'],
            'gitignore' => ['outputPaths' => []],
        ];

        self::assertSame([], GitignorePaths::outputPaths($config), 'An empty list is a choice, not absence');
    }

    #[Test]
    public function a_component_derives_media_paths_from_its_stripped_name(): void
    {
        $paths = GitignorePaths::mediaPaths([], 'com_example', 'component');

        self::assertSame([
            '/media/example/js/*.min.js',
            '/media/example/js/*.min.js.map',
            '/media/example/css/*.min.css',
            '/media/example/css/*.min.css.map',
        ], $paths);
    }

    #[Test]
    public function a_library_strips_its_prefix_too(): void
    {
        $paths = GitignorePaths::mediaPaths([], 'lib_cwmscripture', 'library');

        self::assertStringStartsWith('/media/cwmscripture/', $paths[0]);
    }

    /**
     * A package owns no media directory of its own, and plugins and modules
     * have no generic one, so guessing would ignore paths that either do not
     * exist or are hand-written.
     */
    #[Test]
    public function types_without_a_conventional_media_dir_derive_nothing(): void
    {
        foreach (['package', 'plugin', 'module', 'template', ''] as $type) {
            self::assertSame([], GitignorePaths::mediaPaths([], 'pkg_example', $type), "type: {$type}");
        }
    }

    /**
     * pkg_proclaim is a package wrapper around a component that *does* own
     * /media/, so it sets mediaPaths explicitly. The override exists precisely
     * because the default declines to guess for packages.
     */
    #[Test]
    public function an_explicit_media_override_applies_even_to_a_package(): void
    {
        $config = ['gitignore' => ['mediaPaths' => ['/media/js/', '/media/css/']]];

        self::assertSame(['/media/js/', '/media/css/'], GitignorePaths::mediaPaths($config, 'pkg_proclaim', 'package'));
    }

    #[Test]
    public function an_unprefixed_name_is_used_as_is(): void
    {
        $paths = GitignorePaths::mediaPaths([], 'example', 'component');

        self::assertStringStartsWith('/media/example/', $paths[0]);
    }

    /**
     * Values from JSON arrive as whatever the author typed; the block is built
     * by string concatenation, so they must come back as strings.
     */
    #[Test]
    public function override_values_are_coerced_to_strings(): void
    {
        $config = ['gitignore' => ['outputPaths' => [123, '/ok/']]];

        self::assertSame(['123', '/ok/'], GitignorePaths::outputPaths($config));
    }

    /**
     * An override given as an object rather than a list must not produce
     * string keys in the rendered block.
     */
    #[Test]
    public function override_keys_are_discarded(): void
    {
        $config = ['gitignore' => ['outputPaths' => ['a' => '/one/', 'b' => '/two/']]];

        self::assertSame(['/one/', '/two/'], GitignorePaths::outputPaths($config));
    }
}

<?php

declare(strict_types=1);

namespace CWM\BuildTools\Tests\Build;

use CWM\BuildTools\Build\PackageManifestSubstitution;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class PackageManifestSubstitutionTest extends TestCase
{
    private string $manifestPath;

    protected function setUp(): void
    {
        $dir = sys_get_temp_dir() . '/cwm-manifest-sub-' . bin2hex(random_bytes(6));
        mkdir($dir, 0o777, true);
        $this->manifestPath = $dir . '/pkg.xml';
    }

    protected function tearDown(): void
    {
        @unlink($this->manifestPath);
        @rmdir(dirname($this->manifestPath));
    }

    #[Test]
    public function apply_substitutes_every_configured_token(): void
    {
        file_put_contents($this->manifestPath, '<version>##VERSION##</version><creationDate>##DATE##</creationDate>');

        $sub = new PackageManifestSubstitution($this->manifestPath, [
            '##VERSION##' => '{version}',
            '##DATE##'    => '{date:Y-m-d}',
        ]);
        $sub->apply('7.5.2');

        $contents = (string) file_get_contents($this->manifestPath);
        self::assertStringContainsString('<version>7.5.2</version>', $contents);
        self::assertStringContainsString('<creationDate>' . date('Y-m-d') . '</creationDate>', $contents);
    }

    #[Test]
    public function restore_puts_the_original_bytes_back(): void
    {
        $original = '<version>##VERSION##</version>';
        file_put_contents($this->manifestPath, $original);

        $sub = new PackageManifestSubstitution($this->manifestPath, ['##VERSION##' => '{version}']);
        $sub->apply('7.5.2');
        $sub->restore();

        self::assertSame($original, file_get_contents($this->manifestPath));
    }

    #[Test]
    public function restore_is_safe_to_call_when_apply_was_never_called(): void
    {
        file_put_contents($this->manifestPath, 'untouched');

        $sub = new PackageManifestSubstitution($this->manifestPath, ['##VERSION##' => '{version}']);
        $sub->restore();

        self::assertSame('untouched', file_get_contents($this->manifestPath));
    }

    #[Test]
    public function no_tokens_configured_is_a_noop(): void
    {
        $original = '<version>##VERSION##</version>';
        file_put_contents($this->manifestPath, $original);

        $sub = new PackageManifestSubstitution($this->manifestPath, []);
        $sub->apply('7.5.2');

        self::assertSame($original, file_get_contents($this->manifestPath), 'no tokens configured, nothing to substitute');
    }

    #[Test]
    public function version_placeholders_lists_only_tokens_mapped_to_version(): void
    {
        $sub = new PackageManifestSubstitution($this->manifestPath, [
            '##VERSION##' => '{version}',
            '##DATE##'    => '{date:Y-m-d}',
            '##VENDOR##'  => 'Akeeba Ltd',
        ]);

        self::assertSame(['##VERSION##'], $sub->versionPlaceholders());
    }

    #[Test]
    public function version_placeholders_is_empty_when_no_token_maps_to_version(): void
    {
        $sub = new PackageManifestSubstitution($this->manifestPath, ['##VENDOR##' => 'Akeeba Ltd']);

        self::assertSame([], $sub->versionPlaceholders());
    }
}

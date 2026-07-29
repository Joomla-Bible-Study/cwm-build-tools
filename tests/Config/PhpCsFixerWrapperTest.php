<?php

declare(strict_types=1);

namespace CWM\BuildTools\Tests\Config;

use CWM\BuildTools\Config\PhpCsFixerWrapper;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The wrapper is regenerated on every sync, so `isGenerated()` decides
 * whether a consumer's project-specific overrides survive. Getting it wrong
 * in one direction silently deletes their excludes; in the other, the
 * require path never gets refreshed when the vendor-dir moves.
 */
final class PhpCsFixerWrapperTest extends TestCase
{
    #[Test]
    public function require_path_uses_the_projects_vendor_dir(): void
    {
        // Proclaim relocates Composer's install root; a hard-coded "vendor"
        // produces a wrapper that fatals on require for that project.
        self::assertSame(
            'libraries/vendor/cwm/build-tools/templates/.php-cs-fixer.base.php',
            PhpCsFixerWrapper::requirePath('libraries/vendor')
        );
    }

    #[Test]
    public function require_path_falls_back_to_vendor_when_empty(): void
    {
        self::assertSame(
            'vendor/cwm/build-tools/templates/.php-cs-fixer.base.php',
            PhpCsFixerWrapper::requirePath('')
        );
    }

    #[Test]
    public function require_path_tolerates_surrounding_slashes(): void
    {
        self::assertSame(
            'vendor/cwm/build-tools/templates/.php-cs-fixer.base.php',
            PhpCsFixerWrapper::requirePath('/vendor/')
        );
    }

    #[Test]
    public function rendered_wrapper_is_valid_php_that_returns_the_base(): void
    {
        $rendered = PhpCsFixerWrapper::render('vendor');

        self::assertStringStartsWith("<?php\n", $rendered);
        self::assertStringContainsString(
            "return require __DIR__ . '/vendor/cwm/build-tools/templates/.php-cs-fixer.base.php';",
            $rendered
        );
        self::assertNull(self::syntaxError($rendered), 'rendered wrapper is not parseable PHP');
    }

    #[Test]
    public function rendered_wrapper_is_recognised_as_generated(): void
    {
        // Round-trip: what render() produces, isGenerated() must claim, or
        // the sync can never update its own wrapper.
        foreach (['vendor', 'libraries/vendor'] as $vendorDir) {
            $rendered = PhpCsFixerWrapper::render($vendorDir);

            self::assertTrue(PhpCsFixerWrapper::extendsSharedBase($rendered));
            self::assertTrue(PhpCsFixerWrapper::isGenerated($rendered), "vendor-dir: {$vendorDir}");
        }
    }

    #[Test]
    public function customised_wrapper_is_not_treated_as_generated(): void
    {
        // Extends the base, but adds a project decision. Rewriting this file
        // would delete the exclude without saying so.
        $customised = <<<'PHP'
        <?php

        declare(strict_types=1);

        $config = require __DIR__ . '/vendor/cwm/build-tools/templates/.php-cs-fixer.base.php';
        $config->getFinder()->exclude('legacy');

        return $config;
        PHP;

        self::assertTrue(PhpCsFixerWrapper::extendsSharedBase($customised));
        self::assertFalse(PhpCsFixerWrapper::isGenerated($customised));
    }

    #[Test]
    public function docblock_example_does_not_make_a_generated_wrapper_look_customised(): void
    {
        // The generated file's own docblock shows the `$config = require ...`
        // override pattern. A comment-blind check would read that as project
        // code and refuse to ever refresh the wrapper again.
        $rendered = PhpCsFixerWrapper::render('vendor');

        self::assertStringContainsString('$config = require', $rendered);
        self::assertTrue(PhpCsFixerWrapper::isGenerated($rendered));
    }

    #[Test]
    public function bespoke_config_is_not_claimed_at_all(): void
    {
        $bespoke = <<<'PHP'
        <?php

        return (new PhpCsFixer\Config())->setRules(['@PSR12' => true]);
        PHP;

        self::assertFalse(PhpCsFixerWrapper::extendsSharedBase($bespoke));
        self::assertFalse(PhpCsFixerWrapper::isGenerated($bespoke));
    }

    /**
     * Lint a PHP snippet without executing it. Returns the error message, or
     * null when the snippet parses.
     */
    private static function syntaxError(string $code): ?string
    {
        try {
            // @phpstan-ignore-next-line — parsing is the point; nothing runs.
            $tokens = token_get_all($code, \TOKEN_PARSE);
        } catch (\ParseError $e) {
            return $e->getMessage();
        }

        return $tokens === [] ? 'empty token stream' : null;
    }
}

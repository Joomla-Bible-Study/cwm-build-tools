<?php

declare(strict_types=1);

namespace CWM\BuildTools\Config;

/**
 * Render and recognise a consumer's `.php-cs-fixer.dist.php` wrapper.
 *
 * The inheritance strategy the README describes for tooling configs: the
 * consumer's file is a thin `require` of the shared base in this package,
 * so a rule change ships via `composer update` instead of being copied into
 * five repos by hand.
 *
 * The vendor-dir is a parameter rather than the literal `vendor` because
 * Proclaim relocates Composer's install root to `libraries/vendor` — a
 * hard-coded path produces a wrapper that fatals on require for that one
 * project, which is exactly the consumer this tooling exists to serve.
 */
final class PhpCsFixerWrapper
{
    /**
     * Path fragment identifying a wrapper that already extends the shared
     * base, regardless of which vendor-dir it was generated for.
     */
    public const BASE_PATH_FRAGMENT = 'cwm/build-tools/templates/.php-cs-fixer.base.php';

    /**
     * The relative require path for a given vendor-dir.
     */
    public static function requirePath(string $vendorDir): string
    {
        $vendorDir = trim($vendorDir, '/') ?: 'vendor';

        return $vendorDir . '/' . self::BASE_PATH_FRAGMENT;
    }

    /**
     * Render the wrapper file.
     *
     * Two lines of actual code. Everything else is the comment explaining
     * where the rules live, because the failure mode this guards against is
     * someone opening the file, finding no rules, and re-adding a full
     * hand-maintained rule set on top.
     */
    public static function render(string $vendorDir): string
    {
        $path = self::requirePath($vendorDir);

        return <<<PHP
        <?php

        /**
         * PHP-CS-Fixer configuration — extends the shared CWM base.
         *
         * The rules live in cwm-build-tools so every CWM extension formats the
         * same way and a rule change ships via `composer update`. Do not copy the
         * rule set in here; add project-specific overrides to the returned config
         * instead:
         *
         *     \$config = require __DIR__ . '/{$path}';
         *     \$config->getFinder()->exclude('legacy');
         *
         *     return \$config;
         *
         * Written by `composer sync-configs`. Safe to edit — the sync only
         * rewrites this file when it is still the unmodified one-line wrapper.
         */

        declare(strict_types=1);

        return require __DIR__ . '/{$path}';

        PHP;
    }

    /**
     * Whether $contents already extends the shared base.
     */
    public static function extendsSharedBase(string $contents): bool
    {
        return str_contains($contents, self::BASE_PATH_FRAGMENT);
    }

    /**
     * Whether $contents is a wrapper this tool generated and may rewrite.
     *
     * The distinction that matters is between a wrapper the sync wrote and
     * one a developer has since customised. A generated wrapper requires the
     * base and does nothing else; the moment it grows a `setRules`, an
     * `exclude`, or any other call against the returned config, it holds
     * project decisions and the sync must keep its hands off — even though
     * it still matches {@see extendsSharedBase}.
     *
     * Checked by looking for a bare `return require`: a customised wrapper
     * assigns the config to a variable first, because there is no other way
     * to call a method on it.
     */
    public static function isGenerated(string $contents): bool
    {
        if (!self::extendsSharedBase($contents)) {
            return false;
        }

        // Strip comments before looking at statements — the generated file's
        // own docblock contains the `$config = require ...` example, and a
        // naive search would classify every generated wrapper as customised.
        $code = self::stripComments($contents);

        return (bool) preg_match('/\breturn\s+require\b/', $code);
    }

    /**
     * Remove comments and strings from PHP source, leaving statements.
     *
     * Uses the tokenizer rather than a regex: the docblock this has to see
     * past contains both `*` and `/` and quoted paths, and a regex that
     * handles those correctly is a regex nobody should have to review.
     */
    private static function stripComments(string $contents): string
    {
        $out = '';

        foreach (token_get_all($contents) as $token) {
            if (\is_array($token)) {
                if (\in_array($token[0], [\T_COMMENT, \T_DOC_COMMENT, \T_CONSTANT_ENCAPSED_STRING], true)) {
                    $out .= ' ';

                    continue;
                }

                $out .= $token[1];

                continue;
            }

            $out .= $token;
        }

        return $out;
    }
}

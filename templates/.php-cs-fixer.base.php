<?php

/**
 * Shared PHP-CS-Fixer base config for CWM Joomla extensions.
 *
 * Consumers do not copy this file. Their `.php-cs-fixer.dist.php` is a
 * one-line wrapper that requires it:
 *
 *     <?php
 *     return require __DIR__ . '/vendor/cwm/build-tools/templates/.php-cs-fixer.base.php';
 *
 * `cwm-sync-configs` writes that wrapper (with the consumer's real
 * vendor-dir) and refreshes it when the vendor-dir changes. Project-specific
 * rule overrides belong in the wrapper — call `->setRules()` on the returned
 * Config there rather than forking this file:
 *
 *     $config = require __DIR__ . '/vendor/cwm/build-tools/templates/.php-cs-fixer.base.php';
 *     $config->getFinder()->exclude('legacy');
 *
 *     return $config;
 *
 * Scope: the Finder scans the current working directory, not this file's
 * directory. PHP-CS-Fixer is always invoked from the project root, and
 * deriving the root from `__DIR__` would break the projects that relocate
 * Composer's vendor-dir (Proclaim installs into `libraries/vendor`).
 *
 * Risky rules are off. This runs unattended in CI and over Joomla
 * extension code that predates the toolchain; a fixer allowed to change
 * behaviour is not something to discover in a release diff.
 *
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

use PhpCsFixer\Config;
use PhpCsFixer\Finder;

$projectRoot = getcwd() ?: __DIR__;

$finder = (new Finder())
    ->in($projectRoot)
    ->exclude([
        'vendor',
        'node_modules',
        'build/dist',
        'build/vendor',
        'tmp',
        'cache',
    ])
    // Generated and vendored JS/PHP bundles, plus Joomla's own installer
    // artefacts, are not ours to restyle.
    ->notPath('#(^|/)libraries/vendor/#')
    ->notName('*.min.php')
    ->ignoreDotFiles(true)
    ->ignoreVCS(true);

return (new Config('cwm'))
    ->setRiskyAllowed(false)
    ->setFinder($finder)
    ->setRules([
        '@PSR12'                             => true,

        // Imports: one per statement, ordered, no unused leftovers.
        'no_unused_imports'                  => true,
        'ordered_imports'                    => [
            'sort_algorithm' => 'alpha',
            'imports_order'  => ['class', 'function', 'const'],
        ],
        'single_import_per_statement'        => true,
        'fully_qualified_strict_types'       => true,

        // Arrays: short syntax, aligned `=>` — the shape this repo's own
        // src/ and every example config already use.
        'array_syntax'                       => ['syntax' => 'short'],
        'binary_operator_spaces'             => [
            'default'   => 'single_space',
            'operators' => ['=>' => 'align_single_space_minimal', '=' => 'align_single_space_minimal'],
        ],
        'trim_array_spaces'                  => true,
        'whitespace_after_comma_in_array'    => true,
        'no_trailing_comma_in_singleline'    => true,
        'trailing_comma_in_multiline'        => ['elements' => ['arrays']],

        // Whitespace and blank lines.
        'blank_line_after_namespace'         => true,
        'blank_line_after_opening_tag'       => true,
        'blank_line_before_statement'        => [
            'statements' => ['return', 'throw', 'try', 'if', 'foreach', 'for', 'while', 'switch'],
        ],
        'no_extra_blank_lines'               => ['tokens' => ['extra', 'curly_brace_block', 'square_brace_block']],
        'no_whitespace_in_blank_line'        => true,
        'single_blank_line_at_eof'           => true,
        'method_chaining_indentation'        => true,

        // Docblocks. Joomla extensions are documentation-heavy by
        // convention and the audit pass in this repo backfilled them
        // everywhere; these keep the formatting uniform without
        // inventing or deleting content.
        'phpdoc_indent'                      => true,
        'phpdoc_trim'                        => true,
        'phpdoc_scalar'                      => true,
        'phpdoc_single_line_var_spacing'     => true,
        'phpdoc_no_useless_inheritdoc'       => true,
        'phpdoc_order'                       => true,
        'phpdoc_separation'                  => ['groups' => [['param'], ['return'], ['throws']]],
        'align_multiline_comment'            => true,
        'no_empty_phpdoc'                    => true,
        'no_superfluous_phpdoc_tags'         => false,

        // Syntax hygiene.
        'cast_spaces'                        => ['space' => 'single'],
        'concat_space'                       => ['spacing' => 'one'],
        'include'                            => true,
        'no_empty_statement'                 => true,
        'no_leading_namespace_whitespace'    => true,
        'no_singleline_whitespace_before_semicolons' => true,
        'no_useless_else'                    => true,
        'no_useless_return'                  => true,
        'normalize_index_brace'              => true,
        'object_operator_without_whitespace' => true,
        'single_quote'                       => true,
        'standardize_not_equals'             => true,
        'ternary_operator_spaces'            => true,
        'yoda_style'                         => false,
    ]);

<?php

declare(strict_types=1);

namespace CWM\BuildTools\Dev;

/**
 * Source-tree scanner for Joomla-upgrade-blocking patterns.
 *
 * Joomla 6/7 removes the Bootstrap-modal JS bridge and is dropping the bundled
 * jQuery global. Extensions that still open modals via `bootstrap.modal` +
 * `data-bs-toggle="modal"`, legacy `{handler: 'iframe'}` modal links, the
 * `Joomla.Modal` JS API, or jQuery globals break on upgrade. The first-class
 * replacement is `ModalSelectField` (declarative) or `import JoomlaDialog from
 * 'joomla.dialog'` in a `type="module"` asset.
 *
 * This scanner walks a project's source tree, applies a fixed ruleset of
 * regexes per file type, and returns one finding per match so `cwm-lint-
 * deprecations` can print them and gate CI. Inputs are project-controlled
 * source files (trusted, per the build-tools threat model) — the scanner only
 * reads, never executes.
 */
final class DeprecationScanner
{
    /**
     * Directory names skipped wholesale during the walk — build output, VCS,
     * vendored dependencies, and minified bundles live here and would only
     * produce noise.
     *
     * @var list<string>
     */
    private const DEFAULT_EXCLUDED_DIRS = [
        'vendor',
        'node_modules',
        '.git',
        '.github',
        '.idea',
        'build',
        'dist',
    ];

    /**
     * @var list<array{id: string, label: string, extensions: list<string>, pattern: string, message: string}>
     */
    private array $rules;

    /**
     * @param  list<array{id: string, label: string, extensions: list<string>, pattern: string, message: string}>|null  $rules
     *         Override the built-in ruleset. Defaults to self::defaultRules().
     */
    public function __construct(?array $rules = null)
    {
        $this->rules = $rules ?? self::defaultRules();
    }

    /**
     * The built-in J5 -> J6/J7 deprecation ruleset.
     *
     * Each rule names the file extensions it applies to, a PCRE pattern matched
     * line-by-line, and a one-line remediation message shown in the report.
     *
     * @return list<array{id: string, label: string, extensions: list<string>, pattern: string, message: string}>
     */
    public static function defaultRules(): array
    {
        return [
            [
                'id'         => 'bootstrap-modal-asset',
                'label'      => 'bootstrap.modal asset',
                'extensions' => ['php', 'js', 'mjs'],
                'pattern'    => '/bootstrap\.modal/',
                'message'    => "Bootstrap modal JS is removed in Joomla 6/7. Use ModalSelectField, or import JoomlaDialog from 'joomla.dialog' in a type=module asset.",
            ],
            [
                'id'         => 'data-bs-toggle-modal',
                'label'      => 'data-bs-toggle="modal" trigger',
                'extensions' => ['php', 'html', 'js', 'mjs'],
                'pattern'    => '/data-bs-toggle\s*=\s*["\']modal["\']/',
                'message'    => 'Bootstrap modal trigger markup breaks in Joomla 6/7. Replace with a JoomlaDialog-driven control.',
            ],
            [
                'id'         => 'iframe-modal-handler',
                'label'      => "legacy {handler: 'iframe'} modal link",
                'extensions' => ['php', 'js', 'mjs'],
                'pattern'    => '/handler["\']?\s*(?:=>|:)\s*["\']iframe["\']/',
                'message'    => "The iframe modal handler is gone in Joomla 6/7. Use JoomlaDialog with type 'iframe' / 'inline' instead.",
            ],
            [
                'id'         => 'joomla-modal-js-api',
                'label'      => 'Joomla.Modal / Joomla.loadModal JS API',
                'extensions' => ['js', 'mjs', 'php'],
                'pattern'    => '/Joomla\.(?:Modal\b|loadModal\b)/',
                'message'    => "The Joomla.Modal JS API is removed in Joomla 6/7. Import JoomlaDialog from 'joomla.dialog' and use its instance methods.",
            ],
            [
                'id'         => 'jquery-global',
                'label'      => 'jQuery global dependency',
                'extensions' => ['js', 'mjs'],
                'pattern'    => '/(?:window\.jQuery|\)\s*\(\s*jQuery\s*\)|\bjQuery\s*\()/',
                'message'    => 'Joomla is dropping the bundled jQuery global. Rewrite as a dependency-free ES module.',
            ],
        ];
    }

    /**
     * Walk $root and return every rule match found in scannable source files.
     *
     * Findings are returned in a stable order (directory walk order, then rule
     * order, then line number) so output and tests are deterministic.
     *
     * @param  string        $root          Directory to scan (typically the project root).
     * @param  list<string>  $excludedDirs  Directory basenames to skip. Defaults to
     *                                       self::DEFAULT_EXCLUDED_DIRS.
     *
     * @return list<array{rule: string, label: string, file: string, line: int, snippet: string, message: string}>
     */
    public function scan(string $root, ?array $excludedDirs = null): array
    {
        $excludedDirs = $excludedDirs ?? self::DEFAULT_EXCLUDED_DIRS;
        $findings     = [];

        foreach ($this->files($root, $excludedDirs) as $file) {
            $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));

            // Never flag minified bundles — they are generated, not authored.
            if (str_ends_with($file, '.min.js')) {
                continue;
            }

            $relevant = array_filter(
                $this->rules,
                static fn (array $rule): bool => in_array($ext, $rule['extensions'], true)
            );

            if ($relevant === []) {
                continue;
            }

            $contents = @file_get_contents($file);

            if ($contents === false) {
                continue;
            }

            $lines = explode("\n", $contents);

            foreach ($relevant as $rule) {
                foreach ($lines as $index => $line) {
                    if (preg_match($rule['pattern'], $line) === 1) {
                        $findings[] = [
                            'rule'    => $rule['id'],
                            'label'   => $rule['label'],
                            'file'    => $file,
                            'line'    => $index + 1,
                            'snippet' => trim($line),
                            'message' => $rule['message'],
                        ];
                    }
                }
            }
        }

        return $findings;
    }

    /**
     * Recursively yield file paths under $root, skipping excluded directories
     * and symlinked directories (never followed — same guard rationale as
     * removeDirectory()).
     *
     * @param  list<string>  $excludedDirs
     *
     * @return list<string>
     */
    private function files(string $root, array $excludedDirs): array
    {
        if (!is_dir($root)) {
            return [];
        }

        $found   = [];
        $entries = scandir($root) ?: [];

        sort($entries);

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $root . DIRECTORY_SEPARATOR . $entry;

            if (is_link($path)) {
                continue;
            }

            if (is_dir($path)) {
                if (in_array($entry, $excludedDirs, true)) {
                    continue;
                }

                $found = array_merge($found, $this->files($path, $excludedDirs));

                continue;
            }

            $found[] = $path;
        }

        return $found;
    }
}
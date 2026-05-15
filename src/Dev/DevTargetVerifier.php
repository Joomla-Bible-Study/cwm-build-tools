<?php

declare(strict_types=1);

namespace CWM\BuildTools\Dev;

use CWM\BuildTools\Config\CwmPackage;

/**
 * Verify a `role=dev` Joomla install: confirms that every symlink the
 * project (and every CWM Composer dep) expects is in place, that each
 * installed dep version satisfies the declared composer.json constraint,
 * and that path-repo deps have a clean working tree.
 *
 * Complement to ExtensionVerifier which queries `#__extensions` against
 * a `role=test` install. The dev-target check is purely filesystem-level:
 * it does not need a running Joomla, just paths.
 *
 * Each line of output is prefixed with one of:
 *   ✓ ok        link present and points at expected source
 *   + missing   no link at the target path
 *   ! conflict  link present but points somewhere else (existingRealpath surfaced)
 *   . stale     a real file or dir sits at the link path
 *   ? broken    link present, points where expected, but target file is gone
 *
 * For CWM dep packages, additional per-package rows are emitted:
 *   = version   "required <constraint>   installed <version>"
 *   ~ source    "path repo · <sourcePath> · clean | dirty"
 */
final class DevTargetVerifier
{
    public function __construct(
        private readonly string $projectRoot,
        /** @var array<string, mixed> */
        private readonly array $config,
        private readonly bool $verbose = false,
    ) {
    }

    /**
     * Verify $install against the project's own external links and every
     * CWM dep's declared joomlaLinks. Returns aggregate counts so the
     * caller can build an exit code.
     *
     * @param  list<CwmPackage> $packages
     * @return array{ok: int, errors: int, warnings: int}
     */
    public function verify(InstallConfig $install, array $packages): array
    {
        echo "\n=== Verifying dev install: {$install->path} ===\n";

        if (!is_dir($install->path)) {
            echo "  ERROR: Install path does not exist\n";

            return ['ok' => 0, 'errors' => 1, 'warnings' => 0];
        }

        $resolver = new LinkResolver($this->projectRoot, $this->config);
        $linker   = new Linker(false);

        $selfLinks = $resolver->externalLinks($install->path);
        $depLinks  = $resolver->externalLinksForPackages($install->path, $packages);

        $totals = ['ok' => 0, 'errors' => 0, 'warnings' => 0];

        echo "\n  Self (" . ($this->config['extension']['name'] ?? 'this project') . ")\n";
        $this->reportLinks($linker, $selfLinks, '    ', $totals);

        if ($packages !== []) {
            echo "\n  CWM dependencies (" . count($packages) . ")\n";

            $constraints = $this->readRootConstraints();
            $byPackage   = $this->groupLinks($depLinks);

            foreach ($packages as $pkg) {
                $tag = $pkg->isPathRepo ? 'path' : 'registry';
                echo "    {$pkg->name} @ {$pkg->version} ({$tag})\n";

                $this->reportVersion($pkg, $constraints, $totals);

                if ($pkg->isPathRepo) {
                    $this->reportPathRepoCleanliness($pkg, $totals);
                }

                $this->reportLinks($linker, $byPackage[$pkg->name] ?? [], '      ', $totals);
            }
        }

        echo "\n  Summary: {$totals['ok']} ok, {$totals['errors']} error(s), {$totals['warnings']} warning(s)\n";

        return $totals;
    }

    /**
     * @param  list<array{source: string, target: string}> $links
     * @param  array{ok: int, errors: int, warnings: int}  $totals
     */
    private function reportLinks(Linker $linker, array $links, string $indent, array &$totals): void
    {
        if ($links === []) {
            echo "{$indent}(no derived links)\n";

            return;
        }

        foreach ($links as $pair) {
            $result = $linker->check($pair['source'], $pair['target']);
            $target = $pair['target'];

            switch ($result['status']) {
                case 'ok':
                    $totals['ok']++;

                    if ($this->verbose) {
                        echo "{$indent}✓ ok        {$target}\n";
                    }
                    break;

                case 'missing':
                    $totals['errors']++;
                    echo "{$indent}+ missing   {$target}\n";
                    break;

                case 'wrong':
                    $totals['errors']++;
                    $existing = $result['existingRealpath'] ?? '(unknown)';
                    echo "{$indent}! conflict  {$target}\n";
                    echo "{$indent}            expected: {$pair['source']}\n";
                    echo "{$indent}            found:    {$existing}\n";
                    break;

                case 'stale':
                    $totals['errors']++;
                    echo "{$indent}. stale     {$target}  (real file/dir, not a symlink)\n";
                    break;

                case 'broken':
                    $totals['errors']++;
                    echo "{$indent}? broken    {$target}  (target file gone)\n";
                    break;
            }
        }
    }

    /**
     * @param  array<string, string>                       $constraints
     * @param  array{ok: int, errors: int, warnings: int}  $totals
     */
    private function reportVersion(CwmPackage $pkg, array $constraints, array &$totals): void
    {
        $required = $constraints[$pkg->name] ?? null;

        if ($required === null) {
            $totals['warnings']++;
            echo "      = version   installed {$pkg->version}, no root require entry\n";

            return;
        }

        if ($this->satisfies($pkg->version, $required)) {
            $totals['ok']++;

            if ($this->verbose) {
                echo "      = version   required {$required}   installed {$pkg->version}\n";
            }

            return;
        }

        $totals['errors']++;
        echo "      = version   required {$required}   installed {$pkg->version}  ← out of range\n";
    }

    /**
     * @param  array{ok: int, errors: int, warnings: int}  $totals
     */
    private function reportPathRepoCleanliness(CwmPackage $pkg, array &$totals): void
    {
        $source = $pkg->sourcePath;

        if ($source === null) {
            echo "      ~ source    path repo (source unknown)\n";

            return;
        }

        $status = $this->gitStatusPorcelain($source);

        if ($status === null) {
            echo "      ~ source    path repo · {$source} · (git unavailable)\n";

            return;
        }

        if ($status === '') {
            $totals['ok']++;

            if ($this->verbose) {
                echo "      ~ source    path repo · {$source} · clean\n";
            }

            return;
        }

        $totals['warnings']++;
        $lineCount = substr_count($status, "\n");
        echo "      ~ source    path repo · {$source} · dirty ({$lineCount} change(s))\n";
    }

    /**
     * Lightweight semver check sufficient for caret (`^X.Y`) and tilde
     * (`~X.Y`) constraints — the only forms used in CWM consumers'
     * composer.json. Falls back to literal match for anything else.
     *
     * Not a replacement for Composer's full constraint parser, but it
     * avoids dragging the Composer runtime into the verify call.
     */
    private function satisfies(string $version, string $constraint): bool
    {
        $constraint = trim($constraint);

        // Wildcard / stability-only constraints satisfy any installed version.
        // Examples: `*`, `@dev`, `*@dev`, `dev-main` (when installed is also `dev-*`).
        if ($constraint === '*' || $constraint === '@dev' || $constraint === '*@dev') {
            return true;
        }

        // Branch alias requirements (`dev-main`, `dev-master`) match when the
        // installed version is the same dev branch.
        if (str_starts_with($constraint, 'dev-')) {
            return $constraint === $version || $constraint === trim($version);
        }

        // Strip trailing @<stability> qualifier — the stability constraint
        // doesn't affect range semantics for our purposes.
        if (preg_match('/^(.*)@[a-z]+$/i', $constraint, $m) === 1) {
            $constraint = trim($m[1]);

            if ($constraint === '' || $constraint === '*') {
                return true;
            }
        }

        $version = $this->normaliseVersion($version);

        if ($constraint === $version) {
            return true;
        }

        if (str_starts_with($constraint, '^')) {
            $req = $this->parseVersion(substr($constraint, 1));
            $cur = $this->parseVersion($version);

            if ($req === null || $cur === null) {
                return false;
            }

            // Caret: same major, current >= required.
            if ($req['major'] !== $cur['major']) {
                return false;
            }

            return $this->compare($cur, $req) >= 0;
        }

        if (str_starts_with($constraint, '~')) {
            $req = $this->parseVersion(substr($constraint, 1));
            $cur = $this->parseVersion($version);

            if ($req === null || $cur === null) {
                return false;
            }

            // Tilde ~X.Y: same major+minor, current >= required.
            if ($req['major'] !== $cur['major'] || $req['minor'] !== $cur['minor']) {
                return false;
            }

            return $this->compare($cur, $req) >= 0;
        }

        // Bare version literal — exact match.
        return $constraint === $version;
    }

    private function normaliseVersion(string $version): string
    {
        // Strip leading `v` and any prerelease/build metadata for the
        // satisfies check. CWM consumers always pin against stable
        // X.Y.Z, so this is sufficient.
        $version = ltrim($version, 'v');

        if (preg_match('/^(\d+\.\d+\.\d+)/', $version, $m) === 1) {
            return $m[1];
        }

        return $version;
    }

    /**
     * @return array{major: int, minor: int, patch: int}|null
     */
    private function parseVersion(string $version): ?array
    {
        $clean = $this->normaliseVersion($version);

        if (preg_match('/^(\d+)\.(\d+)\.(\d+)$/', $clean, $m) !== 1) {
            // Allow X.Y by padding patch to 0.
            if (preg_match('/^(\d+)\.(\d+)$/', $clean, $m) !== 1) {
                return null;
            }

            $m[3] = '0';
        }

        return ['major' => (int) $m[1], 'minor' => (int) $m[2], 'patch' => (int) $m[3]];
    }

    /**
     * @param  array{major: int, minor: int, patch: int} $a
     * @param  array{major: int, minor: int, patch: int} $b
     */
    private function compare(array $a, array $b): int
    {
        return ($a['major'] <=> $b['major'])
            ?: ($a['minor'] <=> $b['minor'])
            ?: ($a['patch'] <=> $b['patch']);
    }

    /**
     * @return array<string, string>
     */
    private function readRootConstraints(): array
    {
        $path = $this->projectRoot . '/composer.json';

        if (!is_file($path)) {
            return [];
        }

        $raw = json_decode((string) file_get_contents($path), true);

        if (!is_array($raw)) {
            return [];
        }

        $out      = [];
        $sections = ['require', 'require-dev'];

        foreach ($sections as $section) {
            foreach ($raw[$section] ?? [] as $name => $constraint) {
                $out[(string) $name] = (string) $constraint;
            }
        }

        return $out;
    }

    /**
     * Run `git -C <path> status --porcelain` via proc_open array-form.
     * Returns the porcelain output string (empty == clean), or null when
     * git is unavailable or the path isn't a git working tree.
     */
    private function gitStatusPorcelain(string $path): ?string
    {
        if (!is_dir($path . '/.git') && !is_file($path . '/.git')) {
            return null;
        }

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $pipes   = [];
        $process = proc_open(
            ['git', '-C', $path, 'status', '--porcelain'],
            $descriptors,
            $pipes,
        );

        if (!is_resource($process)) {
            return null;
        }

        fclose($pipes[0]);
        $stdout = (string) stream_get_contents($pipes[1]);
        // Drain stderr to avoid blocking on pipe buffer.
        stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        $exit = proc_close($process);

        if ($exit !== 0) {
            return null;
        }

        return $stdout;
    }

    /**
     * @param  list<array{source: string, target: string, package: string}> $links
     * @return array<string, list<array{source: string, target: string}>>
     */
    private function groupLinks(array $links): array
    {
        $out = [];

        foreach ($links as $link) {
            $out[$link['package']][] = ['source' => $link['source'], 'target' => $link['target']];
        }

        return $out;
    }
}

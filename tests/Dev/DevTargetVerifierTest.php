<?php

declare(strict_types=1);

namespace CWM\BuildTools\Tests\Dev;

use CWM\BuildTools\Config\CwmPackage;
use CWM\BuildTools\Dev\DevTargetVerifier;
use CWM\BuildTools\Dev\InstallConfig;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class DevTargetVerifierTest extends TestCase
{
    private string $tmpDir;
    private string $projectRoot;
    private string $joomlaRoot;

    protected function setUp(): void
    {
        $this->tmpDir      = sys_get_temp_dir() . '/cwm-dev-verifier-' . bin2hex(random_bytes(6));
        $this->projectRoot = $this->tmpDir . '/project';
        $this->joomlaRoot  = $this->tmpDir . '/joomla';

        mkdir($this->projectRoot, 0o777, true);
        mkdir($this->joomlaRoot, 0o777, true);
    }

    protected function tearDown(): void
    {
        $this->rrmdir($this->tmpDir);
    }

    #[Test]
    public function reports_ok_when_no_links_and_no_deps(): void
    {
        $install = $this->install();

        ob_start();
        $totals = (new DevTargetVerifier($this->projectRoot, []))->verify($install, []);
        $output = (string) ob_get_clean();

        self::assertSame(0, $totals['errors']);
        self::assertSame(0, $totals['warnings']);
        self::assertStringContainsString('Verifying dev install', $output);
    }

    #[Test]
    public function flags_missing_dep_link_as_error(): void
    {
        $pkgRoot = $this->tmpDir . '/vendor/cwm/scripturelinks';
        mkdir($pkgRoot, 0o777, true);
        file_put_contents($pkgRoot . '/cwmscripturelinks.xml', '<extension/>');

        $this->writeComposerJson(['require' => ['cwm/scripturelinks' => '^2.1']]);

        $pkg     = $this->makePackage($pkgRoot, '2.1.4', false, null);
        $install = $this->install();

        ob_start();
        $totals = (new DevTargetVerifier($this->projectRoot, []))->verify($install, [$pkg]);
        $output = (string) ob_get_clean();

        self::assertGreaterThan(0, $totals['errors']);
        self::assertStringContainsString('+ missing', $output);
    }

    #[Test]
    public function reports_ok_when_expected_dep_link_is_in_place(): void
    {
        $pkgRoot = $this->tmpDir . '/vendor/cwm/scripturelinks';
        mkdir($pkgRoot, 0o777, true);
        file_put_contents($pkgRoot . '/cwmscripturelinks.xml', '<extension/>');

        $this->writeComposerJson(['require' => ['cwm/scripturelinks' => '^2.1']]);

        mkdir($this->joomlaRoot . '/libraries', 0o777, true);
        symlink($pkgRoot, $this->joomlaRoot . '/libraries/cwmscripturelinks');

        mkdir($this->joomlaRoot . '/administrator/manifests/libraries', 0o777, true);
        symlink(
            $pkgRoot . '/cwmscripturelinks.xml',
            $this->joomlaRoot . '/administrator/manifests/libraries/cwmscripturelinks.xml',
        );

        $pkg     = $this->makePackage($pkgRoot, '2.1.4', false, null);
        $install = $this->install();

        ob_start();
        $totals = (new DevTargetVerifier($this->projectRoot, [], true))->verify($install, [$pkg]);
        $output = (string) ob_get_clean();

        self::assertSame(0, $totals['errors']);
        self::assertGreaterThanOrEqual(3, $totals['ok']);
        self::assertStringContainsString('✓ ok', $output);
    }

    #[Test]
    public function flags_constraint_violation_when_installed_version_too_low(): void
    {
        $pkgRoot = $this->tmpDir . '/vendor/cwm/scripturelinks';
        mkdir($pkgRoot, 0o777, true);
        file_put_contents($pkgRoot . '/cwmscripturelinks.xml', '<extension/>');

        $this->writeComposerJson(['require' => ['cwm/scripturelinks' => '^2.1']]);

        $pkg     = $this->makePackage($pkgRoot, '2.0.5', false, null);
        $install = $this->install();

        ob_start();
        $totals = (new DevTargetVerifier($this->projectRoot, []))->verify($install, [$pkg]);
        $output = (string) ob_get_clean();

        self::assertGreaterThan(0, $totals['errors']);
        self::assertStringContainsString('out of range', $output);
    }

    #[Test]
    public function warns_when_dep_has_no_root_require_entry(): void
    {
        $pkgRoot = $this->tmpDir . '/vendor/cwm/scripturelinks';
        mkdir($pkgRoot, 0o777, true);
        file_put_contents($pkgRoot . '/cwmscripturelinks.xml', '<extension/>');

        $this->writeComposerJson(['require' => []]);

        $pkg     = $this->makePackage($pkgRoot, '2.1.4', false, null);
        $install = $this->install();

        ob_start();
        $totals = (new DevTargetVerifier($this->projectRoot, []))->verify($install, [$pkg]);
        $output = (string) ob_get_clean();

        self::assertGreaterThan(0, $totals['warnings']);
        self::assertStringContainsString('no root require entry', $output);
    }

    #[Test]
    public function reports_clean_path_repo_when_no_uncommitted_changes(): void
    {
        $sibling = $this->tmpDir . '/sibling/cwm';
        mkdir($sibling, 0o777, true);

        $this->writeComposerJson(['require' => ['cwm/scripturelinks' => '^2.1']]);
        file_put_contents($sibling . '/cwmscripturelinks.xml', '<extension/>');

        // Init + add-all + commit, sequenced so the working tree ends clean.
        $this->initGitRepoCommitting($sibling);

        // Sanity: the test fixture is what we think it is.
        $status = $this->captureGitStatus($sibling);
        self::assertSame('', $status, "fixture repo should be clean, got:\n{$status}");

        $pkg     = $this->makePackage($sibling, 'dev-main', true, $sibling);
        $install = $this->install();

        ob_start();
        $totals = (new DevTargetVerifier($this->projectRoot, [], true))->verify($install, [$pkg]);
        $output = (string) ob_get_clean();

        self::assertStringContainsString('clean', $output);
        self::assertSame(0, $totals['warnings']);
    }

    #[Test]
    public function warns_about_dirty_path_repo_working_tree(): void
    {
        $sibling = $this->tmpDir . '/sibling/cwm-dirty';
        mkdir($sibling, 0o777, true);

        $this->initGitRepo($sibling);
        file_put_contents($sibling . '/UNCOMMITTED.md', 'still editing');

        $this->writeComposerJson(['require' => ['cwm/scripturelinks' => '^2.1']]);

        $pkg     = $this->makePackage($sibling, 'dev-main', true, $sibling);
        $install = $this->install();

        ob_start();
        $totals = (new DevTargetVerifier($this->projectRoot, []))->verify($install, [$pkg]);
        $output = (string) ob_get_clean();

        self::assertGreaterThan(0, $totals['warnings']);
        self::assertStringContainsString('dirty', $output);
    }

    #[Test]
    public function at_dev_stability_constraint_satisfies_any_dev_version(): void
    {
        $pkgRoot = $this->tmpDir . '/vendor/cwm/sib';
        mkdir($pkgRoot, 0o777, true);
        file_put_contents($pkgRoot . '/sib.xml', '<extension/>');

        $this->writeComposerJson(['require' => ['cwm/sib' => '@dev']]);

        $pkg     = new CwmPackage(
            name: 'cwm/sib',
            version: 'dev-main',
            versionNormalized: 'dev-main',
            joomlaLinks: [['type' => 'library', 'name' => 'sib']],
            installPath: $pkgRoot,
            isPathRepo: false,
            sourcePath: null,
            reference: 'abcd',
        );
        $install = $this->install();

        // Pre-create the expected links so we focus the assertion on the constraint check.
        mkdir($this->joomlaRoot . '/libraries', 0o777, true);
        symlink($pkgRoot, $this->joomlaRoot . '/libraries/sib');
        mkdir($this->joomlaRoot . '/administrator/manifests/libraries', 0o777, true);
        symlink($pkgRoot . '/sib.xml', $this->joomlaRoot . '/administrator/manifests/libraries/sib.xml');

        ob_start();
        $totals = (new DevTargetVerifier($this->projectRoot, []))->verify($install, [$pkg]);
        $output = (string) ob_get_clean();

        self::assertSame(0, $totals['errors'], "@dev constraint should satisfy dev-main:\n{$output}");
        self::assertStringNotContainsString('out of range', $output);
    }

    #[Test]
    public function wildcard_constraint_satisfies_any_version(): void
    {
        $pkgRoot = $this->tmpDir . '/vendor/cwm/sib';
        mkdir($pkgRoot, 0o777, true);
        file_put_contents($pkgRoot . '/sib.xml', '<extension/>');

        $this->writeComposerJson(['require' => ['cwm/sib' => '*']]);

        $pkg     = new CwmPackage(
            name: 'cwm/sib',
            version: '99.0.0',
            versionNormalized: '99.0.0.0',
            joomlaLinks: [['type' => 'library', 'name' => 'sib']],
            installPath: $pkgRoot,
            isPathRepo: false,
            sourcePath: null,
            reference: 'abcd',
        );
        $install = $this->install();

        mkdir($this->joomlaRoot . '/libraries', 0o777, true);
        symlink($pkgRoot, $this->joomlaRoot . '/libraries/sib');
        mkdir($this->joomlaRoot . '/administrator/manifests/libraries', 0o777, true);
        symlink($pkgRoot . '/sib.xml', $this->joomlaRoot . '/administrator/manifests/libraries/sib.xml');

        ob_start();
        $totals = (new DevTargetVerifier($this->projectRoot, []))->verify($install, [$pkg]);
        $output = (string) ob_get_clean();

        self::assertSame(0, $totals['errors'], "wildcard `*` should satisfy any version:\n{$output}");
    }

    #[Test]
    public function returns_error_when_install_path_is_missing(): void
    {
        $install = new InstallConfig(id: 'gone', path: $this->tmpDir . '/not-here');

        ob_start();
        $totals = (new DevTargetVerifier($this->projectRoot, []))->verify($install, []);
        ob_end_clean();

        self::assertSame(1, $totals['errors']);
    }

    private function install(): InstallConfig
    {
        return new InstallConfig(id: 'j5', path: $this->joomlaRoot, role: InstallConfig::ROLE_DEV);
    }

    private function makePackage(string $root, string $version, bool $isPathRepo, ?string $sourcePath): CwmPackage
    {
        return new CwmPackage(
            name: 'cwm/scripturelinks',
            version: $version,
            versionNormalized: $version . '.0',
            joomlaLinks: [['type' => 'library', 'name' => 'cwmscripturelinks']],
            installPath: $root,
            isPathRepo: $isPathRepo,
            sourcePath: $sourcePath,
            reference: 'abcd',
        );
    }

    /**
     * @param  array<string, mixed> $data
     */
    private function writeComposerJson(array $data): void
    {
        file_put_contents($this->projectRoot . '/composer.json', json_encode($data));
    }

    /**
     * Initialise a git repo with an empty initial commit. Used by the
     * "dirty working tree" test which then adds an uncommitted file.
     */
    private function initGitRepo(string $path): void
    {
        if ($this->runGit($path, ['init', '-q']) !== 0) {
            $this->markTestSkipped('git not available for cleanliness check fixture');
        }

        $this->runGit($path, ['-c', 'user.email=t@t', '-c', 'user.name=t', 'commit', '-q', '-m', 'init', '--allow-empty']);
    }

    /**
     * Initialise a git repo and commit everything currently in $path so
     * the working tree ends clean. Used by the "clean working tree" test.
     */
    private function initGitRepoCommitting(string $path): void
    {
        if ($this->runGit($path, ['init', '-q']) !== 0) {
            $this->markTestSkipped('git not available for cleanliness check fixture');
        }

        $exit = $this->runGit($path, ['add', '-A'], $stderr);
        self::assertSame(0, $exit, "git add -A failed: {$stderr}");

        $exit = $this->runGit($path, [
            '-c', 'user.email=t@t', '-c', 'user.name=t',
            'commit', '-q', '-m', 'init',
        ], $stderr);

        if ($exit !== 0) {
            // GPG signing or hook configuration on the test runner's
            // global git config can break our temp-repo commits.
            // The test isn't about real commits — it's about whether
            // the cleanliness check can distinguish clean from dirty.
            // Skip rather than fail when the runner's env blocks us.
            $this->markTestSkipped("git commit in fixture failed (likely host signing/hook config): {$stderr}");
        }
    }

    private function captureGitStatus(string $path): string
    {
        $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $pipes       = [];
        $process     = proc_open(
            ['git', '-C', $path, 'status', '--porcelain'],
            $descriptors,
            $pipes,
        );

        if (!is_resource($process)) {
            return '<git unavailable>';
        }

        fclose($pipes[0]);
        $out = (string) stream_get_contents($pipes[1]);
        stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);

        return $out;
    }

    /**
     * @param  list<string> $args
     */
    private function runGit(string $cwd, array $args, ?string &$stderr = null): int
    {
        $cmd = array_merge(['git', '-C', $cwd], $args);
        $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $pipes       = [];

        // Sandbox the test fixture from the host's global git config:
        // commit signing, default branch name, hook paths, anything else
        // that would normally apply to user commits. The test asserts
        // cleanliness-detection logic, not real git workflow behavior.
        $env = array_merge($_ENV, [
            'GIT_CONFIG_GLOBAL' => '/dev/null',
            'GIT_CONFIG_SYSTEM' => '/dev/null',
            'HOME'              => $cwd,
        ]);

        $process = proc_open($cmd, $descriptors, $pipes, null, $env);

        if (!is_resource($process)) {
            $stderr = 'proc_open returned false';

            return 127;
        }

        fclose($pipes[0]);
        stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return proc_close($process);
    }

    private function rrmdir(string $path): void
    {
        if (!is_dir($path) && !is_link($path)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $entry) {
            if ($entry->isDir() && !$entry->isLink()) {
                @rmdir($entry->getPathname());
            } else {
                @unlink($entry->getPathname());
            }
        }

        @rmdir($path);
    }
}

<?php

declare(strict_types=1);

namespace CWM\BuildTools\Dev;

/**
 * Result of an ExtensionInstaller operation. `exitCode` 0 means the
 * Joomla CLI exited cleanly; any non-zero is a failed install.
 *
 * `stdout` is captured for surfacing the installed extension id when
 * Joomla logs it; `stderr` carries the failure cause.
 */
final class InstallResult
{
    public function __construct(
        public readonly bool $ok,
        public readonly int $exitCode,
        public readonly string $stdout,
        public readonly string $stderr,
    ) {
    }
}

/**
 * Install (or upgrade) a built Joomla extension zip into a Joomla site by
 * shelling out to Joomla's bundled CLI. Distinct from JoomlaInstaller,
 * which installs Joomla itself.
 *
 * Modern Joomla (5.x / 6.x) exposes:
 *   php <joomlaRoot>/cli/joomla.php extension:install --path=<zip>
 * Note the named `--path=` flag — there is no positional form and no
 * `extension:installfile` command (that name only existed in older
 * tutorials; it never shipped in J5+).
 *
 * Upgrade-over-existing is handled by the same `extension:install` call:
 * Installer::setupInstall() detects an existing element by manifest
 * (type, element, folder, client) and routes the call to the adapter's
 * update() path automatically. There is no separate `extension:update`
 * CLI command in J5/J6 — only `core:update` for Joomla itself.
 *
 * `proc_open` is invoked in array-arg form so no shell interprets the
 * arguments. This is a project-wide guardrail (see CLAUDE.md): shell
 * metacharacters in the zip path or Joomla root must NEVER be expanded.
 */
final class ExtensionInstaller
{
    public function __construct(private readonly bool $verbose = false)
    {
    }

    /**
     * Install (or upgrade-over-existing) the zip at $zipPath into the
     * Joomla site at $joomlaRoot. Returns an InstallResult describing
     * the outcome — callers decide whether to bail or continue.
     */
    public function install(string $zipPath, string $joomlaRoot): InstallResult
    {
        $cliEntry = $joomlaRoot . '/cli/joomla.php';

        if (!is_file($cliEntry)) {
            return new InstallResult(
                ok: false,
                exitCode: 127,
                stdout: '',
                stderr: "Joomla CLI not found at {$cliEntry}",
            );
        }

        if (!is_file($zipPath)) {
            return new InstallResult(
                ok: false,
                exitCode: 2,
                stdout: '',
                stderr: "Extension zip not found at {$zipPath}",
            );
        }

        return $this->run([
            PHP_BINARY,
            $cliEntry,
            'extension:install',
            '--path=' . $zipPath,
            '--no-interaction',
        ]);
    }

    /**
     * Remove an installed extension by its integer extension_id. Joomla's
     * `extension:remove` takes the numeric id (NOT name), and the `locked`
     * column blocks removal of core extensions with exit code 4.
     */
    public function uninstall(int $extensionId, string $joomlaRoot): InstallResult
    {
        $cliEntry = $joomlaRoot . '/cli/joomla.php';

        if (!is_file($cliEntry)) {
            return new InstallResult(
                ok: false,
                exitCode: 127,
                stdout: '',
                stderr: "Joomla CLI not found at {$cliEntry}",
            );
        }

        return $this->run([
            PHP_BINARY,
            $cliEntry,
            'extension:remove',
            (string) $extensionId,
            '--no-interaction',
        ]);
    }

    /**
     * Run a command via proc_open in array-arg form (no shell). Captures
     * stdout/stderr separately so a caller can show the failure cause
     * without burying it in the success log.
     *
     * @param  list<string> $argv
     */
    private function run(array $argv): InstallResult
    {
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $pipes   = [];
        $process = proc_open($argv, $descriptors, $pipes);

        if (!is_resource($process)) {
            return new InstallResult(
                ok: false,
                exitCode: 1,
                stdout: '',
                stderr: 'Failed to spawn process: ' . implode(' ', $argv),
            );
        }

        fclose($pipes[0]);

        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);

        fclose($pipes[1]);
        fclose($pipes[2]);

        $exit = proc_close($process);

        if ($this->verbose) {
            echo "+ " . implode(' ', $argv) . "\n";

            if ($stdout !== '') {
                echo $stdout;
            }

            if ($stderr !== '') {
                fwrite(\STDERR, $stderr);
            }
        }

        return new InstallResult(
            ok: $exit === 0,
            exitCode: $exit,
            stdout: $stdout,
            stderr: $stderr,
        );
    }
}

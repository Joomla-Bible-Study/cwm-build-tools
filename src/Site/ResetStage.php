<?php

declare(strict_types=1);

namespace CWM\BuildTools\Site;

/**
 * Puts a role=test site back to a known state, then reinstalls the built package.
 *
 * The clearing is `cwm-reset-testsite`'s job and is run, not reimplemented: it
 * already knows what a project's extension family is and prints what it retained.
 * What this adds is the part that is specific to a DDEV site: making sure the
 * containers are up so the database can be reached, and installing the package
 * again afterwards.
 */
final class ResetStage
{
    public function __construct(
        private readonly CommandRunner $runner,
        private readonly ExtensionInstallStage $extensions,
        private readonly string $resetScript = __DIR__ . '/../../scripts/reset-testsite.php',
        private readonly string $php = PHP_BINARY,
    ) {
    }

    /**
     * @return list<string>
     */
    public function plan(string $siteId, ?string $zip): array
    {
        $steps = [
            'start the DDEV project if it is stopped',
            'cwm-reset-testsite --install ' . $siteId . ': remove the project\'s extensions, tables and files, keeping what it is told to retain',
        ];

        if ($zip !== null) {
            $steps[] = 'install ' . basename($zip) . ' again';
        }

        return $steps;
    }

    /**
     * @param  callable(string): void  $log
     *
     * @throws SiteException
     */
    public function run(SiteSpec $spec, string $siteId, string $projectRoot, ?string $zip, callable $log, bool $dryRun = false): ?InstallReport
    {
        $log('Making sure the DDEV project is running');
        $start = $this->runner->run(['ddev', 'start', '--skip-confirmation'], $spec->path, true);

        if (!$start->ok()) {
            throw new SiteException('ddev start failed (exit ' . $start->exitCode . '), so the site cannot be reached.' . $this->detail($start));
        }

        $log($dryRun ? 'Previewing the reset' : 'Resetting the site');
        $command = [$this->php, $this->resetScript, '--install', $siteId];

        if ($dryRun) {
            $command[] = '--dry-run';
        }

        $reset = $this->runner->run($command, $projectRoot, true);

        if (!$reset->ok()) {
            throw new SiteException('cwm-reset-testsite failed (exit ' . $reset->exitCode . '), so the package was not reinstalled.' . $this->detail($reset));
        }

        if ($dryRun || $zip === null) {
            return null;
        }

        return $this->extensions->run($spec, $zip, $log);
    }

    private function detail(CommandResult $result): string
    {
        // The output was streamed as it ran; repeat only its tail so the cause is next to the error.
        $text = trim(implode("\n", \array_slice(explode("\n", trim($result->stderr !== '' ? $result->stderr : $result->stdout)), -8)));

        return $text !== '' ? "\n" . $text : '';
    }
}

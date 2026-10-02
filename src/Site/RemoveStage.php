<?php

declare(strict_types=1);

namespace CWM\BuildTools\Site;

use CWM\BuildTools\Dev\InstallConfig;

/**
 * Removes a site this tool created: its DDEV project, its folder, and its record.
 *
 * The order is the safest one for a failure part-way. The DDEV project goes first
 * because it can be refused (DDEV missing, a container stuck) before any file is
 * touched. The folder goes next. The record goes last, so a failed run leaves the
 * site still listed and `cwm-site-remove` can be run again.
 */
final class RemoveStage
{
    public function __construct(
        private readonly CommandRunner $runner,
        private readonly SafeRemover $remover,
        private readonly SiteRegistrar $registrar,
        private readonly DdevConfig $config = new DdevConfig(),
    ) {
    }

    /**
     * What removal would do, without doing it.
     *
     * @return array{lines: list<string>, folderExists: bool, links: int, linkTargets: list<string>}
     *
     * @throws SiteException  when the folder exists and may not be removed
     */
    public function plan(InstallConfig $install, string $projectRoot): array
    {
        $exists = is_dir($install->path);
        $lines  = [];
        $stats  = ['files' => 0, 'directories' => 0, 'links' => 0, 'linkTargets' => []];

        if ($exists) {
            $this->remover->assertRemovable($install->path, $projectRoot);
            $stats   = $this->remover->survey($install->path);
            $lines[] = 'delete the DDEV project (its containers and its database) for ' . $install->path;
            $lines[] = sprintf('delete the folder %s (%d files in %d folders)', $install->path, $stats['files'], $stats['directories']);

            if ($stats['links'] > 0) {
                $lines[] = sprintf('    %d symlink(s) in it point elsewhere, such as into your source; they are unlinked, never followed', $stats['links']);
            }
        } else {
            $lines[] = sprintf('the folder %s is already gone; delete the DDEV project "%s" by name if DDEV still lists it', $install->path, $this->config->projectName($install->id));
        }

        $lines[] = sprintf('remove "%s" from build.properties', $install->id);

        return ['lines' => $lines, 'folderExists' => $exists, 'links' => $stats['links'], 'linkTargets' => $stats['linkTargets']];
    }

    /**
     * @param  callable(string): void  $log
     *
     * @throws SiteException
     */
    public function run(InstallConfig $install, string $projectRoot, string $propertiesPath, callable $log): void
    {
        $exists = is_dir($install->path);

        // Checked again here, not only in plan(): this is the call that deletes.
        if ($exists) {
            $this->remover->assertRemovable($install->path, $projectRoot);
        }

        $log('Deleting the DDEV project');
        $this->deleteProject($install, $exists);

        if ($exists) {
            $log('Deleting ' . $install->path);
            $this->remover->remove($install->path);
        }

        $log('Removing "' . $install->id . '" from build.properties');
        $this->registrar->unregister($propertiesPath, $install->id);
    }

    private function deleteProject(InstallConfig $install, bool $folderExists): void
    {
        // From inside the project when we can: no name to get wrong. By name when the folder is gone.
        $command = ['ddev', 'delete', '--omit-snapshot', '--yes'];

        if (!$folderExists) {
            $command[] = $this->config->projectName($install->id);
        }

        $result = $this->runner->run($command, $folderExists ? $install->path : null, true);

        if ($result->ok()) {
            return;
        }

        if ($result->exitCode === 127) {
            throw new SiteException(
                "ddev could not be run, so the DDEV project was not deleted and nothing else was touched.\n"
                . 'Install ddev, or remove the containers by hand (docker) and then the folder.'
            );
        }

        // A project DDEV no longer knows is already gone, which is what was asked for.
        if (!$folderExists) {
            return;
        }

        // Streamed as it ran; repeat only the tail so the cause sits next to the error.
        $detail = trim(implode("\n", \array_slice(explode("\n", trim($result->stderr !== '' ? $result->stderr : $result->stdout)), -8)));

        throw new SiteException(
            'ddev delete failed (exit ' . $result->exitCode . '), so no files were removed and the site is still recorded.'
            . ($detail !== '' ? "\n" . $detail : '')
        );
    }
}

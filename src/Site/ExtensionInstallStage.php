<?php

declare(strict_types=1);

namespace CWM\BuildTools\Site;

/**
 * Installs a built extension, package or library zip into a site, the way an
 * administrator would from the Extension Manager, but from the command line and
 * with the installer's real messages.
 *
 * The zip is extracted into the site, a small helper is copied next to Joomla's
 * CLI, and the helper hands the extracted folder to Joomla's installer inside
 * the container. A package goes in as one unit: Joomla's package adapter
 * installs its children itself, so the project needs no knowledge of their
 * order. Both the helper and the extracted copy are removed afterwards, whether
 * or not the install worked.
 */
final class ExtensionInstallStage
{
    private const HELPER = 'cli/cwm-install-extension.php';

    public function __construct(
        private readonly DdevEnvironment $environment,
        private readonly string $helperTemplate = __DIR__ . '/../../templates/site/install-extension.php',
    ) {
    }

    /**
     * @return list<string>
     */
    public function plan(SiteSpec $spec, string $zipPath): array
    {
        return [
            'extract ' . basename($zipPath) . ' into ' . $spec->path . '/tmp',
            'install it through Joomla\'s installer inside the container, and report the installer\'s messages',
            'remove the helper and the extracted copy',
        ];
    }

    /**
     * @param  callable(string): void  $log
     *
     * @throws SiteException
     */
    public function run(SiteSpec $spec, string $zipPath, callable $log): InstallReport
    {
        if (!is_file($spec->path . '/configuration.php')) {
            throw new SiteException('Joomla is not installed in ' . $spec->path . ' (no configuration.php). Install Joomla first; do not pass --stack-only.');
        }

        if (!is_file($this->helperTemplate)) {
            throw new SiteException('The installer helper is missing from this copy of cwm-build-tools: ' . $this->helperTemplate);
        }

        $work     = 'tmp/cwm-install-' . bin2hex(random_bytes(4));
        $workPath = $spec->path . '/' . $work;
        $helper   = $spec->path . '/' . self::HELPER;

        try {
            $log('Extracting ' . basename($zipPath));
            $manifest = $this->extract($zipPath, $workPath);

            if (!is_dir(\dirname($helper))) {
                throw new SiteException($spec->path . ' has no cli/ folder, so it does not look like a Joomla site.');
            }

            if (@copy($this->helperTemplate, $helper) === false) {
                throw new SiteException('Could not write ' . $helper . '.');
            }

            $log('Waiting for the files to reach the container');
            $this->environment->waitForFile($spec, self::HELPER);
            $this->environment->waitForFile($spec, $work . '/' . $manifest);

            $log('Installing ' . basename($zipPath));
            $result = $this->environment->exec($spec, [
                'php', self::HELPER, MountPlanner::DOCROOT . '/' . $work,
            ]);

            $report = InstallReport::fromOutput($result->stdout . "\n" . $result->stderr);
        } finally {
            $this->cleanUp($spec, $work, $workPath, $helper);
        }

        if (!$report->ok) {
            throw new SiteException($this->failure($zipPath, $report));
        }

        return $report;
    }

    /**
     * Extract $zipPath into $target and return the name of its root manifest.
     *
     * Every entry is checked before anything is written: a name that is
     * absolute, climbs with `..`, or carries a drive letter would land outside
     * the target.
     *
     * @throws SiteException
     */
    private function extract(string $zipPath, string $target): string
    {
        $zip = new \ZipArchive();

        if ($zip->open($zipPath) !== true) {
            throw new SiteException('Could not open ' . $zipPath . ' as a zip file.');
        }

        $manifest = null;

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = (string) $zip->getNameIndex($i);

            if ($name === '' || $name[0] === '/' || str_contains($name, '\\') || preg_match('/^[A-Za-z]:/', $name) || \in_array('..', explode('/', $name), true)) {
                $zip->close();

                throw new SiteException(sprintf('%s contains an unsafe entry name "%s"; refusing to extract it.', basename($zipPath), $name));
            }

            if ($manifest === null && !str_contains($name, '/') && str_ends_with($name, '.xml')) {
                $manifest = $name;
            }
        }

        if ($manifest === null) {
            $zip->close();

            throw new SiteException(basename($zipPath) . ' has no manifest (*.xml) at its root, so Joomla cannot install it.');
        }

        if (!@mkdir($target, 0o755, true) && !is_dir($target)) {
            $zip->close();

            throw new SiteException('Could not create ' . $target . '.');
        }

        $extracted = $zip->extractTo($target);
        $zip->close();

        if (!$extracted) {
            throw new SiteException('Could not extract ' . basename($zipPath) . ' into ' . $target . '.');
        }

        return $manifest;
    }

    /**
     * Remove the helper and the extracted copy.
     *
     * Joomla's package installer unpacks child archives inside the folder it was
     * given, from inside the container, and DDEV syncs those new files back to
     * the host after a host-side delete has run, which brings the folder back.
     * So the container removes it first, the sync is flushed, and the host copy
     * is removed as a fallback.
     */
    private function cleanUp(SiteSpec $spec, string $work, string $workPath, string $helper): void
    {
        @unlink($helper);

        $inContainer = MountPlanner::DOCROOT . '/' . $work;

        // Only when this run created the folder, and built from a random hex suffix we
        // generated but checked anyway: this runs `rm -rf`.
        if (is_dir($workPath) && preg_match('#^' . preg_quote(MountPlanner::DOCROOT, '#') . '/tmp/cwm-install-[0-9a-f]{8}$#', $inContainer)) {
            $this->environment->exec($spec, ['rm', '-rf', $inContainer]);
            $this->environment->sync($spec);
        }

        $this->removeTree($workPath, $spec->path . '/tmp/');
    }

    private function failure(string $zipPath, InstallReport $report): string
    {
        $lines = ['Joomla did not install ' . basename($zipPath) . '.'];

        if ($report->error !== null) {
            $lines[] = '  ' . $report->error;
        }

        foreach (['error', 'warning', 'message'] as $type) {
            foreach ($report->messages[$type] ?? [] as $message) {
                $lines[] = '  ' . $type . ': ' . $message;
            }
        }

        if (\count($lines) === 1) {
            $lines[] = '  The installer returned false without a message. Check the extension\'s manifest and install script.';
        }

        return implode("\n", $lines);
    }

    /**
     * Remove $path, but only when it sits under $mustBeUnder: this deletes
     * recursively, so it refuses to act on a path it did not create.
     */
    private function removeTree(string $path, string $mustBeUnder): void
    {
        if (!str_starts_with($path, $mustBeUnder) || !is_dir($path) || is_link($path)) {
            return;
        }

        foreach (array_diff(scandir($path) ?: [], ['.', '..']) as $entry) {
            $child = $path . '/' . $entry;

            if (is_dir($child) && !is_link($child)) {
                $this->removeTree($child, $mustBeUnder);
            } else {
                @unlink($child);
            }
        }

        @rmdir($path);
    }
}

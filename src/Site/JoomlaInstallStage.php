<?php

declare(strict_types=1);

namespace CWM\BuildTools\Site;

/**
 * Puts a working Joomla into a running site: fetches the release, runs
 * Joomla's own headless installer inside the container, and checks that the
 * result boots.
 *
 * Replaces walking the web installer by hand. Everything that talks to the
 * database runs in the container, because the database host there (`db`) does
 * not resolve on the host.
 */
final class JoomlaInstallStage
{
    public function __construct(
        private readonly DdevEnvironment $environment,
        private readonly JoomlaSource $source,
    ) {
    }

    /**
     * @return list<string>
     */
    public function plan(SiteSpec $spec, JoomlaSettings $settings): array
    {
        return [
            'download Joomla ' . ($settings->version ?? '(latest stable)') . ' into ' . $spec->path,
            'wait until the files are visible inside the container',
            'run Joomla\'s headless installer (table prefix ' . $settings->dbPrefix . ', admin "' . $settings->adminUsername . '")',
            'check that Joomla boots against the new database',
        ];
    }

    /**
     * @param  callable(string): void  $log
     *
     * @return string  The Joomla version that was installed.
     *
     * @throws SiteException
     */
    public function run(SiteSpec $spec, JoomlaSettings $settings, callable $log): string
    {
        if (is_file($spec->path . '/configuration.php')) {
            throw new SiteException('Joomla is already installed in ' . $spec->path . ' (configuration.php exists).');
        }

        $version = $settings->version ?? $this->source->latest();

        if (!preg_match('/^\d+\.\d+\.\d+(-[A-Za-z0-9.]+)?$/', $version)) {
            throw new SiteException(sprintf('"%s" is not a Joomla version such as 6.1.4.', $version));
        }

        $log('Downloading Joomla ' . $version);

        try {
            $this->source->fetch($version, $spec->path, ['.ddev']);
        } catch (\RuntimeException $e) {
            throw new SiteException('Could not put Joomla ' . $version . ' into ' . $spec->path . ': ' . $e->getMessage());
        }

        $partial = ' The folder now holds a partial install; remove it (and the DDEV project) before trying again.';

        $log('Waiting for the files to reach the container');
        $this->environment->waitForFile($spec, 'installation/joomla.php');

        $log('Running the Joomla installer');
        $db     = $this->environment->databaseSettings();
        $result = $this->environment->exec($spec, [
            'php', 'installation/joomla.php', 'install',
            '--site-name=' . $settings->siteName,
            '--admin-user=Administrator',
            '--admin-username=' . $settings->adminUsername,
            '--admin-password=' . $settings->adminPassword,
            '--admin-email=' . $settings->adminEmail,
            '--db-type=mysqli',
            '--db-host=' . $db['host'],
            '--db-name=' . $db['name'],
            '--db-user=' . $db['user'],
            '--db-pass=' . $db['password'],
            '--db-prefix=' . $settings->dbPrefix,
            '--db-encryption=0',
            '--no-interaction',
        ]);

        if (!$result->ok()) {
            throw new SiteException($this->failure('The Joomla installer failed', $result, $settings) . $partial);
        }

        $this->environment->waitForFile($spec, 'configuration.php');

        $log('Checking that Joomla boots');
        $boot = $this->environment->exec($spec, ['php', 'cli/joomla.php', 'extension:list']);

        if (!$boot->ok()) {
            throw new SiteException($this->failure('Joomla installed but does not boot', $boot, $settings) . $partial);
        }

        return $version;
    }

    /**
     * The installer's own words, with the admin password removed: it was passed
     * on the command line, so a tool that echoes arguments back would print it.
     */
    private function failure(string $summary, CommandResult $result, JoomlaSettings $settings): string
    {
        $detail = trim($result->stdout . "\n" . $result->stderr);
        $detail = str_replace($settings->adminPassword, '********', $detail);

        return $summary . ' (exit ' . $result->exitCode . ')' . ($detail !== '' ? ":\n" . $detail : '.');
    }
}

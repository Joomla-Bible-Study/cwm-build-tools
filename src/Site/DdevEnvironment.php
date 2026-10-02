<?php

declare(strict_types=1);

namespace CWM\BuildTools\Site;

/**
 * Provisions the web, PHP and database stack for a site with DDEV.
 *
 * Stops after `ddev start`: what goes inside the running site (Joomla, the
 * project's extensions) is the next stage's job and does not depend on how the
 * stack was made.
 */
final class DdevEnvironment
{
    public function __construct(
        private readonly CommandRunner $runner,
        private readonly DdevConfig $config,
        private readonly MountPlanner $planner,
        private readonly string $globalConfigPath,
        private readonly ?\Closure $sleep = null,
    ) {
    }

    /**
     * What provisioning would do, in order, without doing any of it.
     *
     * @return list<string>
     */
    public function plan(SiteSpec $spec): array
    {
        $name   = $this->config->projectName($spec->id);
        $steps  = [
            'check that ddev and Docker are available',
            'check that DDEV\'s usage-statistics question has been answered',
            'create ' . $spec->path,
            'ddev config ' . implode(' ', \array_slice($this->config->configArgs($name, $spec->phpVersion, $spec->dbHostPort), 1)),
            'write .ddev/' . DdevConfig::COMPOSE_OVERRIDE . ' with these mounts:',
        ];

        foreach ($this->planner->plan($spec->path, $spec->sourceRoots) as $mount) {
            $steps[] = '    ' . $mount->host . ' -> ' . $mount->container;
        }

        $steps[] = 'ddev start';

        return $steps;
    }

    /**
     * @param  callable(string): void  $log  Receives one progress line per stage.
     *
     * @throws SiteException  with a message that says what to do next
     */
    public function provision(SiteSpec $spec, callable $log): void
    {
        $name   = $this->config->projectName($spec->id);
        $mounts = $this->planner->plan($spec->path, $spec->sourceRoots);

        $this->preflight();
        $this->checkTarget($spec);

        if (!is_dir($spec->path) && !@mkdir($spec->path, 0o755, true) && !is_dir($spec->path)) {
            throw new SiteException('Could not create ' . $spec->path . '.');
        }

        $log('Configuring DDEV project "' . $name . '"');
        $this->must(
            array_merge(['ddev'], $this->config->configArgs($name, $spec->phpVersion, $spec->dbHostPort)),
            $spec->path,
            'ddev config failed'
        );

        if (!is_dir($spec->path . '/.ddev')) {
            throw new SiteException('ddev config finished but did not create ' . $spec->path . '/.ddev, so the mounts cannot be added.');
        }

        $override = $spec->path . '/.ddev/' . DdevConfig::COMPOSE_OVERRIDE;

        if (@file_put_contents($override, $this->config->composeOverride($mounts)) === false) {
            throw new SiteException('Could not write ' . $override . '.');
        }

        $log('Starting the project (the first start pulls images and can take several minutes)');
        $this->must(['ddev', 'start', '--skip-confirmation'], $spec->path, 'ddev start failed', true);
    }

    /**
     * What DDEV's database container is created with, so the Joomla installer
     * can be pointed at it.
     *
     * @return array{host: string, name: string, user: string, password: string}
     */
    public function databaseSettings(): array
    {
        return ['host' => 'db', 'name' => 'db', 'user' => 'db', 'password' => 'db'];
    }

    /**
     * The URL DDEV serves the site on.
     */
    public function url(SiteSpec $spec): string
    {
        return 'https://' . $this->config->projectName($spec->id) . '.ddev.site';
    }

    /**
     * Run a command inside the site's web container.
     *
     * Arguments are shell-escaped into one string because `ddev exec` hands its
     * arguments to a shell, so a value with a space would otherwise split.
     *
     * @param  list<string>  $argv
     */
    public function exec(SiteSpec $spec, array $argv, bool $stream = false): CommandResult
    {
        return $this->runner->run(
            ['ddev', 'exec', implode(' ', array_map('escapeshellarg', $argv))],
            $spec->path,
            $stream
        );
    }

    /**
     * Flush pending file changes between the host and the container.
     *
     * Harmless where DDEV is not syncing files. Without it, a delete made on one
     * side can be undone by files the other side created in the meantime.
     */
    public function sync(SiteSpec $spec): void
    {
        $this->runner->run(['ddev', 'mutagen', 'sync'], $spec->path);
    }

    /**
     * Wait until a file written on the host is visible inside the container.
     *
     * DDEV syncs files into the container asynchronously on macOS and Windows,
     * so a command run straight after a host-side write can fail with "file not
     * found". A sync is requested first (harmless where it is not needed), then
     * the file is polled for.
     *
     * @throws SiteException  when the file has not appeared after $seconds
     */
    public function waitForFile(SiteSpec $spec, string $relativePath, int $seconds = 30): void
    {
        $this->sync($spec);

        $sleep = $this->sleep ?? static function (int $s): void {
            sleep($s);
        };

        for ($waited = 0; $waited <= $seconds; $waited++) {
            if ($this->exec($spec, ['test', '-f', $relativePath])->ok()) {
                return;
            }

            if ($waited < $seconds) {
                $sleep(1);
            }
        }

        throw new SiteException(sprintf('%s did not appear inside the container after %d seconds. Run `ddev mutagen sync` in %s and try again.', $relativePath, $seconds, $spec->path));
    }

    private function preflight(): void
    {
        if (!$this->runner->run(['ddev', '--version'])->ok()) {
            throw new SiteException(
                "ddev is not installed or not on PATH.\n"
                . "  macOS:  brew install ddev/ddev/ddev\n"
                . '  other:  https://docs.ddev.com/en/stable/users/install/ddev-installation/'
            );
        }

        if (!$this->runner->run(['docker', 'info', '--format', '{{.ServerVersion}}'])->ok()) {
            throw new SiteException(
                "Docker is not running, or the docker command is not available.\n"
                . '  Start Docker Desktop, OrbStack or Colima, then run this again.'
            );
        }

        $yaml = is_file($this->globalConfigPath) ? (string) file_get_contents($this->globalConfigPath) : '';

        if (!$this->config->telemetryDecided($yaml)) {
            throw new SiteException(
                "DDEV has not been told whether it may send anonymous usage statistics.\n"
                . "That is your choice, so this command will not make it for you. Run one of:\n"
                . "  ddev config global --instrumentation-opt-in=false\n"
                . "  ddev config global --instrumentation-opt-in=true\n"
                . 'then run this again.'
            );
        }
    }

    private function checkTarget(SiteSpec $spec): void
    {
        if (!is_dir($spec->path)) {
            return;
        }

        $entries = array_values(array_diff(scandir($spec->path) ?: [], ['.', '..']));

        if ($entries === [] || $spec->force) {
            return;
        }

        $hint = \in_array('.ddev', $entries, true) ? 'is already a DDEV project' : 'is not empty';

        throw new SiteException($spec->path . ' ' . $hint . '. Pick another --path, or pass --force to reconfigure it in place.');
    }

    /**
     * @param  list<string>  $command
     */
    private function must(array $command, string $cwd, string $failure, bool $stream = false): void
    {
        $result = $this->runner->run($command, $cwd, $stream);

        if ($result->ok()) {
            return;
        }

        $detail = trim($result->stderr !== '' ? $result->stderr : $result->stdout);

        throw new SiteException($failure . ' (exit ' . $result->exitCode . ')' . ($detail !== '' ? ":\n" . $detail : '.'));
    }
}

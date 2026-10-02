<?php

declare(strict_types=1);

namespace CWM\BuildTools\Site;

/**
 * Renders the pieces of a DDEV project that `cwm-site-create` owns: the
 * `ddev config` arguments, the compose override carrying the mounts, and the
 * check that DDEV's one-time usage-statistics question has been answered.
 *
 * Pure string work, so it is testable without Docker. Running `ddev` is the
 * caller's job.
 */
final class DdevConfig
{
    /** Not `docker-compose.*.yaml` under a `#ddev-generated` header: DDEV rewrites those. */
    public const COMPOSE_OVERRIDE = 'docker-compose.cwm.yaml';

    /**
     * A DDEV project name is a hostname label: lowercase letters, digits, hyphens.
     */
    public function projectName(string $siteId): string
    {
        $name = strtolower((string) preg_replace('/[^a-zA-Z0-9]+/', '-', $siteId));
        $name = trim($name, '-');

        if ($name === '') {
            throw new \InvalidArgumentException(\sprintf('Site id "%s" has no characters usable in a DDEV project name.', $siteId));
        }

        return $name;
    }

    /**
     * Arguments for `ddev config`.
     *
     * The database port is pinned because it is random by default, and host-side
     * scripts read it from build.properties.
     *
     * @return list<string>
     */
    public function configArgs(string $projectName, string $phpVersion, int $dbHostPort, string $database = 'mariadb:11.4'): array
    {
        if (!preg_match('/^\d+\.\d+$/', $phpVersion)) {
            throw new \InvalidArgumentException(\sprintf('PHP version "%s" must look like 8.3.', $phpVersion));
        }

        if ($dbHostPort < 1024 || $dbHostPort > 65535) {
            throw new \InvalidArgumentException(\sprintf('Database port %d is outside 1024-65535.', $dbHostPort));
        }

        return [
            'config',
            '--project-type=joomla',
            '--php-version=' . $phpVersion,
            '--database=' . $database,
            '--docroot=.',
            '--project-name=' . $projectName,
            '--host-db-port=' . $dbHostPort,
        ];
    }

    /**
     * The compose override that adds the mounts to the web container.
     *
     * @param  list<Mount>  $mounts
     */
    public function composeOverride(array $mounts): string
    {
        $lines = [
            '# Written by cwm-site-create. Safe to edit; it is not regenerated unless you re-run with --force.',
            // DDEV prints a notice about custom compose files on every start; this one is deliberate.
            '#ddev-silent-no-warn',
            '#',
        ];

        foreach ($mounts as $mount) {
            $lines[] = '# ' . $mount->container . ': ' . $mount->reason;
        }

        $lines[] = 'services:';
        $lines[] = '  web:';
        $lines[] = '    volumes:';

        foreach ($mounts as $mount) {
            $lines[] = '      - ' . $this->quote($mount->host . ':' . $mount->container . ':cached');
        }

        return implode("\n", $lines) . "\n";
    }

    /**
     * Whether DDEV's usage-statistics question has an answer on record.
     *
     * Until it does, the first `ddev start` stops to ask. With its output
     * piped that is a silent hang, and a non-interactive run answers it "yes"
     * for the user. Neither is a decision to make for someone, so the caller
     * should stop and say which command to run.
     *
     * @param  string  $globalConfigYaml  Contents of ~/.ddev/global_config.yaml, or '' when absent.
     */
    public function telemetryDecided(string $globalConfigYaml): bool
    {
        return (bool) preg_match('/^instrumentation_opt_in:\s*(true|false)\b/m', $globalConfigYaml);
    }

    private function quote(string $value): string
    {
        return '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $value) . '"';
    }
}

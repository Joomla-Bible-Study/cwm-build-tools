<?php

declare(strict_types=1);

namespace CWM\BuildTools\Seed;

use CWM\BuildTools\Dev\InstallConfig;
use CWM\BuildTools\Site\CommandRunner;

/**
 * Runs a project's seed layers against one site.
 *
 * Each layer is a PHP script, run as `php <script> apply|remove` from the
 * project root, and told which site it is working on through environment
 * variables (see {@see environment()}). Applying stops at the first failure,
 * because later layers may build on the failed one. Removing carries on past a
 * failure and reports them all: cleanup that stops half-way leaves more behind
 * than cleanup that tries everything.
 */
final class SeedRunner
{
    public const APPLY  = 'apply';
    public const REMOVE = 'remove';

    public function __construct(
        private readonly CommandRunner $runner,
        private readonly string $php = PHP_BINARY,
    ) {
    }

    /**
     * The variables a layer script receives.
     *
     * `CWM_SEED_DB_HOST` is set only when build.properties records an address for
     * the install; a layer reads the site's credentials from its
     * `configuration.php` as usual (see Dev\TestSite::fromSeedEnvironment()).
     *
     * @return array<string, string>
     */
    public function environment(SeedConfig $config, SeedLayer $layer, InstallConfig $install, string $action): array
    {
        $env = [
            'CWM_SEED_ACTION'    => $action,
            'CWM_SEED_LAYER'     => $layer->name,
            'CWM_SEED_MARKER'    => $config->marker,
            'CWM_SEED_SITE_ID'   => $install->id,
            'CWM_SEED_SITE_PATH' => $install->path,
            'CWM_SEED_SITE_ROLE' => $install->role,
        ];

        $host = (string) ($install->db['host'] ?? '');

        if ($host !== '') {
            $env['CWM_SEED_DB_HOST'] = $host;
        }

        return $env;
    }

    /**
     * @param  list<SeedLayer>         $layers  In the order they should be applied.
     * @param  callable(string): void  $log
     *
     * @return list<string>  Names of the layers that ran successfully, in the order they ran.
     *
     * @throws SeedException  on a failed layer, naming it and what had already been done
     */
    public function run(SeedConfig $config, array $layers, string $action, InstallConfig $install, string $projectRoot, callable $log): array
    {
        if (!\in_array($action, [self::APPLY, self::REMOVE], true)) {
            throw new \InvalidArgumentException(sprintf('Action "%s" must be apply or remove.', $action));
        }

        $ordered = $action === self::REMOVE ? array_reverse($layers) : $layers;
        $done    = [];
        $failed  = [];

        foreach ($ordered as $layer) {
            $log(sprintf('%s layer "%s"', $action === self::APPLY ? 'Applying' : 'Removing', $layer->name));

            $result = $this->runner->run(
                [$this->php, $projectRoot . '/' . $layer->script, $action],
                $projectRoot,
                true,
                $this->environment($config, $layer, $install, $action)
            );

            if ($result->ok()) {
                $done[] = $layer->name;

                continue;
            }

            $failed[] = sprintf('%s (exit %d)', $layer->name, $result->exitCode);

            if ($action === self::APPLY) {
                break;
            }
        }

        if ($failed !== []) {
            throw new SeedException(
                ($action === self::APPLY
                    ? sprintf('Layer %s failed.', $failed[0])
                    : sprintf('Could not remove %d layer(s): %s.', \count($failed), implode(', ', $failed)))
                . ($done !== [] ? sprintf(' Already %s: %s.', $action === self::APPLY ? 'applied' : 'removed', implode(', ', $done)) : '')
                . ($action === self::APPLY ? ' Run with --remove to clear what was written.' : '')
            );
        }

        return $done;
    }
}

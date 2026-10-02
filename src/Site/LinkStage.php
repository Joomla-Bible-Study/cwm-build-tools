<?php

declare(strict_types=1);

namespace CWM\BuildTools\Site;

/**
 * Replaces a site's installed copy of the project with links to its source, by
 * running `cwm-link` for that one site, then checks the links work from inside
 * the container.
 *
 * `cwm-link` is run rather than reimplemented, so the links are exactly the ones
 * `composer link` makes: derived from the manifests, relative, and for dependency
 * packages as well as the project itself.
 */
final class LinkStage
{
    public function __construct(
        private readonly CommandRunner $runner,
        private readonly DdevEnvironment $environment,
        private readonly string $linkScript = __DIR__ . '/../../scripts/link.php',
        private readonly string $php = PHP_BINARY,
    ) {
    }

    /**
     * @return list<string>
     */
    public function plan(string $siteId): array
    {
        return [
            'link the project\'s source into the site with cwm-link --install ' . $siteId . ', replacing the installed copy',
            'check from inside the container that every link resolves',
        ];
    }

    /**
     * @param  callable(string): void  $log
     *
     * @throws SiteException
     */
    public function run(SiteSpec $spec, string $siteId, string $projectRoot, callable $log): void
    {
        $log('Linking the source into the site');

        $result = $this->runner->run([$this->php, $this->linkScript, '--install', $siteId], $projectRoot);

        if (!$result->ok()) {
            $detail = trim($result->stdout . "\n" . $result->stderr);

            throw new SiteException('cwm-link failed (exit ' . $result->exitCode . ')' . ($detail !== '' ? ":\n" . $detail : '.'));
        }

        $log('Checking that the links resolve inside the container');
        $this->environment->sync($spec);

        $broken = $this->environment->exec($spec, [
            'find', '.', '-xtype', 'l',
            '-not', '-path', './tmp/*',
            '-not', '-path', './cache/*',
            '-not', '-path', './administrator/cache/*',
            '-not', '-path', './.ddev/*',
        ]);

        $links = array_values(array_filter(array_map('trim', explode("\n", $broken->stdout))));

        if ($links !== []) {
            $shown = implode("\n", array_map(static fn (string $l): string => '  ' . $l, \array_slice($links, 0, 10)));
            $more  = \count($links) > 10 ? "\n  ... and " . (\count($links) - 10) . ' more' : '';

            throw new SiteException(
                "These links do not resolve inside the container:\n" . $shown . $more . "\n"
                . 'The source tree is probably not mounted where the links climb to. Check ' . $spec->path
                . '/.ddev/' . DdevConfig::COMPOSE_OVERRIDE . ' against `ddev describe`, then `ddev restart`.'
            );
        }
    }
}

<?php

declare(strict_types=1);

namespace CWM\BuildTools\Seed;

/**
 * The `seed` block of cwm-build.config.json: which layers a project has, how
 * they group into profiles, and the marker its rows carry.
 *
 * ```json
 * "seed": {
 *   "marker": "cwmseed-",
 *   "defaultProfile": "test",
 *   "layers": [
 *     { "name": "content",   "script": "build/seed/content.php",   "description": "..." },
 *     { "name": "scenarios", "script": "build/seed/scenarios.php" }
 *   ],
 *   "profiles": { "test": ["content", "scenarios"], "bulk": ["content", "scenarios", "volume"] }
 * }
 * ```
 *
 * The tool owns none of the data. A layer is a script the project writes; this
 * class only checks that the description of them is coherent before anything
 * runs, so a typo fails at once and not half-way through a seed.
 */
final class SeedConfig
{
    public const DEFAULT_MARKER = 'cwmseed-';

    /**
     * @param  array<string, SeedLayer>   $layers    By name, in the order declared.
     * @param  array<string, list<string>> $profiles  Profile name => layer names.
     */
    private function __construct(
        public readonly string $marker,
        public readonly array $layers,
        public readonly array $profiles,
        public readonly ?string $defaultProfile,
    ) {
    }

    /**
     * @param  array<string, mixed>  $projectConfig  Decoded cwm-build.config.json.
     *
     * @throws SeedException
     */
    public static function fromProjectConfig(array $projectConfig, string $projectRoot): self
    {
        $seed = $projectConfig['seed'] ?? null;

        if (!\is_array($seed)) {
            throw new SeedException(
                "cwm-build.config.json has no \"seed\" block, so there is nothing to seed.\n"
                . 'Declare the project\'s layers and profiles there; see the README ("Seeding").'
            );
        }

        $marker = (string) ($seed['marker'] ?? self::DEFAULT_MARKER);

        if (!preg_match('/^[A-Za-z0-9_-]{2,32}$/', $marker)) {
            throw new SeedException(sprintf('seed.marker "%s" must be 2 to 32 letters, digits, hyphens or underscores.', $marker));
        }

        $layers = [];

        foreach ((array) ($seed['layers'] ?? []) as $i => $raw) {
            $name   = (string) ($raw['name'] ?? '');
            $script = (string) ($raw['script'] ?? '');

            if (!preg_match('/^[a-z][a-z0-9_-]*$/', $name)) {
                throw new SeedException(sprintf('seed.layers[%s].name "%s" must start with a lowercase letter and use only lowercase letters, digits, hyphens or underscores.', $i, $name));
            }

            if (isset($layers[$name])) {
                throw new SeedException(sprintf('seed layer "%s" is declared twice.', $name));
            }

            self::assertScriptInsideProject($name, $script, $projectRoot);

            $layers[$name] = new SeedLayer($name, $script, (string) ($raw['description'] ?? ''));
        }

        if ($layers === []) {
            throw new SeedException('seed.layers is empty: declare at least one layer.');
        }

        $profiles = [];

        foreach ((array) ($seed['profiles'] ?? []) as $profile => $names) {
            $profile = (string) $profile;

            if (!preg_match('/^[a-z][a-z0-9_-]*$/', $profile)) {
                throw new SeedException(sprintf('seed profile name "%s" must start with a lowercase letter and use only lowercase letters, digits, hyphens or underscores.', $profile));
            }

            foreach ((array) $names as $layerName) {
                if (!isset($layers[(string) $layerName])) {
                    throw new SeedException(sprintf('seed profile "%s" names layer "%s", which is not declared. Declared: %s.', $profile, (string) $layerName, implode(', ', array_keys($layers))));
                }
            }

            $profiles[$profile] = array_values(array_map('strval', (array) $names));
        }

        $default = isset($seed['defaultProfile']) ? (string) $seed['defaultProfile'] : null;

        if ($default !== null && !isset($profiles[$default])) {
            throw new SeedException(sprintf('seed.defaultProfile "%s" is not a declared profile. Declared: %s.', $default, $profiles === [] ? '(none)' : implode(', ', array_keys($profiles))));
        }

        return new self($marker, $layers, $profiles, $default);
    }

    /**
     * The layers to run, in the order the project declared them (so a layer that
     * depends on an earlier one keeps working however the request was worded).
     *
     * @param  string|null   $profile  A declared profile, or null.
     * @param  list<string>  $names    Layer names, or empty.
     *
     * @return list<SeedLayer>
     *
     * @throws SeedException  when the request is ambiguous or names something undeclared
     */
    public function select(?string $profile, array $names): array
    {
        if ($profile !== null && $names !== []) {
            throw new SeedException('Name a profile or layers with --layer, not both.');
        }

        if ($profile === null && $names === []) {
            $profile = $this->defaultProfile;

            if ($profile === null) {
                throw new SeedException(sprintf(
                    'Name a profile or layers with --layer; this project declares no default. Profiles: %s. Layers: %s.',
                    $this->profiles === [] ? '(none)' : implode(', ', array_keys($this->profiles)),
                    implode(', ', array_keys($this->layers))
                ));
            }
        }

        if ($profile !== null) {
            if (!isset($this->profiles[$profile])) {
                throw new SeedException(sprintf('No profile "%s". Profiles: %s.', $profile, $this->profiles === [] ? '(none)' : implode(', ', array_keys($this->profiles))));
            }

            $names = $this->profiles[$profile];
        }

        foreach ($names as $name) {
            if (!isset($this->layers[$name])) {
                throw new SeedException(sprintf('No layer "%s". Layers: %s.', $name, implode(', ', array_keys($this->layers))));
            }
        }

        return array_values(array_filter($this->layers, static fn (SeedLayer $l): bool => \in_array($l->name, $names, true)));
    }

    private static function assertScriptInsideProject(string $layer, string $script, string $projectRoot): void
    {
        if ($script === '' || $script[0] === '/' || preg_match('/^[A-Za-z]:/', $script) || \in_array('..', explode('/', $script), true)) {
            throw new SeedException(sprintf('seed layer "%s": script "%s" must be a path inside the project (no leading slash, no "..").', $layer, $script));
        }

        if (!str_ends_with($script, '.php')) {
            throw new SeedException(sprintf('seed layer "%s": script "%s" must be a .php file.', $layer, $script));
        }

        if (!is_file($projectRoot . '/' . $script)) {
            throw new SeedException(sprintf('seed layer "%s": %s does not exist.', $layer, $script));
        }
    }
}

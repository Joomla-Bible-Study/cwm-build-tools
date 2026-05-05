<?php

declare(strict_types=1);

namespace CWM\BuildTools\Config;

/**
 * Loads and validates a project's cwm-build.config.json.
 *
 * Phase 1 stub — the bash and php scripts in scripts/ currently parse the
 * file directly via json_decode. This class will become the canonical
 * accessor in Phase 2 once we have PHP entry points (rather than bash
 * wrappers) for the CLI commands.
 */
final class ProjectConfig
{
    public function __construct(private readonly array $data)
    {
    }

    public static function loadFromCwd(): self
    {
        $path = getcwd() . '/cwm-build.config.json';

        if (!is_file($path)) {
            throw new \RuntimeException("cwm-build.config.json not found in " . getcwd());
        }

        $data = json_decode((string) file_get_contents($path), true);

        if (!is_array($data)) {
            throw new \RuntimeException("cwm-build.config.json is not valid JSON");
        }

        return new self($data);
    }

    public function get(string $dottedKey, mixed $default = null): mixed
    {
        $keys  = explode('.', $dottedKey);
        $value = $this->data;

        foreach ($keys as $key) {
            if (!is_array($value) || !array_key_exists($key, $value)) {
                return $default;
            }

            $value = $value[$key];
        }

        return $value;
    }

    public function all(): array
    {
        return $this->data;
    }
}

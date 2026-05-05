<?php

declare(strict_types=1);

namespace CWM\BuildTools\Build;

/**
 * Reads version, creationDate, and other top-level fields from a Joomla
 * extension manifest XML file.
 *
 * Phase 1 stub. Will be the canonical XML reader once Bumper and
 * PackageBuilder are ported from bash/php scripts to PHP classes.
 */
final class ManifestReader
{
    public function __construct(private readonly string $path)
    {
        if (!is_file($this->path)) {
            throw new \RuntimeException("Manifest not found: $this->path");
        }
    }

    public function version(): string
    {
        return $this->readField('version');
    }

    public function creationDate(): ?string
    {
        return $this->readField('creationDate', null);
    }

    public function name(): ?string
    {
        return $this->readField('name', null);
    }

    public function path(): string
    {
        return $this->path;
    }

    private function readField(string $field, ?string $default = null): ?string
    {
        $xml = simplexml_load_file($this->path);

        if (!$xml) {
            throw new \RuntimeException("Could not parse $this->path");
        }

        $value = (string) ($xml->{$field} ?? '');

        return $value !== '' ? $value : $default;
    }
}

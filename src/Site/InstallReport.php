<?php

declare(strict_types=1);

namespace CWM\BuildTools\Site;

/**
 * What Joomla's installer reported for one extension install.
 */
final class InstallReport
{
    /**
     * @param  array<string, list<string>>  $messages  Installer messages, grouped by type
     *                                                  ("message", "warning", "error").
     */
    public function __construct(
        public readonly bool $ok,
        public readonly ?string $name,
        public readonly ?string $type,
        public readonly ?string $version,
        public readonly array $messages,
        public readonly ?string $error,
    ) {
    }

    /**
     * Messages that mean something did not go as intended, though the install
     * itself may have succeeded: an extension's own install script reports a
     * step it could not finish this way.
     *
     * @return list<string>
     */
    public function warnings(): array
    {
        return array_values(array_merge($this->messages['warning'] ?? [], $this->messages['error'] ?? []));
    }

    /**
     * Parse the helper's output. The result is the last `CWM_RESULT:` line;
     * anything before it (PHP notices, deprecations) is ignored.
     *
     * @throws SiteException  when no result line is present, with the output's tail
     */
    public static function fromOutput(string $output): self
    {
        $json = null;

        foreach (array_reverse(explode("\n", $output)) as $line) {
            if (str_starts_with($line, 'CWM_RESULT:')) {
                $json = substr($line, strlen('CWM_RESULT:'));

                break;
            }
        }

        $data = $json !== null ? json_decode($json, true) : null;

        if (!is_array($data)) {
            $tail = trim(implode("\n", \array_slice(explode("\n", trim($output)), -15)));

            throw new SiteException(
                "The installer helper produced no result, so Joomla probably stopped with a fatal error.\n"
                . ($tail !== '' ? "Last output:\n" . $tail : 'It printed nothing.')
            );
        }

        $messages = [];

        foreach ((array) ($data['messages'] ?? []) as $type => $list) {
            $messages[(string) $type] = array_values(array_map('strval', (array) $list));
        }

        return new self(
            (bool) ($data['ok'] ?? false),
            isset($data['name']) ? (string) $data['name'] : null,
            isset($data['type']) ? (string) $data['type'] : null,
            isset($data['version']) ? (string) $data['version'] : null,
            $messages,
            isset($data['error']) ? (string) $data['error'] : null
        );
    }
}

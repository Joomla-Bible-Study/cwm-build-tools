<?php

declare(strict_types=1);

namespace CWM\BuildTools\Http;

/**
 * An HTTP status line and body.
 *
 * Deliberately does not throw on an error status. The ARS publish flow has
 * to tell three cases apart — success, "no such record", and "your token
 * cannot read this" — and collapsing the last two into an exception at the
 * transport layer is how a permission failure gets mistaken for an empty
 * result set. See {@see \CWM\BuildTools\Release\ArsPublisher}.
 */
final class HttpResponse
{
    public function __construct(
        public readonly int $status,
        public readonly string $body,
    ) {
    }

    public function isSuccess(): bool
    {
        return $this->status >= 200 && $this->status < 300;
    }

    /**
     * Decode the body as a JSON object.
     *
     * @return array<string, mixed>|null Null when the body is not a JSON object,
     *                                   which callers must treat as "cannot tell"
     *                                   rather than "empty".
     */
    public function json(): ?array
    {
        $decoded = json_decode($this->body, true);

        return \is_array($decoded) ? $decoded : null;
    }
}

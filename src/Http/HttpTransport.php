<?php

declare(strict_types=1);

namespace CWM\BuildTools\Http;

/**
 * Minimal HTTP client seam.
 *
 * Exists so {@see \CWM\BuildTools\Release\ArsPublisher} can be tested. The
 * publisher's job is deciding whether to create or update a record, and
 * getting that wrong ships a duplicate release to a live download page —
 * a decision that has to be exercised against canned responses rather than
 * discovered during a release.
 */
interface HttpTransport
{
    /**
     * Perform a request and return the response, whatever its status.
     *
     * Implementations must NOT throw on 4xx/5xx — the status is part of the
     * result the caller reasons about. Throwing is reserved for a request
     * that never produced a response at all (DNS, TLS, connection refused).
     *
     * @param  string                $method  Uppercase HTTP verb.
     * @param  array<string, string> $headers Header name => value.
     * @param  string|null           $body    Raw request body, already encoded.
     *
     * @throws \RuntimeException              When no response could be obtained.
     */
    public function request(string $method, string $url, array $headers = [], ?string $body = null): HttpResponse;
}

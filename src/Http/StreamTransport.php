<?php

declare(strict_types=1);

namespace CWM\BuildTools\Http;

/**
 * {@see HttpTransport} on PHP's HTTP stream wrapper.
 *
 * Streams rather than ext-curl because the rest of this package already
 * fetches over streams (see Dev\JoomlaInstaller) and adding an extension
 * requirement to a dev-dependency is a support burden out of proportion to
 * the handful of JSON requests a publish makes.
 *
 * `ignore_errors` is the load-bearing option: without it PHP returns false
 * for any 4xx/5xx and the response body — which is where ARS explains what
 * it objected to — is discarded.
 */
final class StreamTransport implements HttpTransport
{
    public function __construct(
        private readonly int $timeoutSeconds = 30,
    ) {
    }

    public function request(string $method, string $url, array $headers = [], ?string $body = null): HttpResponse
    {
        $headerLines = [];

        foreach ($headers as $name => $value) {
            $headerLines[] = $name . ': ' . $value;
        }

        $options = [
            'http' => [
                'method'  => $method,
                'header'  => implode("\r\n", $headerLines),
                'timeout' => $this->timeoutSeconds,
                // Return the body on 4xx/5xx instead of false.
                'ignore_errors' => true,
                // ARS sits behind the site's normal Joomla routing; a 301 to
                // the canonical host is ordinary and should be followed, but
                // an open-ended redirect chain should not be.
                'max_redirects' => 5,
            ],
        ];

        if ($body !== null) {
            $options['http']['content'] = $body;
        }

        $context = stream_context_create($options);

        $responseBody = @file_get_contents($url, false, $context);

        /**
         * The stream wrapper has no return value for the status, so it has to
         * be read back out of band. How, is version-dependent:
         *
         * - PHP 8.5 added http_get_last_response_headers() and deprecated the
         *   older mechanism in the same release (#80).
         * - Before that, the wrapper injected $http_response_header into the
         *   local scope of whichever function called it. That is why this is
         *   read here rather than in a helper — a helper has its own scope and
         *   would never see it.
         *
         * The ternary short-circuits on 8.5, so the deprecated variable is
         * never referenced there.
         *
         * Both report per-attempt state: a request that never reached the
         * server leaves null/unset even if an earlier one on the same process
         * succeeded, so this doubles as the "did we get a response at all" test.
         *
         * @var list<string>|null $responseHeaders
         */
        $responseHeaders = \function_exists('http_get_last_response_headers')
            ? http_get_last_response_headers()
            : ($http_response_header ?? null);

        if ($responseHeaders === null) {
            throw new \RuntimeException("No HTTP response from {$url} (connection, DNS or TLS failure).");
        }

        return new HttpResponse(
            self::statusFrom($responseHeaders),
            $responseBody === false ? '' : $responseBody
        );
    }

    /**
     * Pull the status code out of the raw header list.
     *
     * The list accumulates a status line per hop when redirects are
     * followed, so the LAST one is the response actually being returned.
     *
     * @param list<string> $headerLines
     */
    private static function statusFrom(array $headerLines): int
    {
        $status = 0;

        foreach ($headerLines as $line) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m) === 1) {
                $status = (int) $m[1];
            }
        }

        return $status;
    }
}

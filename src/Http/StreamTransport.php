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

        // $http_response_header is populated by the stream wrapper in the
        // local scope of the call. It is the only way to see the status.
        $responseBody = @file_get_contents($url, false, $context);

        if (!isset($http_response_header)) {
            throw new \RuntimeException("No HTTP response from {$url} (connection, DNS or TLS failure).");
        }

        return new HttpResponse(
            self::statusFrom($http_response_header),
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

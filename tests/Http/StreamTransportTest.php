<?php

declare(strict_types=1);

namespace CWM\BuildTools\Tests\Http;

use CWM\BuildTools\Http\StreamTransport;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The one place the toolkit actually talks HTTP, exercised for real.
 *
 * Everything above this is tested through FakeTransport, which is the right
 * seam — publishing decisions should be checkable without the network. The
 * cost is that this class was covered by nothing at all, and it is where the
 * version-dependent parts live: `ignore_errors`, the redirect cap, and reading
 * the status back out of band, which PHP 8.5 changed and deprecated in the same
 * release (#80).
 *
 * A local `php -S` fixture server is used rather than mocks because the things
 * worth testing here are the stream wrapper's behaviour, not ours: that a 404
 * still yields its body, that a redirect chain reports the *final* status, and
 * that a connection failure is distinguishable from an HTTP error. None of that
 * survives being faked.
 *
 * On PHP 8.5 this exercises `http_get_last_response_headers()`; on 8.3 and 8.4
 * it exercises the `$http_response_header` fallback. Both paths only ever run
 * on one version each, so the CI matrix is what covers the pair.
 */
final class StreamTransportTest extends TestCase
{
    /** @var resource|null */
    private static $server;

    private static int $port = 0;

    private static string $docRoot = '';

    public static function setUpBeforeClass(): void
    {
        self::$docRoot = sys_get_temp_dir() . '/cwm-st-' . bin2hex(random_bytes(6));

        if (!mkdir(self::$docRoot, 0o777, true) && !is_dir(self::$docRoot)) {
            self::markTestSkipped('Could not create a document root for the fixture server.');
        }

        file_put_contents(self::$docRoot . '/router.php', self::router());

        self::$port = self::freePort();

        $descriptors = [0 => ['pipe', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']];

        $server = proc_open(
            sprintf(
                '%s -S 127.0.0.1:%d -t %s %s',
                escapeshellarg(\PHP_BINARY),
                self::$port,
                escapeshellarg(self::$docRoot),
                escapeshellarg(self::$docRoot . '/router.php')
            ),
            $descriptors,
            $pipes
        );

        if (!\is_resource($server)) {
            self::markTestSkipped('Could not start the fixture server.');
        }

        self::$server = $server;

        if (!self::waitForServer()) {
            self::stopServer();
            self::markTestSkipped('Fixture server did not come up in time.');
        }
    }

    public static function tearDownAfterClass(): void
    {
        self::stopServer();

        foreach (glob(self::$docRoot . '/*') ?: [] as $file) {
            @unlink($file);
        }

        @rmdir(self::$docRoot);
    }

    #[Test]
    public function a_successful_request_returns_status_and_body(): void
    {
        $response = (new StreamTransport())->request('GET', self::url('/ok'));

        self::assertSame(200, $response->status);
        self::assertSame('{"ok":true}', $response->body);
        self::assertTrue($response->isSuccess());
    }

    #[Test]
    public function an_error_response_still_carries_its_body(): void
    {
        // The `ignore_errors` option is load-bearing: without it PHP returns
        // false for any 4xx/5xx and the body — where ARS explains what it
        // objected to — is thrown away before anyone can read it.
        $response = (new StreamTransport())->request('GET', self::url('/error'));

        self::assertSame(422, $response->status);
        self::assertSame('{"errors":[{"title":"nope"}]}', $response->body);
        self::assertFalse($response->isSuccess());
    }

    #[Test]
    public function a_redirect_reports_the_status_it_ended_on(): void
    {
        // The wrapper accumulates one status line per hop, so a naive read of
        // the first would report 301 for a request that ended 200.
        $response = (new StreamTransport())->request('GET', self::url('/redirect'));

        self::assertSame(200, $response->status);
        self::assertSame('{"ok":true}', $response->body);
    }

    #[Test]
    public function an_unreachable_server_is_an_exception_not_a_status(): void
    {
        // "No response" and "a response saying no" lead to opposite actions
        // upstream, so they must not arrive looking the same.
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/No HTTP response/');

        // Port 9 is discard; nothing accepts on it.
        (new StreamTransport(2))->request('GET', 'http://127.0.0.1:9/nope');
    }

    #[Test]
    public function request_headers_and_body_reach_the_server(): void
    {
        $response = (new StreamTransport())->request(
            'POST',
            self::url('/echo'),
            ['X-Joomla-Token' => 'secret-token', 'Accept' => 'application/vnd.api+json'],
            '{"hello":"world"}'
        );

        $echo = json_decode($response->body, true);

        self::assertSame(200, $response->status);
        self::assertSame('POST', $echo['method']);
        self::assertSame('secret-token', $echo['token']);
        self::assertSame('application/vnd.api+json', $echo['accept']);
        self::assertSame('{"hello":"world"}', $echo['body']);
    }

    // --- fixture server --------------------------------------------------

    private static function url(string $path): string
    {
        return 'http://127.0.0.1:' . self::$port . $path;
    }

    /**
     * A port nothing is listening on right now.
     *
     * Racy in principle — something could take it between the check and the
     * server binding — but the alternative is a hard-coded port, which
     * collides with whatever else a CI runner happens to be running.
     */
    private static function freePort(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);

        if ($socket === false) {
            self::markTestSkipped("Could not find a free port: {$errstr} ({$errno}).");
        }

        $name = stream_socket_get_name($socket, false);
        fclose($socket);

        return (int) substr((string) $name, (int) strrpos((string) $name, ':') + 1);
    }

    private static function waitForServer(float $timeoutSeconds = 5.0): bool
    {
        $deadline = microtime(true) + $timeoutSeconds;

        while (microtime(true) < $deadline) {
            $client = @stream_socket_client(
                'tcp://127.0.0.1:' . self::$port,
                $errno,
                $errstr,
                0.2
            );

            if ($client !== false) {
                fclose($client);

                return true;
            }

            usleep(50_000);
        }

        return false;
    }

    private static function stopServer(): void
    {
        if (\is_resource(self::$server)) {
            proc_terminate(self::$server);
            proc_close(self::$server);
        }

        self::$server = null;
    }

    private static function router(): string
    {
        return <<<'PHP'
            <?php
            // Fixture routes for StreamTransportTest. Not part of the shipped tool.
            $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);

            switch ($path) {
                case '/ok':
                    header('Content-Type: application/json');
                    echo '{"ok":true}';
                    return true;

                case '/error':
                    http_response_code(422);
                    header('Content-Type: application/json');
                    echo '{"errors":[{"title":"nope"}]}';
                    return true;

                case '/redirect':
                    http_response_code(301);
                    header('Location: /ok');
                    return true;

                case '/echo':
                    header('Content-Type: application/json');
                    echo json_encode([
                        'method' => $_SERVER['REQUEST_METHOD'] ?? '',
                        'token'  => $_SERVER['HTTP_X_JOOMLA_TOKEN'] ?? '',
                        'accept' => $_SERVER['HTTP_ACCEPT'] ?? '',
                        'body'   => file_get_contents('php://input'),
                    ]);
                    return true;
            }

            http_response_code(404);
            echo 'not found';

            return true;
            PHP;
    }
}

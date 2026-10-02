<?php

declare(strict_types=1);

namespace CWM\BuildTools\Tests\Site;

use CWM\BuildTools\Site\PortFinder;
use CWM\BuildTools\Site\SiteException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class PortFinderTest extends TestCase
{
    #[Test]
    public function aPortInUseIsNotFreeAndTheNextOneIsFound(): void
    {
        $finder = new PortFinder();
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        $this->assertNotFalse($socket);

        $port = (int) substr((string) stream_socket_get_name($socket, false), (int) strrpos((string) stream_socket_get_name($socket, false), ':') + 1);

        $this->assertFalse($finder->isFree($port));
        $this->assertNotSame($port, $finder->firstFree($port));

        fclose($socket);

        $this->assertTrue($finder->isFree($port));
    }

    #[Test]
    public function exhaustingTheSpanSaysHowToRecover(): void
    {
        $finder = new PortFinder();
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        $name   = (string) stream_socket_get_name($socket, false);
        $port   = (int) substr($name, (int) strrpos($name, ':') + 1);

        $this->expectException(SiteException::class);
        $this->expectExceptionMessage('--db-port');

        try {
            $finder->firstFree($port, 1);
        } finally {
            fclose($socket);
        }
    }
}

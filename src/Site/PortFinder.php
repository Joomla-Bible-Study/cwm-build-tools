<?php

declare(strict_types=1);

namespace CWM\BuildTools\Site;

/**
 * Finds a free local TCP port, so two sites do not both claim the same
 * database port.
 */
final class PortFinder
{
    public function isFree(int $port): bool
    {
        $socket = @stream_socket_server('tcp://127.0.0.1:' . $port, $errno, $errstr);

        if ($socket === false) {
            return false;
        }

        fclose($socket);

        return true;
    }

    /**
     * @throws SiteException  when nothing from $from to $from + $span is free
     */
    public function firstFree(int $from, int $span = 100): int
    {
        for ($port = $from; $port < $from + $span; $port++) {
            if ($this->isFree($port)) {
                return $port;
            }
        }

        throw new SiteException(\sprintf('No free port between %d and %d. Pass --db-port.', $from, $from + $span - 1));
    }
}

<?php

declare(strict_types=1);

namespace Turborel\Tests\Support;

use PHPUnit\Framework\TestCase;
use Turborel\Internal\Codec\Reader;
use Turborel\Internal\Codec\Writer;
use Turborel\Internal\ConnectionFailure;
use Turborel\Internal\Session\Wire;
use Turborel\Internal\Transport\Stream;
use Turborel\TurborelException;

/**
 * What the codec's tests share (RULES.md R-TEST-7): bytes written as the
 * spec prints them, and the failures a reader must give.
 */
abstract class CodecTestCase extends TestCase
{
    /** Bytes of the hexadecimal pairs `$pairs`, as the spec prints them, `48 24 00 00 00`. */
    final protected static function hex(string $pairs): string
    {
        $bytes = hex2bin(str_replace([' ', "\n"], '', $pairs));
        self::assertIsString($bytes, "'{$pairs}' are hexadecimal pairs");

        return $bytes;
    }

    /** A reader of `$bytes`, as a reply's body. */
    final protected static function reader(string $bytes): Reader
    {
        return new Reader($bytes, 'reply');
    }

    /** A writer, empty. */
    final protected static function writer(): Writer
    {
        return new Writer();
    }

    /**
     * Asserts that `$attempt` fails with the failure that loses the
     * connection: SQLSTATE 08006, SQLCODE -919.
     *
     * @param \Closure(): mixed $attempt what must fail
     */
    final protected static function assertConnectionFails(\Closure $attempt): void
    {
        self::assertFailure(ConnectionFailure::SQLSTATE, ConnectionFailure::SQLCODE, $attempt);
    }

    /**
     * Asserts that `$attempt` fails as the driver's error of SQLSTATE `$sqlState` and SQLCODE `$sqlCode`.
     *
     * @param \Closure(): mixed $attempt what must fail
     */
    final protected static function assertFailure(string $sqlState, int $sqlCode, \Closure $attempt): void
    {
        try {
            $attempt();
        } catch (TurborelException $failure) {
            self::assertSame([$sqlState, $sqlCode], [$failure->getSqlState(), $failure->getSqlCode()], $failure->getMessage());

            return;
        }
        self::fail("expected SQLSTATE {$sqlState}");
    }

    /**
     * Both ends of a socket pair: the client's, and the one the test writes as the server.
     *
     * @return array{resource, resource}
     */
    final protected static function sockets(): array
    {
        // Windows' PHP pairs sockets of the Internet family alone.
        $windows = \PHP_OS_FAMILY === 'Windows';
        $pair = stream_socket_pair($windows ? \STREAM_PF_INET : \STREAM_PF_UNIX, \STREAM_SOCK_STREAM, $windows ? \STREAM_IPPROTO_TCP : \STREAM_IPPROTO_IP);
        self::assertIsArray($pair);
        [$client, $server] = [$pair[0] ?? null, $pair[1] ?? null];
        self::assertIsResource($client);
        self::assertIsResource($server);

        return [$client, $server];
    }

    /**
     * A wire over the client's end of a socket pair, each wait a second at most, and the server's end.
     *
     * @return array{Wire, resource}
     *
     * @throws TurborelException never, the stream being open
     */
    final protected static function pair(): array
    {
        [$client, $server] = self::sockets();
        $stream = new Stream($client);
        $stream->bound(1.0);

        return [new Wire($stream), $server];
    }
}

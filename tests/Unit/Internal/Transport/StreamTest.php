<?php

declare(strict_types=1);

namespace Turborel\Tests\Unit\Internal\Transport;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Turborel\Internal\ConnectionFailure;
use Turborel\Internal\Quietly;
use Turborel\Internal\Transport\Stream;
use Turborel\Tests\Support\CodecTestCase;
use Turborel\TurborelException;

/**
 * A connection's stream once it is closed.
 */
#[CoversClass(Stream::class)]
#[CoversClass(ConnectionFailure::class)]
#[CoversClass(Quietly::class)]
#[CoversClass(TurborelException::class)]
final class StreamTest extends CodecTestCase
{
    /**
     * Verifies: [CLIENT Timeouts] [CLIENT Connecting]: a stream closed,
     * by the driver or under it, is bounded, written and read no more: each
     * fails as the connection's failure (SQLSTATE 08006), never PHP's own
     * error of a resource closed; it has nothing waiting.
     */
    #[Test]
    public function aClosedStreamFailsAsTheConnection(): void
    {
        [$client] = self::sockets();
        $stream = new Stream($client);
        $stream->close();
        self::assertConnectionFails(static fn() => $stream->bound(1.0));
        self::assertConnectionFails(static fn() => $stream->write('x'));
        self::assertConnectionFails(static fn(): string => $stream->read(1));
        self::assertConnectionFails(static fn(): string => $stream->rest(1));
        self::assertFalse($stream->waiting(0.0));
    }
}

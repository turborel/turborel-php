<?php

declare(strict_types=1);

namespace Turborel\Tests\Unit\Session;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\CoversNamespace;
use PHPUnit\Framework\Attributes\Test;
use Turborel\Internal\Codec\Failure;
use Turborel\Internal\Codec\Frames;
use Turborel\Internal\Codec\Kind;
use Turborel\Internal\Codec\Reply\Pong;
use Turborel\Internal\Codec\Request\Ping;
use Turborel\Internal\Transport\Pipe;
use Turborel\Internal\Transport\Stream;
use Turborel\Session\Canceller;
use Turborel\Session\Connection;
use Turborel\Session\Notice;
use Turborel\Session\Target;
use Turborel\Session\Timeouts;
use Turborel\Session\Trust;
use Turborel\Tests\Support\CodecTestCase;
use Turborel\TurborelException;

/**
 * A connection's layers over streams the test controls (RULES.md
 * R-TEST-1): a listener on loopback that never answers, and a socket
 * pair whose other end the test writes as a server would.
 */
#[CoversNamespace('Turborel\Session')]
#[CoversNamespace('Turborel\Internal')]
#[CoversClass(TurborelException::class)]
final class LoopbackTest extends CodecTestCase
{
    /**
     * Verifies: [CLIENT Timeouts] [CLIENT Running statements]: a server
     * that takes the connection but never answers loses it once the
     * connect timeout runs out, saying it did not answer in time (SQLSTATE
     * 08006); a cancel gives up once its own limit runs out.
     *
     * @throws TurborelException never from the targets
     */
    #[Test]
    public function aServerThatNeverAnswersIsGivenUpOn(): void
    {
        $listener = stream_socket_server('tcp://127.0.0.1:0');
        self::assertIsResource($listener);
        $address = (string) stream_socket_get_name($listener, false);
        $target = Target::tcp('127.0.0.1', (int) substr($address, (int) strrpos($address, ':') + 1));
        $began = hrtime(true);
        self::assertConnectionFails(static fn(): Connection => Connection::connect($target, 'ann', new Trust(), new Timeouts(0.2)));
        self::assertConnectionFails(static fn() => (new Canceller($target, str_repeat('k', 16)))->cancel(0.2));
        self::assertLessThan(Canceller::CANCEL_WITHIN, (hrtime(true) - $began) / 1e9);
        self::assertStringNotContainsString('kkkk', print_r((new Canceller($target, str_repeat('k', 16)))->__debugInfo(), true));
        fclose($listener);
    }

    /**
     * Verifies: [CLIENT What the server tells] [PROTOCOL Errors and notices]:
     * notices before a reply are kept, the last 64, or handed to a handler;
     * a Status updates the settings told; the reply comes after them.
     *
     * @throws TurborelException never, the frames being well formed
     */
    #[Test]
    public function noticesAndStatusesAreTakenOnTheWay(): void
    {
        [$wire, $server] = self::pair();
        $notice = Frames::frame(Kind::NOTICE, self::writer()->string('01000')->string('n')->bytesWritten());
        fwrite($server, str_repeat($notice, 65) . Frames::frame(Kind::STATUS, self::writer()->string('SCHEMA')->string('S')->bytesWritten()) . Frames::frame(Kind::PONG, ''));
        self::assertInstanceOf(Pong::class, $wire->receive());
        self::assertSame([64, 'S'], [\count($wire->told->notices), $wire->told->settings['SCHEMA'] ?? null]);
        $heard = [];
        $wire->told->handler = static function (Notice $notice) use (&$heard): void {
            $heard[] = $notice->message;
        };
        fwrite($server, $notice . Frames::frame(Kind::PONG, ''));
        $wire->receive();
        self::assertSame(['n'], $heard);
    }

    /**
     * Verifies: [PROTOCOL Compressed frames] [PROTOCOL Frames] [CLIENT Running statements]:
     * a compressed frame not granted, or the server closing within a
     * reply, loses the connection (SQLSTATE 08006), and every request
     * after it gets the same failure.
     *
     * @throws TurborelException never from writing the server's end
     */
    #[Test]
    public function aConnectionLostStaysLost(): void
    {
        [$wire, $server] = self::pair();
        fwrite($server, Frames::frame(Kind::COMPRESSED, "\x47\x00\x00\x00\x00x"));
        self::assertConnectionFails($wire->receive(...));
        self::assertConnectionFails(static fn() => $wire->send(new Ping()));
        [$closing, $end] = self::pair();
        fwrite($end, "\x47\x05\x00");
        fclose($end);
        self::assertConnectionFails($closing->receive(...));
        self::assertNotNull($closing->lost);
        self::assertFalse($closing->stream->waiting(0.0));
    }

    /**
     * Verifies: [PROTOCOL Error 'E']: an Error of class 08 but 08003, or of
     * class 28 as the connection is made, ends the connection; another does
     * not; a failure the server reported keeps its rule, position, active
     * flag and diagnostics.
     *
     * @throws TurborelException never, the failures being well formed
     */
    #[Test]
    public function aFailureEndsTheConnectionByItsClass(): void
    {
        $cases = [['08006', -919, true], ['08004', -919, true], ['08003', -919, false], ['28000', -919, true], ['28000', -104, false], ['42000', -103, false]];
        foreach ($cases as [$sqlstate, $sqlcode, $ends]) {
            self::assertSame($ends, (new TurborelException('x', $sqlstate, $sqlcode))->endsConnection(), $sqlstate);
        }
        $body = self::writer()->u32(0xFFFF_FF99)->string('2A000')->string('no T')->flag(true)->string('R')->flag(true)->u64(3)->flag(true)
            ->u8(7)->string('ERROR')->bytesWritten();
        $failure = TurborelException::fromServer(Failure::read(self::reader($body)));
        self::assertSame(['R', 3, true, ['severity' => 'ERROR']], [$failure->getRule(), $failure->getPosition(), $failure->isTransactionActive(), $failure->getDiagnostics()]);
        $plain = new TurborelException('x', '08006', -919);
        self::assertSame([null, null, null, ['severity' => 'FATAL']], [$plain->getRule(), $plain->getPosition(), $plain->isTransactionActive(), $plain->getDiagnostics()]);
        self::assertSame(['severity' => 'ERROR'], (new TurborelException('x', '22003', -301))->getDiagnostics());
    }

    /**
     * Verifies: [CLIENT Timeouts] [CLIENT Connecting]: a named pipe, opened
     * as a file, is opened or refused at once where it is not busy; it is
     * not bounded once open.
     *
     * @throws TurborelException never from the file that is there
     */
    #[Test]
    public function aPipeIsOpenedAsAFile(): void
    {
        self::assertConnectionFails(static fn(): Stream => Pipe::open('/no/such/pipe', 0.1));
        $path = (string) tempnam(sys_get_temp_dir(), 'turborel-pipe-');
        $pipe = Pipe::open($path, 0.1);
        $pipe->bound(1.0);
        $pipe->write('ab');
        $pipe->close();
        self::assertSame('ab', file_get_contents($path));
        unlink($path);
    }
}

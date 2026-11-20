<?php

declare(strict_types=1);

namespace Turborel\Tests\Unit\Session;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\CoversNamespace;
use PHPUnit\Framework\Attributes\Test;
use Turborel\Internal\Codec\Completion;
use Turborel\Internal\Codec\Frames;
use Turborel\Internal\Codec\Kind;
use Turborel\Internal\Codec\Writer;
use Turborel\Internal\Session\Control;
use Turborel\Internal\Session\Ending;
use Turborel\Internal\Statements\Querying;
use Turborel\Session\Capability;
use Turborel\Session\Connection;
use Turborel\Session\Target;
use Turborel\Session\Trust;
use Turborel\Statements\Completed;
use Turborel\Tests\Support\CodecTestCase;
use Turborel\TurborelException;

/**
 * A connection's layers fed replies a server should not send, or a
 * failure where a reply was awaited, over a socket pair whose other end
 * the test writes as the server (RULES.md R-TEST-1).
 */
#[CoversNamespace('Turborel\Session')]
#[CoversNamespace('Turborel\Statements')]
#[CoversNamespace('Turborel\Internal')]
#[CoversClass(TurborelException::class)]
final class RepliesOutOfTurnTest extends CodecTestCase
{
    /**
     * Verifies: [CLIENT Running statements] [PROTOCOL Query 'Q']: a
     * statement's failure among its rows is given, the connection kept; a
     * reply that is no result, or rows broken off, loses the connection.
     *
     * @throws TurborelException never from writing the server's end
     */
    #[Test]
    public function aQueryTakesItsRepliesInTurn(): void
    {
        $description = Frames::frame(Kind::DESCRIPTION, self::writer()->u32(0)->bytesWritten());
        foreach ([[$description . self::error('42000', -103), '42000'], [Frames::frame(Kind::PONG, ''), '08006'], [$description . Frames::frame(Kind::PONG, ''), '08006']] as [$replies, $sqlstate]) {
            [$wire, $server] = self::pair();
            fwrite($server, $replies);
            self::assertFailure($sqlstate, $sqlstate === '08006' ? -919 : -103, static fn() => (new Querying($wire, false))->query('X'));
        }
    }

    /**
     * Verifies: [CLIENT Controlling the session] [PROTOCOL Ping 'G'] [PROTOCOL Reset 'W'] [PROTOCOL ChangeUser 'U']:
     * a ping, a reset or a change of user answered by an Error gives it; one
     * answered out of turn loses the connection.
     *
     * @throws TurborelException never from writing the server's end
     */
    #[Test]
    public function controlRequestsTakeTheirRepliesInTurn(): void
    {
        $requests = [static fn(Control $c) => $c->ping(), static fn(Control $c) => $c->reset(), static fn(Control $c) => $c->changeUser('bob', new Trust())];
        foreach ($requests as $request) {
            foreach ([[self::error('42000', -103), '42000'], [Frames::frame(Kind::SUSPENDED, ''), '08006']] as [$reply, $sqlstate]) {
                [$wire, $server] = self::pair();
                $wire->told->granted = [Capability::PING, Capability::RESET, Capability::CHANGE_USER];
                fwrite($server, $reply);
                self::assertFailure($sqlstate, $sqlstate === '08006' ? -919 : -103, static fn() => $request(new Control($wire)));
            }
        }
    }

    /**
     * Verifies: [PROTOCOL Terminate 'X'] [PROTOCOL Opening a connection]:
     * Terminate answered by a statement's reply fails; a login answered by
     * no Ready loses the connection; a connection's state is shown without
     * its key, and whether each statement is its own transaction is kept.
     *
     * @throws TurborelException never from writing the server's end
     */
    #[Test]
    public function aLoginAndATerminateTakeTheirRepliesInTurn(): void
    {
        [$wire, $server] = self::pair();
        fwrite($server, Frames::frame(Kind::SUSPENDED, ''));
        // Closed for writing alone, so Terminate is not refused by a reset.
        stream_socket_shutdown($server, \STREAM_SHUT_WR);
        self::assertConnectionFails(static fn() => Ending::terminate($wire, 1.0));
        [$client, $server] = self::sockets();
        fwrite($server, Frames::frame(Kind::PONG, ''));
        self::assertConnectionFails(static fn(): Connection => Connection::open($client, 'ann', new Trust()));
        [$client, $server] = self::sockets();
        fwrite($server, Frames::frame(Kind::READY, self::writer()->string('ANN')->raw(str_repeat('k', 16))->strings([])->string('s')->bytesWritten()));
        $connection = Connection::open($client, 'ann', new Trust());
        $connection->setAutocommit(true);
        self::assertSame([true, ['target' => '', 'authorization' => 'ANN', 'lost' => false]], [$connection->autocommit(), $connection->__debugInfo()]);
        self::assertConnectionFails(static fn(): Connection => Connection::connect(Target::local('/no/such/socket'), 'ann', new Trust()));
    }

    /**
     * Verifies: [CLIENT Running statements] [CLIENT What the server tells]:
     * a completion gives its SQLCODE, SQLSTATE, active flag, rows, position,
     * whether more follow, time, generated values and conditions.
     *
     * @throws TurborelException never, the completion being well formed
     */
    #[Test]
    public function aCompletionGivesWhatItCarries(): void
    {
        $condition = self::writer()->u32(0)->string('01000')->string('w')->flag(false)->flag(false)->flag(false)->bytesWritten();
        $body = self::writer()->u32(0)->string('01000')->flag(true)->u8(4)->u64(9)->u8(5)->u8(6)->u64(2)->u8(16)->strings(['7'])
            ->u8(17)->u32(1)->bytes($condition)->u8(18)->u64(1)->u64(2)->u64(3)->bytesWritten();
        $completed = Completed::of(Completion::read(self::reader($body)));
        self::assertSame([0, '01000', true, true, 2, 9, true, ['7'], 1, '01000'], [$completed->sqlcode(), $completed->sqlstate(), $completed->active(),
            $completed->isWarning(), $completed->rows(), $completed->position(), $completed->more(), $completed->generated(), $completed->time()?->total,
            ($completed->conditions()[0] ?? null)?->getSqlState()]);
        self::assertNull(Completed::of(Completion::read(self::reader(self::writer()->u32(0)->string('00000')->flag(false)->bytesWritten())))->time());
    }

    /**
     * An Error frame of `$sqlstate` and `$sqlcode`.
     *
     * @throws TurborelException never, the fields being in range
     */
    private static function error(string $sqlstate, int $sqlcode): string
    {
        return Frames::frame(Kind::ERROR, (new Writer())->u32($sqlcode & 0xFFFF_FFFF)->string($sqlstate)->string('no')->flag(false)->flag(false)->flag(false)->bytesWritten());
    }

}

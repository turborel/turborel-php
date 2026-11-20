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
use Turborel\Internal\Session\Wire;
use Turborel\Internal\Statements\Preparing;
use Turborel\Internal\Statements\Querying;
use Turborel\Session\Capability;
use Turborel\Session\Connection;
use Turborel\Session\Target;
use Turborel\Session\Trust;
use Turborel\Statements\Completed;
use Turborel\Statements\Sink;
use Turborel\Statements\Statement;
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
     * Verifies: [CLIENT Prepared statements] [PROTOCOL Prepare 'P'] [PROTOCOL Describe 'Y'] [PROTOCOL Deallocate 'D']:
     * a prepared statement's request answered out of turn loses the
     * connection; several statements whose replies break off lose it too,
     * the failure given rather than kept as a statement's.
     *
     * @throws TurborelException never from writing the server's end
     */
    #[Test]
    public function statementRequestsTakeTheirRepliesInTurn(): void
    {
        $statement = new Statement(0, [], []);
        $requests = [
            static fn(Preparing $p) => $p->prepare('VALUES 1'),
            static fn(Preparing $p) => $p->describeCursor($statement, 0),
            static fn(Preparing $p) => $p->deallocate($statement),
            static fn(Preparing $p) => $p->fetch($statement, 0, 0),
        ];
        foreach ($requests as $request) {
            [$wire, $server] = self::pair();
            $wire->told->granted = [Capability::DESCRIBE];
            fwrite($server, Frames::frame(Kind::PONG, ''));
            self::assertConnectionFails(static fn() => $request(new Preparing($wire, false)));
            self::assertNotNull($wire->lost);
        }
        [$wire, $server] = self::pair();
        $wire->told->granted = [Capability::MULTI_STATEMENT];
        fwrite($server, Frames::frame(Kind::PONG, ''));
        self::assertConnectionFails(static fn() => (new Querying($wire, false))->many('VALUES 1; VALUES 2'));
    }

    /**
     * Verifies: [CLIENT Running statements] [CLIENT Controlling the session] [PROTOCOL Terminate 'X']:
     * as turborel-client keeps them, a Query's rows streamed or of several
     * statements broken off by Suspended lose the connection, a single
     * statement's do not; an open cursor is described with `describe`
     * alone; Ready to a change of user leaves no transaction active and no
     * warning; the capabilities granted are those the spec names, in its
     * order; an Error among the frames after Terminate is its failure.
     *
     * @throws TurborelException never from the replies in turn
     */
    #[Test]
    public function turborelClientsStateIsKept(): void
    {
        $suspended = Frames::frame(Kind::DESCRIPTION, self::writer()->u32(0)->bytesWritten()) . Frames::frame(Kind::SUSPENDED, '');
        $sink = new class implements Sink {
            public function columns(array $columns): void {}

            public function rows(array $rows): void {}
        };
        foreach ([static fn(Querying $q) => $q->into('X', $sink), static fn(Querying $q) => $q->many('X')] as $streamed) {
            [$wire, $server] = self::pair();
            $wire->told->granted = [Capability::MULTI_STATEMENT];
            fwrite($server, $suspended);
            self::assertConnectionFails(static fn() => $streamed(new Querying($wire, false)));
            self::assertNotNull($wire->lost);
        }
        [$wire, $server] = self::pair();
        fwrite($server, $suspended . Frames::frame(Kind::DESCRIPTION, self::writer()->u32(0)->bytesWritten()));
        self::assertNull((new Querying($wire, false))->query('X')->completed);
        $wire->told->granted = [Capability::DESCRIBE];
        self::assertSame([], (new Preparing($wire, false))->describeCursor(new Statement(0, [], []), 3));
        [$wire, $server] = self::pair();
        [$wire->told->granted, $wire->active, $wire->warning] = [[Capability::CHANGE_USER], true, '01000'];
        fwrite($server, self::ready(['describe', 'no-such', 'ping']));
        $changed = (new Control($wire))->changeUser('bob', new Trust());
        $state = static fn(Wire $w): array => [$w->active, $w->warning];
        self::assertSame(['ANN', [false, null]], [$changed->authorization, $state($wire)]);
        [$client, $server] = self::sockets();
        fwrite($server, self::ready(['ping', 'no-such', 'describe']));
        $connection = Connection::open($client, 'ann', new Trust());
        self::assertSame([[Capability::DESCRIBE, Capability::PING], false], [$connection->granted(), $connection->has('no-such')]);
        [$wire, $server] = self::pair();
        fwrite($server, Frames::frame(Kind::NOTICE, self::writer()->string('01000')->string('n')->bytesWritten()) . self::error('40002', -911));
        stream_socket_shutdown($server, \STREAM_SHUT_WR);
        self::assertFailure('40002', -911, static fn() => Ending::terminate($wire, 1.0));
    }

    /**
     * A Ready frame granting `$capabilities`.
     *
     * @param list<string> $capabilities
     *
     * @throws TurborelException never, the fields being in range
     */
    private static function ready(array $capabilities): string
    {
        return Frames::frame(Kind::READY, self::writer()->string('ANN')->raw(str_repeat('k', 16))->strings($capabilities)->string('s')->bytesWritten());
    }

    /**
     * Verifies: [PROTOCOL Errors and notices] [PROTOCOL Error 'E']: a failure
     * of severity FATAL, or SQLSTATE TBR01 of a replica not caught up, is
     * given as the server sent it, SQLCODE -920 kept, the connection kept.
     *
     * @throws TurborelException never from writing the server's end
     */
    #[Test]
    public function aFailureIsGivenAsTheServerSentIt(): void
    {
        [$wire, $server] = self::pair();
        $failure = (new Writer())->u32(-920 & 0xFFFF_FFFF)->string('TBR01')->string('not caught up')->flag(false)->flag(false)->flag(false)
            ->u8(7)->string('FATAL')->bytesWritten();
        fwrite($server, Frames::frame(Kind::ERROR, $failure));
        try {
            (new Querying($wire, false))->query('VALUES 1');
            self::fail('a replica not caught up');
        } catch (TurborelException $caught) {
            self::assertSame(['TBR01', -920, 'not caught up', 'FATAL', false], [$caught->getSqlState(), $caught->getSqlCode(), $caught->getMessage(),
                $caught->getDiagnostics()['severity'] ?? null, $caught->endsConnection()]);
        }
        self::assertNull($wire->lost);
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

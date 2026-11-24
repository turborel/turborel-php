<?php

declare(strict_types=1);

namespace Turborel\Tests\Live\Throughput;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\CoversNamespace;
use PHPUnit\Framework\Attributes\Test;
use Turborel\Agent\Agent;
use Turborel\Agent\Connector;
use Turborel\Session\Connection;
use Turborel\Session\Target;
use Turborel\Session\Trust;
use Turborel\Statements\Sink;
use Turborel\Tests\Server\LiveServer;
use Turborel\Tests\Support\LiveTestCase;
use Turborel\TurborelException;

/**
 * An SQL-agent's connections to a live server, made, chosen and ended by
 * connection statements.
 */
#[CoversNamespace('Turborel\Agent')]
#[CoversNamespace('Turborel\Session')]
#[CoversNamespace('Turborel\Internal')]
#[CoversNamespace('Turborel\Statements')]
#[CoversNamespace('Turborel\Value')]
#[CoversNamespace('Turborel\Type')]
#[CoversClass(TurborelException::class)]
final class AgentTest extends LiveTestCase
{
    /**
     * Verifies: [CLIENT The SQL-agent]: CONNECT makes a named connection
     * current through the connector, to the address and as the user it
     * names; SET CONNECTION chooses one; every other statement goes to the
     * current one; DISCONNECT ends those it names, CURRENT leaving none;
     * each rule's failure is its SQLSTATE.
     *
     * @throws TurborelException never from the statements that succeed
     */
    #[Test]
    public function connectionsAreMadeChosenAndEnded(): void
    {
        self::server();
        $agent = new Agent(new class implements Connector {
            public function connect(?string $server, ?string $user): Connection
            {
                $port = $server === 'nowhere' ? 1 : LiveServer::PORT;

                return Connection::connect(Target::tcp('127.0.0.1', $port), $user ?? 'ann', new Trust());
            }
        });
        self::assertFailure('08003', -919, static fn() => $agent->current());
        self::assertSame('00000', $agent->query("CONNECT TO 'live' AS 'one' USER 'bob'")->completed?->sqlstate());
        self::assertSame(['ONE', 'BOB'], [$agent->currentName(), $agent->current()->authorization()]);
        $agent->query("CONNECT TO 'live' AS 'two'");
        self::assertSame(['TWO', 'ANN', false], [$agent->currentName(), $agent->current()->authorization(), $agent->isLost()]);
        self::assertSame([['ANN']], $agent->query('VALUES CURRENT_USER')->rows);
        $agent->query('ROLLBACK');
        $agent->query("SET CONNECTION 'one'");
        self::assertSame('BOB', $agent->current()->authorization());
        $agent->query('VALUES 1');
        self::assertFailure('0A001', -900, static fn() => $agent->query("SET CONNECTION 'two'"));
        $agent->query('ROLLBACK');
        self::assertFailure('08002', -919, static fn() => $agent->query("CONNECT TO 'live' AS 'one'"));
        self::assertFailure('2E000', -919, static fn() => $agent->query("CONNECT TO 'live' AS 'not a name'"));
        self::assertFailure('28000', -104, static fn() => $agent->query("CONNECT TO 'live' AS 'three' USER 'select'"));
        self::assertFailure('08001', -919, static fn() => $agent->query("CONNECT TO 'nowhere'"));
        self::assertFailure('42000', -103, static fn() => $agent->query('DISCONNECT 5'));
        self::assertFailure('08003', -919, static fn() => $agent->query("DISCONNECT 'none'"));
        self::assertNull($agent->warning());
        self::assertNotNull($agent->canceller());
        self::assertSame(0, $agent->queryInto("SET CONNECTION 'two'", new class implements Sink {
            public function columns(array $columns): void {}

            public function rows(array $rows): void {}
        })->rows);
        $agent->current()->query('VALUES 1');
        self::assertFailure('25000', -917, static fn() => $agent->query('DISCONNECT ALL'));
        $agent->current()->query('ROLLBACK');
        self::assertSame('00000', $agent->query('DISCONNECT CURRENT')->completed?->sqlstate());
        self::assertSame([null, null], [$agent->currentName(), $agent->currentConnection()]);
        self::assertFailure('08003', -919, static fn() => $agent->query('DISCONNECT CURRENT'));
        $agent->terminate();
    }

    /**
     * Verifies: [CLIENT The SQL-agent]: CONNECT TO DEFAULT makes the default
     * connection, of no name, which DISCONNECT DEFAULT ends; an agent begun
     * connected holds its default connection, current.
     *
     * @throws TurborelException never from the statements
     */
    #[Test]
    public function theDefaultConnectionIsOfNoName(): void
    {
        self::server();
        $agent = new Agent(new class implements Connector {
            public function connect(?string $server, ?string $user): Connection
            {
                return Connection::connect(Target::tcp('127.0.0.1', LiveServer::PORT), $user ?? 'ann', new Trust());
            }
        });
        $agent->query('CONNECT TO DEFAULT');
        $who = $agent->current()->authorization();
        self::assertSame([null, 'ANN'], [$agent->currentName(), $who]);
        self::assertFailure('08002', -919, static fn() => $agent->query('CONNECT TO DEFAULT'));
        $agent->query('DISCONNECT DEFAULT');
        self::assertFailure('08003', -919, static fn() => $agent->query('DISCONNECT DEFAULT'));
        self::assertFailure('08003', -919, static fn() => $agent->query('SET CONNECTION DEFAULT'));
        $connected = Agent::connected(new class implements Connector {
            public function connect(?string $server, ?string $user): Connection
            {
                return Connection::connect(Target::tcp('127.0.0.1', LiveServer::PORT), 'ann', new Trust());
            }
        }, Connection::connect(self::tcp(), 'ann', new Trust()));
        self::assertNull($connected->currentName());
        $connected->terminate();
    }
}

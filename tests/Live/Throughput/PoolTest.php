<?php

declare(strict_types=1);

namespace Turborel\Tests\Live\Throughput;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\CoversNamespace;
use PHPUnit\Framework\Attributes\Test;
use Turborel\Internal\ConnectionFailure;
use Turborel\Pool\Pool;
use Turborel\Pool\PoolConfig;
use Turborel\Pool\Pooled;
use Turborel\Pool\Source;
use Turborel\Session\Connection;
use Turborel\Session\Offer;
use Turborel\Session\Trust;
use Turborel\Settings\Options;
use Turborel\Settings\Settings;
use Turborel\Tests\Server\LiveServer;
use Turborel\Tests\Support\LiveTestCase;
use Turborel\TurborelException;

/**
 * Pools of connections to a live server.
 */
#[CoversNamespace('Turborel\Pool')]
#[CoversNamespace('Turborel\Pipeline')]
#[CoversNamespace('Turborel\Settings')]
#[CoversNamespace('Turborel\Session')]
#[CoversNamespace('Turborel\Internal')]
#[CoversNamespace('Turborel\Statements')]
#[CoversNamespace('Turborel\Value')]
#[CoversNamespace('Turborel\Type')]
#[CoversClass(TurborelException::class)]
final class PoolTest extends LiveTestCase
{
    /**
     * Verifies: [CLIENT Pools]: a pool of settings makes connections while
     * fewer than `max` are made, then waits `wait` for one to come back and
     * fails (SQLSTATE 08001); one released comes back idle, reset by the
     * protocol's Reset, autocommit off, then the `reset` statements, and is
     * handed out again after a Ping; its clones are the same pool.
     *
     * @throws TurborelException never from the connections handed out
     */
    #[Test]
    public function connectionsAreHandedOutAndGivenBack(): void
    {
        self::server();
        $settings = Settings::resolveIn(Options::fromUri('turborel://ann@127.0.0.1:' . LiveServer::PORT . '/?tls_mode=disable'), static fn(string $name): mixed => null);
        $pool = new Pool($settings, new PoolConfig(['max' => 2, 'wait' => 0.2, 'reset' => ["SET SESSION.TIME_ZONE = '+05:00'"]]));
        $first = $pool->get();
        $first->connection()->query("SET SESSION.TIME_ZONE = '+02:00'");
        $first->connection()->setAutocommit(true);
        $second = (clone $pool)->get();
        self::assertSame([2, 0], $pool->counts());
        self::assertFailure('08001', -919, static fn(): Pooled => $pool->get());
        $first->release();
        self::assertSame([2, 1], $pool->counts());
        self::assertFailure('TB910', -910, static fn(): Connection => $first->connection());
        $again = $pool->get();
        self::assertSame(['+05:00', false], [$again->connection()->setting('SESSION.TIME_ZONE'), $again->connection()->autocommit()]);
        $again->release();
        $second->release();
        self::assertSame([2, 2], $pool->counts());
    }

    /**
     * Verifies: [CLIENT Pools]: a connection past `max_lifetime`, beyond
     * `max_idle`, lost, or failing its reset is closed as it comes back; one
     * idle past `idle_timeout`, or failing its check, closed as get meets it,
     * and another made; a failure to make one is get's, its place given up;
     * a pool is made of a closure.
     *
     * @throws TurborelException never from the connections made
     */
    #[Test]
    public function staleConnectionsAreClosed(): void
    {
        $make = static fn(): Connection => Connection::connect(self::tcp(), 'ann', new Trust());
        $short = new Pool($make, new PoolConfig(['maxLifetime' => 0.0]));
        $short->get()->release();
        self::assertSame([0, 0], $short->counts(), 'lived its time');
        $none = new Pool($make, new PoolConfig(['maxIdle' => 0]));
        $none->get()->release();
        self::assertSame([0, 0], $none->counts(), 'beyond max_idle');
        $resting = new Pool($make, new PoolConfig(['idleTimeout' => 0.0]));
        $resting->get()->release();
        $fresh = $resting->get();
        self::assertSame([1, 0], $resting->counts(), 'the stale one closed, another made');
        $fresh->connection()->wire()->lose('gone');
        $fresh->release();
        self::assertSame([0, 0], $resting->counts(), 'a lost one closed');
        $failing = new Pool(new class implements Source {
            public function connect(): Connection
            {
                throw ConnectionFailure::because('no server');
            }
        });
        self::assertConnectionFails(static fn(): Pooled => $failing->get());
        self::assertSame([0, 0], $failing->counts());
        $badReset = new Pool($make, new PoolConfig(['reset' => ['SELECT * FROM NOPE']]));
        $badReset->get()->release();
        self::assertSame([0, 0], $badReset->counts(), 'its reset failed');
    }

    /**
     * Verifies: [CLIENT Pools]: an idle connection that does not answer its
     * check is closed as get meets it, and another made; a get within a
     * Fiber that finds none waits by suspending its Fiber, so the program
     * may give one back, which the Fiber then takes.
     *
     * @throws TurborelException never from the connections made
     */
    #[Test]
    public function aWaiterWithinAFiberLetsAnotherGiveBack(): void
    {
        $pool = new Pool(static fn(): Connection => Connection::connect(self::tcp(), 'ann', new Trust()), new PoolConfig(['max' => 1, 'wait' => 5.0]));
        $first = $pool->get();
        $kept = $first->connection();
        $first->release();
        $kept->wire()->stream->close();
        $held = $pool->get();
        self::assertNotSame($kept, $held->connection(), 'the one that did not answer closed, another made');
        $waiter = new \Fiber(static fn(): Pooled => $pool->get());
        $waiter->start();
        self::assertTrue($waiter->isSuspended(), 'waiting for one to come back');
        $given = $held->connection();
        $held->release();
        while (!$waiter->isTerminated()) {
            $waiter->resume();
        }
        $taken = $waiter->getReturn();
        self::assertInstanceOf(Pooled::class, $taken);
        self::assertSame($given, $taken->connection());
        $taken->release();
    }

    /**
     * Verifies: [CLIENT Pools] [PROTOCOL Reset 'W']: of a server that grants
     * neither `ping` nor `reset`, the check is ROLLBACK and the reset one
     * pipeline: the transaction rolled back, the statements a borrower
     * prepared closed and destroyed, its user and schema those of its
     * login, then the `reset` statements.
     *
     * @throws TurborelException never from the connections made
     */
    #[Test]
    public function aServerOfNeitherIsResetByStatements(): void
    {
        $pool = new Pool(static fn(): Connection => Connection::connect(self::tcp(), 'ann', new Trust(), null, Offer::none()), new PoolConfig(['reset' => ['VALUES 1']]));
        $borrowed = $pool->get();
        $borrowed->connection()->query('CREATE TABLE PO_GONE (N INTEGER)');
        $statement = $borrowed->connection()->prepare('VALUES 1');
        $borrowed->connection()->openCursor($statement, [], 0);
        $borrowed->release();
        self::assertSame([1, 1], $pool->counts());
        $again = $pool->get();
        self::assertFalse($again->connection()->inTransaction());
        self::assertFailure('42000', -103, static fn() => $again->connection()->query('SELECT N FROM PO_GONE'));
        self::assertFailure('26000', -921, static fn() => $again->connection()->execute($statement));
        self::assertSame(0, $again->connection()->wire()->nextStatement);
        $again->release();
    }
}

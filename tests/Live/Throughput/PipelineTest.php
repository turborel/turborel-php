<?php

declare(strict_types=1);

namespace Turborel\Tests\Live\Throughput;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\CoversNamespace;
use PHPUnit\Framework\Attributes\Test;
use Turborel\Pipeline\OnError;
use Turborel\Pipeline\Piped;
use Turborel\Tests\Support\LiveTestCase;
use Turborel\TurborelException;
use Turborel\Value\Exact;

/**
 * Pipelines on a live server: a batch's requests written at once, one
 * outcome each, continuing past a failure or stopping at the first.
 */
#[CoversNamespace('Turborel\Pipeline')]
#[CoversNamespace('Turborel\Session')]
#[CoversNamespace('Turborel\Internal')]
#[CoversNamespace('Turborel\Statements')]
#[CoversNamespace('Turborel\Value')]
#[CoversNamespace('Turborel\Type')]
#[CoversClass(TurborelException::class)]
final class PipelineTest extends LiveTestCase
{
    /**
     * Verifies: [CLIENT Pipelines] [PROTOCOL Requests]: queued queries and
     * executions run in order, one outcome each; a failure is its request's
     * own and the requests after it run; under autocommit each request is
     * its own transaction; a batch past 64 KiB is written a window at a
     * time, every request answered.
     *
     * @throws TurborelException never from the statements
     */
    #[Test]
    public function aBatchRunsInOrderPastAFailure(): void
    {
        $connection = self::owner();
        $connection->setAutocommit(true);
        $connection->query('CREATE TABLE PL_STAFF (ID INTEGER PRIMARY KEY, NAME VARCHAR(20))');
        $insert = $connection->prepare('INSERT INTO PL_STAFF VALUES (?, ?)');
        $pipeline = $connection->pipeline()->query("INSERT INTO PL_STAFF VALUES (1, 'ANN')")->execute($insert, [2, 'BOB'])->execute($insert, [1, 'AGAIN'])
            ->query('SELECT ID, NAME FROM PL_STAFF ORDER BY ID');
        self::assertSame(4, $pipeline->count());
        $outcomes = $pipeline->run();
        self::assertSame([null, null, '23000', null], array_map(static fn(Piped $o): ?string => $o->failure?->getSqlState(), $outcomes));
        self::assertSame(['ANN', 'BOB'], array_column($outcomes[3]->outcome->rows ?? [], 1));
        self::assertFalse($connection->inTransaction());
        $many = $connection->pipeline();
        for ($n = 3; $n < 2003; $n++) {
            $many->execute($insert, [$n, str_repeat('n', 20)]);
        }
        $done = $many->run();
        self::assertSame([2000, 0], [\count($done), \count(array_filter($done, static fn(Piped $o): bool => $o->failure !== null))]);
        $count = $connection->query('SELECT COUNT(*) FROM PL_STAFF')->rows[0][0] ?? null;
        self::assertSame('2002', $count instanceof Exact ? $count->units : null);
        self::assertTrue($connection->pipeline()->isEmpty());
        self::assertSame([], $connection->pipeline()->run());
        $connection->terminate();
    }

    /**
     * Verifies: [CLIENT Pipelines]: on error skip, the batch stops at its
     * first failure, the requests after it rolled back to the savepoint set
     * after it and skipped, those of a window not yet written never sent;
     * the batch is one transaction, committed as it ends under autocommit
     * where it began none; within a transaction it stays open.
     *
     * @throws TurborelException never from the statements
     */
    #[Test]
    public function aSkippingBatchStopsAtItsFirstFailure(): void
    {
        $connection = self::owner();
        $connection->setAutocommit(true);
        $connection->query('CREATE TABLE PL_SKIP (ID INTEGER PRIMARY KEY)');
        $outcomes = $connection->pipeline()->onError(OnError::Skip)->query('INSERT INTO PL_SKIP VALUES (1)')->query('INSERT INTO PL_SKIP VALUES (1)')
            ->query('INSERT INTO PL_SKIP VALUES (2)')->run();
        self::assertSame([false, true, false], array_map(static fn(Piped $o): bool => $o->failure !== null, $outcomes));
        self::assertTrue(($outcomes[2] ?? null)?->isSkipped());
        self::assertFalse($connection->inTransaction(), 'committed as the batch ends');
        $rows = $connection->query('SELECT ID FROM PL_SKIP')->rows;
        self::assertSame(['1'], array_map(static fn(array $r): string => ($r[0] ?? null) instanceof Exact ? $r[0]->units : '', $rows));
        $long = $connection->pipeline()->onError(OnError::Skip)->query('INSERT INTO PL_SKIP VALUES (1)');
        for ($n = 0; $n < 2000; $n++) {
            $long->query('INSERT INTO PL_SKIP VALUES (' . (10 + $n) . ')');
        }
        $skipped = $long->run();
        self::assertCount(2000, array_filter($skipped, static fn(Piped $o): bool => $o->isSkipped()));
        self::assertCount(1, $connection->query('SELECT ID FROM PL_SKIP')->rows);
        $connection->setAutocommit(false);
        $connection->query('INSERT INTO PL_SKIP VALUES (5)');
        $connection->pipeline()->onError(OnError::Skip)->query('INSERT INTO PL_SKIP VALUES (6)')->query('INSERT INTO PL_SKIP VALUES (7)')->run();
        self::assertTrue($connection->inTransaction(), 'within a transaction, it stays open');
        $connection->query('ROLLBACK');
        $connection->terminate();
    }
}

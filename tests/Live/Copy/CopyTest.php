<?php

declare(strict_types=1);

namespace Turborel\Tests\Live\Copy;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\CoversNamespace;
use PHPUnit\Framework\Attributes\Test;
use Turborel\Copy\ExportFormat;
use Turborel\Copy\NativeRows;
use Turborel\Session\Capability;
use Turborel\Session\Connection;
use Turborel\Session\Defaults;
use Turborel\Session\Offer;
use Turborel\Session\Trust;
use Turborel\Tests\Support\LiveTestCase;
use Turborel\TurborelException;
use Turborel\Value\Exact;

/**
 * Rows copied into a live server's tables, and exported from them.
 */
#[CoversNamespace('Turborel\Session')]
#[CoversNamespace('Turborel\Internal')]
#[CoversNamespace('Turborel\Statements')]
#[CoversNamespace('Turborel\Value')]
#[CoversNamespace('Turborel\Copy')]
#[CoversNamespace('Turborel\Type')]
#[CoversClass(TurborelException::class)]
final class CopyTest extends LiveTestCase
{
    /**
     * Verifies: [CLIENT Copying and exporting] [PROTOCOL CopyIn 'I']
     * [PROTOCOL CopyData 'J'] [PROTOCOL CopyEnd 'N'] [PROTOCOL Conversations]:
     * rows are copied into the columns named, or all, many to a message
     * with no reply each, the end giving the rows inserted, under autocommit
     * the copy's own transaction; an iterator's rows are copied; until the
     * end only rows, the end and Ping are sent, any other request refused
     * (SQLSTATE TB910); not granted `copy`, a copy
     * fails before anything is sent.
     *
     * @throws TurborelException never from the copies that succeed
     */
    #[Test]
    public function rowsAreCopiedIntoATable(): void
    {
        $connection = self::table('C_ROWS');
        $connection->setAutocommit(true);
        $copying = $connection->copyIn('C_ROWS', ['N']);
        $connection->ping();
        self::assertFailure('TB910', -910, static fn() => $connection->query('VALUES 1'));
        foreach (range(1, 3000) as $n) {
            $copying->send([$n]);
        }
        self::assertSame([3000, false], [$copying->finish(), $connection->inTransaction()]);
        self::assertSame(2, $connection->copyRows('C_ROWS', [], (static function (): \Generator {
            yield [new Exact('5001', 0), 'a'];
            yield ['5002', 'b'];
        })()));
        self::assertSame([['3002']], array_map(static fn(array $row): array => [($row[0] ?? null) instanceof Exact ? $row[0]->units : ''], $connection->query('SELECT COUNT(*) FROM C_ROWS')->rows));
        $connection->terminate();
        $without = Connection::connect(self::tcp(), 'ann', new Trust(), null, new Offer(array_values(array_diff(Defaults::CAPABILITIES, [Capability::COPY]))));
        self::assertConnectionFails(static fn() => $without->copyIn('C_ROWS'));
        self::assertFalse($without->isLost());
        $without->terminate();
    }

    /**
     * Verifies: [CLIENT Copying and exporting] [PROTOCOL CopyEnd 'N']
     * [PROTOCOL Conversations]: a copy is all or nothing: a row refused
     * undoes every row, its failure's detail naming the row; within a
     * transaction, the copy alone is undone, under a savepoint of its own;
     * a copy abandoned, or let go of before it ends, undoes every row and
     * counts none, and the connection takes requests again.
     *
     * @throws TurborelException never from the statements that succeed
     */
    #[Test]
    public function aCopyIsAllOrNothing(): void
    {
        $connection = self::table('C_ALL');
        $connection->query("INSERT INTO C_ALL VALUES (1, 'kept')");
        try {
            $connection->copyRows('C_ALL', [], [[2, 'a'], [2, 'b'], [3, 'c']]);
            self::fail('a duplicate key');
        } catch (TurborelException $failure) {
            self::assertSame(['23000', 'row 2 of the copy'], [$failure->getSqlState(), $failure->getDiagnostics()['detail'] ?? null]);
        }
        self::assertTrue($connection->inTransaction());
        self::assertSame([['kept']], $connection->query('SELECT S FROM C_ALL')->rows);
        $abandoned = $connection->copyIn('C_ALL');
        $abandoned->send([4, 'd']);
        $abandoned->abandon();
        (static function (Connection $connection): void {
            $connection->copyIn('C_ALL')->send([5, 'e']);
        })($connection);
        self::assertSame([['kept']], $connection->query('SELECT S FROM C_ALL')->rows);
        self::assertFailure('37000', -103, static fn() => $connection->copyRows('C_NOPE', [], [[1]]));
        $connection->terminate();
    }

    /**
     * Verifies: [CLIENT Copying and exporting]: a query's rows are exported
     * as they come, as CSV, a header of the columns' names then a record
     * for each row, or as the protocol's own frames, read back with every
     * value as it was; a statement that is no query is refused.
     *
     * @throws TurborelException never from the exports
     */
    #[Test]
    public function aQueryIsExported(): void
    {
        $connection = self::table('C_OUT');
        $connection->query("INSERT INTO C_OUT VALUES (1, 'a,b'), (2, NULL), (3, '')");
        $csv = self::memory();
        self::assertSame(3, $connection->export('SELECT N, S, 1.5E0 AS X FROM C_OUT ORDER BY N', $csv));
        self::assertSame("N,S,X\r\n1,\"a,b\",1.5E0\r\n2,,1.5E0\r\n3,\"\",1.5E0\r\n", stream_get_contents($csv, -1, 0));
        $native = self::memory();
        self::assertSame(3, $connection->export('SELECT * FROM C_OUT ORDER BY N', $native, ExportFormat::Native));
        rewind($native);
        $read = NativeRows::read($native);
        self::assertEquals([['N', 'S'], $connection->query('SELECT * FROM C_OUT ORDER BY N')->rows], [array_map(static fn($c): ?string => $c->name, $read->columns), $read->rows]);
        self::assertConnectionFails(static fn() => $connection->export("INSERT INTO C_OUT VALUES (4, 'd')", self::memory()));
        $short = self::memory();
        fwrite($short, substr((string) stream_get_contents($native, -1, 0), 0, 20));
        rewind($short);
        self::assertConnectionFails(static fn() => NativeRows::read($short));
        $connection->terminate();
    }

    /**
     * A connection as ANN, in which `$table` has a key N and a string S of at most 5 characters, committed.
     *
     * @throws TurborelException if it cannot be made
     */
    private static function table(string $table): Connection
    {
        $connection = self::owner();
        $connection->query("CREATE TABLE {$table} (N INTEGER PRIMARY KEY, S VARCHAR(5))");
        $connection->query('COMMIT');

        return $connection;
    }

    /**
     * An empty stream in memory.
     *
     * @return resource
     */
    private static function memory(): mixed
    {
        $stream = fopen('php://memory', 'w+b');
        self::assertIsResource($stream);

        return $stream;
    }
}

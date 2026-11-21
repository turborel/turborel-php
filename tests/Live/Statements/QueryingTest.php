<?php

declare(strict_types=1);

namespace Turborel\Tests\Live\Statements;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\CoversNamespace;
use PHPUnit\Framework\Attributes\Test;
use Turborel\Internal\Codec\Reply\Error;
use Turborel\Internal\Codec\Request\Query;
use Turborel\Session\Capability;
use Turborel\Session\Connection;
use Turborel\Session\Defaults;
use Turborel\Session\Offer;
use Turborel\Session\Trust;
use Turborel\Tests\Support\LiveTestCase;
use Turborel\TurborelException;
use Turborel\Type\Column;
use Turborel\Type\ExactType;
use Turborel\Value\Binary;
use Turborel\Value\Datetime;
use Turborel\Value\Exact;
use Turborel\Value\Interval;
use Turborel\Value\LobLocator;
use Turborel\Value\Uuid;

/**
 * Direct SQL statements run on a live server: a query's columns and rows,
 * another statement's completion, a failure; rows a batch at a time;
 * transactions, autocommit, and several statements in one request.
 */
#[CoversNamespace('Turborel\Session')]
#[CoversNamespace('Turborel\Internal')]
#[CoversNamespace('Turborel\Statements')]
#[CoversNamespace('Turborel\Value')]
#[CoversNamespace('Turborel\Type')]
#[CoversClass(TurborelException::class)]
final class QueryingTest extends LiveTestCase
{
    /** The seconds from 0001-01-01 00:00:00 to 1970-01-01 00:00:00, UTC. */
    private const int TO_1970 = 62_135_596_800;

    /**
     * Verifies: [CLIENT Running statements] [PROTOCOL Query 'Q']
     * [PROTOCOL Requests]: a query is answered by its columns and rows and
     * a Complete; one that returns no row completes with SQLCODE 100,
     * SQLSTATE 02000; another statement by its Complete.
     *
     * @throws TurborelException never from the statements
     */
    #[Test]
    public function aQueryGivesItsColumnsAndRows(): void
    {
        $connection = self::owner();
        $made = $connection->query('CREATE TABLE Q_STAFF (ID INTEGER, NAME VARCHAR(20))');
        self::assertSame([false, 0, '00000'], [$made->isRows(), $made->completed?->sqlcode(), $made->completed?->sqlstate()]);
        $connection->query("INSERT INTO Q_STAFF VALUES (1, 'Ada'), (2, 'Grace')");
        $staff = $connection->query('SELECT ID, NAME FROM Q_STAFF ORDER BY ID');
        self::assertSame(['ID', 'NAME'], array_map(static fn(Column $column): ?string => $column->name, $staff->columns));
        self::assertInstanceOf(ExactType::class, $staff->columns[0]->type ?? null);
        self::assertSame([['1', 'Ada'], ['2', 'Grace']], array_map(static fn(array $row): array => [self::units($row[0] ?? null), $row[1] ?? null], $staff->rows));
        $none = $connection->query('SELECT ID FROM Q_STAFF WHERE ID > 9');
        self::assertSame([true, [], 100, '02000'], [$none->isRows(), $none->rows, $none->completed?->sqlcode(), $none->completed?->sqlstate()]);
        $connection->terminate();
    }

    /**
     * Verifies: [CLIENT Running statements] [CLIENT What the server tells]
     * [PROTOCOL Errors and notices] [PROTOCOL Error 'E'] [PROTOCOL Capabilities]:
     * a failure gives its SQLCODE, SQLSTATE, message, the rule it cites
     * apart and its position; granted `diagnostics`, its severity and what
     * it is about: a duplicate key names its constraint and table, its
     * detail the key. The session goes on.
     *
     * @throws TurborelException never from the statements that succeed
     */
    #[Test]
    public function aFailureGivesWhatItCites(): void
    {
        $connection = self::owner();
        try {
            $connection->query('SELECT * FROM NOPE');
            self::fail('a table not defined');
        } catch (TurborelException $failure) {
            self::assertSame(['2A000', -103, 'table ANN.NOPE is not defined', 14], [$failure->getSqlState(), $failure->getSqlCode(), $failure->getMessage(), $failure->getPosition()]);
            self::assertSame('X3.135-1986 §5.4 SR5', $failure->getRule());
            self::assertSame(['severity' => 'ERROR', 'schema' => 'ANN', 'table' => 'NOPE'], $failure->getDiagnostics());
        }
        $connection->query('CREATE TABLE Q_KEYS (K INTEGER PRIMARY KEY)');
        $connection->query('INSERT INTO Q_KEYS VALUES (1)');
        try {
            $connection->query('INSERT INTO Q_KEYS VALUES (1)');
            self::fail('a duplicate key');
        } catch (TurborelException $failure) {
            self::assertSame(['23000', -201, false], [$failure->getSqlState(), $failure->getSqlCode(), $failure->endsConnection()]);
            $diagnostics = $failure->getDiagnostics();
            self::assertSame(['Q_KEYS_PRIMARY1', 'Q_KEYS', 'the key (1) is held by another row'], [$diagnostics['constraint'] ?? null, $diagnostics['table'] ?? null, $diagnostics['detail'] ?? null]);
        }
        self::assertCount(1, $connection->query('SELECT K FROM Q_KEYS')->rows);
        $connection->terminate();
    }

    /**
     * Verifies: [PROTOCOL Query 'Q'] [CLIENT Running statements]: a query
     * that fails before its first batch is sent, dividing by zero on its
     * first row, is answered by its Error alone, with no Description or
     * Rows (SQLSTATE 22012), and the session goes on.
     *
     * @throws TurborelException never from the statements that succeed
     */
    #[Test]
    public function aQueryFailingBeforeItsFirstBatchIsAnsweredByItsErrorAlone(): void
    {
        $connection = self::owner();
        $connection->query('CREATE TABLE Q_ZERO (N INTEGER)');
        $connection->query('INSERT INTO Q_ZERO VALUES (0), (1)');
        $connection->wire()->send(new Query('SELECT 1 / N FROM Q_ZERO ORDER BY N'));
        $reply = $connection->wire()->receive();
        self::assertInstanceOf(Error::class, $reply);
        self::assertSame('22012', $reply->failure->sqlstate);
        self::assertFailure('22012', -301, static fn() => $connection->query('SELECT 1 / N FROM Q_ZERO ORDER BY N'));
        self::assertSame([[1]], array_map(static fn(array $row): array => array_map(static fn(mixed $v): mixed => $v instanceof Exact ? (int) $v->units : $v, $row), $connection->query('SELECT N FROM Q_ZERO WHERE N = 1')->rows));
        $connection->terminate();
    }

    /**
     * Verifies: [CLIENT Running statements]: a query's rows are given to a
     * sink a batch at a time, as they come; another statement's
     * completion is given.
     *
     * @throws TurborelException never from the statements
     */
    #[Test]
    public function rowsAreGivenABatchAtATime(): void
    {
        $connection = self::owner();
        $connection->query('CREATE TABLE Q_TEN (N INTEGER)');
        $made = $connection->queryInto('INSERT INTO Q_TEN VALUES (0), (1), (2), (3), (4), (5), (6), (7), (8), (9)', new Counter());
        self::assertSame([0, 10], [$made->rows, $made->completed?->rows()]);
        $counter = new Counter();
        $streamed = $connection->queryInto('SELECT A.N FROM Q_TEN A, Q_TEN B, Q_TEN C, Q_TEN D', $counter);
        self::assertSame([10_000, 10_000, null, ['N']], [$streamed->rows, $counter->rows, $streamed->completed, $counter->columns]);
        self::assertGreaterThan(1, $counter->batches);
        self::assertFailure('2A000', -103, static fn() => $connection->queryInto('SELECT * FROM NOPE', new Counter()));
        $connection->terminate();
    }

    /**
     * Verifies: [CLIENT Running statements] [PROTOCOL Values]: every kind of
     * value comes: exact, approximate, character, binary, datetime, a
     * date's instant that of its midnight from 0001-01-01, interval, a
     * plug-in's (UUID), null.
     *
     * @throws TurborelException never from the statement
     */
    #[Test]
    public function everyKindOfValueComes(): void
    {
        $connection = self::owner();
        $row = $connection->query("VALUES (12.50, 1.5E0, 'tea', X'0A0B', DATE '2026-11-19', INTERVAL '3' DAY, CAST('919108f7-52d1-4320-9bac-f847db4148a8' AS UUID), CAST(NULL AS INTEGER))")->rows[0] ?? [];
        [$exact, $approximate, $character, $binary, $date, $interval, $uuid, $null] = $row + array_fill(0, 8, null);
        self::assertInstanceOf(Exact::class, $exact);
        self::assertSame(['1250', 2], [$exact->units, $exact->scale]);
        self::assertSame([1.5, 'tea', null], [$approximate, $character, $null]);
        self::assertInstanceOf(Binary::class, $binary);
        self::assertSame("\x0A\x0B", $binary->octets);
        self::assertInstanceOf(Datetime::class, $date);
        self::assertSame((self::TO_1970 + (int) gmmktime(0, 0, 0, 11, 19, 2026)) * 1_000_000, $date->utcMicros, 'microseconds from 0001-01-01');
        self::assertInstanceOf(Interval::class, $interval);
        self::assertInstanceOf(Uuid::class, $uuid);
        self::assertSame('919108f7-52d1-4320-9bac-f847db4148a8', (string) $uuid);
        $connection->terminate();
    }

    /**
     * Verifies: [CLIENT Running statements] [PROTOCOL Query 'Q']
     * [PROTOCOL Conversations]: a transaction begins with the first
     * statement and lasts until COMMIT or ROLLBACK, as the connection tells;
     * under autocommit each statement is its own, by `ends`, with no
     * COMMIT sent, and a commit that fails sends its Error in place of the
     * Complete, no transaction active after.
     *
     * @throws TurborelException never from the statements that succeed
     */
    #[Test]
    public function aTransactionLastsUntilItEndsUnlessEachStatementIsOne(): void
    {
        $connection = self::owner();
        $other = self::owner();
        $connection->query('CREATE TABLE Q_TX (N INTEGER)');
        $connection->query('INSERT INTO Q_TX VALUES (1)');
        self::assertTrue($connection->inTransaction());
        $connection->query('ROLLBACK');
        self::assertFalse($connection->inTransaction());
        self::assertFailure('2A000', -103, static fn() => $other->query('SELECT N FROM Q_TX'));
        $connection->setAutocommit(true);
        $connection->query('CREATE TABLE Q_TX (N INTEGER)');
        $connection->query('INSERT INTO Q_TX VALUES (1)');
        self::assertFalse($connection->inTransaction());
        self::assertCount(1, $other->query('SELECT N FROM Q_TX')->rows);
        $connection->query('CREATE TABLE Q_PARENT (K INTEGER PRIMARY KEY)');
        $connection->query('CREATE TABLE Q_CHILD (K INTEGER REFERENCES Q_PARENT DEFERRABLE INITIALLY DEFERRED)');
        self::assertFailure('40002', -913, static fn() => $connection->query('INSERT INTO Q_CHILD VALUES (9)'));
        self::assertFalse($connection->inTransaction());
        $other->terminate();
        $connection->terminate();
    }

    /**
     * Verifies: [PROTOCOL Query 'Q'] [PROTOCOL Values]: a result that sent a
     * large object by its locator keeps its transaction, under autocommit
     * too, since the locator lasts only as long as it does.
     *
     * @throws TurborelException never from the statements
     */
    #[Test]
    public function aLocatorKeepsItsTransaction(): void
    {
        $connection = self::owner();
        $connection->setAutocommit(true);
        $connection->query('CREATE TABLE Q_BIG (C CLOB)');
        $connection->execute($connection->prepare('INSERT INTO Q_BIG VALUES (?)'), [str_repeat('x', 70_000)]);
        self::assertFalse($connection->inTransaction());
        self::assertInstanceOf(LobLocator::class, $connection->query('SELECT C FROM Q_BIG')->rows[0][0] ?? null);
        self::assertTrue($connection->inTransaction());
        $connection->query('COMMIT');
        self::assertFalse($connection->inTransaction());
        $connection->terminate();
    }

    /**
     * Verifies: [CLIENT Controlling the session] [PROTOCOL Query 'Q']
     * [PROTOCOL Capabilities] [PROTOCOL Complete 'K']: granted
     * `multi-statement`, a text of several statements runs one by one as
     * one request, each outcome given, each Complete but the last saying
     * more follow, until the first that fails, whose failure is given; a text of no statement completes once; not granted,
     * it fails before anything is sent.
     *
     * @throws TurborelException never from the statements
     */
    #[Test]
    public function severalStatementsRunAsOneRequest(): void
    {
        $connection = self::owner();
        $ran = $connection->queryMany('CREATE TABLE Q_MANY (N INTEGER); INSERT INTO Q_MANY VALUES (1); SELECT N FROM Q_MANY; SELECT * FROM NOPE; INSERT INTO Q_MANY VALUES (2)');
        self::assertCount(3, $ran->outcomes);
        self::assertSame([true, true, true], array_map(static fn($outcome): bool => $outcome->completed?->more() ?? false, $ran->outcomes));
        self::assertSame('2A000', $ran->failure?->getSqlState());
        self::assertSame([['1']], array_map(static fn(array $row): array => [self::units($row[0] ?? null)], $connection->query('SELECT N FROM Q_MANY')->rows));
        $empty = $connection->queryMany('');
        self::assertSame([1, '00000', null], [\count($empty->outcomes), ($empty->outcomes[0] ?? null)?->completed?->sqlstate(), $empty->failure]);
        $connection->terminate();
        $without = Connection::connect(self::tcp(), 'ann', new Trust(), null, new Offer(array_values(array_diff(Defaults::CAPABILITIES, [Capability::MULTI_STATEMENT]))));
        self::assertConnectionFails(static fn() => $without->queryMany('VALUES 1; VALUES 2'));
        self::assertFalse($without->isLost());
        $without->terminate();
    }

    /** The units of `$value`, an exact number. */
    private static function units(mixed $value): string
    {
        self::assertInstanceOf(Exact::class, $value);

        return $value->units;
    }
}

<?php

declare(strict_types=1);

namespace Turborel\Tests\Live\Statements;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\CoversNamespace;
use PHPUnit\Framework\Attributes\Test;
use Turborel\Internal\Codec\Reply\Error;
use Turborel\Internal\Codec\Request\Prepare;
use Turborel\Session\Capability;
use Turborel\Session\Connection;
use Turborel\Session\Defaults;
use Turborel\Session\Offer;
use Turborel\Session\Trust;
use Turborel\Statements\Batch;
use Turborel\Tests\Support\LiveTestCase;
use Turborel\TurborelException;
use Turborel\Type\CharacterType;
use Turborel\Type\Column;
use Turborel\Type\ExactType;
use Turborel\Type\Parameter;
use Turborel\Type\ParameterMode;
use Turborel\Value\Binary;
use Turborel\Value\Exact;

/**
 * Prepared statements on a live server: prepared once and executed with
 * values, their cursors opened, fetched and closed, described again, and
 * deallocated.
 */
#[CoversNamespace('Turborel\Session')]
#[CoversNamespace('Turborel\Internal')]
#[CoversNamespace('Turborel\Statements')]
#[CoversNamespace('Turborel\Value')]
#[CoversNamespace('Turborel\Type')]
#[CoversClass(TurborelException::class)]
final class PreparingTest extends LiveTestCase
{
    /**
     * Verifies: [CLIENT Prepared statements] [PROTOCOL Prepare 'P']
     * [PROTOCOL Prepared 'P'] [PROTOCOL Execute 'E']: a statement is
     * prepared once, its parameters written `?`, the type each takes and a
     * query's columns given; executed with values as many times as wanted,
     * across transactions; values not one for each parameter fail
     * (SQLSTATE 07001).
     *
     * @throws TurborelException never from the statements that succeed
     */
    #[Test]
    public function aStatementIsPreparedOnceAndExecutedOften(): void
    {
        $connection = self::staff('P_STAFF');
        $byId = $connection->prepare('SELECT NAME FROM P_STAFF WHERE ID = ?');
        self::assertCount(1, $byId->parameters);
        self::assertInstanceOf(ExactType::class, $byId->parameters[0]->type);
        self::assertSame(['NAME'], array_map(static fn(Column $column): ?string => $column->name, $byId->columns));
        self::assertInstanceOf(CharacterType::class, $byId->columns[0]->type ?? null);
        self::assertSame([['Grace']], $connection->execute($byId, [2])->rows);
        $connection->query('COMMIT');
        self::assertSame([['Ada']], $connection->execute($byId, [new Exact('1', 0)])->rows);
        $connection->query('ROLLBACK');
        $insert = $connection->prepare('INSERT INTO P_STAFF VALUES (?, ?)');
        self::assertSame([], $insert->columns);
        $done = $connection->execute($insert, [9, 'Edsger']);
        self::assertSame([false, 0, 1], [$done->isRows(), $done->completed?->sqlcode(), $done->completed?->rows()]);
        self::assertFailure('07001', -921, static fn() => $connection->execute($insert, [10]));
        $connection->terminate();
    }

    /**
     * Verifies: [PROTOCOL Prepared 'P'] [PROTOCOL Execute 'E'] [CLIENT Prepared statements]:
     * a CALL's parameters are its inputs alone, and its columns one for each
     * `?` of an OUT or INOUT parameter, in the procedure's order, an INOUT
     * one both; executed, it sends one row of those parameters' values
     * before its Complete, which is the CALL's outcome. Granted
     * `parameter-modes`, each `?` gives its mode and its parameter's
     * position, the procedure its schema; another statement's are IN.
     *
     * @throws TurborelException never from the statements
     */
    #[Test]
    public function aCallGivesItsOutputParametersAsARow(): void
    {
        $connection = self::owner();
        $connection->query('CREATE PROCEDURE P_OUT(IN A INTEGER, OUT B INTEGER, INOUT C INTEGER) LANGUAGE SQL MODIFIES SQL DATA '
            . 'BEGIN SET B = A * 2; SET C = C + 1; END');
        $call = $connection->prepare('CALL P_OUT(?, ?, ?)');
        self::assertCount(2, $call->parameters);
        self::assertSame(['B', 'C'], array_map(static fn(Column $column): ?string => $column->name, $call->columns));
        $outcome = $connection->execute($call, [3, 10]);
        self::assertSame([['6', '11'], 0], [array_map(static fn(mixed $value): string => $value instanceof Exact ? $value->units : '', $outcome->rows[0] ?? []), $outcome->completed?->sqlcode()]);
        $modes = array_map(static fn(Parameter $p): array => [$p->mode, $p->ordinal], $call->dynamicParameters());
        self::assertSame([[ParameterMode::In, 1], [ParameterMode::Out, 2], [ParameterMode::InOut, 3]], $modes);
        self::assertSame('ANN', $call->called?->schema);
        $plain = $connection->prepare('VALUES CAST(? AS INTEGER)');
        self::assertSame([null, [[ParameterMode::In, null]]], [$plain->called, array_map(static fn(Parameter $p): array => [$p->mode, $p->ordinal], $plain->dynamicParameters())]);
        $connection->terminate();
    }

    /**
     * Verifies: [CLIENT Prepared statements] [PROTOCOL Execute 'E']
     * [PROTOCOL Fetch 'F'] [PROTOCOL Close 'C'] [PROTOCOL Suspended 'S']:
     * a cursor is opened and gives its first rows, then Suspended while it
     * has more; a fetch gives the next batch, the last ending it with a
     * Complete; a cursor already open, or not open, fails (SQLSTATE 24000).
     *
     * @throws TurborelException never from the statements that succeed
     */
    #[Test]
    public function aCursorIsReadABatchAtATime(): void
    {
        $connection = self::staff('P_CURSOR');
        $all = $connection->prepare('SELECT ID FROM P_CURSOR ORDER BY ID');
        self::assertSame([['1'], true], self::read($connection->openCursor($all, [], 1)));
        self::assertFailure('24000', -501, static fn() => $connection->openCursor($all));
        self::assertSame([['2'], true], self::read($connection->fetch($all, 1)));
        self::assertSame([['3'], false], self::read($connection->fetch($all)));
        self::assertFailure('24000', -501, static fn() => $connection->fetch($all));
        $connection->openCursor($all, [], 1);
        $connection->close($all);
        self::assertFailure('24000', -501, static fn() => $connection->close($all));
        $connection->terminate();
    }

    /**
     * Verifies: [CLIENT Controlling the session] [PROTOCOL Execute 'E']
     * [PROTOCOL Describe 'Y'] [PROTOCOL Description 'D'] [PROTOCOL Capabilities]:
     * granted `cursors`, a statement has several numbered cursors open at
     * once; granted `describe`, an open cursor's columns are described, and
     * the statement again; neither granted, they fail before anything is
     * sent.
     *
     * @throws TurborelException never from the statements that succeed
     */
    #[Test]
    public function numberedCursorsAreOpenAtOnceAndDescribed(): void
    {
        $connection = self::staff('P_NUMBERED');
        $all = $connection->prepare('SELECT ID, NAME FROM P_NUMBERED ORDER BY ID');
        self::assertSame([['1'], true], self::read($connection->openCursor($all, [], 1, 1)));
        self::assertSame([['1'], true], self::read($connection->openCursor($all, [], 1, 2)));
        self::assertSame([['2'], true], self::read($connection->fetch($all, 1, 1)));
        self::assertSame(['ID', 'NAME'], array_map(static fn(Column $column): ?string => $column->name, $connection->describeCursor($all, 2)));
        $connection->close($all, 1);
        self::assertSame([['2'], true], self::read($connection->fetch($all, 1, 2)));
        $connection->close($all, 2);
        self::assertFailure('24000', -501, static fn() => $connection->describeCursor($all, 2));
        $again = $connection->describe($all);
        self::assertEquals([$all->number, $all->parameters, $all->columns], [$again->number, $again->parameters, $again->columns]);
        $connection->terminate();
        $without = Connection::connect(self::tcp(), 'ann', new Trust(), null, new Offer(array_values(array_diff(Defaults::CAPABILITIES, [Capability::CURSORS, Capability::DESCRIBE]))));
        $one = $without->prepare('VALUES 1');
        self::assertConnectionFails(static fn() => $without->openCursor($one, [], 0, 1));
        self::assertConnectionFails(static fn() => $without->describe($one));
        self::assertSame([['1'], false], self::read($without->openCursor($one)));
        $without->terminate();
    }

    /**
     * Verifies: [CLIENT Prepared statements] [PROTOCOL Deallocate 'D']
     * [PROTOCOL Prepare 'P'] [PROTOCOL Describe 'Y']: a statement whose
     * cursor is open is neither deallocated nor replaced (SQLSTATE 24000);
     * deallocated, it is no more (SQLSTATE 26000).
     *
     * @throws TurborelException never from the statements that succeed
     */
    #[Test]
    public function aStatementIsDeallocatedOnceItsCursorIsClosed(): void
    {
        $connection = self::staff('P_GONE');
        $all = $connection->prepare('SELECT ID FROM P_GONE');
        $connection->openCursor($all, [], 1);
        self::assertFailure('24000', -501, static fn() => $connection->deallocate($all));
        $connection->wire()->send(new Prepare($all->number, 'VALUES 1'));
        $replaced = $connection->wire()->receive();
        self::assertInstanceOf(Error::class, $replaced);
        self::assertSame('24000', $replaced->failure->sqlstate);
        $connection->close($all);
        $connection->deallocate($all);
        self::assertFailure('26000', -921, static fn() => $connection->describe($all));
        self::assertFailure('26000', -921, static fn() => $connection->deallocate($all));
        $connection->terminate();
    }

    /**
     * Verifies: [CLIENT What the server tells] [PROTOCOL Execute 'E']
     * [PROTOCOL Fetch 'F'] [PROTOCOL Conversations] [PROTOCOL Capabilities]:
     * under autocommit, granted `fetch-ends`, a cursor's transaction ends as
     * it does: the fetch of its last row commits, and so does its close;
     * not granted, the transaction outlasts it.
     *
     * @throws TurborelException never from the statements
     */
    #[Test]
    public function underAutocommitACursorsTransactionEndsWithIt(): void
    {
        $connection = self::staff('P_ENDS');
        $connection->query('COMMIT');
        $connection->setAutocommit(true);
        $all = $connection->prepare('SELECT ID FROM P_ENDS');
        $connection->openCursor($all, [], 1);
        self::assertTrue($connection->inTransaction());
        $connection->fetch($all);
        self::assertFalse($connection->inTransaction());
        $connection->openCursor($all, [], 1);
        $connection->close($all);
        self::assertFalse($connection->inTransaction());
        $connection->terminate();
        $without = Connection::connect(self::tcp(), 'ann', new Trust(), null, new Offer(array_values(array_diff(Defaults::CAPABILITIES, [Capability::FETCH_ENDS]))));
        $without->setAutocommit(true);
        $again = $without->prepare('SELECT ID FROM P_ENDS');
        $without->openCursor($again, [], 1);
        $without->fetch($again);
        self::assertTrue($without->inTransaction());
        $without->terminate();
    }

    /**
     * Verifies: [CLIENT Prepared statements] [PROTOCOL Declared types]:
     * granted `declared-types`, which connecting asks for, a column gives
     * the type it was declared with, INTEGER of an exact numeric type
     * written as precision 10 and scale 0, and its type written as
     * declared; not granted, none, its type written as its tag's.
     *
     * @throws TurborelException never from the statements
     */
    #[Test]
    public function aColumnGivesTheTypeItWasDeclaredWith(): void
    {
        $connection = self::staff('P_DECLARED');
        [$id, $name] = $connection->prepare('SELECT ID, NAME FROM P_DECLARED')->columns + [null, null];
        self::assertSame(['INTEGER', 'INTEGER', 'CHARACTER VARYING(20)'], [$id?->declared?->name, $id?->typeWritten(), $name?->typeWritten()]);
        self::assertSame('INTEGER', ($connection->query('SELECT ID FROM P_DECLARED')->columns[0] ?? null)?->declared?->name);
        $connection->query('COMMIT');
        $offer = new Offer(array_values(array_diff(Defaults::CAPABILITIES, [Capability::DECLARED_TYPES])));
        $without = Connection::connect(self::socket(), 'ann', new Trust(), null, $offer);
        $bare = $without->prepare('SELECT ID FROM P_DECLARED')->columns[0] ?? null;
        self::assertSame([null, 'NUMERIC(10,0)'], [$bare?->declared, $bare?->typeWritten()]);
        $connection->terminate();
        $without->terminate();
    }

    /**
     * Verifies: [CLIENT Prepared statements] [PROTOCOL Execute 'E']: each
     * `?` of an INSERT's rows takes its object column's type, so large
     * objects beside short literals are stored whole; a `?` cut to its
     * type completes with warning 01004, its value cut, an INSERT's, and a
     * query's compared with a column, with ORDER BY or without.
     *
     * @throws TurborelException never from the statements
     */
    #[Test]
    public function aParameterTakesItsColumnsTypeAndWarnsWhenCut(): void
    {
        $connection = self::owner();
        $connection->query('CREATE TABLE P_ROWS (N INTEGER, BODY CLOB(1M), PIC BLOB(4M))');
        $insert = $connection->prepare("INSERT INTO P_ROWS VALUES (1, 'short', X'0102'), (2, ?, ?)");
        $connection->execute($insert, [str_repeat('c', 80_000), new Binary(str_repeat("\x05", 70_000))]);
        $lengths = $connection->query('SELECT CHAR_LENGTH(BODY), OCTET_LENGTH(PIC) FROM P_ROWS WHERE N = 2')->rows[0] ?? [];
        self::assertSame(['80000', '70000'], array_map(static fn(mixed $v): string => $v instanceof Exact ? $v->units : '', $lengths));
        $connection->query('CREATE TABLE P_CUT (V VARCHAR(5))');
        $connection->execute($connection->prepare('INSERT INTO P_CUT VALUES (?)'), ['abcdefgh']);
        self::assertSame(['01004', [['abcde']]], [$connection->warning(), $connection->query('SELECT V FROM P_CUT')->rows]);
        foreach (['SELECT V FROM P_CUT WHERE V = ?', 'SELECT V FROM P_CUT WHERE V = ? ORDER BY V'] as $sql) {
            $found = $connection->execute($connection->prepare($sql), ['abcdefgh']);
            self::assertSame([[['abcde']], '01004'], [$found->rows, $connection->warning()], $sql);
        }
        $connection->terminate();
    }

    /**
     * A connection as ANN in which `$table` holds Ada, Grace and Barbara, numbered 1 to 3.
     *
     * @throws TurborelException if it cannot be made
     */
    private static function staff(string $table): Connection
    {
        $connection = self::owner();
        $connection->query("CREATE TABLE {$table} (ID INTEGER, NAME VARCHAR(20))");
        $connection->query("INSERT INTO {$table} VALUES (1, 'Ada'), (2, 'Grace'), (3, 'Barbara')");

        return $connection;
    }

    /**
     * The first value of each row of `$batch`, an exact number's units, and whether the cursor has more.
     *
     * @return array{list<string>, bool}
     */
    private static function read(Batch $batch): array
    {
        return [array_map(static function (array $row): string {
            $value = $row[0] ?? null;
            self::assertInstanceOf(Exact::class, $value);

            return $value->units;
        }, $batch->rows), $batch->more];
    }
}

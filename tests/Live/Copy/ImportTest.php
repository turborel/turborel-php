<?php

declare(strict_types=1);

namespace Turborel\Tests\Live\Copy;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\CoversNamespace;
use PHPUnit\Framework\Attributes\Test;
use Turborel\Copy\Delimited;
use Turborel\Copy\Dialect;
use Turborel\Copy\Import;
use Turborel\Copy\ImportFailure;
use Turborel\Copy\Position;
use Turborel\Copy\Rejected;
use Turborel\Session\Capability;
use Turborel\Session\Connection;
use Turborel\Session\Defaults;
use Turborel\Session\Offer;
use Turborel\Session\Trust;
use Turborel\Tests\Support\LiveTestCase;
use Turborel\TurborelException;
use Turborel\Value\Exact;
use Turborel\Value\LobLocator;

/**
 * A source's rows imported into a live server's tables, by copy where the
 * server grants it, else through a prepared INSERT.
 */
#[CoversNamespace('Turborel\Session')]
#[CoversNamespace('Turborel\Internal')]
#[CoversNamespace('Turborel\Statements')]
#[CoversNamespace('Turborel\Value')]
#[CoversNamespace('Turborel\Copy')]
#[CoversNamespace('Turborel\Lobs')]
#[CoversClass(TurborelException::class)]
#[CoversNamespace('Turborel\Type')]
final class ImportTest extends LiveTestCase
{
    /**
     * Verifies: [CLIENT Importing] [CLIENT Copying and exporting]: a CSV
     * source's rows go into the columns its header names, by copy where
     * the server grants it, a batch committed at a time; a row refused
     * stops the import, its record and the rows committed and rolled back
     * told, the failure's detail of the copy naming the row.
     *
     * @throws TurborelException never from the imports that succeed
     */
    #[Test]
    public function aCsvSourceIsCopiedABatchAtATime(): void
    {
        $connection = self::table('I_COPY');
        $done = (new Import('I_COPY', 2))->run($connection, self::csv("n,\"S\"\n1,a\n2,b\n3,c\n"));
        self::assertSame([3, 0, false], [$done->rows, $done->rejected, $connection->inTransaction()]);
        try {
            (new Import('I_COPY', 2))->run($connection, self::csv("N,S\n4,d\n5,e\n6,f\n4,g\n"));
            self::fail('a duplicate key');
        } catch (ImportFailure $stopped) {
            self::assertEquals([new Position(4, 5), '23000', false, 2, 1], [$stopped->position, $stopped->getSqlState(), $stopped->input, $stopped->committed, $stopped->rolledBack]);
        }
        self::assertSame(5, self::rowsOf($connection, 'I_COPY'));
        $connection->terminate();
    }

    /**
     * Verifies: [CLIENT Importing]: through a prepared INSERT, a source with
     * no header gives the table's columns in order; a row that fails is
     * skipped and given to the program, if asked, as is a record of too
     * many fields or a string too long, and the import goes on; a field
     * longer than 64 KiB for a CLOB is sent by a large object made for it.
     *
     * @throws TurborelException never from the imports that succeed
     */
    #[Test]
    public function badRowsAreSkippedWhereAsked(): void
    {
        $connection = self::owner();
        $connection->query('CREATE TABLE I_SKIP (N INTEGER PRIMARY KEY, S VARCHAR(5), BODY CLOB(1M))');
        $connection->query('COMMIT');
        $long = str_repeat('z', 70_000);
        $skipped = [];
        $done = (new Import('I_SKIP', skip: true))->run($connection, self::csv("1,a,x\n1,b,y\n2,toolong,z\n3,c\n4,d,{$long}\n"), static function (Rejected $row) use (&$skipped): void {
            $skipped[] = [$row->position->record, $row->cause->getSqlState(), $row->input];
        });
        self::assertSame([2, 3, [[2, '23000', false], [3, '22001', false], [4, '22000', true]]], [$done->rows, $done->rejected, $skipped]);
        $body = $connection->query('SELECT BODY FROM I_SKIP WHERE N = 4')->rows[0][0] ?? null;
        self::assertInstanceOf(LobLocator::class, $body);
        self::assertSame(70_000, $body->octets);
        $connection->terminate();
    }

    /**
     * Verifies: [CLIENT Importing]: an import begins with no transaction
     * active; a table not named right, or not there, a record of too few
     * fields, or one the source cannot read stops it, saying why and where;
     * through INSERT, the batch under way is rolled back.
     *
     * @throws TurborelException never from the statements that succeed
     */
    #[Test]
    public function anImportStopsSayingWhyAndWhere(): void
    {
        $connection = self::table('I_STOP');
        $connection->query('VALUES 1');
        self::assertFailure('22000', -301, static fn() => Import::into('I_STOP')->run($connection, self::csv("1,a\n")));
        $connection->query('ROLLBACK');
        self::assertFailure('22000', -301, static fn() => Import::into('I_STOP.')->run($connection, self::csv("1,a\n")));
        self::assertFailure('42000', -103, static fn() => Import::into('I_NOPE')->run($connection, self::csv("1,a\n")));
        $without = Connection::connect(self::tcp(), 'ann', new Trust(), null, new Offer(array_values(array_diff(Defaults::CAPABILITIES, [Capability::COPY]))));
        try {
            (new Import('I_STOP', 2))->run($without, self::csv("1,a\n2,b\n3,c\n4\n"));
            self::fail('too few fields');
        } catch (ImportFailure $stopped) {
            self::assertSame(['record 4, line 4: 1 fields, for 2 columns; 2 rows committed, 1 rolled back', true], [$stopped->getMessage(), $stopped->input]);
        }
        try {
            Import::into('I_STOP')->run($without, self::csv("5,e\n6,\"f\n"));
            self::fail('a quote left open');
        } catch (ImportFailure $stopped) {
            self::assertEquals([new Position(2, 2), 0, 1], [$stopped->position, $stopped->committed, $stopped->rolledBack]);
        }
        self::assertSame(2, self::rowsOf($without, 'I_STOP'));
        $without->terminate();
        $connection->terminate();
    }

    /**
     * Verifies: [CLIENT Importing] [CLIENT Copying and exporting]: by copy
     * too, a record of too many fields, a string too long for its column, a
     * record the source cannot read, or a header missing stops the import
     * at its record, the copy abandoned; an empty source imports nothing;
     * through INSERT, a commit that fails stops it, its batch rolled back.
     *
     * @throws TurborelException never from the statements that succeed
     */
    #[Test]
    public function aCopiedImportStopsAtItsRecord(): void
    {
        $connection = self::table('I_CSTOP');
        $stops = ["N,S\n1,a\n2,b,c\n" => [2, true], "N,S\n1,a\n2,toolong\n" => [2, false], "N,S\n1,a\n2,\"b\n" => [2, true]];
        foreach ($stops as $text => [$record, $input]) {
            try {
                Import::into('I_CSTOP')->run($connection, self::csv($text));
                self::fail('a bad record');
            } catch (ImportFailure $stopped) {
                self::assertSame([$record, $input, 0, 1], [$stopped->position?->record, $stopped->input, $stopped->committed, $stopped->rolledBack]);
            }
        }
        self::assertSame([0, 0], [Import::into('I_CSTOP')->run($connection, self::csv("N,S\n"))->rows, self::rowsOf($connection, 'I_CSTOP')]);
        self::assertFailure('22000', -301, static fn() => Import::into('I_CSTOP')->run($connection, new Delimited(self::stream(''), Dialect::csv()->withHeader())));
        $connection->query('CREATE TABLE I_PARENT (K INTEGER PRIMARY KEY)');
        $connection->query('CREATE TABLE I_CHILD (K INTEGER REFERENCES I_PARENT DEFERRABLE INITIALLY DEFERRED)');
        $connection->query('COMMIT');
        try {
            (new Import('I_CHILD', skip: true))->run($connection, self::csv("1\n2\n"));
            self::fail('a deferred constraint');
        } catch (ImportFailure $stopped) {
            self::assertSame(['40002', null, 0, 2], [$stopped->getSqlState(), $stopped->position, $stopped->committed, $stopped->rolledBack]);
        }
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
     * The rows of `$table`.
     *
     * @throws TurborelException never, the table being there
     */
    private static function rowsOf(Connection $connection, string $table): int
    {
        $count = $connection->query("SELECT COUNT(*) FROM {$table}")->rows[0][0] ?? null;
        $connection->query('COMMIT');

        return $count instanceof Exact ? (int) $count->units : -1;
    }

    /** A CSV source of `$text`, with a header if its first line names N. */
    private static function csv(string $text): Delimited
    {
        $dialect = preg_match('/^n,/i', $text) === 1 ? Dialect::csv()->withHeader() : Dialect::csv();

        return new Delimited(self::stream($text), $dialect);
    }

    /**
     * A stream holding `$text`.
     *
     * @return resource
     */
    private static function stream(string $text): mixed
    {
        $stream = fopen('php://memory', 'w+b');
        self::assertIsResource($stream);
        fwrite($stream, $text);
        rewind($stream);

        return $stream;
    }
}

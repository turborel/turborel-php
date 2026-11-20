<?php

declare(strict_types=1);

namespace Turborel\Tests\Live\Statements;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\CoversNamespace;
use PHPUnit\Framework\Attributes\Test;
use Turborel\Session\Capability;
use Turborel\Session\Connection;
use Turborel\Session\Defaults;
use Turborel\Session\Notice;
use Turborel\Session\Offer;
use Turborel\Session\Trust;
use Turborel\Tests\Support\LiveTestCase;
use Turborel\TurborelException;

/**
 * What a live server tells beside a statement's rows: its completion's
 * counts, generated values, time and commit position; the notices and
 * conditions it raised; the attributes each request carries; and what a
 * reset keeps and drops.
 */
#[CoversNamespace('Turborel\Session')]
#[CoversNamespace('Turborel\Internal')]
#[CoversNamespace('Turborel\Statements')]
#[CoversNamespace('Turborel\Value')]
#[CoversNamespace('Turborel\Type')]
#[CoversClass(TurborelException::class)]
final class ToldTest extends LiveTestCase
{
    /** A query that completes with warning 01003, a null value eliminated in a set function. */
    private const string WARNS = 'SELECT SUM(N) FROM (VALUES (1), (CAST(NULL AS INTEGER))) AS T(N)';

    /**
     * Verifies: [CLIENT What the server tells] [PROTOCOL Complete 'K']
     * [PROTOCOL Capabilities]: granted `row-counts`, a completion gives the
     * rows a statement changed, or a query returned, and the server's time
     * for it; granted `generated-values`, the identity values an INSERT
     * generated, in the order of its rows; granted `commit-position`, the
     * log position of each commit, the last kept.
     *
     * @throws TurborelException never from the statements
     */
    #[Test]
    public function aCompletionCountsTimesAndPlacesItsStatement(): void
    {
        $connection = self::owner();
        $connection->setAutocommit(true);
        $connection->query('CREATE TABLE T_STAFF (ID INTEGER GENERATED ALWAYS AS IDENTITY, NAME VARCHAR(20))');
        $connection->query("INSERT INTO T_STAFF (NAME) VALUES ('Edsger'), ('Barbara')");
        $inserted = $connection->completed();
        self::assertSame([2, ['1', '2']], [$inserted?->rows(), $inserted?->generated()]);
        self::assertGreaterThanOrEqual(0, $inserted?->time()?->total);
        $first = $connection->commitPosition();
        self::assertNotNull($first);
        $connection->query("INSERT INTO T_STAFF (NAME) VALUES ('Grace')");
        self::assertGreaterThan($first, $connection->commitPosition());
        $connection->query('SELECT NAME FROM T_STAFF');
        self::assertSame([3, []], [$connection->completed()?->rows(), $connection->completed()?->generated()]);
        $connection->terminate();
        $without = Connection::connect(self::tcp(), 'ann', new Trust(), null, Offer::none());
        $without->query("INSERT INTO T_STAFF (NAME) VALUES ('Alan')");
        self::assertSame([null, [], null], [$without->completed()?->rows(), $without->completed()?->generated(), $without->completed()?->time()]);
        $without->terminate();
    }

    /**
     * Verifies: [CLIENT What the server tells] [PROTOCOL Errors and notices]
     * [PROTOCOL Complete 'K'] [PROTOCOL Notice 'N'] [PROTOCOL Capabilities]:
     * granted `conditions`, a statement's warnings come in its completion,
     * taken once, its warning's SQLSTATE kept; granted `notices` alone,
     * they come as notices, kept up to 64 or handed to a handler before
     * the statement's Complete, each of severity WARNING; granted neither,
     * not at all; a failing statement's come as notices.
     *
     * @throws TurborelException never from the statements that succeed
     */
    #[Test]
    public function warningsComeOneWayAsGranted(): void
    {
        $connection = self::owner();
        $connection->query(self::WARNS);
        self::assertSame('01003', $connection->warning());
        $conditions = $connection->takeConditions();
        self::assertSame(
            [['01003', 'the statement completed with warning: null value eliminated in set function']],
            array_map(static fn(TurborelException $condition): array => [$condition->getSqlState(), $condition->getMessage()], $conditions),
        );
        self::assertSame([[], []], [$connection->takeConditions(), $connection->takeNotices()]);
        $connection->query('CREATE PROCEDURE T_FAILS() LANGUAGE SQL MODIFIES SQL DATA BEGIN '
            . "SIGNAL SQLSTATE '01999' SET MESSAGE_TEXT = 'w'; SIGNAL SQLSTATE '22999' SET MESSAGE_TEXT = 'e'; END");
        self::assertFailure('22999', -401, static fn() => $connection->query('CALL T_FAILS()'));
        self::assertSame(['01999'], array_map(static fn(Notice $notice): string => $notice->sqlState, $connection->takeNotices()));
        $connection->terminate();
        $notified = Connection::connect(self::tcp(), 'ann', new Trust(), null, new Offer(array_values(array_diff(Defaults::CAPABILITIES, [Capability::CONDITIONS]))));
        $notified->query(self::WARNS);
        $notice = $notified->takeNotices()[0] ?? null;
        self::assertSame(['01003', 'WARNING', []], [$notice?->sqlState, $notice?->severity(), $notified->takeConditions()]);
        $heard = [];
        $notified->setNoticeHandler(static function (Notice $notice) use ($notified, &$heard): void {
            $heard[] = [$notice->sqlState, $notified->completed()?->sqlstate()];
        });
        $notified->query('VALUES 1');
        $notified->query(self::WARNS);
        self::assertSame([[['01003', '00000']], []], [$heard, $notified->takeNotices()]);
        $notified->terminate();
        $neither = Connection::connect(self::tcp(), 'ann', new Trust(), null, Offer::none());
        $neither->query(self::WARNS);
        self::assertSame([[], []], [$neither->takeNotices(), $neither->takeConditions()]);
        $neither->terminate();
    }

    /**
     * Verifies: [CLIENT Controlling the session] [PROTOCOL Reset 'W']
     * [PROTOCOL Errors and notices] [PROTOCOL Conversations]: a reset rolls
     * the transaction back, told by notice 01T01 of severity NOTICE, which
     * the server sends as a notice whatever is granted; prepared statements
     * and cursors are gone, the authorization kept, and the Complete says
     * no transaction is active.
     *
     * @throws TurborelException never from the statements that succeed
     */
    #[Test]
    public function aResetRollsBackAndTellsSo(): void
    {
        $connection = self::owner();
        $connection->query('CREATE TABLE T_RESET (N INTEGER)');
        $one = $connection->prepare('VALUES 1');
        $connection->openCursor($one, [], 0, 1);
        $connection->reset();
        $notice = $connection->takeNotices()[0] ?? null;
        self::assertSame(['01T01', 'NOTICE', false], [$notice?->sqlState, $notice?->severity(), $connection->inTransaction()]);
        self::assertFailure('26000', -921, static fn() => $connection->execute($one));
        self::assertFailure('2A000', -103, static fn() => $connection->query('SELECT N FROM T_RESET'));
        self::assertSame('ANN', $connection->authorization());
        $connection->query('ROLLBACK');
        $connection->reset();
        self::assertSame([], $connection->takeNotices(), 'no transaction, no notice');
        $connection->terminate();
    }

    /**
     * Verifies: [CLIENT Controlling the session] [PROTOCOL Query 'Q']
     * [PROTOCOL Capabilities]: granted `query-attributes`, attributes set are
     * carried by every later statement, read by QUERY_ATTRIBUTE, until set
     * again; not granted, setting any fails before anything is sent.
     *
     * @throws TurborelException never from the statements
     */
    #[Test]
    public function queryAttributesAreCarriedUntilSetAgain(): void
    {
        $connection = self::owner();
        $connection->setQueryAttributes(['team' => 'tea', 'traceparent' => '00-0af7651916cd43dd8448eb211c80319c-b7ad6b7169203331-01']);
        self::assertSame([['tea']], $connection->query("VALUES QUERY_ATTRIBUTE('team')")->rows);
        self::assertSame([['tea']], $connection->execute($connection->prepare("VALUES QUERY_ATTRIBUTE('team')"))->rows);
        $connection->setQueryAttributes([]);
        self::assertSame([[null]], $connection->query("VALUES QUERY_ATTRIBUTE('team')")->rows);
        $connection->terminate();
        $without = Connection::connect(self::tcp(), 'ann', new Trust(), null, Offer::none());
        self::assertConnectionFails(static fn() => $without->setQueryAttributes(['team' => 'tea']));
        $without->setQueryAttributes([]);
        $without->terminate();
    }
}

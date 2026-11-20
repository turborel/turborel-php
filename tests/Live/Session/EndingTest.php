<?php

declare(strict_types=1);

namespace Turborel\Tests\Live\Session;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\CoversNamespace;
use PHPUnit\Framework\Attributes\Test;
use Turborel\Internal\Codec\Reply\Error;
use Turborel\Internal\Codec\Request\Query;
use Turborel\Session\Connection;
use Turborel\Session\Target;
use Turborel\Session\Timeouts;
use Turborel\Session\Trust;
use Turborel\Tests\Support\LiveTestCase;
use Turborel\TurborelException;
use Turborel\Value\Exact;

/**
 * A live session ended by Terminate, a statement cancelled from another
 * connection, and the waits a connection bounds.
 */
#[CoversNamespace('Turborel\Session')]
#[CoversNamespace('Turborel\Internal')]
#[CoversNamespace('Turborel\Statements')]
#[CoversNamespace('Turborel\Value')]
#[CoversNamespace('Turborel\Type')]
#[CoversClass(TurborelException::class)]
final class EndingTest extends LiveTestCase
{
    /** The rows of the table a long statement crosses with itself. */
    private const int ROWS = 300;

    /** A statement that runs long: 27 million rows, each tested. */
    private const string LONG = 'SELECT COUNT(*) FROM LONG A, LONG B, LONG C WHERE A.N + B.N + C.N > 0';

    /**
     * Verifies: [PROTOCOL Terminate 'X'] [CLIENT Connecting]: Terminate ends
     * the session, its transaction committed as SESSION.COMMIT_AT_END, ON,
     * says; a commit that fails then, of a deferred constraint not
     * satisfied, gives its failure (SQLSTATE 40002); the connection is lost
     * after it.
     *
     * @throws TurborelException never from the statements before Terminate
     */
    #[Test]
    public function terminateCommitsOrGivesTheFailingCommit(): void
    {
        $connection = self::owner();
        $connection->query('CREATE TABLE KEPT (N INTEGER)');
        $connection->query('INSERT INTO KEPT VALUES (7)');
        $connection->terminate();
        self::assertTrue($connection->isLost());
        $check = self::owner();
        self::assertCount(1, $check->query('SELECT N FROM KEPT')->rows);
        $check->query('CREATE TABLE PARENT (ID INTEGER PRIMARY KEY)');
        $check->query('CREATE TABLE CHILD (P INTEGER REFERENCES PARENT (ID) DEFERRABLE INITIALLY DEFERRED)');
        $check->query('COMMIT');
        $check->query('INSERT INTO CHILD VALUES (5)');
        self::assertFailure('40002', -913, $check->terminate(...));
    }

    /**
     * Verifies: [PROTOCOL Cancel 'Z'] [CLIENT Running statements]: a running
     * statement is cancelled over a connection of its own, by the key Ready
     * gave, which the server closes saying nothing; the statement fails
     * with SQLCODE -920, SQLSTATE TB920, and the session goes on; over TCP
     * and over the socket alike.
     *
     * @throws TurborelException never from setting the table up
     */
    #[Test]
    public function aRunningStatementIsCancelled(): void
    {
        self::long();
        foreach ([self::tcp(), self::socket()] as $target) {
            $connection = Connection::connect($target, 'ann', new Trust());
            $canceller = $connection->canceller();
            self::assertNotNull($canceller);
            $connection->send(new Query(self::LONG));
            // Cancelled once it runs: a cancel before it is of nothing.
            do {
                $canceller->cancel();
            } while (!$connection->wire()->stream->waiting(0.05));
            $reply = $connection->receive();
            self::assertInstanceOf(Error::class, $reply);
            self::assertSame(['TB920', -920], [$reply->failure->sqlstate, $reply->failure->sqlcode]);
            self::assertCount(1, $connection->query('VALUES 1')->rows, 'the session goes on');
            $connection->terminate();
        }
    }

    /**
     * Verifies: [CLIENT Timeouts]: a read timeout of the program's own, set
     * on a connection, loses it when the server does not answer in time
     * (SQLSTATE 08006); replies longer than the body taken lose it too.
     *
     * @throws TurborelException never from connecting
     */
    #[Test]
    public function aWaitPastItsLimitLosesTheConnection(): void
    {
        self::long();
        $connection = Connection::connect(self::tcp(), 'ann', new Trust(), new Timeouts(5.0, null));
        self::assertNull($connection->readTimeout());
        $connection->setReadTimeout(0.05);
        self::assertSame(0.05, $connection->readTimeout());
        self::assertConnectionFails(static fn() => $connection->query(self::LONG));
        self::assertTrue($connection->isLost());
        $small = self::owner();
        $small->setMaxBody(16);
        self::assertConnectionFails(static fn() => $small->query("VALUES 'a value longer than sixteen bytes'"));
        self::assertConnectionFails(static fn() => Connection::connect(Target::tcp('127.0.0.1', 1), 'ann', new Trust()));
    }

    /**
     * Makes the table LONG, of ROWS rows, if it is not there.
     *
     * @throws TurborelException never, the owner making it
     */
    private static function long(): void
    {
        $owner = self::owner();
        $count = $owner->query("SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_NAME = 'LONG'")->rows[0][0] ?? null;
        if ($count instanceof Exact && $count->units === '0') {
            $owner->query('CREATE TABLE LONG (N INTEGER)');
            $owner->query('INSERT INTO LONG VALUES ' . implode(',', array_map(static fn(int $n): string => "({$n})", range(1, self::ROWS))));
            $owner->query('COMMIT');
        }
        $owner->terminate();
    }
}

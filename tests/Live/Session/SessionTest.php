<?php

declare(strict_types=1);

namespace Turborel\Tests\Live\Session;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\CoversNamespace;
use PHPUnit\Framework\Attributes\Test;
use Turborel\Internal\ConnectionFailure;
use Turborel\Session\Capability;
use Turborel\Session\Connection;
use Turborel\Session\Defaults;
use Turborel\Session\Offer;
use Turborel\Session\Scram;
use Turborel\Session\Trust;
use Turborel\Tests\Server\LiveServer;
use Turborel\Tests\Support\LiveTestCase;
use Turborel\TurborelException;
use Turborel\Value\Exact;

/**
 * What a live connection asks for and is granted, what the server tells
 * it, and the session controlled: ping, reset, change of user.
 */
#[CoversNamespace('Turborel\Session')]
#[CoversNamespace('Turborel\Internal')]
#[CoversNamespace('Turborel\Statements')]
#[CoversNamespace('Turborel\Value')]
#[CoversNamespace('Turborel\Type')]
#[CoversClass(TurborelException::class)]
final class SessionTest extends LiveTestCase
{
    /**
     * Verifies: [CLIENT What a connection asks for] [PROTOCOL Opening a connection] [PROTOCOL Capabilities]:
     * by default a connection asks for every capability but compression,
     * and is granted those the server has, in the table's order.
     *
     * @throws TurborelException never, the server trusting local clients
     */
    #[Test]
    public function everyCapabilityButCompressionIsAskedFor(): void
    {
        $connection = self::owner();
        self::assertSame(Defaults::CAPABILITIES, $connection->granted());
        self::assertFalse($connection->has(Capability::COMPRESSION));
        $connection->terminate();
    }

    /**
     * Verifies: [PROTOCOL The Native Protocol] [PROTOCOL Capabilities] [CLIENT What a connection asks for]:
     * a connection asking for none is granted none and told no Status, the
     * protocol's first form; a method needing a capability not granted
     * fails before it sends anything (SQLSTATE 08006), the connection kept.
     *
     * @throws TurborelException never from the statements
     */
    #[Test]
    public function aConnectionAskingForNoneSeesTheFirstForm(): void
    {
        $connection = Connection::connect(self::tcp(), 'ann', new Trust(), null, Offer::none());
        self::assertSame([[], []], [$connection->granted(), $connection->settings()]);
        foreach ([$connection->ping(...), $connection->reset(...), static fn() => $connection->changeUser('bob', new Trust())] as $needing) {
            self::assertConnectionFails($needing);
        }
        self::assertFalse($connection->isLost());
        self::assertEquals([[new Exact('1', 0)]], $connection->query('VALUES 1')->rows);
        $connection->terminate();
    }

    /**
     * Verifies: [PROTOCOL Capabilities] [PROTOCOL Opening a connection] [PROTOCOL Compressed frames]:
     * asked for, compression is granted after Ready, which comes
     * uncompressed; a result past the threshold then comes compressed and
     * reads as it was.
     *
     * @throws TurborelException never from the statements
     */
    #[Test]
    public function compressionBeginsAfterReady(): void
    {
        $connection = Connection::connect(self::tcp(), 'ann', new Trust(), null, (new Offer())->compressed());
        self::assertTrue($connection->has(Capability::COMPRESSION));
        $long = str_repeat('x', 5000);
        self::assertSame([[$long]], $connection->query("VALUES '{$long}'")->rows);
        $connection->terminate();
    }

    /**
     * Verifies: [PROTOCOL Hello 'H'] [PROTOCOL Capabilities] [CLIENT What a connection asks for]:
     * a connection's attributes in Hello are read by the server, granted
     * or not, and shown in INFORMATION_SCHEMA.CONNECTION_ATTRIBUTES.
     *
     * @throws TurborelException never from the statements
     */
    #[Test]
    public function connectionAttributesAreShown(): void
    {
        foreach ([(new Offer())->attribute('app', 'php'), Offer::none()->attribute('app', 'php')] as $offer) {
            $connection = Connection::connect(self::tcp(), 'ann', new Trust(), null, $offer);
            $rows = $connection->query("SELECT ATTRIBUTE_VALUE FROM INFORMATION_SCHEMA.CONNECTION_ATTRIBUTES WHERE ATTRIBUTE_NAME = 'app'")->rows;
            self::assertContains(['php'], $rows);
            $connection->terminate();
        }
    }

    /**
     * Verifies: [PROTOCOL Status 'T'] [CLIENT What the server tells] [PROTOCOL Opening a connection]:
     * with `status`, the server tells each session setting as the session
     * begins, before Ready: AUTHORIZATION, SCHEMA, READ_ONLY,
     * SERVER_VERSION, SERVER_READ_ONLY, CATALOG and OWNER, and every
     * setting a session may set for itself; a statement that changes one
     * tells it again, and a Reset tells them all, at the database's values.
     *
     * @throws TurborelException never from the statements
     */
    #[Test]
    public function theServerTellsTheSessionsSettings(): void
    {
        $connection = self::owner();
        $told = $connection->settings();
        self::assertSame(['ANN', 'ANN', 'NO', 'turborel 0.0.0 (alpha)', 'NO', 'YES'], [$told['AUTHORIZATION'] ?? null, $told['SCHEMA'] ?? null,
            $told['READ_ONLY'] ?? null, $told['SERVER_VERSION'] ?? null, $told['SERVER_READ_ONLY'] ?? null, $told['OWNER'] ?? null]);
        self::assertArrayHasKey('CATALOG', $told);
        self::assertSame(['+00:00', 'READ COMMITTED'], [$connection->setting('SESSION.TIME_ZONE'), $connection->setting('TRANSACTION.ISOLATION')]);
        $connection->query("SET SESSION.TIME_ZONE = '+02:00'");
        self::assertSame('+02:00', $connection->setting('SESSION.TIME_ZONE'));
        $connection->reset();
        self::assertSame('+00:00', $connection->setting('SESSION.TIME_ZONE'));
        self::assertNull($connection->setting('NO.SUCH'));
        $connection->terminate();
    }

    /**
     * Verifies: [PROTOCOL Ping 'G'] [PROTOCOL Reset 'W'] [CLIENT Controlling the session]:
     * a ping is answered by Pong; a reset rolls an active transaction back,
     * telling notice 01T01, and keeps the session's user; what it rolled
     * back is gone.
     *
     * @throws TurborelException never from the statements
     */
    #[Test]
    public function aSessionIsPingedAndReset(): void
    {
        $connection = self::owner();
        $connection->ping();
        $connection->query('CREATE TABLE RESET_ME (N INTEGER)');
        self::assertTrue($connection->inTransaction());
        $connection->reset();
        self::assertFalse($connection->inTransaction());
        self::assertSame('ANN', $connection->setting('AUTHORIZATION'));
        self::assertSame('01T01', ($connection->wire()->told->notices[0] ?? null)?->sqlState);
        self::assertFailure('2A000', -103, static fn() => $connection->query('SELECT * FROM RESET_ME'));
        $connection->terminate();
    }

    /**
     * Verifies: [PROTOCOL ChangeUser 'U'] [CLIENT Controlling the session]:
     * a connection authenticated again as another user, by the same
     * Challenge and Answer steps as Hello's, is that user's new session,
     * renewed as by Reset: its transaction rolled back, told by notice
     * 01T01, none active after (RULES.md D8), under a new cancel key, with
     * the same capabilities; refused, the connection fails.
     *
     * @throws TurborelException never from the accepted change
     */
    #[Test]
    public function aConnectionChangesItsUser(): void
    {
        $connection = Connection::connect(self::socket(), 'ann', new Trust());
        $before = $connection->canceller();
        $connection->query('VALUES 1');
        self::assertTrue($connection->inTransaction());
        $granted = $connection->granted();
        $connection->changeUser('bob', new Scram(LiveServer::BOB_PASSWORD));
        self::assertSame(['BOB', 'BOB', false, $granted], [$connection->authorization(), $connection->setting('AUTHORIZATION'), $connection->inTransaction(), $connection->granted()]);
        self::assertSame('01T01', ($connection->takeNotices()[0] ?? null)?->sqlState);
        self::assertNotEquals($before, $connection->canceller(), 'a new cancel key');
        self::assertFailure(ConnectionFailure::REFUSED, ConnectionFailure::SQLCODE, static fn() => $connection->changeUser('ann', new Scram('wrong')));
        self::assertTrue($connection->isLost());
        self::assertFailure(ConnectionFailure::REFUSED, ConnectionFailure::SQLCODE, static fn() => $connection->ping());
    }
}

<?php

declare(strict_types=1);

namespace Turborel\Tests\Live\Copy;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\CoversNamespace;
use PHPUnit\Framework\Attributes\Test;
use Turborel\Internal\Misuse;
use Turborel\Session\Connection;
use Turborel\Tests\Support\LiveTestCase;
use Turborel\TurborelException;
use Turborel\Value\Binary;
use Turborel\Value\Lob;
use Turborel\Value\LobKind;
use Turborel\Value\LobLocator;

/**
 * Large objects on a live server: read whole or by their locators, a
 * range or as a stream; locators freed and held; values written a piece
 * at a time.
 */
#[CoversNamespace('Turborel\Session')]
#[CoversNamespace('Turborel\Internal')]
#[CoversNamespace('Turborel\Statements')]
#[CoversNamespace('Turborel\Value')]
#[CoversNamespace('Turborel\Lobs')]
#[CoversClass(TurborelException::class)]
#[CoversNamespace('Turborel\Type')]
final class LobsTest extends LiveTestCase
{
    /**
     * Verifies: [CLIENT Large objects] [PROTOCOL Values] [PROTOCOL Lob 'L']:
     * a value of at most 64 KiB comes whole in its row, a longer one as its
     * locator and lengths; a range is read by octets, or by a character
     * one's characters, at most 256 KiB a chunk, of a value held whole too;
     * a stream reads it whole; the locator keeps its transaction under
     * autocommit, and is no longer valid once it ends (SQLSTATE 0F001).
     *
     * @throws TurborelException never from the reads of valid locators
     */
    #[Test]
    public function aLongValueComesByItsLocator(): void
    {
        $connection = self::documents('L_READ');
        $connection->setAutocommit(true);
        [$short, $body, $picture] = ($connection->query('SELECT SHORT, BODY, PIC FROM L_READ')->rows[0] ?? []) + [null, null, null];
        self::assertInstanceOf(Lob::class, $short);
        self::assertEquals(new Lob(LobKind::Character, 'café'), $short);
        self::assertInstanceOf(LobLocator::class, $body);
        self::assertInstanceOf(LobLocator::class, $picture);
        self::assertSame([300_001, 300_000, 70_000, true], [$body->octets, $body->characters, $picture->octets, $connection->inTransaction()]);
        self::assertSame([262_144, 'xxx', 'éx', 'fé', 'af'], [\strlen($connection->readLob($body, 0, 300_000)), $connection->readLob($body, 299_997, 3, true),
            $connection->readLob($body, 0, 2, true), $connection->readLob($short, 2, 2, true), $connection->readLob($short, 1, 2)]);
        self::assertSame(str_repeat("\x07", 70_000), stream_get_contents($connection->lobReader($picture)));
        self::assertSame('café', stream_get_contents($connection->lobReader($short)));
        $connection->query('COMMIT');
        self::assertFailure('0F001', -301, static fn() => $connection->readLob($body, 0, 1));
        $connection->terminate();
    }

    /**
     * Verifies: [CLIENT Large objects] [PROTOCOL Lob 'L']: a locator freed
     * is no longer valid; one held is valid past its transaction's commit,
     * until it is freed.
     *
     * @throws TurborelException never from the locators valid
     */
    #[Test]
    public function aLocatorIsFreedOrHeld(): void
    {
        $connection = self::documents('L_HOLD');
        [, $body, $picture] = ($connection->query('SELECT SHORT, BODY, PIC FROM L_HOLD')->rows[0] ?? []) + [null, null, null];
        self::assertInstanceOf(LobLocator::class, $body);
        self::assertInstanceOf(LobLocator::class, $picture);
        $connection->freeLob($picture);
        self::assertFailure('0F001', -301, static fn() => $connection->readLob($picture, 0, 1));
        self::assertSame('00000', $connection->holdLob($body)->sqlstate());
        $connection->query('COMMIT');
        self::assertSame("\u{E9}x", $connection->readLob($body, 0, 3));
        $connection->freeLob($body);
        self::assertFailure('0F001', -301, static fn() => $connection->freeLob($body));
        $connection->terminate();
    }

    /**
     * Verifies: [CLIENT Large objects] [PROTOCOL Lob 'L'] [PROTOCOL Values]:
     * a value is written a piece at a time, a character split between
     * writes counted once, sent in Appends of a chunk, and passed as a
     * parameter by its locator; read back, it is as written; once
     * finished, it takes nothing more (SQLSTATE TB910).
     *
     * @throws TurborelException never from the writes
     */
    #[Test]
    public function aValueIsWrittenAPieceAtATime(): void
    {
        $connection = self::owner();
        $connection->query('CREATE TABLE L_WRITE (BODY CLOB(1M))');
        $writer = $connection->createLob(LobKind::Character);
        $writer->write("caf\xC3");
        $writer->write("\xA9" . str_repeat('y', 300_000));
        $written = $writer->finish();
        self::assertSame([300_005, 300_004], [$written->octets, $written->characters]);
        foreach ([static fn() => $writer->write('z'), $writer->flush(...), $writer->finish(...)] as $after) {
            self::assertFailure(Misuse::SQLSTATE, Misuse::SQLCODE, $after);
        }
        $connection->execute($connection->prepare('INSERT INTO L_WRITE VALUES (?)'), [$written]);
        $read = $connection->query('SELECT BODY FROM L_WRITE')->rows[0][0] ?? null;
        self::assertInstanceOf(LobLocator::class, $read);
        self::assertSame('café' . str_repeat('y', 300_000), stream_get_contents($connection->lobReader($read)));
        $connection->terminate();
    }

    /**
     * A connection as ANN, in which `$table` holds a short CLOB, a CLOB of 300 000 characters, and a BLOB of 70 000 octets, committed.
     *
     * @throws TurborelException if it cannot be made
     */
    private static function documents(string $table): Connection
    {
        $connection = self::owner();
        $connection->query("CREATE TABLE {$table} (SHORT CLOB(1K), BODY CLOB(1M), PIC BLOB(1M))");
        $connection->execute($connection->prepare("INSERT INTO {$table} VALUES (?, ?, ?)"), ['café', 'é' . str_repeat('x', 299_999), new Binary(str_repeat("\x07", 70_000))]);
        $connection->query('COMMIT');

        return $connection;
    }
}

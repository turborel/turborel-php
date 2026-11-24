<?php

declare(strict_types=1);

namespace Turborel\Tests\Live\Statements;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\CoversNamespace;
use PHPUnit\Framework\Attributes\Test;
use Turborel\Tests\Support\LiveTestCase;
use Turborel\TurborelException;
use Turborel\Value\Exact;
use Turborel\Value\Uuid;
use Turborel\Value\Xml;

/**
 * XML values and UUIDs to and from a live server.
 */
#[CoversNamespace('Turborel\Session')]
#[CoversNamespace('Turborel\Internal')]
#[CoversNamespace('Turborel\Statements')]
#[CoversNamespace('Turborel\Value')]
#[CoversNamespace('Turborel\Type')]
#[CoversClass(TurborelException::class)]
final class ValuesTest extends LiveTestCase
{
    /**
     * Verifies: [CLIENT XML values] [PROTOCOL Values]: an XML parameter is
     * made of its text; an XML value comes as its serialization, read as
     * its text, and parsed for its nodes; a value the server cannot
     * serialize, an attribute standing alone, fails the fetch (SQLSTATE
     * 2200W), the session going on.
     *
     * @throws TurborelException never from the statements that succeed
     */
    #[Test]
    public function anXmlValueIsItsSerialization(): void
    {
        $connection = self::owner();
        $connection->query('CREATE TABLE V_ORDERS (ID INTEGER, BODY XML)');
        $connection->execute($connection->prepare('INSERT INTO V_ORDERS VALUES (?, ?)'), [18, new Xml('<order no="18"><item>jam</item></order>')]);
        $body = $connection->query('SELECT BODY FROM V_ORDERS WHERE ID = 18')->rows[0][0] ?? null;
        self::assertInstanceOf(Xml::class, $body);
        self::assertSame('<order no="18"><item>jam</item></order>', $body->text);
        $order = $body->nodes()[0] ?? null;
        self::assertInstanceOf(\DOMElement::class, $order);
        self::assertSame(['order', '18', 'jam'], [$order->nodeName, $order->getAttribute('no'), $order->textContent]);
        self::assertFailure('2200W', -301, static fn() => $connection->query("VALUES (XMLQUERY('attribute a {1}' RETURNING SEQUENCE BY REF EMPTY ON EMPTY))"));
        self::assertCount(1, $connection->query('SELECT ID FROM V_ORDERS')->rows);
        $connection->terminate();
    }

    /**
     * Verifies: [CLIENT UUIDs] [PROTOCOL Values]: a UUID parameter is made of
     * its octets or of its text; a UUID comes as its 16 octets, shown as
     * its text.
     *
     * @throws TurborelException never from the statements
     */
    #[Test]
    public function aUuidIsItsOctets(): void
    {
        $connection = self::owner();
        $connection->query('CREATE TABLE V_TICKETS (ID UUID, SEAT CHAR(3))');
        $insert = $connection->prepare('INSERT INTO V_TICKETS VALUES (?, ?)');
        $connection->execute($insert, [Uuid::fromText('919108f7-52d1-4320-9bac-f847db4148a8'), '12A']);
        $connection->execute($insert, [new Uuid(str_repeat("\x11", 16)), '12B']);
        $ids = array_map(static function (array $row): string {
            $id = $row[0] ?? null;
            self::assertInstanceOf(Uuid::class, $id);
            self::assertSame(16, \strlen($id->octets));

            return (string) $id;
        }, $connection->query('SELECT ID FROM V_TICKETS ORDER BY SEAT')->rows);
        self::assertSame(['919108f7-52d1-4320-9bac-f847db4148a8', '11111111-1111-1111-1111-111111111111'], $ids);
        $connection->terminate();
    }

    /**
     * Verifies: [PROTOCOL Values] [CLIENT Running statements]: an exact
     * number of 38 digits, the most turborel holds, goes as a parameter and
     * comes back as a result whole, its units a string, never a float; one
     * of 39 digits is refused, a parameter or text cast with SQLSTATE 22003,
     * a literal with 42000 and SQLCODE -103; 1/3 is 0.333333, a
     * quotient's scale as turborel gives it.
     *
     * @throws TurborelException never from the numbers of 38 digits
     */
    #[Test]
    public function anExactNumberOf38DigitsGoesAndComesWhole(): void
    {
        $connection = self::owner();
        $connection->query('CREATE TABLE V_WIDE (N NUMERIC(38,0), D NUMERIC(38,10))');
        $insert = $connection->prepare('INSERT INTO V_WIDE VALUES (?, ?)');
        $connection->execute($insert, [new Exact('-' . str_repeat('9', 38), 0), Exact::fromString('1234567890123456789012345678.0123456789')]);
        $row = $connection->query('SELECT N, D FROM V_WIDE')->rows[0] ?? [];
        self::assertSame(['-' . str_repeat('9', 38), '1234567890123456789012345678.0123456789'], array_map(static fn(mixed $v): string => $v instanceof Exact ? (string) $v : '', $row));
        self::assertFailure('22003', -301, static fn() => $connection->execute($insert, [new Exact(str_repeat('9', 39), 0), null]));
        self::assertFailure('42000', -103, static fn() => $connection->query('VALUES ' . str_repeat('9', 39)));
        self::assertFailure('22003', -301, static fn() => $connection->query("VALUES CAST('" . str_repeat('9', 41) . "' AS DECIMAL(5))"));
        $third = ($connection->query('VALUES 1/3')->rows[0] ?? [])[0] ?? null;
        self::assertInstanceOf(Exact::class, $third);
        self::assertSame('0.333333', (string) $third);
        $connection->terminate();
    }
}

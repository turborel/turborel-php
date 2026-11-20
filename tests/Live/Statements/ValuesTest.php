<?php

declare(strict_types=1);

namespace Turborel\Tests\Live\Statements;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\CoversNamespace;
use PHPUnit\Framework\Attributes\Test;
use Turborel\Tests\Support\LiveTestCase;
use Turborel\TurborelException;
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
}

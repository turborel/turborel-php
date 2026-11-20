<?php

declare(strict_types=1);

namespace Turborel\Tests\Live\Pdo;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\CoversNamespace;
use PHPUnit\Framework\Attributes\Test;
use Turborel\Pdo\Connection;
use Turborel\Pdo\Pdo;
use Turborel\Settings\Options;
use Turborel\Settings\Settings;
use Turborel\Tests\Server\LiveServer;
use Turborel\Tests\Support\LiveTestCase;
use Turborel\Turborel;
use Turborel\TurborelException;
use Turborel\Value\Exact;

/**
 * The PDO-style API against a live server (RULES.md D2).
 */
#[CoversNamespace('Turborel\Pdo')]
#[CoversNamespace('Turborel\Settings')]
#[CoversNamespace('Turborel\Session')]
#[CoversNamespace('Turborel\Internal')]
#[CoversNamespace('Turborel\Statements')]
#[CoversNamespace('Turborel\Value')]
#[CoversNamespace('Turborel\Type')]
#[CoversClass(TurborelException::class)]
#[CoversClass(Turborel::class)]
final class PdoTest extends LiveTestCase
{
    /**
     * Verifies: [DESIGN] D2: a connection is made from a `turborel://` URI,
     * its user given apart as PDO's is, or from the driver's settings; its
     * attributes: errors always raised as exceptions, autocommit, the
     * default fetch mode, the server's and the driver's versions and name;
     * an attribute not taken is refused (SQLSTATE TB910).
     *
     * @throws TurborelException never from the connections made
     */
    #[Test]
    public function aConnectionIsMadeAndItsAttributesRead(): void
    {
        $pdo = new Connection(self::uri(), 'ann', null, [Pdo::ATTR_DEFAULT_FETCH_MODE => Pdo::FETCH_ASSOC]);
        self::assertSame([Pdo::ERRMODE_EXCEPTION, true, Pdo::FETCH_ASSOC, 'turborel 0.0.0 (alpha)', Turborel::VERSION, 'turborel'], [
            $pdo->getAttribute(Pdo::ATTR_ERRMODE), $pdo->getAttribute(Pdo::ATTR_AUTOCOMMIT), $pdo->getAttribute(Pdo::ATTR_DEFAULT_FETCH_MODE),
            $pdo->getAttribute(Pdo::ATTR_SERVER_VERSION), $pdo->getAttribute(Pdo::ATTR_CLIENT_VERSION), $pdo->getAttribute(Pdo::ATTR_DRIVER_NAME)]);
        self::assertSame(['ANN', true], [$pdo->client()->authorization(), $pdo->setAttribute(Pdo::ATTR_ERRMODE, Pdo::ERRMODE_EXCEPTION)]);
        foreach ([[Pdo::ATTR_ERRMODE, 0], [Pdo::ATTR_AUTOCOMMIT, 1], [Pdo::ATTR_DEFAULT_FETCH_MODE, 99], [99, true]] as [$attribute, $value]) {
            self::assertFailure('TB910', -910, static fn(): bool => $pdo->setAttribute($attribute, $value));
        }
        self::assertFailure('TB910', -910, static fn(): mixed => $pdo->getAttribute(99));
        $settings = Settings::resolveIn((new Options())->set(Options::USER, 'bob')->set(Options::HOST, '127.0.0.1')->set(Options::PORT, (string) LiveServer::PORT)
            ->set(Options::PASSWORD, LiveServer::BOB_PASSWORD)->set(Options::TLS_MODE, 'require'), static fn(string $name): mixed => null);
        self::assertSame('BOB', Connection::fromSettings($settings)->client()->authorization());
        self::assertFailure('08001', -919, static fn(): Connection => new Connection('postgres://ann@db'));
    }

    /**
     * Verifies: [DESIGN] D2: `exec` gives the rows a statement changed;
     * `prepare` takes `?` parameters, bound by position by `bindValue` and
     * `bindParam`, each as its type says, or given to `execute`; `query` runs
     * a statement at once; rows are fetched as each mode says, by `fetch`,
     * `fetchAll`, `fetchColumn` and iteration; `rowCount`, `columnCount` and
     * `getColumnMeta` tell of them; `lastInsertId` gives the last identity
     * value an INSERT generated; values come as Appendix A maps them.
     *
     * @throws TurborelException never from the statements
     */
    #[Test]
    public function statementsArePreparedBoundAndFetched(): void
    {
        $pdo = new Connection(self::uri());
        $pdo->exec('CREATE TABLE PDO_STAFF (ID INTEGER GENERATED ALWAYS AS IDENTITY, NAME VARCHAR(20) NOT NULL, PAID DECIMAL(9, 2))');
        self::assertSame(2, $pdo->exec("INSERT INTO PDO_STAFF (NAME, PAID) VALUES ('Ada', 10.50), ('Grace', 20.25)"));
        $first = $pdo->lastInsertId();
        self::assertSame('2', $first);
        $insert = $pdo->prepare('INSERT INTO PDO_STAFF (NAME, PAID) VALUES (?, ?)');
        $name = 'Edsger';
        $insert->bindParam(1, $name);
        $insert->bindValue(2, new Exact('3000', 2));
        $insert->execute();
        $name = 'Barbara';
        $insert->execute();
        $last = $pdo->lastInsertId();
        self::assertSame(['4', 1], [$last, $insert->rowCount()]);
        $insert->execute(['Alan', null]);
        $byPaid = $pdo->prepare('SELECT ID, NAME FROM PDO_STAFF WHERE ID > ? ORDER BY ID');
        $byPaid->bindValue(1, '3', Pdo::PARAM_INT);
        $byPaid->execute();
        self::assertSame([2, 2], [$byPaid->columnCount(), $byPaid->rowCount()]);
        $name = ['name' => 'NAME', 'native_type' => 'CHARACTER VARYING', 'turborel:decl_type' => 'CHARACTER VARYING(20)', 'pdo_type' => Pdo::PARAM_STR, 'len' => 20, 'precision' => 0, 'flags' => ['not_null']];
        self::assertSame($name, $byPaid->getColumnMeta(1));
        self::assertSame(['INTEGER', 'INTEGER'], [$byPaid->getColumnMeta(0)['native_type'] ?? null, $byPaid->getColumnMeta(0)['turborel:decl_type'] ?? null]);
        self::assertFalse($byPaid->getColumnMeta(9));
        $barbara = $byPaid->fetch(Pdo::FETCH_ASSOC);
        self::assertIsArray($barbara);
        self::assertSame('Barbara', $barbara['NAME'] ?? null);
        self::assertSame('Alan', $byPaid->fetchColumn(1));
        self::assertSame([], $byPaid->fetchAll(Pdo::FETCH_NUM));
        self::assertFalse($byPaid->fetch());
        $all = $pdo->query('SELECT NAME FROM PDO_STAFF ORDER BY ID', Pdo::FETCH_COLUMN, 0)->fetchAll();
        self::assertSame(['Ada', 'Grace', 'Edsger', 'Barbara', 'Alan'], $all);
        $both = $pdo->query('SELECT NAME FROM PDO_STAFF WHERE ID = 1')->fetch();
        self::assertSame(['NAME' => 'Ada', 0 => 'Ada'], $both);
        $object = $pdo->query('SELECT NAME, PAID FROM PDO_STAFF WHERE ID = 2', Pdo::FETCH_OBJ)->fetch();
        self::assertInstanceOf(\stdClass::class, $object);
        self::assertEquals(['Grace', new Exact('2025', 2)], [$object->NAME ?? null, $object->PAID ?? null]);
        $people = $pdo->query('SELECT NAME FROM PDO_STAFF ORDER BY ID', Pdo::FETCH_CLASS, Person::class, ['staff']);
        $names = array_map(static fn(mixed $p): string => $p instanceof Person ? "{$p->NAME} ({$p->kind})" : '', iterator_to_array($people));
        self::assertSame('Ada (staff)', $names[0] ?? null);
        self::assertSame("'it''s'", $pdo->quote("it's"));
        self::assertFailure('TB910', -910, static fn(): bool => $byPaid->bindValue(':id', 1));
        self::assertFailure('TB910', -910, static fn(): mixed => $byPaid->fetch(Pdo::FETCH_ASSOC, 1));
        $missing = $pdo->prepare('SELECT NAME FROM PDO_STAFF WHERE ID = ? OR ID = ?');
        $missing->bindValue(2, 1);
        self::assertFailure('TB910', -910, static fn(): bool => $missing->execute());
        self::assertTrue($missing->closeCursor());
    }

    /**
     * Verifies: [DESIGN] D2: each statement is its own transaction unless one
     * is begun, which lasts until `commit` or `rollBack`; with autocommit
     * off, statements make a transaction `commit` ends; ending none, or
     * beginning one within another, is refused (SQLSTATE TB910); every
     * failure is a TurborelException of its SQLSTATE and SQLCODE, which
     * `errorCode` and `errorInfo` give as PDO does.
     *
     * @throws TurborelException never from the statements that succeed
     */
    #[Test]
    public function transactionsAndFailuresAreAsPdos(): void
    {
        $pdo = new Connection(self::uri());
        $other = new Connection(self::uri());
        $pdo->exec('CREATE TABLE PDO_TX (N INTEGER)');
        self::assertFalse($pdo->inTransaction());
        self::assertTrue($pdo->beginTransaction());
        $pdo->exec('INSERT INTO PDO_TX VALUES (1)');
        self::assertTrue($pdo->inTransaction());
        self::assertFailure('TB910', -910, static fn(): bool => $pdo->beginTransaction());
        self::assertSame([], $other->query('SELECT N FROM PDO_TX')->fetchAll());
        self::assertTrue($pdo->rollBack());
        $pdo->beginTransaction();
        $pdo->exec('INSERT INTO PDO_TX VALUES (2)');
        $pdo->commit();
        $committed = $other->query('SELECT N FROM PDO_TX WHERE N > 0')->fetchAll();
        self::assertCount(1, $committed);
        self::assertFailure('TB910', -910, static fn(): bool => $pdo->commit());
        $pdo->setAttribute(Pdo::ATTR_AUTOCOMMIT, false);
        $pdo->exec('INSERT INTO PDO_TX VALUES (3)');
        self::assertTrue($pdo->inTransaction());
        $pdo->commit();
        $pdo->setAttribute(Pdo::ATTR_AUTOCOMMIT, true);
        try {
            $pdo->exec('SELECT * FROM NOPE');
            self::fail('no table');
        } catch (TurborelException $failure) {
            self::assertSame(['2A000', -103], [$failure->getSqlState(), $failure->getSqlCode()]);
            self::assertSame(['2A000', -103, 'table ANN.NOPE is not defined'], $failure->errorInfo());
        }
        self::assertSame(['2A000', ['2A000', -103, 'table ANN.NOPE is not defined']], [$pdo->errorCode(), $pdo->errorInfo()]);
        $pdo->exec('VALUES 1');
        self::assertSame(['00000', ['00000', null, null]], [$pdo->errorCode(), $pdo->prepare('VALUES 1')->errorInfo()]);
        self::assertSame('00000', $pdo->prepare('VALUES 1')->errorCode());
    }

    /**
     * The live server's URI, as ANN over TCP without TLS.
     *
     * @throws TurborelException if the server cannot be set up
     */
    private static function uri(): string
    {
        self::server();

        return 'turborel://ann@127.0.0.1:' . LiveServer::PORT . '/?tls_mode=disable';
    }
}

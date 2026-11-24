<?php

declare(strict_types=1);

namespace Turborel\Agent;

use Turborel\Internal\Agent\Failures;
use Turborel\Internal\Agent\Parser;
use Turborel\Internal\Agent\Statement;
use Turborel\Internal\Agent\Value;
use Turborel\Internal\Codec\Completion;
use Turborel\Internal\Copy\Names;
use Turborel\Session\Canceller;
use Turborel\Session\Connection;
use Turborel\Statements\Completed;
use Turborel\Statements\Outcome;
use Turborel\Statements\Sink;
use Turborel\Statements\Streamed;
use Turborel\TurborelException;

/**
 * An SQL-agent, as turborel-client's `Agent` [CLIENT The SQL-agent]: a
 * program's connections to servers, each named, one current, and the
 * statements that make, choose and end them, CONNECT, SET CONNECTION and
 * DISCONNECT, which it runs itself (ISO/IEC 9075:1992 §15); every other
 * statement goes to the current connection's session.
 */
final class Agent
{
    /** @var list<array{?string, Connection}> the connections held, each by its name, none for the default */
    private array $held = [];

    /** The current connection's place, if any. */
    private ?int $current = null;

    /** An agent with no connection, `$connector` making those CONNECT asks for. */
    public function __construct(private readonly Connector $connector) {}

    /** An agent whose default connection, current, is `$connection`: as a client begins, connected. */
    public static function connected(Connector $connector, Connection $connection): self
    {
        $agent = new self($connector);
        [$agent->held, $agent->current] = [[[null, $connection]], 0];

        return $agent;
    }

    /**
     * The current connection.
     *
     * @throws TurborelException SQLSTATE 08003 if there is none
     */
    public function current(): Connection
    {
        return $this->currentConnection() ?? throw Failures::of('08003', 'there is no current connection');
    }

    /** The current connection, if there is one. */
    public function currentConnection(): ?Connection
    {
        return $this->current === null ? null : ($this->held[$this->current][1] ?? null);
    }

    /** What cancels the current connection's statement, if any. */
    public function canceller(): ?Canceller
    {
        return $this->currentConnection()?->canceller();
    }

    /** The SQLSTATE of the warning the current connection's last statement completed with, if it did. */
    public function warning(): ?string
    {
        return $this->currentConnection()?->warning();
    }

    /** Whether the current connection is lost: none is not. */
    public function isLost(): bool
    {
        return $this->currentConnection()?->isLost() ?? false;
    }

    /** The current connection's name; none for the default one, or for no connection. */
    public function currentName(): ?string
    {
        return $this->current === null ? null : ($this->held[$this->current][0] ?? null);
    }

    /**
     * Runs `$sql`: a connection statement itself, its completion; any other in the current connection's session.
     *
     * @throws TurborelException a connection statement's failure, or the statement's
     */
    public function query(string $sql): Outcome
    {
        $done = $this->connectionStatement($sql);

        return $done === null ? $this->current()->query($sql) : new Outcome([], [], $done);
    }

    /**
     * Runs `$sql` as `query` does, a query's rows given to `$sink` as they come.
     *
     * @throws TurborelException as `query`'s
     */
    public function queryInto(string $sql, Sink $sink): Streamed
    {
        $done = $this->connectionStatement($sql);

        return $done === null ? $this->current()->queryInto($sql, $sink) : new Streamed(0, $done);
    }

    /**
     * `$sql`, if a connection statement, run: its completion; none if it is another.
     *
     * @throws TurborelException the statement's, as each rule of ISO/IEC 9075:1992 §15 says
     */
    public function connectionStatement(string $sql): ?Completed
    {
        $statement = Parser::statement($sql);

        return match ($statement?->kind) {
            null => null,
            Statement::CONNECT, Statement::CONNECT_DEFAULT => $this->connect($statement),
            Statement::SET => $this->setConnection($statement),
            default => $this->disconnect($statement),
        };
    }

    /**
     * Ends every connection, as the client ends.
     *
     * @throws TurborelException the first failure ending one; every one is ended still
     */
    public function terminate(): void
    {
        $first = null;
        foreach ($this->held as [, $connection]) {
            try {
                $connection->terminate();
            } catch (TurborelException $failure) {
                $first ??= $failure;
            }
        }
        [$this->held, $this->current] = [[], null];
        if ($first !== null) {
            throw $first;
        }
    }

    /**
     * CONNECT TO a server, AS a name, USER a user, or TO DEFAULT: the connection made current (ISO/IEC 9075:1992 §15.1).
     *
     * @throws TurborelException SQLSTATE 0A001 in an active transaction; 28000 if the user is no authorization identifier; 2E000 if the name is no identifier; 08002 if it is in use; 08001 if the server cannot be reached; 08004 if it refuses
     */
    private function connect(Statement $statement): Completed
    {
        $this->notInTransaction();
        $user = self::text($statement->values['user'] ?? null);
        if ($user !== null && Names::identifier($user) === null) {
            throw Failures::of('28000', "'{$user}' is not an <authorization identifier>");
        }
        $server = self::text($statement->values['server'] ?? null);
        $named = self::text($statement->values['name'] ?? null) ?? $server;
        $name = $named === null ? null : (Names::identifier($named) ?? throw Failures::of('2E000', "'{$named}' is not an <identifier>"));
        if ($this->find($name) !== null) {
            throw Failures::of('08002', ($name === null ? 'the default connection' : "the connection {$name}") . ' is established');
        }
        try {
            $connection = $this->connector->connect($server, $user);
        } catch (TurborelException $failure) {
            throw Failures::of($failure->getSqlState() === '08006' ? '08001' : '08004', $failure->getMessage());
        }
        $this->held[] = [$name, $connection];
        $this->current = \count($this->held) - 1;

        return self::done('00000');
    }

    /**
     * SET CONNECTION a name, or DEFAULT: that connection current (ISO/IEC 9075:1992 §15.2).
     *
     * @throws TurborelException SQLSTATE 0A001 in an active transaction; 08003 if there is no such connection
     */
    private function setConnection(Statement $statement): Completed
    {
        $this->notInTransaction();
        $name = self::name($statement->values['name'] ?? null);
        $this->current = $this->find($name) ?? throw Failures::of('08003', 'there is ' . ($name === null ? 'no default connection' : "no connection {$name}"));

        return self::done('00000');
    }

    /**
     * DISCONNECT a name, DEFAULT, ALL or CURRENT: each connection it names ended (ISO/IEC 9075:1992 §15.3).
     *
     * @throws TurborelException SQLSTATE 08003 if it names no connection held; 25000 if one's transaction is active
     */
    private function disconnect(Statement $statement): Completed
    {
        $ended = match ($statement->object) {
            'ALL' => array_keys($this->held),
            'CURRENT' => [$this->current ?? throw Failures::of('08003', 'there is no current connection')],
            'DEFAULT' => [$this->find(null) ?? throw Failures::of('08003', 'there is no default connection')],
            default => [$this->find($name = self::name($statement->values['name'] ?? null)) ?? throw Failures::of('08003', 'there is no connection ' . $name)],
        };
        foreach ($ended as $at) {
            if (($this->held[$at][1] ?? null)?->inTransaction() ?? false) {
                throw new TurborelException("a connection's transaction is active", '25000', -917);
            }
        }
        $current = $this->current === null || !isset($this->held[$this->current]) ? false : $this->held[$this->current][0];
        [$kept, $warned] = [[], false];
        foreach ($this->held as $at => $held) {
            if (!\in_array($at, $ended, true)) {
                $kept[] = $held;

                continue;
            }
            try {
                $held[1]->terminate();
            } catch (TurborelException) {
                $warned = true;
            }
        }
        $this->held = $kept;
        $this->current = $current === false ? null : $this->find($current);

        return self::done($warned ? '01002' : '00000');
    }

    /**
     * That the current connection's transaction is not active: no transaction spans servers.
     *
     * @throws TurborelException SQLSTATE 0A001 if it is
     */
    private function notInTransaction(): void
    {
        if ($this->currentConnection()?->inTransaction() ?? false) {
            throw Failures::of('0A001', "the current connection's transaction is active, and none spans servers");
        }
    }

    /** The place of the connection named `$name`, none for the default one, if held. */
    private function find(?string $name): ?int
    {
        foreach ($this->held as $at => [$held]) {
            if ($held === $name) {
                return $at;
            }
        }

        return null;
    }

    /**
     * The text `$value` is, its spaces trimmed; none of none.
     *
     * @throws TurborelException SQLSTATE 42000 of a value that is no character string literal
     */
    private static function text(?Value $value): ?string
    {
        if ($value === null) {
            return null;
        }
        if (!$value->literal) {
            throw new TurborelException("a client's connection statement names by character string literals", '42000', -103);
        }

        return trim($value->text, ' ');
    }

    /**
     * The connection name `$value` gives: the identifier it is, if it is one, else its text; none of none.
     *
     * @throws TurborelException SQLSTATE 42000 of a value that is no character string literal
     */
    private static function name(?Value $value): ?string
    {
        $text = self::text($value);

        return $text === null ? null : (Names::identifier($text) ?? $text);
    }

    /** A completion of SQLCODE 0, of `$sqlstate`. */
    private static function done(string $sqlstate): Completed
    {
        return Completed::of(new Completion(0, $sqlstate, false));
    }
}

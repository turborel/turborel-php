# The SQL-agent

[Contents](README.md)

## NAME

`Turborel\Agent\Agent`: a program's connections, each named, one
current, made, chosen and ended by connection statements.

## SYNOPSIS

```php
final class Agent
{
    public function __construct(Connector $connector);
    public static function connected(Connector $connector, Connection $connection): self;
    public function current(): Connection;
    public function currentConnection(): ?Connection;
    public function currentName(): ?string;
    public function canceller(): ?Canceller;
    public function warning(): ?string;
    public function isLost(): bool;
    public function query(string $sql): Outcome;
    public function queryInto(string $sql, Sink $sink): Streamed;
    public function connectionStatement(string $sql): ?Completed;
    public function terminate(): void;
}

interface Connector
{
    public function connect(?string $server, ?string $user): Connection;
}
```

## DESCRIPTION

`query` and `queryInto` run a connection statement themselves, giving
its completion, and send every other statement to the current
connection. CONNECT TO a server, AS a name, USER a user, each a
character string literal, makes a connection through the `Connector`,
named by the name, else the server, and makes it current; CONNECT TO
DEFAULT makes the default connection, of no name. SET CONNECTION makes
one current, a name or DEFAULT. DISCONNECT ends those it names, a name,
DEFAULT, CURRENT or ALL, none current after if the current one was
ended; a failure ending one makes the completion warning 01002.
`connected` begins with a default connection, current. `terminate` ends
every one.

## ERRORS

| SQLSTATE | SQLCODE | When |
|---|---|---|
| 08003 | -919 | No current connection, or none of the name |
| 0A001 | -900 | CONNECT or SET CONNECTION while the current connection's transaction is active |
| 28000 | -104 | A user that is no authorization identifier |
| 2E000 | -919 | A connection name that is no identifier |
| 08002 | -919 | A connection name in use |
| 08001 | -919 | A server the connector cannot reach |
| 08004 | -919 | A server that refuses the connection |
| 25000 | -917 | DISCONNECT of a connection whose transaction is active |
| 2A000 | -102, -103 | A connection statement that does not parse, or names by no character string literal |

## EXAMPLES

```php
use Turborel\Agent\Agent;
use Turborel\Agent\Connector;
use Turborel\Session\Connection;
use Turborel\Session\Target;
use Turborel\Session\Trust;

$agent = new Agent(new class implements Connector {
    public function connect(?string $server, ?string $user): Connection
    {
        return Connection::connect(Target::tcp('127.0.0.1'), $user ?? 'ann', new Trust());
    }
});
$agent->query("CONNECT TO 'db' AS 'reports' USER 'bob'");
echo $agent->currentName(), ': ', $agent->query('VALUES CURRENT_USER')->rows[0][0], PHP_EOL;
$agent->query('ROLLBACK');
$agent->query('DISCONNECT ALL');
```

```text
REPORTS: BOB
```

## STANDARD

[CLIENT The SQL-agent].

## SEE ALSO

[Connections](21-connections.md).

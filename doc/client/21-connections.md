# Connections

[Contents](README.md)

## NAME

`Turborel\Session\Connection`: a connection to a server, and its
session.

## SYNOPSIS

```php
final class Connection
{
    public static function connect(Target $target, string $user, Mechanism $mechanism, ?Timeouts $timeouts = null, ?Offer $offer = null): self;
    public static function open(mixed $stream, string $user, Mechanism $mechanism, ?Offer $offer = null): self;
    public function authorization(): string;
    public function server(): string;
    public function target(): ?Target;
    public function granted(): array;
    public function has(string $capability): bool;
    public function setting(string $name): ?string;
    public function settings(): array;
    public function inTransaction(): bool;
    public function autocommit(): bool;
    public function setAutocommit(bool $on): void;
    public function readTimeout(): ?float;
    public function setReadTimeout(?float $within): void;
    public function setMaxBody(int $bytes): void;
    public function isLost(): bool;
    public function canceller(): ?Canceller;
    public function query(string $sql): Outcome;
    public function ping(): void;
    public function reset(): void;
    public function changeUser(string $user, Mechanism $mechanism): void;
    public function terminate(): void;
}
```

## DESCRIPTION

`connect` connects to a `Target`, TCP, a Unix socket or named pipe, or
TLS, as the user, proved by a `Mechanism`: `Trust`, `Password`,
`Certificate` or `Scram`. Over plain TCP a mechanism that sends a secret
is refused before anything is sent. It waits as its `Timeouts` say, 30
seconds to connect and log in and no limit after by default, and asks
for what its `Offer` asks, every capability but compression by default.

`open` logs in over a stream the program opened. Its maker answers for
its privacy and bounds its waits; of a TLS stream opened with the `ssl`
option `capture_peer_cert`, `$scram->bind(TlsChannel::of($stream))`
binds SCRAM-SHA-256-PLUS first. Such a connection has no target, so no
canceller.

`authorization` and `server` give what Ready told: the session's
authorization identifier and the server's product. `granted` lists the
capabilities granted, in the spec's order; `setting` and `settings`
give the session's settings as the server last told them. `ping` asks
whether the server and the connection are alive, `reset` puts the
session back as it was at login, and `changeUser` authenticates the
connection again as another user, each only where granted.

`query` runs one statement; [Statements and Cursors](22-statements.md)
describes it with the rest of a session's statements. `setReadTimeout` bounds each
read and write from then on, `setMaxBody` the longest reply taken, 16
MiB by default. A connection lost, `isLost`, takes no more requests.

`Scram` takes `inTheClear()`, `channelBinding(ChannelBinding)` and
`withCache(KeyCache)`: a `KeyCache` keeps the keys a password derives,
64 at most, so a later login of the same salt and iterations skips
their slow derivation; `lookups()` counts its hits and misses.

## ERRORS

| SQLSTATE | SQLCODE | When |
|---|---|---|
| 28000 | -919 | A secret over plain TCP; the server refusing the user, the password or the certificate; SCRAM's exchange refused |
| 08004 | -919 | The server refusing the connection, as of a protocol version it does not speak |
| 08006 | -919 | The server not reached, TLS failing, a wait run out, a reply that cannot be read, a capability not granted, or the connection lost |
| 40002 | -913 | Terminate's commit failing |
| TB920 | -920 | The statement cancelled |

## EXAMPLES

```php
use Turborel\Session\Connection;
use Turborel\Session\Target;
use Turborel\Session\Trust;

$connection = Connection::connect(Target::tcp('127.0.0.1'), 'ann', new Trust());
echo implode(' ', array_slice($connection->granted(), 0, 3)), PHP_EOL;
$connection->ping();
$connection->terminate();
```

```text
notices status diagnostics
```

## STANDARD

[PROTOCOL Opening a connection], [PROTOCOL Mechanisms],
[PROTOCOL Capabilities], [PROTOCOL Status 'T'], [PROTOCOL Cancel 'Z'],
[PROTOCOL Terminate 'X'], [CLIENT Connecting], [CLIENT Timeouts],
[CLIENT What a connection asks for], [CLIENT What the server tells],
[CLIENT Controlling the session], [CLIENT The SCRAM key cache].

## SEE ALSO

[Errors](30-errors.md), [Values](23-values.md).

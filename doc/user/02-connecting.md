# Connecting

[Contents](README.md)

A program reaches a server through a `Turborel\Session\Connection`,
made to a target as a user, proved by a mechanism. This chapter tells
how; [Connections](../client/21-connections.md) in the API Reference
lists every method. The standard interface connects by a URI, as
[The Connection Class](../client/01-api-connection.md) tells.

## Where the server is

A `Target` says where the server is and how it is reached:

| Target | Reaches |
|---|---|
| `Target::tcp('db.example')` | TCP, on port 7422 unless a second argument names another |
| `Target::local('/run/turborel/socket')` | a Unix socket; on Windows, a named pipe, as `\\.\pipe\turborel` |
| `Target::tls('db.example', $tls)` | TLS, begun on the TCP port, as `$tls` says |

A Unix socket or a named pipe reaches the server's own machine alone, so
a connection over one needs no TLS.

## Who connects

`Connection::connect` takes the target, the user, and a mechanism that
proves the user:

| Mechanism | Proves |
|---|---|
| `new Trust()` | nothing: the server takes the user as it names itself, as it does only of a client of its own machine, and only where SERVER.TRUST_LOCAL is ON |
| `new Scram($password)` | a password, by SCRAM-SHA-256: the password never travels, and the server must prove it knows the user's verifier; over TLS it is SCRAM-SHA-256-PLUS, bound to the connection |
| `new Password($password)` | a password, sent whole |
| `new Certificate()` | the user the TLS client certificate's common name names |

A password, whether proved by SCRAM or sent whole, goes only over TLS, a
Unix socket or a named pipe: over plain TCP it is refused before anything
is sent, with SQLSTATE 28000. `(new Scram($password))->inTheClear()` sends
SCRAM over plain TCP all the same, for a server whose
SERVER.SCRAM_WITHOUT_TLS is ON.

```php
use Turborel\Session\Connection;
use Turborel\Session\Target;
use Turborel\Session\Trust;

$connection = Connection::connect(Target::tcp('127.0.0.1'), 'ann', new Trust());
echo $connection->server(), ', as ', $connection->authorization(), PHP_EOL;
$connection->terminate();
```

```text
turborel 0.0.0 (alpha), as ANN
```

The user is read as an identifier, so `ann` logs in as ANN. A login the
server refuses, as a wrong password, fails with SQLSTATE 28000, and the
connection is closed.

## TLS

`Tls::trusting($file)` trusts the certificate authorities of a PEM
file alone; `Tls::roots($file, true)` trusts the platform's too, and
`Tls::roots(null, true)` the platform's alone, those of the files
SSL_CERT_FILE and SSL_CERT_DIR name if either is set.
`Tls::presenting($roots, $chain, $key)` presents a certificate chain and
key as well, for the mechanism `certificate`. The server's certificate
must be signed by an authority trusted and be of the host's name, or of
the name `forServerName` gives; else the handshake fails with SQLSTATE
08006.

```php
use Turborel\Session\Connection;
use Turborel\Session\Scram;
use Turborel\Session\Target;
use Turborel\Session\Tls;

$tls = Tls::trusting('/etc/turborel/ca.pem');
$connection = Connection::connect(Target::tls('localhost', $tls), 'bob', new Scram('bob-pass-2'));
echo $connection->authorization(), PHP_EOL;
$connection->terminate();
```

```text
BOB
```

## How long it waits

`Timeouts` says how long a connection waits on its server: `connect`, to
connect, begin TLS and for each step of the login, 30 seconds by
default; `read`, for each read or write after, with no limit by default,
as a statement may run as long as it must. `setReadTimeout` changes a
connection's read limit. A wait that runs out loses the connection with
SQLSTATE 08006, saying the server did not answer in time. A named pipe
is bounded only as it is opened.

```php
$connection = Connection::connect(Target::tcp('127.0.0.1'), 'ann', new Trust(), new Timeouts(5.0, 60.0));
```

## What a connection asks for

A connection asks the server for every capability but compression, and
the server grants those it has. `new Offer()` is that offer;
`Offer::none()` asks for none, `->compressed()` adds compression, and
`->attribute($name, $value)` adds a connection attribute, which the
server shows in INFORMATION_SCHEMA.CONNECTION_ATTRIBUTES. `granted()`
gives what was granted, and a method that needs a capability not granted
fails, before it sends anything, with SQLSTATE 08006.

The server tells the session's settings as it begins, and again as a
statement changes one: `setting($name)` gives one with no round trip,
`settings()` all of them.

```php
$connection = Connection::connect(Target::tcp('127.0.0.1'), 'ann', new Trust());
echo $connection->setting('SESSION.TIME_ZONE'), ' ', $connection->setting('SERVER_READ_ONLY'), PHP_EOL;
$connection->query("SET SESSION.TIME_ZONE = '+02:00'");
echo $connection->setting('SESSION.TIME_ZONE'), PHP_EOL;
```

```text
+00:00 NO
+02:00
```

## Ending a connection

`terminate` ends the session, its transaction committed or rolled back
as SESSION.COMMIT_AT_END says, ON by default, and waits for the server
to close, at most the read timeout, else 60 seconds. A commit that fails
then, as of a deferred constraint not satisfied, gives its failure,
SQLSTATE 40002.

`canceller()` gives what cancels the statement a connection runs: a new
connection to the same server, made as the first was, which sends the
session's cancel key and is closed. The statement fails with SQLCODE
-920, SQLSTATE TB920, and the session goes on. A cancel gives up after
10 seconds, or the limit `cancel` takes. PHP runs one thread, so a
program cancels from another process, or between sending a request and
reading its reply.

# Settings

[Contents](README.md)

A program that connects as settings from outside it say, a URI, a
service file, the environment, a password file, uses
`Turborel\Settings\Settings` rather than naming each part of a
connection itself. [Settings](../client/26-settings.md) lists the
classes, and [Appendix C](../appendix/c-settings.md) every setting.

## Connecting by settings

`Settings::fromUri` reads a connection URI, and `connect` connects as it
says, giving a connection like `Connection::connect`'s:

```php
use Turborel\Settings\Settings;

$settings = Settings::fromUri('turborel://ann@localhost:7422/?tls_mode=verify-full&tls_roots=/etc/turborel/ca.pem');
$connection = $settings->connect();
echo $connection->server(), ', as ', $connection->authorization(), PHP_EOL;
$connection->terminate();
```

```text
turborel 0.0.0 (alpha), as ANN
```

A password the settings give is proved by SCRAM-SHA-256; with no
password, the user is proved by the client's certificate if one is
named, else trusted as a local client. A password is never sent over
plain TCP (SQLSTATE 28000).

## Where settings come from

Settings come in layers, each setting taken from the first layer that
has it: the program's own, an `Options` it fills or a URI; the section
of the service file the program or the environment names; the
environment's TURBOREL_ variables; the defaults. A variable set but
empty gives nothing. A setting that cannot be read, a service file that
cannot be read or has no section of the name, and settings that name no
user are refused with SQLSTATE 08001.

```php
use Turborel\Settings\Options;
use Turborel\Settings\Settings;

$options = (new Options())->set(Options::USER, 'ann')->set(Options::HOST, 'db1.example,db2.example:7500');
$settings = Settings::resolveIn($options, static fn(string $name): ?string => $name === 'TURBOREL_PORT' ? '7600' : null);
foreach ($settings->hosts as $host) {
    echo $host->name, ':', $host->port, PHP_EOL;
}
echo $settings->tlsMode->value, ', ', $settings->connectTimeout, ' seconds', PHP_EOL;
```

```text
db1.example:7600
db2.example:7500
prefer, 30 seconds
```

`Settings::resolve` reads the process's environment; `resolveIn`, as
here, reads it through a function the program gives.

## The connection URI

```text
turborel://[<user>[:<password>]@][<host>[:<port>][,<host>[:<port>]]...][/][?<setting>=<value>[&<setting>=<value>]...]
```

Its parts are percent-encoded, so `p%40ss` is the password `p@ss` and
`%2Frun%2Fturborel.sock` a Unix socket; an IPv6 address is written in
brackets. A server serves one database, so the URI has no path. A URI of
another scheme, of a path, of a setting not in Appendix C or of a pair
without `=` is refused (SQLSTATE 08001).

## The service file

A service file names sets of settings: sections, each `[name]` on a line
of its own, then lines `setting = value`; a line beginning with `#` is a
comment, and blank lines are skipped. A section cannot set `service` or
`service_file`. A malformed line is refused, its file and line named.
`~/.turborel_service.conf` is read unless `service_file` names another.

```text
[production]
host = db1.example,db2.example
user = ann
tls_mode = verify-full
tls_roots = /etc/turborel/ca.pem
target = read-write
```

## The password file

A password the settings do not give is looked up in the password file,
`~/.turborel_pass` unless `passfile` names another, by the server and
user of each connection. Each line is `host:port:user:password`; a field
`*` matches anything; `\` takes the character after it as it is, so
`\:` is a colon in a field and `\*` a star; a line beginning with `#` is
a comment. The first line that matches is taken. A Unix socket is
matched by its path and the default port. The file is read only if it
is a regular file, or a link to one; it should be readable by its owner
alone, which its owner answers for.

## Several servers

`host` may name several servers, tried in the order given, or with
`load_balance=random` in an order drawn at random for each connection.
A name is tried at each address it resolves to. A server that cannot be
reached, refuses the user, or is not of the role asked is passed for the
next. With none kept, the failure names each address and why (SQLSTATE
08001; 28000 if the last refused the user).

`target=read-write` keeps a server that takes changes, a server alone or
its group's primary; `target=read-only` a replica that follows its
group. The server tells each session whether it takes changes, as
SERVER_READ_ONLY; of one that does not, the role is read from
INFORMATION_SCHEMA.REPLICAS. `Role::of` gives the role a connection's
session sees.

## TLS by mode

`tls_mode` says how a connection over TCP uses TLS; a Unix socket or a
named pipe uses none.

| Mode | TLS | The server's certificate |
|---|---|---|
| `disable` | Never | |
| `prefer` | If the server takes it, else plain TCP | Not checked |
| `require` | Always | Not checked |
| `verify-ca` | Always | Signed by an authority trusted |
| `verify-full` | Always | Signed by an authority trusted, and of the server's name |

`verify-ca` and `verify-full` need the authorities trusted:
`tls_roots`, or `tls_system_roots=true`. The name checked is
`server_name`, else the host's. Under `prefer`, a server reached whose
TLS handshake fails is connected to again over plain TCP.

`channel_binding` says whether SCRAM binds its exchange to the TLS
connection: `prefer` binds where it can, `disable` never, and `require`
refuses settings whose mechanism binds nothing, a connection not over
TLS, or a password not proved by SCRAM (SQLSTATE 28000). The binding is
`tls-server-end-point`, of the server's certificate.

The settings keep one SCRAM key cache for every connection they make,
so a later login meeting the same salt and iterations skips the slow
derivation of its keys.

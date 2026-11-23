<p align="center">
    <picture>
      <source media="(prefers-color-scheme: dark)" srcset="artwork/turborel-logo-white.svg">
      <img src="artwork/turborel-logo.svg" alt="turborel" width="360">
    </picture>
</p>

> turborel-php is in alpha, meaning, version 0.0.0. Its version is the
> number of the Turborel release it is made for, and the alpha is made
> for Turborel 0.0.0, which speaks protocol version 1. Anything may
> change from one build to the next, API and behaviour alike. See
> [VERSIONING.md](VERSIONING.md). The latest Turborel alpha is built for
> Linux, macOS and Windows as the pre-release
> [nightly](https://github.com/turborel/turborel/releases/tag/nightly),
> and as the image `ghcr.io/turborel/turborel:nightly`.

turborel-php is the PHP driver for
[Turborel](https://github.com/turborel/turborel), a relational database
system. It connects a PHP program to a Turborel server,
`turboreld`, over Turborel's [native
protocol](https://github.com/turborel/turborel/blob/main/doc/client/05-protocol.md),
version 1, which it speaks itself, in plain PHP, with no C extension to build.

Its standard interface is an API in PDO's shape, written in PHP; a real PDO driver would need a C extension, so it is not `\PDO` itself. Beneath it is a low-level
client built to do all that Turborel's own client library,
turborel-client, does: TCP, Unix sockets and Windows named pipes, TLS,
SCRAM-SHA-256 and SCRAM-SHA-256-PLUS, prepared statements and cursors,
large objects, bulk copy, import and export, settings from a URI, the
environment and service and password files, several servers, pipelines
and pools.

The driver was built in milestones, M0 to M8, and every one is done.
[CONFORMANCE.md](CONFORMANCE.md) shows what is done, row by row, and
[TODO.md](TODO.md) what is open.

## Requirements

- PHP 8.3 or later, 64-bit, on Linux, macOS or Windows ([RULES.md](RULES.md) D3).
- The extension openssl, for TLS; zlib, for compressed frames, and bcmath, for arithmetic on exact numbers, where wanted.
- PHP 8.3, 8.4 and 8.5 are tested; no package beyond PHP's own extensions.
- A Turborel server of the same release, which speaks protocol version 1
  ([running the server](https://github.com/turborel/turborel/blob/main/doc/operator/02-turboreld.md)).

## Installing

No package is published yet. The driver's package, `turborel/turborel`, goes to
Packagist only once every Turborel driver is done, with the others
([VERSIONING.md](VERSIONING.md#publishing)). Until then, build the
driver from source:

```
git clone https://github.com/turborel/turborel-php.git
cd turborel-php
composer install
composer verify
```

A program takes the checkout in as a Composer `path` repository, then
requires the package:

```json
{
    "repositories": [{"type": "path", "url": "../turborel-php"}],
    "require": {"turborel/turborel": "0.0.0"}
}
```

## A first query

The standard API:

```php
use Turborel\Pdo\Connection;
use Turborel\Pdo\Pdo;
use Turborel\TurborelException;

try {
    $db = new Connection('turborel://ann@db.example/?tls_mode=verify-full', password: 'ann\'s');
    $staff = $db->prepare('SELECT ID, NAME FROM STAFF WHERE ID < ? ORDER BY ID');
    $staff->execute([3]);
    foreach ($staff->fetchAll(Pdo::FETCH_ASSOC) as $row) {
        echo $row['ID'], ' ', $row['NAME'], PHP_EOL;
    }
} catch (TurborelException $failure) {
    echo $failure->getSqlState(), ' ', $failure->getSqlCode(), ': ', $failure->getMessage(), PHP_EOL;
}
```

## The manual

[doc/](doc/README.md) is the manual: a User's Guide, an API Reference
and Appendices, published at
https://turborel.com/docs/drivers/php/. The SQL a
program sends, and the server, are described in Turborel's own manual,
[Turborel Documentation](https://github.com/turborel/turborel/blob/main/doc/README.md).
[VERSIONING.md](VERSIONING.md) tells how the driver's releases are
numbered and published.

## Contributing

Read [CONTRIBUTING.md](CONTRIBUTING.md) before making a change: how a
change is made, its commit message, pull requests and issues. Every
change passes `composer verify`. [RULES.md](RULES.md) says how the
driver is designed, written and verified,
[CONFORMANCE.md](CONFORMANCE.md) is the parity matrix every Turborel
driver keeps, and [TODO.md](TODO.md) lists open work.

## Security

Report a vulnerability privately, never in a public issue, as
[SECURITY.md](SECURITY.md) says.

## License and trademark

The code is under the BSD-2-Clause Plus Patent License, in
[LICENSE](LICENSE). The name Turborel and its logos are not:
[TRADEMARK.md](TRADEMARK.md) says how they may be used.

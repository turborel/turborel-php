<p align="center">
    <picture>
      <source media="(prefers-color-scheme: dark)" srcset="artwork/turborel-logo-white.svg">
      <img src="artwork/turborel-logo.svg" alt="turborel" width="360">
    </picture>
</p>

> turborel-php is in alpha, meaning, version 0.0.0. Anything may change
> from one build to the next, its classes, methods and behaviour alike.
> Its version is the turborel release it is made for, and the alpha
> speaks turborel's native protocol version 1. See
> [VERSIONING.md](VERSIONING.md).

turborel-php is the PHP driver for
[turborel](https://github.com/turborel/turborel), a relational database
system. It connects a PHP program to a turborel server, `turboreld`, over
turborel's native protocol, written in plain PHP: no C extension to
build, nothing to install but the package. Its standard interface is an
API in PDO's shape, written in PHP; a real PDO driver would need a C
extension, so this one is userland, and is not `\PDO` itself
([RULES.md](RULES.md) D2).

It is being built to do all that turborel's own client library,
turborel-client, does: TLS and SCRAM-SHA-256-PLUS, prepared statements
and cursors, large objects, copy, import and export, settings from a URI
or a service file, several servers, pipelines and pools.
[CONFORMANCE.md](CONFORMANCE.md) shows what is done, row by row, and
[TODO.md](TODO.md) what comes next. Today it holds the repository's kit
and the protocol's frame header.

## What it needs

- PHP 8.3 or later, 64-bit, on Linux, macOS or Windows.
- The extension openssl, for TLS.
- The extensions zlib, for compressed frames, and bcmath, for arithmetic
  on exact numbers, if wanted. Exact numbers come as strings either way.

It depends on no other package.

## Installing

The package is not yet published on Packagist; it will be, as
`turborel/turborel`, once every turborel driver is done. Until then,
build it from source:

```
git clone https://github.com/turborel/turborel-php.git
cd turborel-php
composer install
composer verify
```

and require it in a program's composer.json from that checkout, as a
Composer `path` repository. The User's Guide's chapter
[Installing](doc/user/01-installing.md) will say more as the driver grows.

## A first query

This is what the PDO-style API will be once milestone M7 builds it; it
is not there yet, and its names may change until then.

```php
use Turborel\Pdo\Connection;
use Turborel\TurborelException;

try {
    $db = new Connection('turborel://ann@db.example/?tls_mode=verify-full', password: 'ann\'s');
    $staff = $db->prepare('SELECT ID, NAME FROM STAFF WHERE ID < ? ORDER BY ID');
    $staff->execute([3]);
    foreach ($staff->fetchAll(Connection::FETCH_ASSOC) as $row) {
        echo $row['ID'], ' ', $row['NAME'], PHP_EOL;
    }
} catch (TurborelException $failure) {
    echo $failure->getSqlState(), ' ', $failure->getSqlCode(), ': ', $failure->getMessage(), PHP_EOL;
}
```

## Documents

- [doc/](doc/README.md): the manual: a User's Guide, an API Reference
  and Appendices, written as each milestone lands, and published on
  turborel.com's documentation site.
- [VERSIONING.md](VERSIONING.md): the version, and how a release is
  published.
- [CONFORMANCE.md](CONFORMANCE.md): what is implemented of the protocol
  and of turborel-client.
- [RULES.md](RULES.md): how the driver is designed, written and verified.
- [TODO.md](TODO.md): open work.
- [CONTRIBUTING.md](CONTRIBUTING.md) and [SECURITY.md](SECURITY.md):
  contributing, and reporting an issue or a vulnerability.
- [LICENSE](LICENSE) and [TRADEMARK.md](TRADEMARK.md): the code is
  under the BSD-2-Clause Plus Patent License; the name Turborel and its
  logos are not, and TRADEMARK.md says how they may be used.

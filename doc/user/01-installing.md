# Installing

[Contents](README.md)

The driver needs PHP 8.3 or later, 64-bit, on Linux, macOS or Windows,
with the extension openssl, for TLS. The extensions zlib, for
compressed frames, dom, for an XML value's nodes, and mbstring, for
Unicode's upper case of an import's names, are used where they are
loaded. It needs no package but PHP's own extensions, and a Turborel
server of its release, which speaks protocol version 1.

## Taking the driver in

No package is published until every Turborel driver is done; then
`composer require turborel/turborel` takes it in from Packagist. Until
then the driver is built from its source, as the repository's
[README](../../README.md) says, and a program takes the checkout in as a
Composer `path` repository:

```json
{
    "repositories": [{"type": "path", "url": "../turborel-php"}],
    "require": {"turborel/turborel": "0.0.0"}
}
```

## A first query

The standard interface is an API in PDO's shape: `Turborel\Pdo\Connection`
is made from a `turborel://` URI, as `\PDO` is from a DSN, and its
statements read rows in PDO's fetch modes. Every failure is a
`Turborel\TurborelException`.

```php
use Turborel\Pdo\Connection;
use Turborel\Pdo\Pdo;
use Turborel\TurborelException;

try {
    $db = new Connection('turborel://ann@localhost:7422/?tls_mode=verify-full&tls_roots=/etc/turborel/ca.pem');
    $db->exec('CREATE TABLE STAFF (ID INTEGER, NAME VARCHAR(20))');
    $db->exec("INSERT INTO STAFF VALUES (1, 'Ada'), (2, 'Grace'), (3, 'Barbara')");
    $staff = $db->prepare('SELECT ID, NAME FROM STAFF WHERE ID < ? ORDER BY ID');
    $staff->execute([3]);
    foreach ($staff->fetchAll(Pdo::FETCH_ASSOC) as $row) {
        echo $row['ID']->units, ' ', $row['NAME'], PHP_EOL;
    }
    $db->exec('SELECT * FROM NOPE');
} catch (TurborelException $failure) {
    echo $failure->getSqlState(), ' ', $failure->getSqlCode(), ': ', $failure->getMessage(), PHP_EOL;
}
```

```text
1 Ada
2 Grace
2A000 -103: table ANN.NOPE is not defined
```

An exact number comes as a `Turborel\Value\Exact`, its digits `units`
and its `scale`, so none is lost; [Appendix A](../appendix/a-types.md)
maps every type. The chapters after this one use the low-level client
beneath the standard interface, which has every feature of Turborel's
own client library; `Connection::client()` gives a standard connection's.

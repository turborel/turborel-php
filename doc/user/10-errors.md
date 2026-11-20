# Errors and Diagnostics

[Contents](README.md)

Every failure reaches a program as one class, `Turborel\TurborelException`,
whether the server reported it or the driver found it. A program tells
failures apart by their codes, not by their messages, which are for
people. [Errors](../client/30-errors.md) lists its methods, and
[Appendix B](../appendix/b-errors.md) the codes.

Each example runs on `$connection`, a connection made as
[Connecting](02-connecting.md) shows, as a user with a schema of its
own.

## What a failure says

`getSqlState()` gives its SQLSTATE, five characters, its class the first
two; `getSqlCode()` its SQLCODE, negative for an error; `getMessage()`
what it is. A failure the server reported says more: `getRule()`, the
rule of a standard it cites; `getPosition()`, where in the statement it
is, in characters; `isTransactionActive()`, whether the session's
transaction is active after it; and `getDiagnostics()`, granted
`diagnostics`, its severity, detail, hint, context, and the schema,
table, column, data type and constraint, each that is known. A failure
the driver found gives its severity alone, `FATAL` if it ends the
connection, else `ERROR`.

```php
$connection->query('CREATE TABLE KEYS (K INTEGER PRIMARY KEY)');
$connection->query('INSERT INTO KEYS VALUES (1)');
try {
    $connection->query('INSERT INTO KEYS VALUES (1)');
} catch (Turborel\TurborelException $failure) {
    echo $failure->getSqlState(), ' ', $failure->getSqlCode(), ': ', $failure->getMessage(), PHP_EOL;
    echo $failure->getRule(), PHP_EOL;
    print_r($failure->getDiagnostics());
}
```

```text
23000 -201: PRIMARY KEY ANN.KEYS_PRIMARY1 (K) of ANN.KEYS would be violated: a row of these values is there
X3.135-1986 §6.4 GR2
Array
(
    [severity] => ERROR
    [detail] => the key (1) is held by another row
    [schema] => ANN
    [table] => KEYS
    [constraint] => KEYS_PRIMARY1
)
```

## Failures that end the connection

A statement's failure leaves the connection as it was. A failure of the
connection itself loses it: SQLSTATE class 08, but 08003, which names
another connection, and credentials refused as a connection is made,
class 28 of SQLCODE -919. `endsConnection()` tells which, and
`isLost()` whether a connection is lost; a lost connection fails every
later request with the failure that lost it. The driver raises SQLSTATE
08006, SQLCODE -919, when the server cannot be reached, a wait runs out,
a reply's frame is malformed, or a reply comes out of turn. A reply
whose frame is whole but holds a value the driver cannot take, as a
damaged JSON value, fails its statement alone with that value's error,
as SQLCODE -916, the rest of the statement's replies read past first,
and the connection goes on.

## Warnings

A statement that completes with a warning, of SQLSTATE class 01, is not
a failure. `warning()` gives the SQLSTATE of the warning the last
statement completed with. Granted `conditions`, `takeConditions()`
gives each condition the statement raised, each a `TurborelException`
of its SQLSTATE and message, and takes them, so each is told once.

```php
$connection->query('SELECT SUM(N) FROM (VALUES (1), (CAST(NULL AS INTEGER))) AS T(N)');
echo $connection->warning(), PHP_EOL;
foreach ($connection->takeConditions() as $condition) {
    echo $condition->getSqlState(), ': ', $condition->getMessage(), PHP_EOL;
}
```

```text
01003
01003: the statement completed with warning: null value eliminated in set function
```

Granted `notices` but not `conditions`, the warnings come as notices
instead, each a `Turborel\Session\Notice` of its `sqlState`, `message`
and `diagnostics`. So do the warnings of a statement that fails, and
the server's own notices, such as 01T01 of a reset that rolled a
transaction back. A handler `setNoticeHandler` sets is given each as it
comes, before the statement's outcome; with none, the last 64 are kept,
and `takeNotices()` takes them.

```php
use Turborel\Session\Notice;

$connection->query('CREATE TABLE SCRATCH (N INTEGER)');
$connection->setNoticeHandler(static function (Notice $notice): void {
    echo 'notice ', $notice->sqlState, ' (', $notice->severity(), ')', PHP_EOL;
});
$connection->reset();
```

```text
notice 01T01 (NOTICE)
```

# Prepared Statements

[Contents](README.md)

A statement run often is prepared once, then executed with values for
its dynamic parameters, as many times as wanted.

Each example runs on `$connection`, a connection made as
[Connecting](02-connecting.md) shows, as a user with a schema of its
own.

## Preparing and executing

`prepare` prepares a statement, its dynamic parameters written `?`, and
gives a `Turborel\Statements\Statement`: its `parameters`, the type each
`?` takes, and a query's `columns`. `execute` runs it with a value for
each `?`, in order, and gives its outcome as `query` does. Values not
one for each `?` fail with SQLSTATE 07001.

```php
$connection->query('CREATE TABLE STAFF (ID INTEGER, NAME VARCHAR(20))');
$insert = $connection->prepare('INSERT INTO STAFF VALUES (?, ?)');
foreach ([[1, 'Ada'], [2, 'Grace'], [3, 'Barbara']] as $values) {
    $connection->execute($insert, $values);
}
$byId = $connection->prepare('SELECT NAME FROM STAFF WHERE ID = ?');
echo get_class($byId->parameters[0]->type), PHP_EOL;
echo $connection->execute($byId, [2])->rows[0][0], PHP_EOL;
```

```text
Turborel\Type\ExactType
Grace
```

A prepared statement stays valid across transactions, until it is
deallocated, the session reset, or the connection ended. The server
keeps a query's plan, and an INSERT's, UPDATE's or DELETE's, between
executions, and makes it again when what it was made of has changed.

## Cursors

`openCursor` runs a prepared query and gives its first rows, at most as
many as asked, all if 0, as a `Batch`: its `rows`, and `more`, whether
the cursor has more. `fetch` gives the next batch, and the batch that
reads the last row closes the cursor; `close` closes it before then. A
cursor already open, or not open, fails with SQLSTATE 24000.

```php
$all = $connection->prepare('SELECT NAME FROM STAFF ORDER BY ID');
$batch = $connection->openCursor($all, [], 2);
while (true) {
    echo implode(', ', array_column($batch->rows, 0)), $batch->more ? ' ...' : '', PHP_EOL;
    if (!$batch->more) {
        break;
    }
    $batch = $connection->fetch($all, 2);
}
```

```text
Ada, Grace ...
Barbara
```

A statement has several cursors open at once, granted `cursors`, each
by a number of the program's: the last argument of `openCursor`, and of
`fetch`, `close` and `describeCursor`, is that number, 0 unless given.
Granted `describe`, `describe` describes a statement again, as it is
now, and `describeCursor` gives an open cursor's columns.

## Deallocating

`deallocate` destroys a statement, of no use after: one whose cursor is
open fails with SQLSTATE 24000, and one no more with SQLSTATE 26000.

```php
$connection->deallocate($byId);
try {
    $connection->execute($byId, [1]);
} catch (Turborel\TurborelException $failure) {
    echo $failure->getSqlState(), ': ', $failure->getMessage(), PHP_EOL;
}
```

```text
26000: there is no prepared statement 1
```

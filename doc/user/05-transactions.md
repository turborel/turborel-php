# Transactions

[Contents](README.md)

A transaction begins with the first statement of a session, and lasts
until COMMIT or ROLLBACK, as SQL-92 has it. `inTransaction()` tells
whether one is active, as the server said after the last statement.

Each example runs on `$connection`, a connection made as
[Connecting](02-connecting.md) shows, as a user with a schema of its
own.

```php
$connection->query('CREATE TABLE ACCOUNTS (ID INTEGER, BALANCE INTEGER)');
echo var_export($connection->inTransaction(), true), PHP_EOL;
$connection->query('COMMIT');
echo var_export($connection->inTransaction(), true), PHP_EOL;
```

```text
true
false
```

## Autocommit

`setAutocommit(true)` makes each statement `query`, `queryInto`,
`queryMany` or `execute` runs its own transaction: the server commits it
as the statement succeeds, or rolls it back as it fails, in the same
request, so no COMMIT is sent. A commit that fails sends its failure in
place of the statement's completion, the transaction rolled back, as
SQLSTATE 40002 of a deferred constraint not satisfied.

```php
$connection->setAutocommit(true);
$connection->query('CREATE TABLE PARENT (K INTEGER PRIMARY KEY)');
$connection->query('CREATE TABLE CHILD (K INTEGER REFERENCES PARENT DEFERRABLE INITIALLY DEFERRED)');
try {
    $connection->query('INSERT INTO CHILD VALUES (9)');
} catch (Turborel\TurborelException $failure) {
    echo $failure->getSqlState(), ', active: ', var_export($connection->inTransaction(), true), PHP_EOL;
}
```

```text
40002, active: false
```

A result that sent a large object by its locator keeps its transaction,
under autocommit too, since the locator lasts only as long as the
transaction does.

## Cursors under autocommit

A cursor `openCursor` opens is read within its transaction. Under
autocommit, granted `fetch-ends`, the transaction ends as the cursor
does: the fetch that reads its last row commits, a fetch that fails
rolls back, and `close` commits.

## Ending a session

A reset rolls the session's transaction back, and the server tells so
by notice 01T01. `terminate` ends the transaction as
SESSION.COMMIT_AT_END says, committed by default
([Connecting](02-connecting.md#ending-a-connection)).

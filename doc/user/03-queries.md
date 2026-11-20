# Running Queries

[Contents](README.md)

A connection runs a statement with `query`, and gives what it did as an
`Outcome`. This chapter tells how; [Statements and
Cursors](../client/22-statements.md) lists every method.

Each example runs on `$connection`, a connection made as
[Connecting](02-connecting.md) shows, as a user with a schema of its
own.

## A statement and its outcome

`query` runs one statement. A query's `Outcome` holds its `columns`,
each a `Turborel\Type\Column` of a name and a type, and its `rows`, each
a list of one value for each column. Another statement's outcome has no
columns, and its `completed` gives its completion: its SQLCODE and
SQLSTATE, 0 and 00000 of a statement that did what it was asked.
`isRows()` tells the one from the other.

```php
$connection->query('CREATE TABLE STAFF (ID INTEGER, NAME VARCHAR(20))');
$connection->query("INSERT INTO STAFF VALUES (1, 'Ada'), (2, 'Grace')");
$staff = $connection->query('SELECT ID, NAME FROM STAFF ORDER BY ID');
foreach ($staff->rows as [$id, $name]) {
    echo $id->units, ' ', $name, PHP_EOL;
}
$none = $connection->query('SELECT NAME FROM STAFF WHERE ID > 9');
echo count($none->rows), ' rows, SQLCODE ', $none->completed->sqlcode(), ', SQLSTATE ', $none->completed->sqlstate(), PHP_EOL;
```

```text
1 Ada
2 Grace
0 rows, SQLCODE 100, SQLSTATE 02000
```

A query that returns no row completes with SQLCODE 100 and SQLSTATE
02000. A query's completion comes once its last row has, so `completed`
is that of a query too.

## Values

A character string comes as a PHP string, an approximate number as a
float, a boolean as a bool, and null as null. An exact number comes as
a `Turborel\Value\Exact`, of its digits as `units` and its `scale`, so
none of its digits is lost; a datetime, an interval, a binary string, a
UUID, an XML value, a large object and each composite come as a class
of `Turborel\Value`. A statement's parameters are written the same way,
and a PHP int or float is an exact or approximate number.
[Appendix A](../appendix/a-types.md) maps each type.

## Rows as they come

`query` holds every row before it returns. `queryInto` gives a query's
columns, then its rows a batch at a time as the server sends them, to a
`Turborel\Statements\Sink`, so no more than a batch is held; it returns
a `Streamed` of how many rows there were, or of another statement's
completion.

```php
use Turborel\Statements\Sink;

$connection->query('CREATE TABLE TEN (N INTEGER)');
$connection->query('INSERT INTO TEN VALUES (0), (1), (2), (3), (4), (5), (6), (7), (8), (9)');
$sum = new class implements Sink {
    public int $total = 0;
    public function columns(array $columns): void {}
    public function rows(array $rows): void
    {
        foreach ($rows as [$n]) {
            $this->total += (int) $n->units;
        }
    }
};
$streamed = $connection->queryInto('SELECT A.N FROM TEN A, TEN B, TEN C', $sum);
echo $streamed->rows, ' rows, their sum ', $sum->total, PHP_EOL;
```

```text
1000 rows, their sum 4500
```

## What a completion tells

`completed()` gives the last statement's completion, each part as the
server granted it: `rows()`, the rows an INSERT, UPDATE, DELETE or MERGE
changed, or a query returned; `generated()`, the identity values an
INSERT generated, in the order of its rows, as SQL writes them; and
`time()`, the server's time for the statement in microseconds, `total`,
`queued` waiting for a worker before it, and `locks` waiting for locks
within it. `commitPosition()` gives the log position of the last commit.

```php
$connection->setAutocommit(true);
$connection->query('CREATE TABLE PEOPLE (ID INTEGER GENERATED ALWAYS AS IDENTITY, NAME VARCHAR(20))');
$connection->query("INSERT INTO PEOPLE (NAME) VALUES ('Edsger'), ('Barbara')");
$done = $connection->completed();
echo $done->rows(), ' rows, generated ', implode(', ', $done->generated()), PHP_EOL;
```

```text
2 rows, generated 1, 2
```

## Several statements in one request

`queryMany` runs a text of several statements, split at each semicolon
outside a string, a comment or a compound statement, as one request:
each in order, until one fails. Its `Ran` gives each outcome in
`outcomes`, and in `failure` the failure that stopped the rest, if one
did.

```php
$ran = $connection->queryMany("CREATE TABLE NOTES (T VARCHAR(10)); INSERT INTO NOTES VALUES ('a'); SELECT * FROM NOPE; INSERT INTO NOTES VALUES ('b')");
echo count($ran->outcomes), ' ran; then ', $ran->failure->getSqlState(), ': ', $ran->failure->getMessage(), PHP_EOL;
```

```text
2 ran; then 2A000: table ANN.NOPE is not defined
```

## Query attributes

`setQueryAttributes` sets attributes, by name, that every later
statement carries until they are set again; SQL reads one by
QUERY_ATTRIBUTE(name). One named `traceparent` is a request's trace
context, which the server writes in its record of a slow statement.

```php
$connection->setQueryAttributes(['team' => 'tea']);
echo $connection->query("VALUES QUERY_ATTRIBUTE('team')")->rows[0][0], PHP_EOL;
```

```text
tea
```

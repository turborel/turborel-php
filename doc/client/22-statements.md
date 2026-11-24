# Statements and Cursors

[Contents](README.md)

## NAME

`Turborel\Session\Connection`'s statements: direct SQL, prepared
statements, cursors, and what each statement's completion tells.

## SYNOPSIS

```php
final class Connection
{
    public function query(string $sql): Outcome;
    public function queryInto(string $sql, Sink $sink): Streamed;
    public function queryMany(string $sql): Ran;
    public function prepare(string $sql): Statement;
    public function execute(Statement $statement, array $values = []): Outcome;
    public function openCursor(Statement $statement, array $values = [], int $rows = 0, int $cursor = 0): Batch;
    public function fetch(Statement $statement, int $rows = 0, int $cursor = 0): Batch;
    public function close(Statement $statement, int $cursor = 0): void;
    public function describe(Statement $statement): Statement;
    public function describeCursor(Statement $statement, int $cursor = 0): array;
    public function deallocate(Statement $statement): void;
    public function completed(): ?Completed;
    public function commitPosition(): int|string|null;
    public function warning(): ?string;
    public function takeConditions(): array;
    public function setNoticeHandler(?\Closure $handler): void;
    public function takeNotices(): array;
    public function setQueryAttributes(array $attributes): void;
}
```

`Outcome` holds `columns`, `rows` and `completed`, and `isRows()`;
`Streamed` holds `rows`, a count, and `completed`; `Ran` holds
`outcomes` and `failure`; `Statement` holds `number`, `parameters` and
`columns`; `Batch` holds `rows` and `more`. `Sink` takes `columns` and
then `rows`, a batch at a time. A `Turborel\Type\Column` holds `name`,
`type`, as its values' tag tells it, `notNull`, and, granted
`declared-types`, `declared`: the type it was declared with, its
`name` as the Information Schema's DATA_TYPE gives it, as INTEGER, and
its type as SQL `written`; `typeWritten()` gives the type as it was
declared, else as its tag's type is written, as NUMERIC(10,0).

A `Statement`'s `parameters` are those an execution gives values for:
of a CALL, not its OUT ones, whose values come back as the row its
`columns` describe. Granted `parameter-modes`, which a connection asks
for, a CALL of an SQL-invoked procedure has `called`, a
`Turborel\Type\Called` of the procedure's `schema` and `name` and each
`?` in written order; `dynamicParameters()` gives every `?` as a
`Turborel\Type\Parameter` of its `mode`, a `ParameterMode` (`In`,
`InOut` or `Out`), and its parameter's `ordinal` position, from 1. Of
another statement, or a procedure written in Rust, each is `In`.

## DESCRIPTION

`query` runs a direct SQL statement and gives a query's columns and
rows, or another statement's completion. `queryInto` gives a query's
columns and rows to a `Sink` as they come. Granted `multi-statement`,
`queryMany` runs several statements as one request, until one fails.
Under `setAutocommit(true)` each statement is its own transaction, ended
in the same request.

`prepare` prepares a statement, its dynamic parameters written `?`;
`execute` runs it with a value for each. `openCursor` runs a prepared
query and gives its first `$rows` rows, all if 0; `fetch` gives the
next, and `close` closes the cursor. Granted `cursors`, the cursor
number keeps several of one statement open; granted `describe`,
`describe` and `describeCursor` describe a statement and an open cursor
again. Under autocommit, granted `fetch-ends`, a cursor's transaction
ends as it does. `deallocate` destroys a statement.

`completed` gives the last statement's completion as a `Completed`:
`sqlcode()`, `sqlstate()`, `active()`, `isWarning()`, and as granted
`rows()`, `generated()`, `time()`, `position()` and `conditions()`. A
count or position past `PHP_INT_MAX` comes as a string of its digits.
`commitPosition` gives the log position of the last commit, `warning`
the last statement's warning. Granted `conditions`, `takeConditions`
takes the last statement's conditions. A `Notice` goes to the handler
`setNoticeHandler` sets, as it comes, else the last 64 are kept for
`takeNotices`. Granted `query-attributes`, `setQueryAttributes` sets the
attributes every later Query and Execute carries.

## ERRORS

| SQLSTATE | SQLCODE | When |
|---|---|---|
| 07001 | -921 | Values not one for each `?` |
| 24000 | -501 | A cursor already open, or not open; a statement deallocated or prepared again while its cursor is open |
| 26000 | -921 | A statement that is no more |
| 22003 | -301 | A row count or cursor number not a u32 |
| 40002 | -913 | A commit under autocommit that fails |
| 08006 | -919 | A capability not granted, before anything is sent; a reply out of turn, or its frame malformed, which loses the connection |
| TB916, 0A000 | -916, -900 | A reply holding a value the driver cannot take: the statement fails, the rest of its replies read past, the connection kept |

A statement's own failures are the server's; [Appendix B](../appendix/b-errors.md)
lists their classes.

## EXAMPLES

On `$connection`, a `Connection`:

```php
$connection->query('CREATE TABLE STAFF (ID INTEGER, NAME VARCHAR(20))');
$insert = $connection->prepare('INSERT INTO STAFF VALUES (?, ?)');
$connection->execute($insert, [1, 'Ada']);
$connection->execute($insert, [2, 'Grace']);
$all = $connection->prepare('SELECT NAME FROM STAFF ORDER BY ID');
$first = $connection->openCursor($all, [], 1);
$rest = $connection->fetch($all);
echo $first->rows[0][0], ' then ', $rest->rows[0][0], ', more: ', var_export($rest->more, true), PHP_EOL;
$id = $connection->prepare('SELECT ID FROM STAFF')->columns[0];
echo $id->declared?->name, ', tag type ', get_debug_type($id->type), PHP_EOL;
```

```text
Ada then Grace, more: false
INTEGER, tag type Turborel\Type\ExactType
```

## STANDARD

[PROTOCOL Query 'Q'], [PROTOCOL Prepare 'P'], [PROTOCOL Execute 'E'],
[PROTOCOL Fetch 'F'], [PROTOCOL Close 'C'], [PROTOCOL Deallocate 'D'],
[PROTOCOL Describe 'Y'], [PROTOCOL Complete 'K'], [PROTOCOL Declared types], [PROTOCOL Parameter modes],
[PROTOCOL Conversations], [CLIENT Running statements],
[CLIENT Prepared statements], [CLIENT What the server tells],
[CLIENT Controlling the session].

## SEE ALSO

[Connections](21-connections.md), [Values](23-values.md),
[Errors](30-errors.md).

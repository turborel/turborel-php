# Copying, Importing and Exporting

[Contents](README.md)

Rows go into a table by copy, with no round trip but the copy's end;
a source's rows by import; a query's rows out by export.
[Copy, Import and Export](../client/25-copy.md) lists the classes.
Each example runs on `$connection`, a connection made as
[Connecting](02-connecting.md) shows, as a user with a schema of its own.

## Copying

`copyIn` begins a copy of rows into a table, of the columns named, or
all. Its `send` sends a row, many to a message and no reply each, and
`finish` ends the copy, giving the rows inserted. Where the server
grants `columns`, rows of strings and nulls go by column, each cell its
text, the faster way to load many rows; others go by row. Each value is
assigned to its column as INSERT assigns it, so a string too long fails
rather than being cut. A copy is all or nothing: a row
refused undoes every row of it, its failure's detail naming the row,
and within a transaction the copy alone is undone. A copy let go of
before `finish` is abandoned. Until a copy ends, the connection sends
nothing but its rows, its end and a ping. `copyRows` copies the rows an
iterator gives.

```php
$connection->setAutocommit(true);
$connection->query('CREATE TABLE STAFF (ID INTEGER PRIMARY KEY, NAME VARCHAR(20))');
echo $connection->copyRows('STAFF', ['ID', 'NAME'], [[1, 'Ada'], [2, 'Grace']]), ' rows', PHP_EOL;
try {
    $connection->copyRows('STAFF', [], [[3, 'Niklaus'], [1, 'Edsger']]);
} catch (Turborel\TurborelException $failure) {
    echo $failure->getSqlState(), ', ', $failure->getDiagnostics()['detail'], PHP_EOL;
}
```

```text
2 rows
23000, row 2 of the copy
```

## Importing

`Import` inserts the rows of a `Source` into a table: `Import::into`
names the table, a batch of 1000 rows committed at a time; the
constructor takes the batch, 0 for all at once, and whether a row that
fails is skipped, each then given to the closure `run` takes. A source
gives its `headings`, its fields' names, or none, its fields then the
table's columns in order; then each `record`, a value for each field
and where it began. `Delimited` reads CSV (`Dialect::csv()`) or
tab-separated text (`Dialect::tsv()`) from a stream, with a header, a
delimiter and a text for null as its `Dialect` says, a record at a time.

The import goes by copy where the server grants it, no bad row is to be
skipped and no column is a large object's; else through a prepared
INSERT, a field longer than 64 KiB for a large object column sent by a
large object made for it. The session must have no transaction active.
A row that fails ends the import with an `ImportFailure`: where its
record began, why, the rows committed, and how many of the batch rolled
back.

```php
use Turborel\Copy\Delimited;
use Turborel\Copy\Dialect;
use Turborel\Copy\Import;

$connection->query('CREATE TABLE PEOPLE (ID INTEGER PRIMARY KEY, NAME VARCHAR(10))');
$connection->query('COMMIT');
$csv = fopen('php://memory', 'w+');
fwrite($csv, "ID,NAME\n1,Ada\n2,\"Hopper, G\"\n3,Barbara Liskov\n");
rewind($csv);
try {
    Import::into('PEOPLE')->run($connection, new Delimited($csv, Dialect::csv()->withHeader()));
} catch (Turborel\Copy\ImportFailure $stopped) {
    echo $stopped->getMessage(), PHP_EOL;
}
```

```text
record 3, line 4: SQLCODE -301 (SQLSTATE 22001): a value of 14 characters, not spaces beyond 10, does not fit CHARACTER VARYING(10) [ISO/IEC 9075:1992 §13.8 GR4b iii, §9.2 GR3e ii]; 0 rows committed, 2 rolled back
```

## Exporting

`export` runs a query and writes its rows to a stream as they come, as
CSV (`ExportFormat::Csv`, the default), a header of its columns' names
then a record for each row, or as the protocol's own frames
(`ExportFormat::Native`), which `NativeRows::read` reads back with every
value as it was. A CSV field is quoted where it holds a comma, a quote,
a line break or spaces at either end; a null is an empty field, and an
empty string `""`; each record ends with CR LF. Each value is written as turborel shows it.

```php
$connection->query('CREATE TABLE TEAS (NAME VARCHAR(20), PRICE DECIMAL(5, 2), SINCE DATE)');
$connection->query("INSERT INTO TEAS VALUES ('Assam, strong', 4.50, DATE '2026-11-19'), ('Sencha', NULL, NULL)");
$out = fopen('php://memory', 'w+');
$connection->export('SELECT NAME, PRICE, SINCE FROM TEAS ORDER BY NAME', $out);
foreach (explode("\r\n", rtrim(stream_get_contents($out, -1, 0))) as $record) {
    echo $record, PHP_EOL;
}
```

```text
NAME,PRICE,SINCE
"Assam, strong",4.50,2026-11-19
Sencha,,
```

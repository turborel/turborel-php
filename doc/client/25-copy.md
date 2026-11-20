# Copy, Import and Export

[Contents](README.md)

## NAME

`Turborel\Copy`: copies into a table, imports of a source's rows, and
exports of a query's.

## SYNOPSIS

```php
final class Connection
{
    public function copyIn(string $table, array $columns = []): Copying;
    public function copyRows(string $table, array $columns, iterable $rows): int|string;
    public function export(string $sql, mixed $out, ExportFormat $format = ExportFormat::Csv): int;
}

final class Copying
{
    public function send(array $row): void;
    public function finish(): int|string;
    public function abandon(): void;
}

final readonly class Import
{
    public function __construct(string $table, int $batch = Import::DEFAULT_BATCH, bool $skip = false);
    public static function into(string $table): self;
    public function run(Connection $connection, Source $source, ?\Closure $rejected = null): Imported;
}

interface Source
{
    public function headings(): ?array;
    public function record(): ?Record;
}

final class Delimited implements Source
{
    public function __construct(mixed $input, Dialect $dialect);
}

enum ExportFormat { case Csv; case Native; }

final readonly class NativeRows
{
    public static function read(mixed $input): self;
}
```

`Dialect` is made by `csv()` or `tsv()` and changed by
`withDelimiter`, `withHeader`, `withNull` and `withMaxRecord`.
`Imported` holds `rows` and `rejected`; `Rejected` a `position`, a
`cause` and whether it is the input's; `Record` its `values` and
`position`; `Position` its `record` and `line`; `Heading` a `name` and a
`type`; `NativeRows` its `columns` and `rows`.

## DESCRIPTION

`copyIn` begins a copy into a table, of the columns named or all, under
autocommit its own transaction, granted `copy`. `send` sends a row,
many to a message with no reply each; `finish` ends the copy and gives
the rows inserted; `abandon` undoes every row. A copy is all or
nothing, a row refused undoing every row, its failure's detail naming
it, as `row 2 of the copy`. Until it ends, the connection sends nothing
but its rows, its end and Ping. A copy let go of before it ends is
abandoned. `copyRows` copies an iterator's rows.

`Import::run` inserts a source's records into the table, whose session
has no transaction active: by copy where `copy` is granted, no bad row
is to be skipped and no column is a large object's; else through a
prepared INSERT, each string for a character string column assigned as
INSERT assigns it, and each value longer than 64 KiB for a large object
column sent by a large object made for it. A batch is committed at a
time, 1000 rows by default, 0 for all at once. With `skip`, a row that
fails is given to `$rejected`, and the import goes on.

A `Source` gives its headings once, or none, then its records until it
gives none, holding a record at a time. `Delimited` reads CSV or
tab-separated text from a stream: records end at CR LF or LF; a CSV
field may be enclosed in quotes, a quote in it written twice; a field
written as the dialect's text for null, not quoted, is null, an empty
one by default; a byte order mark beginning the input is passed over; a
record past the longest, 16 MiB by default, is refused.

`export` runs a query and writes its rows to a stream as they come, as
CSV, a header of the columns' names then a record for each row ended by
CR LF, or as native rows, which `NativeRows::read` reads back.

## ERRORS

| SQLSTATE | SQLCODE | When |
|---|---|---|
| 08006 | -919 | `copy` not granted; an export of a statement no query, or a write to its stream that failed; input that is not native rows |
| TB910 | -910 | A request other than a copy's rows, its end or Ping while the copy is under way; a delimiter not one ASCII character, nor a quote or a line break |
| 22000 | -301 | A source that cannot be read, a record of too many or too few fields, a session with a transaction active, a table name that is not one: an import's input, `SourceFailure` or `ImportFailure` |
| 22001 | -301 | A string longer than its column |

An `ImportFailure` takes the SQLSTATE and SQLCODE of its cause, the
server's or the input's, and its `position`, `committed` and
`rolledBack` say where it stopped and what was kept.

## EXAMPLES

On `$connection`, a `Connection`:

```php
$connection->query('CREATE TABLE NUMBERS (N INTEGER)');
$copying = $connection->copyIn('NUMBERS');
foreach (range(1, 5000) as $n) {
    $copying->send([$n]);
}
echo $copying->finish(), ' rows copied', PHP_EOL;
$connection->query('COMMIT');
```

```text
5000 rows copied
```

## STANDARD

[PROTOCOL CopyIn 'I'], [PROTOCOL CopyData 'J'], [PROTOCOL CopyEnd 'N'],
[PROTOCOL Conversations], [CLIENT Importing],
[CLIENT Copying and exporting].

## SEE ALSO

[Large Objects](24-large-objects.md), [Errors](30-errors.md).

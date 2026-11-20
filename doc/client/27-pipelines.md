# Pipelines

[Contents](README.md)

## NAME

`Turborel\Pipeline\Pipeline`: a connection's requests written at once,
one outcome each.

## SYNOPSIS

```php
final class Pipeline
{
    public const int WINDOW = 65536;
    public function query(string $sql): self;
    public function execute(Statement $statement, array $values = []): self;
    public function onError(OnError $onError): self;
    public function count(): int;
    public function isEmpty(): bool;
    public function run(): array;
}

enum OnError { case Continue; case Skip; }

final readonly class Piped
{
    public ?Outcome $outcome;
    public ?TurborelException $failure;
    public function isSkipped(): bool;
}
```

## DESCRIPTION

`Connection::pipeline()` gives a pipeline, empty, continuing past a
failure. `query` and `execute` queue requests, run as the connection's
`query` and `execute` run them; `run` writes them a window of 64 KiB at a
time, reading each window's replies before the next is written, and
gives one `Piped` for each, in order: its `outcome`, or its `failure`,
the requests after it run. Under autocommit each request is its own
transaction.

Under `OnError::Skip` the batch stops at its first failure: a savepoint
`TURBOREL_PIPELINE_n` is set before each request but the first, the
requests after a failure rolled back to the one set after it, or never
sent if their window was not yet written, each skipped. The batch is one
transaction, committed as it ends under autocommit where it began none.

## ERRORS

| SQLSTATE | SQLCODE | When |
|---|---|---|
| 08006 | -919 | The connection lost: what the requests answered before is not given |
| | | A skipping batch whose undoing, release or commit fails: that failure |

## EXAMPLES

On `$connection`, a `Connection`:

```php
use Turborel\Pipeline\OnError;

$connection->setAutocommit(true);
$connection->query('CREATE TABLE KEYS (K INTEGER PRIMARY KEY)');
$outcomes = $connection->pipeline()->onError(OnError::Skip)
    ->query('INSERT INTO KEYS VALUES (1)')->query('INSERT INTO KEYS VALUES (1)')->query('INSERT INTO KEYS VALUES (2)')->run();
foreach ($outcomes as $piped) {
    echo $piped->isSkipped() ? 'skipped' : ($piped->failure?->getSqlState() ?? 'done'), PHP_EOL;
}
```

```text
done
23000
skipped
```

## STANDARD

[PROTOCOL Requests], [CLIENT Pipelines].

## SEE ALSO

[Statements and Cursors](22-statements.md), [Pools](28-pools.md).

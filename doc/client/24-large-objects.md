# Large Objects

[Contents](README.md)

## NAME

`Turborel\Session\Connection`'s large objects, and
`Turborel\Lobs\LobWriter`.

## SYNOPSIS

```php
final class Connection
{
    public function readLob(Lob|LobLocator $lob, int|string $from, int|string $count, bool $characters = false): string;
    public function lobReader(Lob|LobLocator $lob): mixed;
    public function createLob(LobKind $kind): LobWriter;
    public function freeLob(LobLocator $lob): Completed;
    public function holdLob(LobLocator $lob): Completed;
}

final class LobWriter
{
    public function write(string $octets): void;
    public function flush(): void;
    public function finish(): LobLocator;
}
```

## DESCRIPTION

A large object of at most SERVER.LOB_INLINE_BYTES, 64 KiB by default,
comes whole, a `Lob` of its `kind` and `octets`; a longer one as a
`LobLocator` of its `kind`, `locator`, `octets` and `characters`, valid
until its transaction ends, which it keeps open under autocommit too.

`readLob` gives at most a chunk of 256 KiB from octet `$from`, at most
`$count`, or of a character one's whole characters if `$characters`;
fewer only at its end, or a chunk's. `lobReader` gives a read-only
stream of the whole value, which `fread` and `stream_get_contents`
read, a chunk asked for at a time; a failing read throws from the
function that read. `freeLob` ends a locator; `holdLob` keeps it past
the commit, until it is freed or a rollback ends it.

`createLob` begins a value of `LobKind::Character` or `LobKind::Binary`.
`write` takes its octets, sent in Appends of a chunk; a character one's
are UTF-8, a character split between writes counted once. `finish` sends
the rest and gives the value as a `LobLocator`, to pass as a parameter,
valid until its transaction ends.

## ERRORS

| SQLSTATE | SQLCODE | When |
|---|---|---|
| 0F001 | -301 | A locator no longer valid |
| 22021 | -301 | A character large object's octets not UTF-8, as the server finds them |
| 22001 | -301 | A value longer than its column takes |
| 08006 | -919 | A reply out of turn, or the server sending less than a value's length |

## EXAMPLES

On `$connection`, a `Connection`:

```php
use Turborel\Value\LobKind;

$writer = $connection->createLob(LobKind::Binary);
for ($n = 0; $n < 4; $n++) {
    $writer->write(str_repeat("\x07", 100000));
}
$scan = $writer->finish();
$connection->query('CREATE TABLE IMAGES (IMAGE BLOB(1M))');
$connection->execute($connection->prepare('INSERT INTO IMAGES VALUES (?)'), [$scan]);
$image = $connection->query('SELECT IMAGE FROM IMAGES')->rows[0][0];
echo strlen(stream_get_contents($connection->lobReader($image))), PHP_EOL;
$connection->query('COMMIT');
```

```text
400000
```

## STANDARD

[PROTOCOL Values], [PROTOCOL Lob 'L'], [CLIENT Large objects].

## SEE ALSO

[Values](23-values.md), [Statements and Cursors](22-statements.md).

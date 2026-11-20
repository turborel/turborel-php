# The Connection Class

[Contents](README.md)

## NAME

`Turborel\Pdo\Connection`: a connection in PDO's shape, the driver's
standard interface.

## SYNOPSIS

```php
final class Connection
{
    public function __construct(string|Settings $dsn, ?string $username = null, ?string $password = null, ?array $options = null);
    public static function fromSettings(Settings $settings, array $attributes = []): self;
    public function prepare(string $sql, array $options = []): Statement;
    public function query(string $sql, ?int $mode = null, mixed ...$args): Statement;
    public function exec(string $sql): int;
    public function beginTransaction(): bool;
    public function commit(): bool;
    public function rollBack(): bool;
    public function inTransaction(): bool;
    public function lastInsertId(?string $name = null): string|false;
    public function quote(string $string, int $type = Pdo::PARAM_STR): string;
    public function setAttribute(int $attribute, mixed $value): bool;
    public function getAttribute(int $attribute): mixed;
    public function errorCode(): string;
    public function errorInfo(): array;
    public function client(): Turborel\Session\Connection;
}
```

`Turborel\Pdo\Pdo` holds the constants, each of `\PDO`'s value:
`PARAM_NULL`, `PARAM_INT`, `PARAM_STR`, `PARAM_LOB`, `PARAM_BOOL`; the
fetch modes; `ATTR_AUTOCOMMIT`, `ATTR_ERRMODE`, `ATTR_SERVER_VERSION`,
`ATTR_CLIENT_VERSION`, `ATTR_DRIVER_NAME` and `ATTR_DEFAULT_FETCH_MODE`;
`ERRMODE_EXCEPTION`.

## DESCRIPTION

A connection is made from a connection URI, as
[Settings](26-settings.md) reads one, `$username` and `$password` given
apart over its own, or from the driver's `Settings`. Its options are
attributes set as it opens.

`prepare` prepares a statement, its parameters written `?`; `query` runs
one at once, its rows read in the mode given; `exec` runs one and gives
the rows it inserted, updated, deleted or merged, or a query returned.
Each statement is its own transaction, as PDO's autocommit makes it,
until `beginTransaction` begins one that lasts until `commit` or
`rollBack`; with ATTR_AUTOCOMMIT false, statements make a transaction
`commit` ends. `inTransaction` says whether one is active.
`lastInsertId` gives the last identity value an INSERT generated, as SQL
writes it. `quote` writes a string as a literal.

Errors are always raised as exceptions: ATTR_ERRMODE is
ERRMODE_EXCEPTION, and no other mode is taken. `errorCode` and
`errorInfo` give the last operation's failure as PDO gives it, its
SQLSTATE, SQLCODE and message. `client` gives the low-level connection,
for what the standard interface has no place for.

## ERRORS

| SQLSTATE | SQLCODE | When |
|---|---|---|
| 08001 | -919 | A URI or setting that cannot be read |
| TB910 | -910 | An attribute not taken, an error mode other than exceptions, a transaction begun within one, or ended where none is |
| | | A statement's failure, the server's |

## EXAMPLES

```php
use Turborel\Pdo\Connection;
use Turborel\Pdo\Pdo;

$db = new Connection('turborel://ann@127.0.0.1:7422/?tls_mode=disable', options: [Pdo::ATTR_DEFAULT_FETCH_MODE => Pdo::FETCH_NUM]);
$db->exec('CREATE TABLE PEOPLE (ID INTEGER GENERATED ALWAYS AS IDENTITY, NAME VARCHAR(20))');
$db->beginTransaction();
$db->exec("INSERT INTO PEOPLE (NAME) VALUES ('Edsger')");
$db->commit();
echo $db->lastInsertId(), ' ', $db->getAttribute(Pdo::ATTR_SERVER_VERSION), PHP_EOL;
```

```text
1 turborel 0.0.0 (alpha)
```

## STANDARD

[DESIGN] D2; [CLIENT Connecting by settings].

## SEE ALSO

[The Statement Class](02-api-statement.md), [Errors](30-errors.md).

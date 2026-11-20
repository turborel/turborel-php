# The Statement Class

[Contents](README.md)

## NAME

`Turborel\Pdo\Statement`: a statement in PDO's shape.

## SYNOPSIS

```php
final class Statement implements \IteratorAggregate
{
    public readonly string $queryString;
    public function bindValue(int|string $param, mixed $value, int $type = Pdo::PARAM_STR): bool;
    public function bindParam(int|string $param, mixed &$var, int $type = Pdo::PARAM_STR, int $maxLength = 0, mixed $driverOptions = null): bool;
    public function execute(?array $params = null): bool;
    public function fetch(?int $mode = null, int $cursorOrientation = Pdo::FETCH_ORI_NEXT, int $cursorOffset = 0): mixed;
    public function fetchAll(?int $mode = null, mixed ...$args): array;
    public function fetchColumn(int $column = 0): mixed;
    public function setFetchMode(int $mode, mixed ...$args): bool;
    public function rowCount(): int;
    public function columnCount(): int;
    public function getColumnMeta(int $column): array|false;
    public function closeCursor(): bool;
    public function errorCode(): string;
    public function errorInfo(): array;
    public function getIterator(): \Generator;
}
```

## DESCRIPTION

A statement's `?` parameters are bound by position, from 1: `bindValue`
binds a value, `bindParam` a variable read as the statement executes;
or `execute` takes a value for each, in order. A value is taken as its
type says: PARAM_INT makes an integer of a string of an integer's
digits, PARAM_STR a string of a number or truth value, PARAM_BOOL a
truth value, PARAM_LOB a string of a stream's contents, PARAM_NULL null;
any other value goes as it is, a class of `Turborel\Value` among them. A
parameter named, as `:id`, is refused, the protocol's parameters being
`?` alone.

`execute` runs it; its rows are read, in the order they came, by
`fetch`, `fetchAll`, `fetchColumn`, or iteration, in the mode
`setFetchMode` sets, or the connection's default: FETCH_ASSOC by column
name, FETCH_NUM by number from 0, FETCH_BOTH both, FETCH_OBJ a
`\stdClass`, FETCH_COLUMN one column's value, FETCH_CLASS an object of
the class named, each property it declares of a column set before its
constructor runs. A value comes as [Appendix A](../appendix/a-types.md)
maps it. A column of no name is named by the empty string.

`rowCount` gives the rows the statement changed, or a query returned;
`columnCount` its columns; `getColumnMeta` a column's name, its SQL
type's name as `native_type`, the PDO type it reads as, its length and
precision, and `not_null` among its flags where it takes no null.
`closeCursor` lets its rows go.

## ERRORS

| SQLSTATE | SQLCODE | When |
|---|---|---|
| TB910 | -910 | A parameter named, or a position not bound; a fetch mode not taken; an orientation other than the next row; a column not there; parameters given a statement run as written |
| | | The statement's failure, the server's |

## EXAMPLES

On `$db`, a `Turborel\Pdo\Connection`:

```php
use Turborel\Pdo\Pdo;

$db->exec('CREATE TABLE TEAS (NAME VARCHAR(20) NOT NULL, PRICE DECIMAL(5, 2))');
$insert = $db->prepare('INSERT INTO TEAS VALUES (?, ?)');
$name = 'Assam';
$insert->bindParam(1, $name);
$insert->bindValue(2, new Turborel\Value\Exact('450', 2));
$insert->execute();
$name = 'Sencha';
$insert->execute();
$teas = $db->query('SELECT NAME, PRICE FROM TEAS ORDER BY NAME', Pdo::FETCH_OBJ);
foreach ($teas as $tea) {
    echo $tea->NAME, ' ', $tea->PRICE, PHP_EOL;
}
echo $teas->getColumnMeta(0)['native_type'], PHP_EOL;
```

```text
Assam 4.50
Sencha 4.50
CHARACTER VARYING
```

## STANDARD

[DESIGN] D2; [CLIENT Prepared statements].

## SEE ALSO

[The Connection Class](01-api-connection.md), [Values](23-values.md).

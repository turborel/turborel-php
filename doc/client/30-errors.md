# Errors

[Contents](README.md)

## NAME

`Turborel\TurborelException`: the driver's one error.

## SYNOPSIS

```php
class TurborelException extends \RuntimeException
{
    public function __construct(string $message, string $sqlState, int $sqlCode, ?\Throwable $previous = null);
    public function getSqlState(): string;
    public function getSqlCode(): int;
    public function getRule(): ?string;
    public function getPosition(): int|string|null;
    public function isTransactionActive(): ?bool;
    public function getDiagnostics(): array;
    public function endsConnection(): bool;
}
```

## DESCRIPTION

Every failure the driver gives is a `TurborelException`, whether the
server reported it or the driver found it. `getSqlState` gives its
SQLSTATE, five characters; `getSqlCode` its SQLCODE, negative for an
error; `getMessage` what it is, for people; `getPrevious` its cause, if
it had one.

A failure the server reported says more. `getRule` gives the rule of a
standard it cites, as `X3.135-1986 §6.4 GR2`; `getPosition` where in
the statement it is, in characters; `isTransactionActive` whether the
session's transaction is active after it, none of a failure the driver
found; `getDiagnostics`, granted `diagnostics`, its fields by name:
`severity`, `detail`, `hint`, `context`, `schema`, `table`, `column`,
`dataType` and `constraint`, each that is known. A statement's
conditions, `takeConditions`, are `TurborelException`s too, of class 01
or 02, never thrown.

`endsConnection` tells whether the failure lost its connection: a
connection exception, class 08 but 08003, or credentials refused as the
connection is made, class 28 of SQLCODE -919.

## ERRORS

The failures the driver raises itself:

| SQLSTATE | SQLCODE | When |
|---|---|---|
| 08006 | -919 | The server not reached; TLS failing; a wait run out; a reply that cannot be read, or out of turn; a capability not granted |
| 28000 | -919 | A secret over plain TCP; SCRAM's exchange refused |
| 22003 | -301 | A number, or a field's value, out of its range |
| 22021 | -301 | A string that is not UTF-8 |
| 22032 | -301 | JSON text that does not parse |
| 2200N | -301 | An XML value's text that is not well-formed content, as `nodes()` parses it |
| 07006 | -921 | A PHP value the protocol has no value for |

## EXAMPLES

On `$connection`, a `Connection`:

```php
try {
    $connection->query('SELECT * FROM NOPE');
} catch (Turborel\TurborelException $failure) {
    echo $failure->getSqlState(), ' ', $failure->getSqlCode(), ' at ', $failure->getPosition(), ': ', $failure->getMessage(), PHP_EOL;
    echo $failure->getRule(), ', ends the connection: ', var_export($failure->endsConnection(), true), PHP_EOL;
}
```

```text
2A000 -103 at 14: table ANN.NOPE is not defined
X3.135-1986 §5.4 SR5, ends the connection: false
```

## STANDARD

[PROTOCOL Error 'E'], [PROTOCOL Errors and notices],
[CLIENT Running statements], [CLIENT What the server tells].

## SEE ALSO

[Appendix B](../appendix/b-errors.md),
[Statements and Cursors](22-statements.md).

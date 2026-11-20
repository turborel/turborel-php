# Values

[Contents](README.md)

## NAME

`Turborel\Value`, `Turborel\Type`: the values the low-level client gives
and takes for each of the protocol's types, and the types of columns and
parameters.

## SYNOPSIS

```php
use Turborel\Value\Exact;
use Turborel\Value\Json;
use Turborel\Value\Uuid;

$price = Exact::fromString('-12.340');      // units '-12340', scale 3
$document = Json::fromText('{"a":[1,2.5]}');
$id = Uuid::fromText('919108f7-52d1-4320-9bac-f847db4148a8');
```

## DESCRIPTION

A value is PHP's own where PHP has a type that holds every value of its
SQL type: `null`, a `string` for a character string, a `float` for an
approximate number, a `bool` for a truth value, and an `int` sent as an
exact number of scale 0. Every other value is an object of
`Turborel\Value`, made by its constructor or a named constructor, and
checked as it is made. [Appendix A](../appendix/a-types.md) maps each
tag to its class.

An exact number keeps its units, an `i128`, as the string of their
digits, and its scale, so every exact number Turborel holds reaches a
program with no loss: `(string) $price` writes it with its point,
`-12.340`. A `DecFloat` is kept in Turborel's one form, its trailing
zeros in its exponent. A `Json` holds its stored form, which `text()`
writes as JSON text and `value()` gives as PHP's values. A `Uuid` holds
its 16 octets, and `(string) $id` gives its text.

The connection that reads and sends them is filled at milestone M2, and
the rest of this page at M3.

## ERRORS

| SQLSTATE | SQLCODE | When |
|---|---|---|
| 22003 | -301 | A value, or one of its fields, out of its range: an exact number's units past an `i128`, a `DecFloat` past 34 digits, a UUID not of 16 octets |
| 22021 | -301 | A string, or a character large object, that is not UTF-8 |
| 22032 | -301 | JSON text that does not parse, damaged stored form, or JSON with no text, a datetime's |
| 07006 | -921 | A PHP value no SQL value is, sent as a parameter |
| 08006 | -919 | A value a server sent that cannot be read |

## EXAMPLES

```php
$json = Json::fromText('{"b":1,"a":2,"b":3}');
echo $json->text(), PHP_EOL;
var_dump($json->value());
```

```text
{"b":1,"a":2,"b":3}
object(stdClass)#5 (2) {
  ["b"]=>
  int(1)
  ["a"]=>
  int(2)
}
```

## STANDARD

[PROTOCOL Data types], [PROTOCOL Values], and Turborel's RULES.md D89
and D184.

## SEE ALSO

[Appendix A: Types](../appendix/a-types.md), [Errors](30-errors.md).

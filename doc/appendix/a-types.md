# Appendix A: Types

[Contents](README.md)

How each data type and value of the native protocol, version 1, reaches
a PHP program, and what the driver takes for each when a program sends
one. The values are PHP's own where PHP has a type that holds every
value of the SQL type, and otherwise classes of the namespace
`Turborel\Value`, each implementing `Turborel\Value\SqlValue`. A
column's or a parameter's type is an object of the namespace
`Turborel\Type`. The tags are those of
[The Native Protocol](https://github.com/turborel/turborel/blob/main/doc/client/05-protocol.md),
and UUID's, 27, that of Turborel's RULES.md D184.

## Values

| Tag | SQL value | PHP, as read | PHP, as sent |
|---|---|---|---|
| 0 | null | `null` | `null` |
| 1 | character string, of CHARACTER or CHARACTER VARYING | `string`, UTF-8 | `string`, which must be UTF-8 |
| 2 | exact number | `Exact`: `units`, the digits of an `i128`, and `scale`, 0 to 4294967295 | `Exact`, or an `int` as an exact number of scale 0 |
| 3 | approximate number | `float`, its bits kept, NaN and -0.0 among them | `float` |
| 4 | a plug-in's value | `PluginValue`: its type's name, its text and its bytes | the `PluginValue` read, unchanged |
| 6 | binary string, of BINARY or BINARY VARYING | `Binary`: its octets | `Binary` |
| 8, 9, 11 | date, time, timestamp | `Datetime`: `moment` (`Moment::Date`, `Time` or `Timestamp`), `utcMicros`, the instant in microseconds in UTC, an `i64`; `displacement`, minutes, -32768 to 32767; `zoned`, whether its type is WITH TIME ZONE; `precision`, 0 to 255 | `Datetime` |
| 13 | interval | `Interval`: its `qualifier`, a `Qualifier` of its start and end `IntervalField` (YEAR 0 to SECOND 5) and two precisions, and its `units`, the digits of an `i128`, months or microseconds | `Interval` |
| 14 | truth value | `bool`; unknown is `null` | `bool` |
| 15 | decimal floating point | `DecFloat`: `coefficient`, at most 34 digits with no trailing zeros, and `exponent`, its adjusted exponent within -6143 to 6144 | `DecFloat` |
| 16 | array | `ArrayValue`: its `elements`, each a value of this table, arrays nested in it | `ArrayValue` |
| 17 | row | `RowValue`: its `elements`, one for each field | `RowValue` |
| 20 | large object, whole | `Lob`: its `kind`, `LobKind::Character` or `Binary`, and its `octets`, of a character one UTF-8 | `Lob` |
| 21 | large object, by locator | `LobLocator`: its kind, its locator, a `u32`, and its lengths in octets and characters | the `LobLocator` |
| 22 | multiset | `Multiset`: its `elements`, at most 65535, in no set order | `Multiset` |
| 23 | structured value | `Structured`: its most specific type's `schema` and `name`, and its `attributes` | `Structured` |
| 24 | reference | `Reference`: its octets | `Reference` |
| 25 | JSON | `Json`: its stored form, Turborel's binary tree, checked whole; `text()` its JSON text, `value()` its item as PHP's values | `Json`, made by `Json::fromText()` or `Json::fromValue()` |
| 26 | XML | `Xml`: its serialization as CONTENT | `Xml` |
| 27 | UUID | `Uuid`: its 16 octets; `(string)` its text | `Uuid`, made by `Uuid::fromText()` too |

A value's tag says how it is written, not its column's type: a CHARACTER
VARYING value comes with tag 1, as a CHARACTER one does. The driver
takes tags 5, 7, 10 and 12 as values too, as turborel-client does, and
reads them as tags 1, 6, 9 and 11.

An `u64` past PHP_INT_MAX, a large object's length for one, is the
string of its digits. No exact number, decimal floating-point number or
large integer passes through a `float` (RULES.md R-NUM-3, R-TYPE-6).

## JSON items

A `Json`'s `value()` gives its item as PHP's values: the SQL/JSON null
as `null`, true and false as `bool`, a number as an `int` where it is an
integer an `i64` holds, as an `Exact` or a `DecFloat` where it is exact,
or as a `float` where it is approximate, a string as `string`, a
datetime as `Datetime`, an array as a list, and an object as a
`\stdClass`, which keeps the first member of a key.

`Json::fromValue()` takes the same, and an array with string keys as an
object, a `\JsonSerializable` as what it serializes to, and a `Json` as
its item. `Json::fromText()` reads JSON text as Turborel does: a number
with no exponent is exact, of at most 18 digits, or a decimal
floating-point number of at most 34 significant digits; one with an
exponent is approximate; an object keeps every member, in order.

## Data types

| Tag | SQL type | `Turborel\Type` |
|---|---|---|
| 1, 5 | CHARACTER, CHARACTER VARYING | `CharacterType`: `varying`, `length`, `units` (`CharUnits`) |
| 2 | exact numeric | `ExactType`: `precision`, `scale` |
| 3 | approximate numeric | `ApproximateType`: `precision` |
| 4 | a plug-in's type | `PluginType`: `name`, `parameters` |
| 6, 7 | BINARY, BINARY VARYING | `BinaryType`: `varying`, `length` |
| 8 to 12 | DATE, TIME, TIME WITH TIME ZONE, TIMESTAMP, TIMESTAMP WITH TIME ZONE | `DatetimeType`: `moment`, `zoned`, `precision` |
| 13 | INTERVAL | `IntervalType`: `qualifier` |
| 14 | BOOLEAN | `BooleanType` |
| 15 | DECFLOAT | `DecFloatType`: `precision` |
| 16 | array | `ArrayType`: `maxCardinality`, 1 to 65535, `element` |
| 17 | row | `RowType`: `fields`, each a `Field` of `name` and `type` |
| 18, 19 | CHARACTER LARGE OBJECT, BINARY LARGE OBJECT | `LobType`: `kind`, `length`, `units` |
| 22 | multiset | `MultisetType`: `element` |
| 23 | structured type | `StructuredType`: `name`, `supertypes`, each a `UdtName` |
| 24 | reference type | `ReferenceType`: `referenced`, `scope` |
| 25 | JSON | `JsonType` |
| 26 | XML | `XmlType`: `primary`, `secondary`, `schema` |
| 27 | UUID | `UuidType` |

A distinct type comes as its source type. A plug-in's type the driver
does not know is kept by its name, and its values by their text.

## Bounds

A value nested more than 16 deep in arrays, rows, multisets and
structured values, a multiset of more than 65535 elements, and a JSON
value nested more than 128 deep or damaged, each fail the connection
when a server sends one (SQLSTATE 08006, SQLCODE -919). A value a
program sends out of its field's range is refused before it is sent,
with SQLSTATE 22003; a string not UTF-8 with 22021; and a PHP value of
no SQL value with 07006.

<?php

declare(strict_types=1);

namespace Turborel\Internal\Copy;

use Turborel\TurborelException;
use Turborel\Value\ArrayValue;
use Turborel\Value\Binary;
use Turborel\Value\Datetime;
use Turborel\Value\DecFloat;
use Turborel\Value\Exact;
use Turborel\Value\Interval;
use Turborel\Value\Json;
use Turborel\Value\Lob;
use Turborel\Value\LobKind;
use Turborel\Value\LobLocator;
use Turborel\Value\Multiset;
use Turborel\Value\PluginValue;
use Turborel\Value\Reference;
use Turborel\Value\RowValue;
use Turborel\Value\Structured;
use Turborel\Value\Uuid;
use Turborel\Value\Xml;

/**
 * A value as text, as turborel's `Value` shows it: a character string as
 * it is, a number as SQL writes it, a datetime and an interval as their
 * literals' strings, a binary string in hexadecimal digits, a large object
 * held by its locator by what it is, and a composite as its literal.
 *
 * @internal
 */
final class Text
{
    /**
     * `$value` as turborel shows it; null as NULL.
     *
     * @throws TurborelException never, every value having a text
     */
    public static function of(mixed $value): string
    {
        return match (true) {
            $value === null => 'NULL',
            \is_string($value) => $value,
            \is_bool($value) => $value ? 'TRUE' : 'FALSE',
            \is_int($value) => (string) $value,
            \is_float($value) => Numbers::upperExp($value),
            $value instanceof Exact => (string) $value,
            $value instanceof DecFloat => Numbers::decFloat($value),
            $value instanceof Binary => strtoupper(bin2hex($value->octets)),
            $value instanceof Reference => 'REF(' . strtoupper(bin2hex($value->octets)) . ')',
            $value instanceof Datetime => Moments::datetime($value),
            $value instanceof Interval => Moments::interval($value),
            $value instanceof Lob => $value->kind === LobKind::Character ? $value->octets : strtoupper(bin2hex($value->octets)),
            $value instanceof LobLocator => $value->kind === LobKind::Character
                ? "<CHARACTER LARGE OBJECT of {$value->characters} characters>" : "<BINARY LARGE OBJECT of {$value->octets} octets>",
            $value instanceof Json => self::json($value),
            $value instanceof Uuid => (string) $value,
            $value instanceof Xml => $value->text,
            $value instanceof PluginValue => $value->text,
            default => self::literal($value),
        };
    }

    /**
     * `$value` as a literal, as a composite writes each of its parts.
     *
     * @throws TurborelException never, every value having a literal
     */
    public static function literal(mixed $value): string
    {
        return match (true) {
            $value === null => 'NULL',
            \is_string($value) => self::quoted($value),
            $value instanceof Binary => "X'" . self::of($value) . "'",
            $value instanceof Datetime => strtoupper($value->moment->name) . " '" . self::of($value) . "'",
            $value instanceof DecFloat => "CAST('" . self::of($value) . "' AS DECFLOAT)",
            $value instanceof Interval => Moments::intervalLiteral($value),
            $value instanceof Lob => $value->kind === LobKind::Character ? self::quoted($value->octets) : "X'" . self::of($value) . "'",
            $value instanceof Json => 'JSON(' . self::quoted(self::json($value)) . ')',
            $value instanceof Uuid => "UUID '{$value}'",
            $value instanceof Xml => 'XMLPARSE(CONTENT ' . self::quoted($value->text) . ' PRESERVE WHITESPACE)',
            $value instanceof ArrayValue => 'ARRAY[' . self::literals($value->elements) . ']',
            $value instanceof Multiset => 'MULTISET[' . self::literals($value->elements) . ']',
            $value instanceof RowValue => 'ROW(' . self::literals($value->elements) . ')',
            $value instanceof Structured => "{$value->schema}.{$value->name}(" . self::literals($value->attributes) . ')',
            default => self::of($value),
        };
    }

    /**
     * `$parts` as literals, comma separated.
     *
     * @param list<mixed> $parts
     *
     * @throws TurborelException never
     */
    private static function literals(array $parts): string
    {
        return implode(', ', array_map(self::literal(...), $parts));
    }

    /** `$text` as a character string literal, a quote in it written twice. */
    private static function quoted(string $text): string
    {
        return "'" . str_replace("'", "''", $text) . "'";
    }

    /** A JSON value's text, or what is wrong with it, in angle brackets. */
    private static function json(Json $value): string
    {
        try {
            return $value->text();
        } catch (TurborelException $damaged) {
            return '<' . $damaged->getMessage() . '>';
        }
    }
}

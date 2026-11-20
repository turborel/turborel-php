<?php

declare(strict_types=1);

namespace Turborel\Internal\Codec;

use Turborel\Internal\ValueFailure;
use Turborel\TurborelException;
use Turborel\Value\ArrayValue;
use Turborel\Value\Binary;
use Turborel\Value\Datetime;
use Turborel\Value\DecFloat;
use Turborel\Value\Exact;
use Turborel\Value\Interval;
use Turborel\Value\Json;
use Turborel\Value\Lob;
use Turborel\Value\LobLocator;
use Turborel\Value\Moment;
use Turborel\Value\Multiset;
use Turborel\Value\PluginValue;
use Turborel\Value\Reference;
use Turborel\Value\RowValue;
use Turborel\Value\Structured;
use Turborel\Value\Uuid;
use Turborel\Value\Xml;

/**
 * Values written as the protocol writes them, a tag then its body
 * [PROTOCOL Values]: null, a bool, an int as an exact number of scale 0, a
 * float, a string as a character string, and each of Turborel\Value's
 * classes, a plug-in's value sent back as it came.
 *
 * @internal
 */
final class ValueWriter
{
    /** pack()'s format of an f64, little-endian. */
    private const string F64_FORMAT = 'e';

    /** An i16's bits, all set: a displacement is written in a u32's low half. */
    private const int I16_MASK = 0xFFFF;

    /** An i32's bits, all set: an exponent is written as a u32's bits. */
    private const int I32_MASK = 0xFFFF_FFFF;

    /**
     * Writes `$value`.
     *
     * @throws TurborelException SQLSTATE 07006 if the protocol has no value of it, 22003 if a field is out of range, 22021 if a string is not UTF-8
     */
    public static function write(Writer $writer, mixed $value): Writer
    {
        return match (true) {
            $value === null => $writer->u8(Tag::NULL),
            \is_bool($value) => $writer->u8(Tag::BOOLEAN)->u8($value ? 1 : 0),
            \is_int($value) => $writer->u8(Tag::EXACT)->i128((string) $value)->u32(0),
            \is_float($value) => $writer->u8(Tag::APPROXIMATE)->raw(pack(self::F64_FORMAT, $value)),
            \is_string($value) => $writer->u8(Tag::CHARACTER)->string($value),
            $value instanceof Exact => $writer->u8(Tag::EXACT)->i128($value->units)->u32($value->scale),
            $value instanceof DecFloat => $writer->u8(Tag::DECFLOAT)->i128($value->coefficient)->u32($value->exponent & self::I32_MASK),
            $value instanceof Datetime => self::datetime($writer, $value),
            $value instanceof Interval => Qualifiers::write($writer->u8(Tag::INTERVAL), $value->qualifier)->i128($value->units),
            $value instanceof Binary => $writer->u8(Tag::BINARY)->bytes($value->octets),
            $value instanceof PluginValue => $writer->u8(Tag::PLUGIN)->string($value->type)->string($value->text)->bytes($value->bytes),
            default => self::composite($writer, $value),
        };
    }

    /**
     * A list of values: their count, then each.
     *
     * @param list<mixed> $values
     *
     * @throws TurborelException as `write` does of one
     */
    public static function list(Writer $writer, array $values): Writer
    {
        $writer->u32(\count($values));
        foreach ($values as $value) {
            self::write($writer, $value);
        }

        return $writer;
    }

    /**
     * Writes a value of the classes the rest of `write` does not take.
     *
     * @throws TurborelException SQLSTATE 07006 if the protocol has no value of it
     */
    private static function composite(Writer $writer, mixed $value): Writer
    {
        return match (true) {
            $value instanceof ArrayValue => self::list($writer->u8(Tag::ARRAY), $value->elements),
            $value instanceof RowValue => self::list($writer->u8(Tag::ROW), $value->elements),
            $value instanceof Multiset => self::list($writer->u8(Tag::MULTISET), $value->elements),
            $value instanceof Structured => self::list($writer->u8(Tag::STRUCTURED)->string($value->schema)->string($value->name), $value->attributes),
            $value instanceof Lob => $writer->u8(Tag::LOB_WHOLE)->u8($value->kind->value)->bytes($value->octets),
            $value instanceof LobLocator => $writer->u8(Tag::LOB_LOCATOR)->u8($value->kind->value)->u32($value->locator)
                ->u64($value->octets)->u64($value->characters),
            $value instanceof Reference => $writer->u8(Tag::REFERENCE)->bytes($value->octets),
            $value instanceof Json => $writer->u8(Tag::JSON)->bytes($value->bytes),
            $value instanceof Xml => $writer->u8(Tag::XML)->string($value->text),
            $value instanceof Uuid => $writer->u8(Tag::UUID)->bytes($value->octets),
            default => throw ValueFailure::restricted('the protocol has no value of a ' . get_debug_type($value)),
        };
    }

    /**
     * A datetime: its moment's tag, zoned, precision, the displacement's
     * bits in a u32's low half, and the instant.
     *
     * @throws TurborelException never, its fields having been checked as it was made
     */
    private static function datetime(Writer $writer, Datetime $value): Writer
    {
        $tag = match ($value->moment) {
            Moment::Date => Tag::DATE,
            Moment::Time => Tag::TIME,
            Moment::Timestamp => Tag::TIMESTAMP,
        };

        return $writer->u8($tag)->u8($value->zoned ? 1 : 0)->u8($value->precision)
            ->u32($value->displacement & self::I16_MASK)->i64($value->utcMicros);
    }
}

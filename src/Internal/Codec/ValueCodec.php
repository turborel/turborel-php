<?php

declare(strict_types=1);

namespace Turborel\Internal\Codec;

use Turborel\Internal\Codec\Json\Node;
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
use Turborel\Value\LobKind;
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
 * Values read from a message, a tag then its body, as turborel's
 * `fields.rs` reads them [PROTOCOL Values]: null, a string, an Exact, a
 * float, a bool, or one of Turborel\Value's classes.
 *
 * A value's tag says how it is written, not its column's type, so a
 * CHARACTER VARYING value comes with tag 1; turborel's reader takes tags 5,
 * 7, 10 and 12 as their types' values too, and so does this one. It takes
 * tags 18 and 19 too, as large objects in their stored layout, which no
 * server sends; this one refuses them as values it cannot take (TB916,
 * RULES.md D8).
 *
 * @internal
 */
final class ValueCodec
{
    /** The tag of a large object's stored form, which turborel's reader takes and no server sends. */
    private const int STORED_LOB = 18;

    /** The tag of a large object locator's stored form, which turborel's reader takes and no server sends. */
    private const int STORED_LOB_LOCATOR = 19;

    /** The datetime values by their tags: each its moment. */
    private const array MOMENTS = [
        Tag::DATE => Moment::Date,
        Tag::TIME => Moment::Time,
        Tag::TIME_ZONED => Moment::Time,
        Tag::TIMESTAMP => Moment::Timestamp,
        Tag::TIMESTAMP_ZONED => Moment::Timestamp,
    ];

    /** [DESIGN] The most elements of a multiset, as turborel takes it. */
    public const int MAX_MULTISET = 65_535;

    /** The bytes of an f64. */
    private const int F64 = 8;

    /** unpack()'s format of an f64, little-endian. */
    private const string F64_FORMAT = 'e';

    /** The largest displacement field: an i16's bits in the low half of a u32. */
    private const int MAX_ZONE_BITS = 0xFFFF;

    /** The sign bit of an i16. */
    private const int I16_SIGN = 0x8000;

    /** The values of an i16's bits. */
    private const int I16_RANGE = 0x1_0000;

    /**
     * The value `$reader` reads next.
     *
     * @throws TurborelException SQLSTATE 08006 if it is malformed
     */
    public static function read(Reader $reader): mixed
    {
        $tag = $reader->u8();
        if (isset(self::MOMENTS[$tag])) {
            return self::datetime($reader, self::MOMENTS[$tag]);
        }

        return match ($tag) {
            Tag::NULL => null,
            Tag::CHARACTER, Tag::VARYING => $reader->string(),
            Tag::EXACT => new Exact($reader->i128(), $reader->u32()),
            Tag::APPROXIMATE => self::float($reader),
            Tag::PLUGIN => new PluginValue($reader->string(), $reader->string(), $reader->bytes()),
            Tag::BINARY, Tag::BINARY_VARYING => new Binary($reader->bytes()),
            Tag::INTERVAL => new Interval(Qualifiers::read($reader), $reader->i128()),
            Tag::BOOLEAN => self::truth($reader),
            Tag::DECFLOAT => self::decFloat($reader),
            Tag::ARRAY => new ArrayValue($reader->nested(self::list(...))),
            Tag::ROW => new RowValue($reader->nested(self::list(...))),
            Tag::MULTISET => $reader->nested(self::multiset(...)),
            Tag::STRUCTURED => $reader->nested(static fn(Reader $r): Structured => new Structured($r->string(), $r->string(), self::list($r))),
            Tag::LOB_WHOLE => self::lob($reader),
            Tag::LOB_LOCATOR => new LobLocator(self::lobKind($reader), $reader->u32(), $reader->u64(), $reader->u64()),
            Tag::REFERENCE => new Reference($reader->bytes()),
            Tag::JSON => self::json($reader),
            Tag::XML => new Xml($reader->string()),
            Tag::UUID => self::uuid($reader),
            self::STORED_LOB, self::STORED_LOB_LOCATOR => throw ValueFailure::corrupt("value tag {$tag} is a large object's stored form, never sent"),
            default => throw $reader->malformed("no value has the tag {$tag}"),
        };
    }

    /**
     * A list of values: their count, then each; a row is one.
     *
     * @return list<mixed>
     *
     * @throws TurborelException SQLSTATE 08006 if one is malformed
     */
    public static function list(Reader $reader): array
    {
        $values = [];
        // Grown with each value read, never reserved for the count, so a count past what follows fails as the body runs out.
        for ($count = $reader->u32(); $count > 0; $count--) {
            $values[] = self::read($reader);
        }

        return $values;
    }

    /**
     * An approximate number: an IEEE 754 double's bits, kept as they are.
     *
     * @throws TurborelException SQLSTATE 08006 if the body ends within it
     */
    private static function float(Reader $reader): float
    {
        $value = unpack(self::F64_FORMAT, $reader->take(self::F64));

        return \is_array($value) && \is_float($value[1] ?? null) ? $value[1] : 0.0;
    }

    /**
     * A truth value: 0 false, 1 true.
     *
     * @throws TurborelException SQLSTATE TB916 if it is neither, as turborel reads it; 08006 if it is cut short
     */
    private static function truth(Reader $reader): bool
    {
        return match ($byte = $reader->u8()) {
            0 => false,
            1 => true,
            default => throw ValueFailure::corrupt("{$byte} is not a truth value"),
        };
    }

    /**
     * A datetime of `$moment`: zoned, precision, the displacement's i16 in a
     * u32's low half, and the instant, an i64.
     *
     * @throws TurborelException SQLSTATE 08006 if its displacement is out of range
     */
    private static function datetime(Reader $reader, Moment $moment): Datetime
    {
        [$zoned, $precision, $zone] = [$reader->u8() === 1, $reader->u8(), $reader->u32()];
        if ($zone > self::MAX_ZONE_BITS) {
            throw $reader->malformed('a time zone displacement is out of range');
        }
        $displacement = $zone >= self::I16_SIGN ? $zone - self::I16_RANGE : $zone;

        return new Datetime($moment, $reader->i64(), $displacement, $zoned, $precision);
    }

    /**
     * A decimal floating-point number, which must be in turborel's one form.
     *
     * @throws TurborelException SQLSTATE 08006 if it is not
     */
    private static function decFloat(Reader $reader): DecFloat
    {
        $coefficient = $reader->i128();
        $exponent = Wide::i32($reader->u32());
        try {
            $value = new DecFloat($coefficient, $exponent);
        } catch (TurborelException) {
            throw $reader->malformed('a DECFLOAT value is malformed');
        }
        if ($value->coefficient !== $coefficient || $value->exponent !== $exponent) {
            throw $reader->malformed('a DECFLOAT value is malformed');
        }

        return $value;
    }

    /**
     * A multiset, of at most MAX_MULTISET elements.
     *
     * @throws TurborelException SQLSTATE 08006 if it has more, or is malformed
     */
    private static function multiset(Reader $reader): Multiset
    {
        $elements = self::list($reader);
        if (\count($elements) > self::MAX_MULTISET) {
            throw $reader->malformed('a multiset has more elements than its maximum cardinality');
        }

        return new Multiset($elements);
    }

    /**
     * A large object's kind: 0 character, 1 binary.
     *
     * @throws TurborelException SQLSTATE 08006 if it is neither
     */
    private static function lobKind(Reader $reader): LobKind
    {
        return LobKind::tryFrom($reader->u8()) ?? throw $reader->malformed('not a kind of large object');
    }

    /**
     * A large object whole: its kind, then its octets, of a character one UTF-8.
     *
     * @throws TurborelException SQLSTATE 08006 if it is malformed
     */
    private static function lob(Reader $reader): Lob
    {
        $kind = self::lobKind($reader);
        $octets = $reader->bytes();
        if ($kind === LobKind::Character && !Utf8::valid($octets)) {
            throw $reader->malformed('a character large object is not UTF-8');
        }

        return new Lob($kind, $octets);
    }

    /**
     * A JSON value's stored form, checked whole: of at most 256 MiB, nested at most 128 deep.
     *
     * @throws TurborelException SQLSTATE TB916 if it is damaged, 0A000 if past either limit, as turborel reads it; 08006 if cut short
     */
    private static function json(Reader $reader): Json
    {
        $length = $reader->u32();
        if ($length > Node::MAX_OCTETS) {
            throw ValueFailure::beyondJson('its stored form is longer than 256 MiB');
        }

        return new Json($reader->take($length));
    }

    /**
     * A UUID: bytes, 16 of them.
     *
     * @throws TurborelException SQLSTATE 08006 if there are not 16
     */
    private static function uuid(Reader $reader): Uuid
    {
        $octets = $reader->bytes();
        if (\strlen($octets) !== Uuid::OCTETS) {
            throw $reader->malformed('a UUID is 16 octets');
        }

        return new Uuid($octets);
    }
}

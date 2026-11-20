<?php

declare(strict_types=1);

namespace Turborel\Internal\Codec\Json;

use Turborel\Internal\Codec\Wide;
use Turborel\Internal\Codec\Writer;
use Turborel\Internal\ValueFailure;
use Turborel\TurborelException;
use Turborel\Value\Datetime;
use Turborel\Value\DecFloat;
use Turborel\Value\Exact;
use Turborel\Value\Json;
use Turborel\Value\Moment;

/**
 * PHP values written as a JSON value's stored form (Node), as turborel
 * writes an SQL/JSON item (its `binary::encode`): an exact number in the
 * narrowest of its three nodes, an array's and an object's offsets after
 * their heads, an object's directory sorted by key octets, members of one
 * key in the order written.
 *
 * It takes null, a bool, an int, an Exact, a DecFloat, a float, a string,
 * a Datetime, a list, an array of string keys or a \stdClass as an object,
 * a \JsonSerializable as what it serializes to, and a Json as its item.
 *
 * @internal
 */
final class Encoder
{
    /** pack()'s format of an i64, little-endian. */
    private const string I64 = 'P';

    /** pack()'s format of an f64, little-endian. */
    private const string F64 = 'e';

    /** pack()'s format of an i16's bits, little-endian. */
    private const string U16 = 'v';

    /** pack()'s format of a u32, little-endian. */
    private const string U32 = 'V';

    /** A u32's bits, all set: an i32's bits are written as a u32's. */
    private const int U32_MASK = 0xFFFF_FFFF;

    /** An i16's bits, all set: a zone is written as a u16's bits. */
    private const int U16_MASK = 0xFFFF;

    /**
     * The document of `$value`.
     *
     * @throws TurborelException SQLSTATE 22032 if it holds what JSON has no item of, or is too deep or too long
     */
    public static function encode(mixed $value): string
    {
        $document = \chr(Node::VERSION) . self::node($value, 0);
        if (\strlen($document) > Node::MAX_OCTETS) {
            throw ValueFailure::invalidJson('a JSON value\'s stored form is longer than 256 MiB');
        }

        return $document;
    }

    /**
     * The node of `$value`, `$depth` arrays and objects in.
     *
     * @throws TurborelException SQLSTATE 22032 if JSON has no item of it, or it nests too deep
     */
    private static function node(mixed $value, int $depth): string
    {
        if ($depth > Node::MAX_NESTING) {
            throw ValueFailure::invalidJson('arrays and objects nest too deep');
        }

        return match (true) {
            $value === null => \chr(Node::NULL),
            \is_bool($value) => \chr($value ? Node::TRUE : Node::FALSE),
            \is_int($value) => \chr(Node::INTEGER) . pack(self::I64, $value),
            \is_float($value) => \chr(Node::APPROXIMATE) . pack(self::F64, $value),
            \is_string($value) => \chr(Node::STRING) . self::text($value),
            $value instanceof Exact => self::exact($value),
            $value instanceof DecFloat => self::decFloat($value),
            $value instanceof Datetime => self::datetime($value),
            $value instanceof Json => substr($value->bytes, 1),
            $value instanceof \JsonSerializable => self::node($value->jsonSerialize(), $depth),
            default => self::container($value, $depth),
        };
    }

    /**
     * The array or object node of `$value`.
     *
     * @throws TurborelException SQLSTATE 22032 if JSON has no item of it
     */
    private static function container(mixed $value, int $depth): string
    {
        if (\is_array($value) && array_is_list($value)) {
            return self::array($value, $depth + 1);
        }
        if (\is_array($value) || $value instanceof \stdClass) {
            $members = [];
            foreach ((array) $value as $key => $member) {
                $members[] = [(string) $key, $member];
            }

            return self::object($members, $depth + 1);
        }
        if ($value instanceof JsonObject) {
            return self::object($value->members, $depth + 1);
        }

        throw ValueFailure::invalidJson('JSON has no item of a ' . get_debug_type($value));
    }

    /**
     * A string's length and its octets.
     *
     * @throws TurborelException SQLSTATE 22021 if it is not UTF-8
     */
    private static function text(string $text): string
    {
        return (new Writer())->string($text)->bytesWritten();
    }

    /**
     * An exact number, in the narrowest of the integer, decimal and wide decimal nodes.
     *
     * @throws TurborelException never, an Exact's units being an i128's
     */
    private static function exact(Exact $value): string
    {
        $narrow = filter_var($value->units, \FILTER_VALIDATE_INT);
        if (\is_int($narrow) && $value->scale === 0) {
            return \chr(Node::INTEGER) . pack(self::I64, $narrow);
        }
        if (\is_int($narrow) && $value->scale <= Node::MAX_DECIMAL_SCALE) {
            return \chr(Node::DECIMAL) . pack(self::I64, $narrow) . \chr($value->scale);
        }

        return \chr(Node::WIDE) . Wide::i128ToBytes($value->units) . pack(self::U32, $value->scale);
    }

    /**
     * A decimal floating-point number: its coefficient, then its exponent's bits.
     *
     * @throws TurborelException never, a DecFloat's coefficient being an i128's
     */
    private static function decFloat(DecFloat $value): string
    {
        return \chr(Node::DECFLOAT) . Wide::i128ToBytes($value->coefficient) . pack(self::U32, $value->exponent & self::U32_MASK);
    }

    /** A datetime: its moment, its instant, its zone, its zoned flag and its precision. */
    private static function datetime(Datetime $value): string
    {
        $moment = Node::MOMENTS[match ($value->moment) {
            Moment::Date => 0,
            Moment::Time => 1,
            Moment::Timestamp => 2,
        }];

        return \chr(Node::DATETIME) . \chr($moment) . pack(self::I64, $value->utcMicros)
            . pack(self::U16, $value->displacement & self::U16_MASK) . \chr($value->zoned ? 1 : 0) . \chr($value->precision);
    }

    /**
     * An array of `$elements`: its count, its size, each element's offset, then the elements.
     *
     * @param list<mixed> $elements
     *
     * @throws TurborelException SQLSTATE 22032 if an element has no item
     */
    private static function array(array $elements, int $depth): string
    {
        $offsets = '';
        $body = '';
        foreach ($elements as $element) {
            $offsets .= pack(self::U32, \strlen($body));
            $body .= self::node($element, $depth);
        }

        return \chr(Node::ARRAY) . pack(self::U32 . self::U32, \count($elements), \strlen($body)) . $offsets . $body;
    }

    /**
     * An object of `$members`: its count, its size, its directory sorted
     * by key octets, those of one key in the order written, then the
     * members in the order written.
     *
     * @param list<array{string, mixed}> $members
     *
     * @throws TurborelException SQLSTATE 22032 if a value has no item
     */
    private static function object(array $members, int $depth): string
    {
        $directory = [];
        $body = '';
        foreach ($members as [$key, $member]) {
            $directory[] = [$key, \strlen($body)];
            $body .= self::text($key) . self::node($member, $depth);
        }
        // usort is stable, so members of one key keep the order written.
        usort($directory, static fn(array $a, array $b): int => strcmp($a[0], $b[0]));
        $offsets = implode('', array_map(static fn(array $entry): string => pack(self::U32, $entry[1]), $directory));

        return \chr(Node::OBJECT) . pack(self::U32 . self::U32, \count($members), \strlen($body)) . $offsets . $body;
    }
}

<?php

declare(strict_types=1);

namespace Turborel\Internal\Codec\Json;

/**
 * The stored form of a JSON value, as turborel's RULES.md D89 lays it out
 * and a JSON value's body carries it [PROTOCOL Values]: a version octet,
 * 1, then the root node, each node a tag octet and its body, integers
 * little-endian.
 *
 * ```text
 * 0 null | 1 false | 2 true
 * 3 integer: i64
 * 4 decimal: units i64, scale u8
 * 5 wide decimal: units i128, scale u32
 * 6 decimal floating point: coefficient i128, exponent i32
 * 7 approximate: f64 bits
 * 8 string: octets u32, UTF-8
 * 9 datetime: moment u8, utc i64, zone minutes i16, zoned u8, precision u8
 * 10 array: count u32, size u32, an offset u32 of each element, the elements
 * 11 object: count u32, size u32, an offset u32 of each member in key order,
 *    the members in written order, each key octets u32, key, value node
 * ```
 *
 * @internal
 */
final class Node
{
    /** The format's version, the first octet of every document. */
    public const int VERSION = 1;

    /** The SQL/JSON null. */
    public const int NULL = 0;

    /** False. */
    public const int FALSE = 1;

    /** True. */
    public const int TRUE = 2;

    /** An exact number of scale 0 an i64 holds. */
    public const int INTEGER = 3;

    /** An exact number whose units an i64 holds, of a scale a u8 holds. */
    public const int DECIMAL = 4;

    /** Any other exact number. */
    public const int WIDE = 5;

    /** A decimal floating-point number. */
    public const int DECFLOAT = 6;

    /** An approximate number. */
    public const int APPROXIMATE = 7;

    /** A character string. */
    public const int STRING = 8;

    /** A datetime. */
    public const int DATETIME = 9;

    /** An array. */
    public const int ARRAY = 10;

    /** An object. */
    public const int OBJECT = 11;

    /** A count, a size, an offset or a string's length. */
    public const int WORD = 4;

    /** An i64, an f64 or a datetime's instant. */
    public const int LONG = 8;

    /** An i128. */
    public const int WIDE_WORD = 16;

    /** An array's or an object's count and size. */
    public const int CONTAINER_HEAD = 8;

    /** A datetime's body: its moment, instant, zone, zoned flag and precision. */
    public const int DATETIME_BODY = 13;

    /** The largest scale of a decimal node: a u8. */
    public const int MAX_DECIMAL_SCALE = 0xFF;

    /** turborel's moments of a datetime node: date, time, timestamp, in order. */
    public const array MOMENTS = [0, 1, 2];

    /** [DESIGN] The deepest arrays and objects nest, as turborel takes them. */
    public const int MAX_NESTING = 128;

    /** [DESIGN] The most octets a document holds, as turborel takes them: 256 MiB. */
    public const int MAX_OCTETS = 1 << 28;
}

<?php

declare(strict_types=1);

namespace Turborel\Internal\Codec;

/**
 * The tags of data types and of values, one numbering for both, as the
 * spec's two tables give them [PROTOCOL Data types] [PROTOCOL Values], and
 * UUID's, 27, as turborel's RULES.md D184 adds it.
 *
 * @internal
 */
final class Tag
{
    /** A null value. */
    public const int NULL = 0;

    /** CHARACTER, as a type; a character string, of either string type, as a value. */
    public const int CHARACTER = 1;

    /** An exact numeric type, and an exact number. */
    public const int EXACT = 2;

    /** An approximate numeric type, and an approximate number. */
    public const int APPROXIMATE = 3;

    /** A plug-in's type, and a value of one. */
    public const int PLUGIN = 4;

    /** CHARACTER VARYING, as a type. */
    public const int VARYING = 5;

    /** BINARY, as a type; a binary string, of either binary type, as a value. */
    public const int BINARY = 6;

    /** BINARY VARYING, as a type. */
    public const int BINARY_VARYING = 7;

    /** DATE, as a type and a value. */
    public const int DATE = 8;

    /** TIME, as a type; a time, of either time type, as a value. */
    public const int TIME = 9;

    /** TIME WITH TIME ZONE, as a type. */
    public const int TIME_ZONED = 10;

    /** TIMESTAMP, as a type; a timestamp, of either type, as a value. */
    public const int TIMESTAMP = 11;

    /** TIMESTAMP WITH TIME ZONE, as a type. */
    public const int TIMESTAMP_ZONED = 12;

    /** INTERVAL, as a type and a value. */
    public const int INTERVAL = 13;

    /** BOOLEAN, as a type; a truth value. */
    public const int BOOLEAN = 14;

    /** DECFLOAT, as a type and a value. */
    public const int DECFLOAT = 15;

    /** An array type, and an array. */
    public const int ARRAY = 16;

    /** A row type, and a row. */
    public const int ROW = 17;

    /** CHARACTER LARGE OBJECT, as a type. */
    public const int CLOB = 18;

    /** BINARY LARGE OBJECT, as a type. */
    public const int BLOB = 19;

    /** A large object's value, whole. */
    public const int LOB_WHOLE = 20;

    /** A large object's value, by its locator. */
    public const int LOB_LOCATOR = 21;

    /** A multiset type, and a multiset. */
    public const int MULTISET = 22;

    /** A structured type, and a structured value. */
    public const int STRUCTURED = 23;

    /** A reference type, and a reference. */
    public const int REFERENCE = 24;

    /** JSON, as a type and a value. */
    public const int JSON = 25;

    /** XML, as a type and a value. */
    public const int XML = 26;

    /** UUID, as a type and a value (turborel RULES.md D184). */
    public const int UUID = 27;
}

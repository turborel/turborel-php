<?php

declare(strict_types=1);

namespace Turborel\Value;

/**
 * Which datetime a value is, by the tag of its value [PROTOCOL Values].
 */
enum Moment: int
{
    /** A date. */
    case Date = 8;

    /** A time, with time zone or without. */
    case Time = 9;

    /** A timestamp, with time zone or without. */
    case Timestamp = 11;
}

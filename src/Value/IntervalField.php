<?php

declare(strict_types=1);

namespace Turborel\Value;

/**
 * A field of an interval's qualifier, by its number [PROTOCOL Data types].
 */
enum IntervalField: int
{
    /** YEAR. */
    case Year = 0;

    /** MONTH. */
    case Month = 1;

    /** DAY. */
    case Day = 2;

    /** HOUR. */
    case Hour = 3;

    /** MINUTE. */
    case Minute = 4;

    /** SECOND. */
    case Second = 5;
}

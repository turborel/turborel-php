<?php

declare(strict_types=1);

namespace Turborel\Type;

use Turborel\Value\Moment;

/**
 * DATE, tag 8; TIME, tag 9, or WITH TIME ZONE, 10; TIMESTAMP, tag 11, or
 * WITH TIME ZONE, 12: its precision [PROTOCOL Data types].
 */
final readonly class DatetimeType implements DataType
{
    /** The type `$moment`, WITH TIME ZONE if `$zoned`, of the precision `$precision`. */
    public function __construct(
        /** Whether it is DATE, TIME or TIMESTAMP. */
        public Moment $moment,
        /** Whether it is WITH TIME ZONE, which DATE never is. */
        public bool $zoned,
        /** Its precision, a u32. */
        public int $precision,
    ) {}
}

<?php

declare(strict_types=1);

namespace Turborel\Value;

use Turborel\Internal\ValueFailure;
use Turborel\TurborelException;

/**
 * A date, a time or a timestamp, as the protocol writes each: whether its
 * type is WITH TIME ZONE, its precision, the displacement it is shown in,
 * in minutes, and its instant, microseconds in UTC [PROTOCOL Values].
 */
final readonly class Datetime implements SqlValue
{
    /** The largest precision: a u8. */
    private const int MAX_PRECISION = 0xFF;

    /** The least displacement: an i16's. */
    private const int MIN_DISPLACEMENT = -0x8000;

    /** The greatest displacement: an i16's. */
    private const int MAX_DISPLACEMENT = 0x7FFF;

    /**
     * The datetime `$moment` at `$utcMicros`, shown at `$displacement` minutes.
     *
     * @throws TurborelException SQLSTATE 22003 if the precision is not a u8 or the displacement not an i16
     */
    public function __construct(
        /** Whether it is a date, a time or a timestamp. */
        public Moment $moment,
        /** Its instant: microseconds in UTC. */
        public int $utcMicros,
        /** The zone's displacement it is shown in, in minutes. */
        public int $displacement = 0,
        /** Whether its type is WITH TIME ZONE. */
        public bool $zoned = false,
        /** Its precision: the digits of its seconds' fraction. */
        public int $precision = 0,
    ) {
        if ($precision < 0 || $precision > self::MAX_PRECISION) {
            throw ValueFailure::outOfRange("a datetime's precision cannot be {$precision}");
        }
        if ($displacement < self::MIN_DISPLACEMENT || $displacement > self::MAX_DISPLACEMENT) {
            throw ValueFailure::outOfRange("a displacement of {$displacement} minutes is out of range");
        }
    }
}

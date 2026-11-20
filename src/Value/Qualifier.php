<?php

declare(strict_types=1);

namespace Turborel\Value;

use Turborel\Internal\ValueFailure;
use Turborel\TurborelException;

/**
 * An interval's qualifier: its start and end fields, its leading field's
 * precision and its seconds' fraction's [PROTOCOL Data types].
 */
final readonly class Qualifier
{
    /** The largest precision: a u32. */
    private const int MAX_PRECISION = 0xFFFF_FFFF;

    /**
     * The qualifier `$start` TO `$end`.
     *
     * @throws TurborelException SQLSTATE 22003 if a precision is not a u32
     */
    public function __construct(
        /** Its start field. */
        public IntervalField $start,
        /** Its end field. */
        public IntervalField $end,
        /** Its leading field's precision. */
        public int $leading = 2,
        /** Its seconds' fraction's precision. */
        public int $fraction = 0,
    ) {
        foreach ([$leading, $fraction] as $precision) {
            if ($precision < 0 || $precision > self::MAX_PRECISION) {
                throw ValueFailure::outOfRange("an interval's precision cannot be {$precision}");
            }
        }
    }
}

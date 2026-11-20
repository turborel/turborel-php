<?php

declare(strict_types=1);

namespace Turborel\Value;

use Turborel\Internal\ValueFailure;
use Turborel\TurborelException;

/**
 * An interval's qualifier: its start and end fields, its leading field's
 * precision and its seconds' fraction's [PROTOCOL Data types], each
 * precision kept as turborel keeps it: the leading at most 9, the fraction
 * at most 6, and none unless the end field is SECOND.
 */
final readonly class Qualifier
{
    /** The largest precision written: a u32. */
    private const int MAX_PRECISION = 0xFFFF_FFFF;

    /** The largest leading precision, as turborel's MAX_LEADING_PRECISION. */
    private const int MAX_LEADING = 9;

    /** The largest fraction's precision, as turborel's MAX_TIME_PRECISION. */
    private const int MAX_FRACTION = 6;

    /** Its leading field's precision. */
    public int $leading;

    /** Its seconds' fraction's precision. */
    public int $fraction;

    /**
     * The qualifier `$start` TO `$end`, of `$leading` and `$fraction` digits, as turborel's Qualifier::new keeps them.
     *
     * @throws TurborelException SQLSTATE 22003 if a precision is not a u32
     */
    public function __construct(
        /** Its start field. */
        public IntervalField $start,
        /** Its end field. */
        public IntervalField $end,
        int $leading = 2,
        int $fraction = 0,
    ) {
        foreach ([$leading, $fraction] as $precision) {
            if ($precision < 0 || $precision > self::MAX_PRECISION) {
                throw ValueFailure::outOfRange("an interval's precision cannot be {$precision}");
            }
        }
        $this->leading = min($leading, self::MAX_LEADING);
        $this->fraction = $end === IntervalField::Second ? min($fraction, self::MAX_FRACTION) : 0;
    }
}

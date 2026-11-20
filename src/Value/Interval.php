<?php

declare(strict_types=1);

namespace Turborel\Value;

use Turborel\Internal\Codec\Wide;
use Turborel\TurborelException;

/**
 * An interval: its qualifier, then its units, months of a year-month
 * interval or microseconds of a day-time one [PROTOCOL Values].
 */
final readonly class Interval implements SqlValue
{
    /**
     * The interval of `$units` units of `$qualifier`.
     *
     * @throws TurborelException SQLSTATE 22003 if `$units` is no i128's digits
     */
    public function __construct(
        /** Its qualifier. */
        public Qualifier $qualifier,
        /** Its units, the digits of an i128: months, or microseconds. */
        public string $units,
    ) {
        Wide::i128ToBytes($units);
    }
}

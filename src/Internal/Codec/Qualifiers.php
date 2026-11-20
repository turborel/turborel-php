<?php

declare(strict_types=1);

namespace Turborel\Internal\Codec;

use Turborel\TurborelException;
use Turborel\Value\IntervalField;
use Turborel\Value\Qualifier;

/**
 * An interval's qualifier, as the INTERVAL type writes it and an interval
 * value repeats it: start field, end field, leading precision, fractional
 * precision, each a u32, the fields numbered 0 YEAR to 5 SECOND
 * [PROTOCOL Data types].
 *
 * @internal
 */
final class Qualifiers
{
    /**
     * The qualifier `$reader` reads next.
     *
     * @throws TurborelException SQLSTATE 08006 if a field is out of range
     */
    public static function read(Reader $reader): Qualifier
    {
        $start = IntervalField::tryFrom($reader->u32());
        $end = IntervalField::tryFrom($reader->u32());
        [$leading, $fraction] = [$reader->u32(), $reader->u32()];
        if ($start === null || $end === null) {
            throw $reader->malformed('an interval\'s field is out of range');
        }

        return new Qualifier($start, $end, $leading, $fraction);
    }

    /**
     * Writes `$qualifier`.
     *
     * @throws TurborelException SQLSTATE 22003 if a precision is not a u32
     */
    public static function write(Writer $writer, Qualifier $qualifier): Writer
    {
        return $writer->u32($qualifier->start->value)->u32($qualifier->end->value)
            ->u32($qualifier->leading)->u32($qualifier->fraction);
    }
}

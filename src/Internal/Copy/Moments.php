<?php

declare(strict_types=1);

namespace Turborel\Internal\Copy;

use Turborel\Value\Datetime;
use Turborel\Value\Interval;
use Turborel\Value\IntervalField;
use Turborel\Value\Moment;

/**
 * Datetimes and intervals as turborel shows them: a datetime in the zone
 * it is shown in, `YYYY-MM-DD hh:mm:ss`, its fraction to its precision,
 * its displacement if its type is WITH TIME ZONE; an interval's literal's
 * string, its leading field all it holds of it, each other field two
 * digits after its separator.
 *
 * @internal
 */
final class Moments
{
    /** Microseconds in a day, a minute and a second. */
    private const int DAY = 86_400_000_000;

    private const int MINUTE = 60_000_000;

    private const int SECOND = 1_000_000;

    /** The days from 0001-01-01 to 1970-01-01. */
    private const int UNIX_EPOCH_DAYS = 719_162;

    /** The units of each field: months of YEAR and MONTH, microseconds of the rest. */
    private const array UNITS = ['Year' => 12, 'Month' => 1, 'Day' => self::DAY, 'Hour' => 3_600_000_000, 'Minute' => self::MINUTE, 'Second' => 1];

    /** The separator before each field not the leading one. */
    private const array SEPARATORS = ['Month' => '-', 'Hour' => ' ', 'Year' => ':', 'Day' => ':', 'Minute' => ':', 'Second' => ':'];

    /** `$value` as turborel shows a datetime. */
    public static function datetime(Datetime $value): string
    {
        $local = $value->utcMicros + $value->displacement * self::MINUTE;
        if ($value->moment === Moment::Time) {
            $local = self::floorRem($local, self::DAY);
        }
        $text = '';
        if ($value->moment !== Moment::Time) {
            $text = self::civil(intdiv($local - self::floorRem($local, self::DAY), self::DAY));
        }
        if ($value->moment === Moment::Date) {
            return $text;
        }
        $time = self::floorRem($local, self::DAY);
        $seconds = intdiv($time, self::SECOND);
        $text .= ($value->moment === Moment::Timestamp ? ' ' : '') . \sprintf('%02d:%02d:%02d', intdiv($seconds, 3600), intdiv($seconds, 60) % 60, $seconds % 60);
        if ($value->precision > 0) {
            $text .= '.' . substr(\sprintf('%06d', $time % self::SECOND), 0, $value->precision);
        }
        if ($value->zoned) {
            $size = abs($value->displacement);
            $text .= \sprintf('%s%02d:%02d', $value->displacement < 0 ? '-' : '+', intdiv($size, 60), $size % 60);
        }

        return $text;
    }

    /** `$value` as turborel shows an interval: its literal's string. */
    public static function interval(Interval $value): string
    {
        $negative = str_starts_with($value->units, '-');
        $size = ltrim($value->units, '-');
        $text = $negative ? '-' : '';
        $fields = self::fields($value);
        foreach ($fields as $at => $field) {
            $n = self::field($value, $size, $field, $at === 0);
            $whole = $field === IntervalField::Second ? self::divide($n, self::SECOND) : $n;
            $text .= $at === 0 ? $whole : self::SEPARATORS[$field->name] . str_pad($whole, 2, '0', \STR_PAD_LEFT);
            if ($field === IntervalField::Second && $value->qualifier->fraction > 0) {
                $fraction = str_pad(self::remainder($n, self::SECOND), 6, '0', \STR_PAD_LEFT);
                $text .= '.' . substr($fraction, 0, $value->qualifier->fraction);
            }
        }

        return $text;
    }

    /** `$value` as an interval literal: INTERVAL, its sign, its string quoted, its qualifier. */
    public static function intervalLiteral(Interval $value): string
    {
        $text = self::interval($value);
        $negative = str_starts_with($text, '-');
        $q = $value->qualifier;
        $start = strtoupper($q->start->name);
        if ($q->start === $q->end) {
            $qualifier = $q->end === IntervalField::Second ? "SECOND({$q->leading}, {$q->fraction})" : "{$start}({$q->leading})";
        } else {
            $qualifier = "{$start}({$q->leading}) TO " . strtoupper($q->end->name) . ($q->end === IntervalField::Second ? "({$q->fraction})" : '');
        }

        return 'INTERVAL ' . ($negative ? '-' : '') . "'" . ltrim($text, '-') . "' " . $qualifier;
    }

    /**
     * The fields of `$value`'s qualifier, leading first.
     *
     * @return list<IntervalField>
     */
    private static function fields(Interval $value): array
    {
        return array_map(IntervalField::from(...), range($value->qualifier->start->value, $value->qualifier->end->value));
    }

    /** The value of `$field` in an interval of magnitude `$size`: the leading field all it holds of it, SECOND in microseconds. */
    private static function field(Interval $value, string $size, IntervalField $field, bool $leading): string
    {
        $above = $leading ? $size : self::remainder($size, self::UNITS[IntervalField::from(max(0, $field->value - 1))->name]);

        return $field === IntervalField::Second ? $above : self::divide($above, self::UNITS[$field->name]);
    }

    /** `$digits`, a magnitude of any length, divided by `$by`, the quotient's digits. */
    private static function divide(string $digits, int $by): string
    {
        return self::divmod($digits, $by)[0];
    }

    /** `$digits` modulo `$by`. */
    private static function remainder(string $digits, int $by): string
    {
        return self::divmod($digits, $by)[1];
    }

    /**
     * `$digits`, a magnitude's decimal digits, divided by `$by`: the quotient's digits and the remainder's, by long division.
     *
     * @return array{string, string}
     */
    private static function divmod(string $digits, int $by): array
    {
        $quotient = '';
        $carry = 0;
        foreach (str_split($digits) as $digit) {
            $carry = $carry * 10 + (int) $digit;
            $quotient .= intdiv($carry, $by);
            $carry %= $by;
        }
        $quotient = ltrim($quotient, '0');

        return [$quotient === '' ? '0' : $quotient, (string) $carry];
    }

    /** The date `$days` after 0001-01-01, as `YYYY-MM-DD` (Howard Hinnant's civil_from_days). */
    private static function civil(int $days): string
    {
        $z = $days - self::UNIX_EPOCH_DAYS + 719_468;
        $era = intdiv($z >= 0 ? $z : $z - 146_096, 146_097);
        $doe = $z - $era * 146_097;
        $yoe = intdiv($doe - intdiv($doe, 1460) + intdiv($doe, 36_524) - intdiv($doe, 146_096), 365);
        $doy = $doe - (365 * $yoe + intdiv($yoe, 4) - intdiv($yoe, 100));
        $mp = intdiv(5 * $doy + 2, 153);
        $day = $doy - intdiv(153 * $mp + 2, 5) + 1;
        $month = $mp < 10 ? $mp + 3 : $mp - 9;
        $year = $yoe + $era * 400 + ($month <= 2 ? 1 : 0);

        return \sprintf('%04d-%02d-%02d', $year, $month, $day);
    }

    /** `$n` modulo `$by`, never negative. */
    private static function floorRem(int $n, int $by): int
    {
        return (($n % $by) + $by) % $by;
    }
}

<?php

declare(strict_types=1);

namespace Turborel\Internal\Copy;

use Turborel\Value\DecFloat;

/**
 * Numbers as turborel shows them: an approximate number as Rust's `{:E}`
 * writes an `f64`, the shortest digits that read back as it, and a
 * decimal floating-point number as a numeral while its adjusted exponent
 * is from -6 to 33, else in scientific notation.
 *
 * @internal
 */
final class Numbers
{
    /** The least adjusted exponent a decimal floating-point number is written plainly at, as turborel's PLAIN_LEAST. */
    private const int PLAIN_LEAST = -6;

    /** The digits of a decimal floating-point number, past which it is written in scientific notation. */
    private const int PRECISION = 34;

    /** The most significant digits a double needs to read back as itself. */
    private const int DOUBLE_DIGITS = 17;

    /** `$value` as Rust's `{:E}` writes it: `1.5E0`, `1E-7`, `NaN`, `inf`. */
    public static function upperExp(float $value): string
    {
        if (is_nan($value)) {
            return 'NaN';
        }
        if (is_infinite($value)) {
            return $value < 0 ? '-inf' : 'inf';
        }
        $sign = ($value < 0 || ($value === 0.0 && fdiv(1, $value) < 0)) ? '-' : '';
        $magnitude = abs($value);
        if ($magnitude === 0.0) {
            return "{$sign}0E0";
        }
        $written = \sprintf('%.' . (self::DOUBLE_DIGITS - 1) . 'e', $magnitude);
        for ($digits = 1; $digits < self::DOUBLE_DIGITS; $digits++) {
            $shorter = \sprintf('%.' . ($digits - 1) . 'e', $magnitude);
            if ((float) $shorter === $magnitude) {
                $written = $shorter;

                break;
            }
        }
        [$mantissa, $exponent] = explode('e', $written) + ['', '0'];
        $mantissa = str_contains($mantissa, '.') ? rtrim(rtrim($mantissa, '0'), '.') : $mantissa;

        return $sign . $mantissa . 'E' . (int) $exponent;
    }

    /** `$value` as turborel shows a decimal floating-point number. */
    public static function decFloat(DecFloat $value): string
    {
        $digits = ltrim($value->coefficient, '-');
        $adjusted = $value->exponent + \strlen($digits) - 1;
        if ($digits === '0' || ($adjusted >= self::PLAIN_LEAST && $adjusted < self::PRECISION)) {
            return (string) $value;
        }
        $sign = str_starts_with($value->coefficient, '-') ? '-' : '';
        $rest = substr($digits, 1);

        return $sign . $digits[0] . ($rest === '' ? '' : '.' . $rest) . 'E' . $adjusted;
    }
}

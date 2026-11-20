<?php

declare(strict_types=1);

namespace Turborel\Value;

use Turborel\Internal\Codec\Wide;
use Turborel\Internal\ValueFailure;
use Turborel\TurborelException;

/**
 * A decimal floating-point number: a coefficient, an i128, times ten to the
 * exponent, an i32 [PROTOCOL Values].
 *
 * turborel keeps each value in one form, its coefficient of at most 34
 * digits with no trailing zeros (0 of exponent 0), its adjusted exponent,
 * that of its leading digit, within decimal128's -6143 to 6144; a value is
 * made in that form, its trailing zeros moved into the exponent.
 */
final readonly class DecFloat implements SqlValue
{
    /** The most digits of a coefficient: DECFLOAT's precision, 34. */
    public const int MAX_DIGITS = 34;

    /** The greatest adjusted exponent, decimal128's. */
    public const int MAX_EXPONENT = 6144;

    /** The least adjusted exponent, decimal128's. */
    public const int MIN_EXPONENT = -6143;

    /** Its digits, signed, with no trailing zeros. */
    public string $coefficient;

    /** The power of ten they are multiplied by. */
    public int $exponent;

    /**
     * The number `$coefficient` times ten to `$exponent`, in turborel's one form.
     *
     * @throws TurborelException SQLSTATE 22003 if it has more than 34 digits, or its exponent is out of range
     */
    public function __construct(string $coefficient, int $exponent)
    {
        if (!Wide::isInteger($coefficient)) {
            throw ValueFailure::outOfRange("'{$coefficient}' is not a coefficient");
        }
        $digits = ltrim($coefficient, '-');
        $kept = rtrim($digits, '0');
        if ($kept === '') {
            [$this->coefficient, $this->exponent] = ['0', 0];

            return;
        }
        $exponent += \strlen($digits) - \strlen($kept);
        $adjusted = $exponent + \strlen($kept) - 1;
        if (\strlen($kept) > self::MAX_DIGITS || $adjusted < self::MIN_EXPONENT || $adjusted > self::MAX_EXPONENT) {
            throw ValueFailure::outOfRange("{$coefficient}E{$exponent} is out of DECFLOAT's range");
        }
        $this->coefficient = (str_starts_with($coefficient, '-') ? '-' : '') . $kept;
        $this->exponent = $exponent;
    }

    /** The number written plain, without an exponent, as turborel writes one in JSON: `-0.000000015`. */
    public function __toString(): string
    {
        $negative = str_starts_with($this->coefficient, '-');
        $digits = ltrim($this->coefficient, '-');
        if ($this->exponent >= 0) {
            $plain = $digits === '0' ? '0' : $digits . str_repeat('0', $this->exponent);
        } else {
            $padded = str_pad($digits, -$this->exponent + 1, '0', \STR_PAD_LEFT);
            $plain = substr($padded, 0, $this->exponent) . '.' . substr($padded, $this->exponent);
        }

        return ($negative ? '-' : '') . $plain;
    }
}

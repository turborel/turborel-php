<?php

declare(strict_types=1);

namespace Turborel\Value;

use Turborel\Internal\Codec\Wide;
use Turborel\Internal\ValueFailure;
use Turborel\TurborelException;

/**
 * An exact number: units, an i128, divided by ten to the scale, a u32
 * [PROTOCOL Values]. The units are the string of their digits, since no
 * PHP integer holds an i128, so every exact number turborel has is kept
 * with no loss (RULES.md D4).
 */
final readonly class Exact implements SqlValue
{
    /** A number written in digits, with a point or not. */
    private const string NUMERAL = '/^([+-]?)([0-9]*)(?:\.([0-9]*))?$/';

    /** The largest scale written in digits, as turborel's MAX_EXACT_PRECISION: past it, the number is written with an exponent, as turborel writes it, so a scale the protocol allows cannot make its text without bound. */
    private const int MAX_PLAIN_SCALE = 38;

    /** The largest scale: a u32. */
    private const int MAX_SCALE = 0xFFFF_FFFF;

    /**
     * The number `$units` divided by ten to `$scale`.
     *
     * @throws TurborelException SQLSTATE 22003 if `$units` is no i128's digits or `$scale` no u32
     */
    public function __construct(
        /** Its units: the digits of an i128, signed. */
        public string $units,
        /** Its scale: the digits after its point. */
        public int $scale = 0,
    ) {
        Wide::i128ToBytes($units);
        if ($scale < 0 || $scale > self::MAX_SCALE) {
            throw ValueFailure::outOfRange("an exact number's scale cannot be {$scale}");
        }
    }

    /**
     * The exact number `$text` writes, as `-12.340`: its scale the digits
     * after its point, trailing zeros counted.
     *
     * @throws TurborelException SQLSTATE 22003 if it is no number, or its units no i128
     */
    public static function fromString(string $text): self
    {
        if (preg_match(self::NUMERAL, $text, $parts) !== 1 || $parts[2] . ($parts[3] ?? '') === '') {
            throw ValueFailure::outOfRange("'{$text}' is not an exact number");
        }
        $fraction = $parts[3] ?? '';
        $digits = ltrim($parts[2] . $fraction, '0');
        $sign = $parts[1] === '-' && $digits !== '' ? '-' : '';

        return new self($sign . ($digits === '' ? '0' : $digits), \strlen($fraction));
    }

    /**
     * The number written in digits, its point before its last `scale`
     * digits, as `-0.005`; of a scale past 38, which no data type has, its
     * units and an exponent, as `5E-40`, as turborel writes it.
     */
    public function __toString(): string
    {
        $negative = str_starts_with($this->units, '-');
        $digits = ltrim($this->units, '-');
        if ($this->scale === 0) {
            return $this->units;
        }
        if ($this->scale > self::MAX_PLAIN_SCALE) {
            return "{$this->units}E-{$this->scale}";
        }
        $digits = str_pad($digits, $this->scale + 1, '0', \STR_PAD_LEFT);

        return ($negative ? '-' : '') . substr($digits, 0, -$this->scale) . '.' . substr($digits, -$this->scale);
    }
}

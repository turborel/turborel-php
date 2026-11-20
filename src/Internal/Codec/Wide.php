<?php

declare(strict_types=1);

namespace Turborel\Internal\Codec;

use Turborel\Internal\ValueFailure;
use Turborel\TurborelException;

/**
 * The protocol's integers wider than PHP's: an i128, and a u64 past
 * PHP_INT_MAX, written as the strings of their digits (RULES.md D4,
 * R-NUM-1), and converted on four 32-bit limbs, so no float and no
 * bcmath is needed and every value converts exactly [PROTOCOL Values and fields].
 *
 * @internal
 */
final class Wide
{
    /** The bits of a limb. */
    private const int LIMB_BITS = 32;

    /** A limb's bits, all set. */
    private const int LIMB_MASK = 0xFFFF_FFFF;

    /** The limbs of an i128. */
    private const int I128_LIMBS = 4;

    /** The limbs of a u64. */
    private const int U64_LIMBS = 2;

    /** The sign bit of an i128's most significant limb. */
    private const int SIGN_BIT = 0x8000_0000;

    /** The digits converted at a time: 10^9 fits a limb's arithmetic in 64 bits. */
    private const int CHUNK = 1_000_000_000;

    /** The digits of a chunk. */
    private const int CHUNK_DIGITS = 9;

    /** An integer's digits, signed or not. */
    private const string INTEGER = '/^(-?)(0|[1-9][0-9]*)$/';

    /** The largest i128's digits. */
    public const string I128_MAX = '170141183460469231731687303715884105727';

    /** The least i128's digits. */
    public const string I128_MIN = '-170141183460469231731687303715884105728';

    /** pack()'s format of a u32, little-endian. */
    private const string U32 = 'V';

    /** pack()'s format of a 64-bit integer, little-endian. */
    private const string U64 = 'P';

    /** The u32 of four bytes, least significant first. */
    public static function u32FromBytes(string $bytes): int
    {
        $limbs = self::limbs($bytes);

        return $limbs[0] ?? 0;
    }

    /**
     * The four bytes of `$value`, a u32.
     *
     * @throws TurborelException SQLSTATE 22003 if it is not a u32
     */
    public static function u32ToBytes(int $value): string
    {
        if ($value < 0 || $value > self::LIMB_MASK) {
            throw ValueFailure::outOfRange("{$value} is not a u32");
        }

        return pack(self::U32, $value);
    }

    /** The i32 whose bits are the u32 `$bits`. */
    public static function i32(int $bits): int
    {
        return $bits >= self::SIGN_BIT ? $bits - (self::LIMB_MASK + 1) : $bits;
    }

    /** The i64 of eight bytes, least significant first, two's complement. */
    public static function i64FromBytes(string $bytes): int
    {
        [$low, $high] = self::limbs($bytes) + [0, 0];

        return ($high << self::LIMB_BITS) | $low;
    }

    /** The eight bytes of `$value`, an i64, two's complement. */
    public static function i64ToBytes(int $value): string
    {
        return pack(self::U64, $value);
    }

    /** The u64 of eight bytes: an int, or past PHP_INT_MAX the string of its digits. */
    public static function u64FromBytes(string $bytes): int|string
    {
        $value = self::i64FromBytes($bytes);

        return $value >= 0 ? $value : self::digits(self::limbs($bytes));
    }

    /**
     * The eight bytes of `$value`, a u64 as an int or a string of its digits.
     *
     * @throws TurborelException SQLSTATE 22003 if it is not a u64
     */
    public static function u64ToBytes(int|string $value): string
    {
        if (\is_int($value) && $value >= 0) {
            return pack(self::U64, $value);
        }
        [$negative, $limbs] = self::parse((string) $value, self::U64_LIMBS);
        if ($negative && $limbs !== [0, 0]) {
            throw ValueFailure::outOfRange("{$value} is not a u64");
        }

        return self::bytes($limbs);
    }

    /** The digits of the i128 of sixteen bytes, least significant first, two's complement. */
    public static function i128FromBytes(string $bytes): string
    {
        $limbs = self::limbs($bytes);
        if ((($limbs[self::I128_LIMBS - 1] ?? 0) & self::SIGN_BIT) === 0) {
            return self::digits($limbs);
        }

        return '-' . self::digits(self::negated($limbs));
    }

    /**
     * The sixteen bytes of the i128 whose digits are `$digits`.
     *
     * @throws TurborelException SQLSTATE 22003 if they are no integer an i128 holds
     */
    public static function i128ToBytes(string $digits): string
    {
        [$negative, $limbs] = self::parse($digits, self::I128_LIMBS);
        $top = $limbs[self::I128_LIMBS - 1] ?? 0;
        $beyond = ($top & self::SIGN_BIT) !== 0;
        // The magnitude 2^127 is the least i128 alone; any other with the sign bit is out of range.
        if ($beyond && !($negative && $limbs === [0, 0, 0, self::SIGN_BIT])) {
            throw ValueFailure::outOfRange("{$digits} is out of the range of an i128");
        }

        return self::bytes($negative ? self::negated($limbs) : $limbs);
    }

    /** Whether `$digits` is an integer's, `-` and digits with no leading zero, -0 aside. */
    public static function isInteger(string $digits): bool
    {
        return preg_match(self::INTEGER, $digits) === 1 && $digits !== '-0';
    }

    /**
     * The limbs of `$bytes`, least significant first.
     *
     * @return list<int>
     */
    private static function limbs(string $bytes): array
    {
        $limbs = unpack(self::U32 . '*', $bytes);

        return $limbs === false ? [] : array_values(array_map(static fn(mixed $limb): int => \is_int($limb) ? $limb : 0, $limbs));
    }

    /**
     * The bytes of `$limbs`, least significant first.
     *
     * @param list<int> $limbs
     */
    private static function bytes(array $limbs): string
    {
        return pack(self::U32 . '*', ...$limbs);
    }

    /**
     * `$limbs` negated in two's complement: every bit inverted, then one added.
     *
     * @param list<int> $limbs
     *
     * @return list<int>
     */
    private static function negated(array $limbs): array
    {
        $carry = 1;
        $out = [];
        foreach ($limbs as $limb) {
            $sum = (~$limb & self::LIMB_MASK) + $carry;
            $out[] = $sum & self::LIMB_MASK;
            $carry = $sum >> self::LIMB_BITS;
        }

        return $out;
    }

    /**
     * The decimal digits of the unsigned number of `$limbs`.
     *
     * @param list<int> $limbs least significant first
     */
    private static function digits(array $limbs): string
    {
        $chunks = [];
        while (array_filter($limbs, static fn(int $limb): bool => $limb !== 0) !== []) {
            $remainder = 0;
            for ($at = \count($limbs) - 1; $at >= 0; $at--) {
                $current = ($remainder << self::LIMB_BITS) | ($limbs[$at] ?? 0);
                $limbs[$at] = intdiv($current, self::CHUNK);
                $remainder = $current % self::CHUNK;
            }
            $chunks[] = $remainder;
        }
        if ($chunks === []) {
            return '0';
        }
        $text = (string) array_pop($chunks);
        foreach (array_reverse($chunks) as $chunk) {
            $text .= str_pad((string) $chunk, self::CHUNK_DIGITS, '0', \STR_PAD_LEFT);
        }

        return $text;
    }

    /**
     * The sign and the magnitude's `$width` limbs of the integer `$digits`.
     *
     * @return array{bool, list<int>}
     *
     * @throws TurborelException SQLSTATE 22003 if it is no integer, or wider than `$width` limbs
     */
    private static function parse(string $digits, int $width): array
    {
        if (!self::isInteger($digits)) {
            throw ValueFailure::outOfRange("'{$digits}' is not an integer");
        }
        $negative = str_starts_with($digits, '-');
        $magnitude = ltrim($digits, '-');
        // Padded to whole chunks, so each is read most significant first.
        $padded = str_pad($magnitude, (int) ceil(\strlen($magnitude) / self::CHUNK_DIGITS) * self::CHUNK_DIGITS, '0', \STR_PAD_LEFT);
        $limbs = array_fill(0, $width, 0);
        foreach (str_split($padded, self::CHUNK_DIGITS) as $chunk) {
            $limbs = self::multiplyAdd($limbs, (int) $chunk);
        }

        return [$negative, $limbs];
    }

    /**
     * `$limbs` times 10^9, plus `$add`, within their width.
     *
     * @param list<int> $limbs
     *
     * @return list<int>
     *
     * @throws TurborelException SQLSTATE 22003 if the result is wider
     */
    private static function multiplyAdd(array $limbs, int $add): array
    {
        $carry = $add;
        foreach ($limbs as $at => $limb) {
            $product = $limb * self::CHUNK + $carry;
            $limbs[$at] = $product & self::LIMB_MASK;
            $carry = $product >> self::LIMB_BITS;
        }
        if ($carry !== 0) {
            throw ValueFailure::outOfRange('an integer is wider than its field');
        }

        return $limbs;
    }
}

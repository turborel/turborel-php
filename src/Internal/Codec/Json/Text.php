<?php

declare(strict_types=1);

namespace Turborel\Internal\Codec\Json;

use Turborel\Internal\ValueFailure;
use Turborel\TurborelException;
use Turborel\Value\Datetime;
use Turborel\Value\DecFloat;
use Turborel\Value\Exact;

/**
 * An item (Decoder's) serialized as JSON text, as turborel writes it: no
 * insignificant white space; members in the order kept; a string's `"`
 * and `\` escaped, and its control characters as `\b`, `\f`, `\n`, `\r`,
 * `\t` or a `\u` escape of four lower case digits, nothing else escaped;
 * an exact number's digits and its scale's; an approximate number's
 * shortest literal with an exponent, as `1.5E0`; a decimal floating-point
 * number plain. A datetime, or an approximate number not finite, has no
 * JSON text (SQLSTATE 22032).
 *
 * @internal
 */
final class Text
{
    /** The two-character escapes of a string's characters. */
    private const array ESCAPES = ['"' => '\"', '\\' => '\\\\', "\x08" => '\b', "\x0C" => '\f', "\n" => '\n', "\r" => '\r', "\t" => '\t'];

    /** The control characters a string escapes, U+0000 to U+001F. */
    private const string CONTROLS = '/[\x00-\x1F"\\\\]/';

    /** The most significant digits an f64 needs to be read back as itself. */
    private const int F64_DIGITS = 17;

    /** sprintf()'s format of a number in scientific notation, of a precision given. */
    private const string SCIENTIFIC = '%.*e';

    /**
     * The JSON text of `$item`.
     *
     * @throws TurborelException SQLSTATE 22032 if it is or holds a datetime, or an approximate number not finite
     */
    public static function of(mixed $item): string
    {
        return match (true) {
            $item === null => 'null',
            \is_bool($item) => $item ? 'true' : 'false',
            \is_int($item), $item instanceof Exact, $item instanceof DecFloat => (string) $item,
            \is_float($item) => self::approximate($item),
            \is_string($item) => self::string($item),
            $item instanceof JsonObject => self::object($item),
            \is_array($item) => '[' . implode(',', array_map(self::of(...), $item)) . ']',
            $item instanceof Datetime => throw ValueFailure::invalidJson('a datetime has no JSON text'),
            default => throw ValueFailure::invalidJson('a ' . get_debug_type($item) . ' has no JSON text'),
        };
    }

    /**
     * An object's members, in order.
     *
     * @throws TurborelException SQLSTATE 22032 if a value has no JSON text
     */
    private static function object(JsonObject $object): string
    {
        $members = [];
        foreach ($object->members as [$key, $value]) {
            $members[] = self::string($key) . ':' . self::of($value);
        }

        return '{' . implode(',', $members) . '}';
    }

    /** A string, quoted, its control characters and `"` and `\` escaped. */
    private static function string(string $text): string
    {
        $escaped = preg_replace_callback(
            self::CONTROLS,
            static fn(array $match): string => self::ESCAPES[$match[0]] ?? \sprintf('\u%04x', \ord($match[0])),
            $text,
        );

        return '"' . $escaped . '"';
    }

    /**
     * The shortest literal of `$value` with an exponent, as turborel's
     * CAST writes it: `1.5E0`, `-1.0E-3`, `0E0`.
     *
     * @throws TurborelException SQLSTATE 22032 if it is not finite
     */
    public static function approximate(float $value): string
    {
        if (!is_finite($value)) {
            throw ValueFailure::invalidJson('an approximate number that is not finite has no JSON text');
        }
        if ($value === 0.0) {
            return '0E0';
        }
        // Seventeen digits always read back as the number; fewer often do.
        $text = \sprintf(self::SCIENTIFIC, self::F64_DIGITS - 1, $value);
        for ($precision = 0; $precision < self::F64_DIGITS - 1; $precision++) {
            $shorter = \sprintf(self::SCIENTIFIC, $precision, $value);
            if ((float) $shorter === $value) {
                $text = $shorter;
                break;
            }
        }
        [$mantissa, $exponent] = explode('e', $text, 2) + ['', '0'];
        if (!str_contains($mantissa, '.')) {
            $mantissa .= '.0';
        }

        return $mantissa . 'E' . (int) $exponent;
    }
}

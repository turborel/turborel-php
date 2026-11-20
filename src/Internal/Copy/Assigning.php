<?php

declare(strict_types=1);

namespace Turborel\Internal\Copy;

use Turborel\Internal\Codec\Cited;
use Turborel\Internal\Codec\Diagnostics;
use Turborel\Internal\Codec\Failure;
use Turborel\Internal\Codec\FailureState;
use Turborel\TurborelException;
use Turborel\Type\CharacterType;
use Turborel\Type\DataType;
use Turborel\Type\LobType;
use Turborel\Value\CharUnits;
use Turborel\Value\Lob;
use Turborel\Value\LobKind;

/**
 * A character string assigned to a character string column as INSERT
 * assigns it, as turborel-client's import does before it sends it
 * (ISO/IEC 9075:1992 §13.8 GR4b iii): a string too long refused, not cut,
 * unless what is past the length is spaces; CHARACTER padded with spaces
 * to its length; a character large object's string given as a large
 * object held whole.
 *
 * @internal
 */
final class Assigning
{
    /** The rule by which INSERT assigns a value to its column. */
    private const string INSERT_ASSIGNS = 'ISO/IEC 9075:1992 §13.8 GR4b iii';

    /** SQLSTATE 22001, string data, right truncation, and SQLCODE -301, a data exception. */
    private const string TRUNCATION = '22001';

    private const int DATA = -301;

    /**
     * `$value` as it is sent for a parameter of `$type`: a string for a character string type assigned to it; any other as it is.
     *
     * @throws TurborelException SQLSTATE 22001 of a string too long for its column
     */
    public static function assigned(mixed $value, DataType $type): mixed
    {
        if (!\is_string($value)) {
            return $value;
        }
        if ($type instanceof CharacterType) {
            return self::character($value, $type);
        }
        if ($type instanceof LobType && $type->kind === LobKind::Character) {
            return new Lob(LobKind::Character, self::lob($value, $type));
        }

        return $value;
    }

    /**
     * `$value` assigned to `$type`, CHARACTER or CHARACTER VARYING.
     *
     * @throws TurborelException SQLSTATE 22001 if it is too long
     */
    private static function character(string $value, CharacterType $type): string
    {
        $octets = $type->units === CharUnits::Octets;
        [$count, $length] = [self::count($value, $octets), $type->length];
        if ($count > $length) {
            $kept = self::prefix($value, $length, $octets);
            if (trim(substr($value, \strlen($kept)), ' ') !== '') {
                $written = ($type->varying ? 'CHARACTER VARYING' : 'CHARACTER') . self::length($type->length, $type->units);
                $unit = $octets ? 'octets' : 'characters';
                $rule = self::INSERT_ASSIGNS . ', §9.2 ' . ($type->varying ? 'GR3e ii' : 'GR3b ii');

                throw self::truncation("a value of {$count} {$unit}, not spaces beyond {$length}, does not fit {$written}", $rule, $written);
            }
            [$value, $count] = [$kept, self::count($kept, $octets)];
        }

        return $type->varying ? $value : $value . str_repeat(' ', max(0, $length - $count));
    }

    /**
     * `$value` assigned to `$type`, a character large object: cut to its length if what is past it is spaces.
     *
     * @throws TurborelException SQLSTATE 22001 if it is too long
     */
    private static function lob(string $value, LobType $type): string
    {
        $octets = $type->units === CharUnits::Octets;
        $length = (int) min($type->length, \PHP_INT_MAX);
        if (self::count($value, $octets) <= $length) {
            return $value;
        }
        $kept = self::prefix($value, $length, $octets);
        if (trim(substr($value, \strlen($kept)), ' ') !== '') {
            $written = 'CHARACTER LARGE OBJECT' . self::length($type->length, $type->units);
            $rule = self::INSERT_ASSIGNS . '; ISO/IEC 9075-2:2023 §9.2 GR3e ii';

            throw self::truncation("a large object longer than {$written}, not padding beyond it, does not fit it", $rule, $written);
        }

        return $kept;
    }

    /** A type's length as written: in parentheses, OCTETS after it if it counts them. */
    private static function length(int|string $length, CharUnits $units): string
    {
        return '(' . $length . ($units === CharUnits::Octets ? ' OCTETS' : '') . ')';
    }

    /** The characters of `$value`, or its octets if `$octets`. */
    private static function count(string $value, bool $octets): int
    {
        return $octets ? \strlen($value) : \strlen($value) - (int) preg_match_all('/[\x80-\xBF]/', $value);
    }

    /** The longest beginning of `$value` of at most `$most` characters, or of whole characters within `$most` octets. */
    private static function prefix(string $value, int $most, bool $octets): string
    {
        if ($octets) {
            $kept = substr($value, 0, $most);
            while ($kept !== '' && preg_match('//u', $kept) !== 1) {
                $kept = substr($kept, 0, -1);
            }

            return $kept;
        }
        preg_match('/^.{0,' . min($most, 65535) . '}/us', $value, $match);
        $kept = $match[0] ?? '';
        while (self::count($kept, false) < $most && \strlen($kept) < \strlen($value)) {
            preg_match('/^.{0,' . min($most - self::count($kept, false), 65535) . '}/us', substr($value, \strlen($kept)), $more);
            $kept .= $more[0] ?? '';
        }

        return $kept;
    }

    /** The failure of a string too long, `$message`, citing `$rule`, of the type written `$type`. */
    private static function truncation(string $message, string $rule, string $type): TurborelException
    {
        $diagnostics = new Diagnostics(['severity' => 'ERROR', 'dataType' => $type]);

        return TurborelException::fromServer(new Failure(self::DATA, self::TRUNCATION, $message, new Cited($rule), new FailureState(false, $diagnostics)));
    }
}

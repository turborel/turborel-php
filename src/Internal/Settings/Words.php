<?php

declare(strict_types=1);

namespace Turborel\Internal\Settings;

use Turborel\TurborelException;

/**
 * Settings read from their text, as turborel-client's `settings.rs` and
 * `values.rs` read them: a word of a few, a yes or no, a port, whole
 * seconds.
 *
 * @internal
 */
final class Words
{
    /** The greatest u64, as Rust parses whole seconds. */
    private const string U64_MAX = '18446744073709551615';

    /** The greatest TCP port. */
    private const int MAX_PORT = 65535;

    /**
     * The case of `$enum` the word `$text` is, the value of `$key`.
     *
     * @template T of \BackedEnum
     *
     * @param class-string<T> $enum
     *
     * @return T
     *
     * @throws TurborelException SQLSTATE 08001 if it names none
     */
    public static function word(string $enum, string $key, string $text): \BackedEnum
    {
        $found = $enum::tryFrom($text);
        if ($found === null) {
            $known = implode(', ', array_map(static fn(\BackedEnum $case): string => (string) $case->value, $enum::cases()));

            throw Invalid::because("{$key} is {$text}, not one of {$known}");
        }

        return $found;
    }

    /**
     * The yes or no `$text` is, `true` or `false`, the value of `$key`.
     *
     * @throws TurborelException SQLSTATE 08001 if it is neither
     */
    public static function boolean(string $key, string $text): bool
    {
        return match ($text) {
            'true' => true,
            'false' => false,
            default => throw Invalid::because("{$key} is {$text}, not one of true, false"),
        };
    }

    /**
     * The port `$text` is, the value of `$key`.
     *
     * @throws TurborelException SQLSTATE 08001 if it is no port
     */
    public static function port(string $key, string $text): int
    {
        $digits = preg_match('/^\+?(\d+)$/', trim($text), $match) === 1 ? ltrim($match[1], '0') : null;
        if ($digits === null || \strlen($digits) > 5 || (int) $digits > self::MAX_PORT) {
            throw Invalid::because("{$key} is {$text}, no port");
        }

        return (int) $digits;
    }

    /**
     * The time `$text` is, in whole seconds; none for 0, no limit.
     *
     * @throws TurborelException SQLSTATE 08001 if it is not a whole number of seconds
     */
    public static function seconds(string $key, string $text): ?float
    {
        $digits = preg_match('/^\+?(\d+)$/', trim($text), $match) === 1 ? ltrim($match[1], '0') : null;
        if ($digits === null || \strlen($digits) > 20 || (\strlen($digits) === 20 && strcmp($digits, self::U64_MAX) > 0)) {
            throw Invalid::because("{$key} is {$text}, not a whole number of seconds");
        }

        return $digits === '' ? null : (float) $digits;
    }
}

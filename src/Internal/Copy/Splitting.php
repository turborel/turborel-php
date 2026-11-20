<?php

declare(strict_types=1);

namespace Turborel\Internal\Copy;

use Turborel\Copy\Dialect;

/**
 * A delimited record split into its fields, as turborel-client's
 * `delimited.rs` splits it: CSV's fields enclosed in quotes as they need,
 * a quote in one written twice (RFC 4180 §2 rules 5 to 7); tab-separated
 * text's never. A quote in a field not enclosed, anything but the
 * delimiter after a closing quote, a quote left open, or a field not
 * UTF-8 is refused.
 *
 * @internal
 */
final class Splitting
{
    /** What encloses a field. */
    private const string QUOTE = '"';

    /** Whether `$bytes`, a CSV record split at `$delimiter` so far, ends within a quoted field. */
    public static function inQuotes(string $bytes, string $delimiter): bool
    {
        // Where the scan is: 0 at a field's start, 1 in a plain field, 2 in a quoted one, 3 after a quote in a quoted one.
        $at = 0;
        $length = \strlen($bytes);
        for ($i = 0; $i < $length; $i++) {
            [$quote, $split] = [$bytes[$i] === self::QUOTE, $bytes[$i] === $delimiter];
            $at = match ($at) {
                2 => $quote ? 3 : 2,
                0, 3 => $split ? 0 : ($quote ? 2 : 1),
                default => $split ? 0 : 1,
            };
        }

        return $at === 2;
    }

    /**
     * The fields of the record `$bytes`, each its text and whether it was enclosed in quotes.
     *
     * @return list<array{string, bool}>
     *
     * @throws \UnexpectedValueException what is wrong with it
     */
    public static function split(string $bytes, Dialect $dialect): array
    {
        if (!$dialect->quoted) {
            $parts = $dialect->delimiter === '' ? [$bytes] : explode($dialect->delimiter, $bytes);

            return array_map(static fn(string $text, int $n): array => [self::text($text, $n), false], $parts, array_keys($parts));
        }
        $fields = [];
        $at = 0;
        while (true) {
            $n = \count($fields);
            $quoted = ($bytes[$at] ?? '') === self::QUOTE;
            [$written, $at] = $quoted ? self::enclosed($bytes, $at + 1, $n) : self::plain($bytes, $at, $dialect->delimiter, $n);
            $fields[] = [self::text($written, $n), $quoted];
            if ($at >= \strlen($bytes)) {
                return $fields;
            }
            if ($bytes[$at] !== $dialect->delimiter) {
                throw new \UnexpectedValueException('field ' . ($n + 1) . ": a quoted field's closing quote is followed by more");
            }
            ++$at;
        }
    }

    /**
     * A field not enclosed in quotes from `$at` to the next delimiter, and where it ends; `$n` its index.
     *
     * @return array{string, int}
     *
     * @throws \UnexpectedValueException of a quote in it
     */
    private static function plain(string $bytes, int $at, string $delimiter, int $n): array
    {
        $end = strpos($bytes, $delimiter, $at);
        $end = $end === false ? \strlen($bytes) : $end;
        $written = substr($bytes, $at, $end - $at);
        if (str_contains($written, self::QUOTE)) {
            throw new \UnexpectedValueException('field ' . ($n + 1) . ': a quote in a field not enclosed in quotes');
        }

        return [$written, $end];
    }

    /**
     * A field enclosed in quotes, its opening quote read, to its closing quote, a quote written twice in it one, and where it ends.
     *
     * @return array{string, int}
     *
     * @throws \UnexpectedValueException if it is not closed
     */
    private static function enclosed(string $bytes, int $at, int $n): array
    {
        $written = '';
        while (true) {
            $quote = strpos($bytes, self::QUOTE, $at);
            if ($quote === false) {
                throw new \UnexpectedValueException('field ' . ($n + 1) . ': a quoted field is not closed');
            }
            $written .= substr($bytes, $at, $quote - $at);
            if (($bytes[$quote + 1] ?? '') !== self::QUOTE) {
                return [$written, $quote + 1];
            }
            $written .= self::QUOTE;
            $at = $quote + 2;
        }
    }

    /**
     * `$bytes` as text, of the field of index `$n`.
     *
     * @throws \UnexpectedValueException if it is not UTF-8
     */
    private static function text(string $bytes, int $n): string
    {
        if (preg_match('//u', $bytes) !== 1) {
            throw new \UnexpectedValueException('field ' . ($n + 1) . ' is not UTF-8');
        }

        return $bytes;
    }
}

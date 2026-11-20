<?php

declare(strict_types=1);

namespace Turborel\Internal\Codec;

/**
 * Whether bytes are UTF-8, by PCRE, which every PHP has, where mbstring
 * may be missing (RULES.md D4).
 *
 * @internal
 */
final class Utf8
{
    /** A pattern that matches any UTF-8 text, and fails on any other bytes. */
    private const string ANY_TEXT = '//u';

    /** The largest code point of one, two and three octets, in order. */
    private const array LIMITS = [0x7F, 0x7FF, 0xFFFF];

    /** The bits a continuation octet holds. */
    private const int CONTINUATION_BITS = 6;

    /** A continuation octet's marker. */
    private const int CONTINUATION = 0x80;

    /** A continuation octet's bits, all set. */
    private const int CONTINUATION_MASK = 0x3F;

    /** The leading octet's marker of two, three and four octets, in order. */
    private const array LEADS = [0xC0, 0xE0, 0xF0];

    /** The UTF-8 of the code point `$point`, a scalar value, as RFC 3629 encodes it. */
    public static function encode(int $point): string
    {
        if ($point <= self::LIMITS[0]) {
            return \chr($point);
        }
        $tail = '';
        $count = $point <= self::LIMITS[1] ? 1 : ($point <= self::LIMITS[2] ? 2 : 3);
        for ($i = 0; $i < $count; $i++) {
            $tail = \chr(self::CONTINUATION | ($point & self::CONTINUATION_MASK)) . $tail;
            $point >>= self::CONTINUATION_BITS;
        }

        return \chr(self::LEADS[$count - 1] | $point) . $tail;
    }

    /** Whether `$bytes` are UTF-8. */
    public static function valid(string $bytes): bool
    {
        return preg_match(self::ANY_TEXT, $bytes) === 1;
    }
}

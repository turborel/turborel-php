<?php

declare(strict_types=1);

namespace Turborel\Internal\Tls;

/**
 * DER's elements, read by their tags and lengths alone [ITU-T X.690
 * §8.1], as turborel's `der.rs` reads them: enough to walk a certificate
 * to its signature algorithm. Nothing but the lengths is checked: what is
 * read comes from a certificate TLS has verified.
 *
 * @internal
 */
final class Der
{
    /** A SEQUENCE's tag. */
    public const int SEQUENCE = 0x30;

    /** An OBJECT IDENTIFIER's tag. */
    public const int OID = 0x06;

    /** A length's long form: its low bits count its bytes. */
    private const int LONG_FORM = 0x80;

    /** The low bits of a long form's first byte. */
    private const int COUNT_MASK = 0x7F;

    /** The most bytes of a long form's length, as turborel reads one. */
    private const int MAX_LENGTH_BYTES = 4;

    /** The bits of a byte. */
    private const int BYTE_BITS = 8;

    /**
     * The element `$der` begins with: its tag, its body and what follows it; none if it is cut short.
     *
     * @return array{int, string, string}|null
     */
    public static function element(string $der): ?array
    {
        if (\strlen($der) < 2) {
            return null;
        }
        [$tag, $first, $at] = [\ord($der[0]), \ord($der[1]), 2];
        $length = $first;
        if ($first >= self::LONG_FORM) {
            $count = $first & self::COUNT_MASK;
            if ($count === 0 || $count > self::MAX_LENGTH_BYTES || \strlen($der) < 2 + $count) {
                return null;
            }
            $length = 0;
            for ($i = 0; $i < $count; $i++) {
                $length = ($length << self::BYTE_BITS) | \ord($der[2 + $i]);
            }
            $at += $count;
        }
        if (\strlen($der) - $at < $length) {
            return null;
        }

        return [$tag, substr($der, $at, $length), substr($der, $at + $length)];
    }

    /**
     * The body of the element of tag `$tag` `$der` begins with, and what follows it; none if it begins with another.
     *
     * @return array{string, string}|null
     */
    public static function expect(string $der, int $tag): ?array
    {
        $element = self::element($der);

        return $element !== null && $element[0] === $tag ? [$element[1], $element[2]] : null;
    }
}

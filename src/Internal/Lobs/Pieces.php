<?php

declare(strict_types=1);

namespace Turborel\Internal\Lobs;

/**
 * Where a character large object's octets are cut into pieces, as
 * turborel's `char_cut` cuts them: never within a UTF-8 character.
 *
 * @internal
 */
final class Pieces
{
    /**
     * The longest prefix of `$bytes`, at most `$most` octets, of whole UTF-8 characters; a character the end of `$bytes`
     * splits left out too, its rest yet to come; 0 if no character ends within `$most`.
     */
    public static function charCut(string $bytes, int $most): int
    {
        $cut = min($most, \strlen($bytes));
        if ($cut < \strlen($bytes)) {
            while ($cut > 0 && (\ord($bytes[$cut]) & 0xC0) === 0x80) {
                --$cut;
            }

            return $cut;
        }
        for ($back = 1; $back <= 4 && $cut - $back >= 0; $back++) {
            $byte = \ord($bytes[$cut - $back]);
            if (($byte & 0xC0) !== 0x80) {
                $width = match (true) {
                    ($byte & 0xE0) === 0xC0 => 2,
                    ($byte & 0xF0) === 0xE0 => 3,
                    ($byte & 0xF8) === 0xF0 => 4,
                    default => 1,
                };

                return $cut - $back + $width > $cut ? $cut - $back : $cut;
            }
        }

        return $cut;
    }
}

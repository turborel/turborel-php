<?php

declare(strict_types=1);

namespace Turborel\Internal\Lobs;

use Turborel\Value\Lob;
use Turborel\Value\LobKind;

/**
 * A range of a large object held whole, as turborel-client's `held_range`
 * takes it: by octets, or by a character one's characters.
 *
 * @internal
 */
final class Held
{
    /** The range of `$count` from `$from` of `$lob`, in characters if `$characters` and it is a character one. */
    public static function range(Lob $lob, int $from, int $count, bool $characters): string
    {
        [$from, $count] = [max(0, $from), max(0, $count)];
        if (!$characters || $lob->kind === LobKind::Binary) {
            return substr($lob->octets, min($from, \strlen($lob->octets)), $count);
        }
        $all = preg_split('//u', $lob->octets, -1, \PREG_SPLIT_NO_EMPTY);

        return implode('', \array_slice($all === false ? [] : $all, $from, $count));
    }
}

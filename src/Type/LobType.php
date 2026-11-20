<?php

declare(strict_types=1);

namespace Turborel\Type;

use Turborel\Value\CharUnits;
use Turborel\Value\LobKind;

/**
 * CHARACTER LARGE OBJECT, tag 18, or BINARY LARGE OBJECT, tag 19: its length, a u64, and its units [PROTOCOL Data types].
 */
final readonly class LobType implements DataType
{
    /** Makes it of its parts. */
    public function __construct(
        /** Whether it holds characters or octets. */
        public LobKind $kind,
        /** Its length, a u64: an int, or past PHP_INT_MAX its digits. */
        public int|string $length,
        /** What its length counts. */
        public CharUnits $units,
    ) {}
}

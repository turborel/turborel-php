<?php

declare(strict_types=1);

namespace Turborel\Type;

use Turborel\Value\CharUnits;

/**
 * CHARACTER, tag 1, or CHARACTER VARYING, tag 5: a length and its units [PROTOCOL Data types].
 */
final readonly class CharacterType implements DataType
{
    /** Makes it of its parts. */
    public function __construct(
        /** Whether it is CHARACTER VARYING. */
        public bool $varying,
        /** Its length, a u32. */
        public int $length,
        /** What its length counts. */
        public CharUnits $units,
    ) {}
}

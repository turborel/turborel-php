<?php

declare(strict_types=1);

namespace Turborel\Value;

/**
 * What a character string type's length counts, by its units' byte
 * [PROTOCOL Data types].
 */
enum CharUnits: int
{
    /** Characters. */
    case Characters = 0;

    /** Octets. */
    case Octets = 1;
}

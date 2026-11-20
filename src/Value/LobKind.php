<?php

declare(strict_types=1);

namespace Turborel\Value;

/**
 * Whether a large object holds characters or octets, by its kind's byte
 * [PROTOCOL Values].
 */
enum LobKind: int
{
    /** A CHARACTER LARGE OBJECT, its octets UTF-8. */
    case Character = 0;

    /** A BINARY LARGE OBJECT. */
    case Binary = 1;
}

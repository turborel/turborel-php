<?php

declare(strict_types=1);

namespace Turborel\Internal\Auth;

use Turborel\Internal\ConnectionFailure;
use Turborel\TurborelException;

/**
 * A SCRAM exchange refused, as turborel's `scram.rs` refuses one: SQLSTATE
 * 28000, SQLCODE -919, the connection lost with it [PROTOCOL Mechanisms].
 *
 * @internal
 */
final class ScramFailure
{
    /** The exchange refused, saying `$why`. */
    public static function because(string $why): TurborelException
    {
        return ConnectionFailure::refused("SCRAM: {$why}");
    }
}

<?php

declare(strict_types=1);

namespace Turborel\Internal\Agent;

use Turborel\TurborelException;

/**
 * The SQL-agent's failures, as turborel-client's `exception` makes them:
 * of class 28, SQLCODE -104; of class 0A, -900; any other, a connection
 * exception, -919.
 *
 * @internal
 */
final class Failures
{
    /** The failure of SQLSTATE `$sqlstate`, `$message`. */
    public static function of(string $sqlstate, string $message): TurborelException
    {
        $code = match (substr($sqlstate, 0, 2)) {
            '28' => -104,
            '0A' => -900,
            default => -919,
        };

        return new TurborelException($message, $sqlstate, $code);
    }
}

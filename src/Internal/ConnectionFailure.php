<?php

declare(strict_types=1);

namespace Turborel\Internal;

use Turborel\TurborelException;

/**
 * The failure that loses a connection: SQLCODE -919, SQLSTATE 08006.
 *
 * A malformed frame, a body cut short or one longer than the reader takes
 * all fail the connection with these codes [PROTOCOL Frames], as they do
 * in turborel-client, so a program sees one failure whichever side found it.
 *
 * @internal
 */
final class ConnectionFailure
{
    /** SQLSTATE 08006, connection failure [PROTOCOL Errors and notices]. */
    public const string SQLSTATE = '08006';

    /** SQLCODE -919, a connection lost [PROTOCOL Errors and notices]. */
    public const int SQLCODE = -919;

    /** The failure that loses the connection, saying why in `$message`. */
    public static function because(string $message): TurborelException
    {
        return new TurborelException($message, self::SQLSTATE, self::SQLCODE);
    }
}

<?php

declare(strict_types=1);

namespace Turborel\Internal;

use Turborel\TurborelException;

/**
 * The failure of a program's misuse of the API that turborel-client has
 * no case of, as a call out of order or an argument of no use: SQLSTATE
 * TB910, SQLCODE -910, as every Turborel driver gives it, the connection
 * kept.
 *
 * @internal
 */
final class Misuse
{
    /** SQLSTATE TB910, the API misused. */
    public const string SQLSTATE = 'TB910';

    /** SQLCODE -910, turborel's Code::Api. */
    public const int SQLCODE = -910;

    /** The misuse `$message` says. */
    public static function of(string $message): TurborelException
    {
        return new TurborelException($message, self::SQLSTATE, self::SQLCODE);
    }
}

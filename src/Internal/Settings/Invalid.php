<?php

declare(strict_types=1);

namespace Turborel\Internal\Settings;

use Turborel\TurborelException;

/**
 * A setting that cannot be read, or settings no connection can be made
 * by, as turborel-client's `invalid` fails them: SQLSTATE 08001, the
 * client unable to connect, SQLCODE -919.
 *
 * @internal
 */
final class Invalid
{
    /** SQLSTATE 08001, the client unable to connect. */
    public const string SQLSTATE = '08001';

    /** SQLCODE -919, a connection's failure. */
    public const int SQLCODE = -919;

    /** The failure `$message` says. */
    public static function because(string $message): TurborelException
    {
        return new TurborelException($message, self::SQLSTATE, self::SQLCODE);
    }
}

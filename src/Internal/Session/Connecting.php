<?php

declare(strict_types=1);

namespace Turborel\Internal\Session;

use Turborel\Internal\ConnectionFailure;
use Turborel\Internal\Transport\Stream;
use Turborel\Session\Connection;
use Turborel\Session\Mechanism;
use Turborel\Session\Offer;
use Turborel\Session\Target;
use Turborel\Session\Timeouts;
use Turborel\Session\TlsChannel;
use Turborel\TurborelException;

/**
 * A connection made over a stream opened to a target, as
 * turborel-client's `log_in` makes it [CLIENT Connecting]: the mechanism
 * bound to TLS's channel, if any; a secret refused over plain TCP before
 * it is sent; the user logged in; then each read bounded by the read
 * timeout.
 *
 * @internal
 */
final class Connecting
{
    /**
     * That `$mechanism` sends no secret over `$target`, plain TCP.
     *
     * @throws TurborelException SQLSTATE 28000 if it would
     */
    public static function inTheClear(Target $target, Mechanism $mechanism): void
    {
        if ($target->isPlainTcp() && $mechanism->secret()) {
            throw ConnectionFailure::refused("{$mechanism->name()} is sent only over TLS, a Unix socket or a named pipe, never over plain TCP");
        }
    }

    /**
     * The connection over `$opened`, a stream, its TLS channel and the target it reached, as `$user` by `$mechanism`.
     *
     * @param array{Stream, ?TlsChannel, Target} $opened
     *
     * @throws TurborelException SQLSTATE 28000 of a secret over plain TCP or credentials refused, 08006 if the login fails
     */
    public static function over(array $opened, string $user, Mechanism $mechanism, Timeouts $timeouts, Offer $offer): Connection
    {
        [$stream, $channel, $target] = $opened;
        try {
            self::inTheClear($target, $mechanism);
        } catch (TurborelException $refused) {
            $stream->close();

            throw $refused;
        }
        if ($channel !== null) {
            $mechanism->bind($channel);
        }
        $wire = new Wire($stream);
        $connection = new Connection($wire, $target, $timeouts, Login::logIn($wire, $user, $mechanism, $offer), $offer->compressFrom);
        $stream->bound($timeouts->read);

        return $connection;
    }
}

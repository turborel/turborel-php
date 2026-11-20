<?php

declare(strict_types=1);

namespace Turborel\Internal\Session;

use Turborel\Internal\Codec\Reply\Challenge;
use Turborel\Internal\Codec\Reply\Error;
use Turborel\Internal\Codec\Reply\Ready;
use Turborel\Internal\Codec\Request\Answer;
use Turborel\Internal\Codec\Request\Hello;
use Turborel\Session\Mechanism;
use Turborel\Session\Offer;
use Turborel\TurborelException;

/**
 * A login, as turborel-client's `connection.rs` logs in
 * [PROTOCOL Opening a connection]: Hello, then each Challenge answered,
 * until Ready or an Error; the stream closed if it fails.
 *
 * @internal
 */
final class Login
{
    /**
     * Logs in over `$wire` as `$user`, by `$mechanism`, asking for what `$offer` asks: the server's Ready.
     *
     * @throws TurborelException the server's refusal, or SQLSTATE 08006 if it sends no Ready
     */
    public static function logIn(Wire $wire, string $user, Mechanism $mechanism, Offer $offer): Ready
    {
        try {
            return self::exchange($wire, $user, $mechanism, $offer);
        } catch (TurborelException $refused) {
            $wire->stream->close();

            throw $refused;
        }
    }

    /**
     * Hello, then each Challenge answered, until Ready or an Error.
     *
     * @throws TurborelException the server's refusal, or SQLSTATE 08006 if it sends no Ready
     */
    private static function exchange(Wire $wire, string $user, Mechanism $mechanism, Offer $offer): Ready
    {
        // The name first: SCRAM's initial message leaves the next connection to bind afresh.
        [$name, $initial] = [$mechanism->name(), $mechanism->initial()];
        $wire->send(new Hello($user, $name, $initial, $offer->capabilities, $offer->attributes));
        while (true) {
            $reply = $wire->receive();
            if ($reply instanceof Challenge) {
                $wire->send(new Answer($mechanism->answer($reply->challenge)));
            } elseif ($reply instanceof Ready) {
                $mechanism->ready();

                return $reply;
            } elseif ($reply instanceof Error) {
                $mechanism->refused();

                throw TurborelException::fromServer($reply->failure);
            } else {
                throw $wire->lose('the server sent no Ready');
            }
        }
    }
}

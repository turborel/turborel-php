<?php

declare(strict_types=1);

namespace Turborel\Internal\Session;

use Turborel\Internal\Codec\Reply\Challenge;
use Turborel\Internal\Codec\Reply\Complete;
use Turborel\Internal\Codec\Reply\Error;
use Turborel\Internal\Codec\Reply\Pong;
use Turborel\Internal\Codec\Reply\Ready;
use Turborel\Internal\Codec\Request\Answer;
use Turborel\Internal\Codec\Request\ChangeUser;
use Turborel\Internal\Codec\Request\Ping;
use Turborel\Internal\Codec\Request\Reset;
use Turborel\Internal\ConnectionFailure;
use Turborel\Session\Capability;
use Turborel\Session\Mechanism;
use Turborel\TurborelException;

/**
 * What a connection asks of its session beyond statements, as
 * turborel-client's `session.rs` asks it, each only where granted, else
 * failing before anything is sent [CLIENT What a connection asks for]: a
 * ping, a reset, a change of user [CLIENT Controlling the session].
 *
 * @internal
 */
final readonly class Control
{
    /** The control of the session over `$wire`. */
    public function __construct(private Wire $wire) {}

    /**
     * That the server granted `$capability`, unless `$ok`.
     *
     * @throws TurborelException SQLSTATE 08006 if it did not, the connection kept
     */
    public function needs(string $capability, bool $ok = false): void
    {
        if (!$ok && !$this->wire->told->has($capability)) {
            throw ConnectionFailure::because("the server did not grant {$capability}");
        }
    }

    /**
     * Asks whether the server and the connection are alive, with no statement run [PROTOCOL Ping 'G'].
     *
     * @throws TurborelException SQLSTATE 08006 if `ping` was not granted, or the connection is lost
     */
    public function ping(): void
    {
        $this->needs(Capability::PING);
        $this->wire->send(new Ping());
        $reply = $this->wire->receive();
        if ($reply instanceof Error) {
            throw $this->wire->failed(TurborelException::fromServer($reply->failure));
        }
        if (!$reply instanceof Pong) {
            throw $this->wire->lose('the server sent no Pong');
        }
    }

    /**
     * Puts the session back as it was at login, its transaction rolled back [PROTOCOL Reset 'W'].
     *
     * @throws TurborelException SQLSTATE 08006 if `reset` was not granted, or the connection is lost
     */
    public function reset(): void
    {
        $this->needs(Capability::RESET);
        $this->wire->send(new Reset());
        $reply = $this->wire->receive();
        if ($reply instanceof Error) {
            throw $this->wire->failed(TurborelException::fromServer($reply->failure));
        }
        if (!$reply instanceof Complete) {
            throw $this->wire->lose('the server sent no completion');
        }
    }

    /**
     * Authenticates the connection again as `$user`, by `$mechanism`, and gives the server's Ready [PROTOCOL ChangeUser 'U'].
     *
     * @throws TurborelException the refusal, which fails the connection, or SQLSTATE 08006 if `change-user` was not granted
     */
    public function changeUser(string $user, Mechanism $mechanism): Ready
    {
        $this->needs(Capability::CHANGE_USER);
        // The name first: SCRAM's initial message leaves the next connection to bind afresh.
        [$name, $initial] = [$mechanism->name(), $mechanism->initial()];
        $this->wire->send(new ChangeUser($user, $name, $initial));
        while (true) {
            $reply = $this->wire->receive();
            if ($reply instanceof Challenge) {
                $this->wire->send(new Answer($mechanism->answer($reply->challenge)));
            } elseif ($reply instanceof Ready) {
                // The session is renewed as by Reset: the wire noted no transaction active, and no warning.
                $mechanism->ready();

                return $reply;
            } elseif ($reply instanceof Error) {
                throw $this->wire->failed(TurborelException::fromServer($reply->failure));
            } else {
                throw $this->wire->lose('the server sent no Ready');
            }
        }
    }
}

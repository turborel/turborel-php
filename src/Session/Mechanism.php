<?php

declare(strict_types=1);

namespace Turborel\Session;

use Turborel\TurborelException;

/**
 * What a connection proves its user by [PROTOCOL Mechanisms]: its name,
 * the first bytes Hello carries, and its answer to each Challenge, until
 * the server sends Ready or an Error [PROTOCOL Opening a connection].
 */
interface Mechanism
{
    /** Its name, as Hello names it; asked before `initial`, which may change it. */
    public function name(): string;

    /** Its first bytes, which Hello carries; called once a login begins. */
    public function initial(): string;

    /**
     * Its answer to the Challenge `$challenge`.
     *
     * @throws TurborelException if the challenge cannot be answered
     */
    public function answer(string $challenge): string;

    /** Whether it sends a secret, so is never used over plain TCP [CLIENT Connecting]. */
    public function secret(): bool;

    /**
     * That the login it made may be taken, now the server sends Ready.
     *
     * @throws TurborelException if the server proved nothing it should have
     */
    public function ready(): void;

    /** Binds it to the TLS channel `$channel`, before its first bytes. */
    public function bind(TlsChannel $channel): void;

    /** Tells it the server refused its login. */
    public function refused(): void;
}

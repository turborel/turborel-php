<?php

declare(strict_types=1);

namespace Turborel\Session;

use Turborel\Internal\ConnectionFailure;
use Turborel\TurborelException;

/**
 * The mechanism `trust`: the server takes the user as it names itself, as it does only of a client of its own machine, where SERVER.TRUST_LOCAL is ON [PROTOCOL Mechanisms].
 */
final class Trust implements Mechanism
{
    /** The mechanism's name. */
    public const string NAME = 'trust';

    /** {@inheritDoc} */
    public function name(): string
    {
        return self::NAME;
    }

    /** {@inheritDoc} */
    public function initial(): string
    {
        return '';
    }

    /**
     * It has no answer to a challenge.
     *
     * @throws TurborelException SQLSTATE 08006, always
     */
    public function answer(string $challenge): string
    {
        throw ConnectionFailure::because('trust has no answer to a challenge');
    }

    /** {@inheritDoc} */
    public function secret(): bool
    {
        return false;
    }

    /** {@inheritDoc} */
    public function ready(): void {}

    /** {@inheritDoc} */
    public function bind(TlsChannel $channel): void {}

    /** {@inheritDoc} */
    public function refused(): void {}
}

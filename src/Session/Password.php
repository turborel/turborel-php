<?php

declare(strict_types=1);

namespace Turborel\Session;

use Turborel\Internal\ConnectionFailure;
use Turborel\TurborelException;

/**
 * The mechanism `password`: the password's UTF-8 sent whole, only over TLS, a Unix socket or a named pipe [PROTOCOL Mechanisms]. SCRAM-SHA-256 is what a password should be proved by.
 */
final class Password implements Mechanism
{
    /** The mechanism's name. */
    public const string NAME = 'password';

    /** The mechanism of the password `$password`. */
    public function __construct(
        #[\SensitiveParameter]
        private readonly string $password,
    ) {}

    /**
     * Its fields as var_dump() shows them, the password left out (RULES.md R-SAFE-4).
     *
     * @return array<string, string>
     */
    public function __debugInfo(): array
    {
        return ['password' => '(secret)'];
    }

    /** {@inheritDoc} */
    public function name(): string
    {
        return self::NAME;
    }

    /** {@inheritDoc} */
    public function initial(): string
    {
        return $this->password;
    }

    /**
     * It has no answer to a challenge.
     *
     * @throws TurborelException SQLSTATE 08006, always
     */
    public function answer(string $challenge): string
    {
        throw ConnectionFailure::because('password has no answer to a challenge');
    }

    /** {@inheritDoc} */
    public function secret(): bool
    {
        return true;
    }

    /** {@inheritDoc} */
    public function ready(): void {}

    /** {@inheritDoc} */
    public function bind(TlsChannel $channel): void {}

    /** {@inheritDoc} */
    public function refused(): void {}
}

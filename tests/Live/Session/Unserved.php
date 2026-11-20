<?php

declare(strict_types=1);

namespace Turborel\Tests\Live\Session;

use Turborel\Session\Mechanism;
use Turborel\Session\TlsChannel;

/**
 * A mechanism no server serves, to see the login refused before any exchange.
 */
final class Unserved implements Mechanism
{
    public function name(): string
    {
        return 'no-such-mechanism';
    }

    public function initial(): string
    {
        return '';
    }

    public function answer(string $challenge): string
    {
        return '';
    }

    public function secret(): bool
    {
        return false;
    }

    public function ready(): void {}

    public function bind(TlsChannel $channel): void {}

    public function refused(): void {}
}

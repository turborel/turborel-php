<?php

declare(strict_types=1);

namespace Turborel\Internal\Pool;

use Turborel\Pool\PoolConfig;
use Turborel\Pool\Source;
use Turborel\Session\Connection;

/**
 * What a pool's clones share, as turborel-client's `Shared`: what makes
 * the connections, how they are kept, those idle, and how many are made.
 *
 * @internal
 */
final class Shared
{
    /** How long a waiter outside a Fiber sleeps before it looks again, in microseconds. */
    private const int NAP = 10_000;

    /** @var list<Idle> the connections idle, the last given back last */
    public array $idle = [];

    /** How many connections are made, handed out and idle. */
    public int $made = 0;

    /** The pool of what `$source` makes, kept as `$config` says. */
    public function __construct(
        /** What makes the connections. */
        public readonly Source $source,
        /** How they are kept. */
        public readonly PoolConfig $config,
    ) {}

    /** The last connection given back that may still be handed out; those gone stale closed on the way. */
    public function take(): ?Idle
    {
        $now = (int) hrtime(true);
        while (($idle = array_pop($this->idle)) !== null) {
            if ($this->fresh($idle, $now)) {
                return $idle;
            }
            $this->unmade($idle->connection);
        }

        return null;
    }

    /** Whether the pool has room for one more connection, which it then counts made. */
    public function room(): bool
    {
        if ($this->made >= $this->config->max) {
            return false;
        }
        ++$this->made;

        return true;
    }

    /** One fewer connection made: `$connection`, if any, closed. */
    public function unmade(?Connection $connection): void
    {
        $this->made = max(0, $this->made - 1);
        $connection?->wire()->stream->close();
    }

    /** Lets another give a connection back: suspends the Fiber that waits, else sleeps a moment. */
    public function await(): void
    {
        if (\Fiber::getCurrent() !== null) {
            \Fiber::suspend();

            return;
        }
        usleep(self::NAP);
    }

    /** Gives `$connection`, made at `$made`, back, reset; or closes it, if it has lived its time, its reset fails, or enough are idle. */
    public function giveBack(Connection $connection, int $made): void
    {
        $lived = $this->config->maxLifetime !== null && (int) hrtime(true) - $made >= (int) ($this->config->maxLifetime * 1e9);
        $kept = !$lived && Resetting::reset($connection, $this->config->reset);
        if ($kept && \count($this->idle) < $this->config->maxIdle) {
            $this->idle[] = new Idle($connection, $made, (int) hrtime(true));

            return;
        }
        $this->unmade($connection);
    }

    /** Whether `$idle` may still be handed out at `$now`. */
    private function fresh(Idle $idle, int $now): bool
    {
        $lived = $this->config->maxLifetime === null || $now - $idle->made < (int) ($this->config->maxLifetime * 1e9);
        $rested = $this->config->idleTimeout === null || $now - $idle->since < (int) ($this->config->idleTimeout * 1e9);

        return $lived && $rested && !$idle->connection->isLost();
    }
}

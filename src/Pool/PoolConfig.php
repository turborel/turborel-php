<?php

declare(strict_types=1);

namespace Turborel\Pool;

/**
 * How a pool keeps its connections, as turborel-client's `PoolConfig`
 * [CLIENT Pools]: each time in seconds, each part changed by its `with`
 * method, which gives a new configuration.
 */
final readonly class PoolConfig
{
    /** The connections a pool makes at most by default. */
    public const int DEFAULT_MAX = 10;

    /** How long `get` waits by default for a connection to come back, in seconds: as long as a step of connecting. */
    public const float DEFAULT_WAIT = 30.0;

    /** How long a connection may stay idle by default, in seconds: ten minutes. */
    public const float DEFAULT_IDLE_TIMEOUT = 600.0;

    /** How long a connection lives by default, in seconds: an hour. */
    public const float DEFAULT_MAX_LIFETIME = 3600.0;

    /** The most connections made at once, handed out and idle. */
    public int $max;

    /** The most kept idle. */
    public int $maxIdle;

    /** How long one may stay idle; none, as long as it lives. */
    public ?float $idleTimeout;

    /** How long one lives from when it was made; none, without limit. */
    public ?float $maxLifetime;

    /** How long `get` waits for one to come back. */
    public float $wait;

    /** Whether an idle connection is checked to answer before it is handed out. */
    public bool $check;

    /** @var list<string> statements run, after the pool's own, as each connection comes back */
    public array $reset;

    /**
     * The configuration of `$parts`, each part not given its default.
     *
     * @param array{max?: int, maxIdle?: int, idleTimeout?: ?float, maxLifetime?: ?float, wait?: float, check?: bool, reset?: list<string>} $parts
     */
    public function __construct(array $parts = [])
    {
        $this->max = $parts['max'] ?? self::DEFAULT_MAX;
        $this->maxIdle = $parts['maxIdle'] ?? self::DEFAULT_MAX;
        $this->idleTimeout = \array_key_exists('idleTimeout', $parts) ? $parts['idleTimeout'] : self::DEFAULT_IDLE_TIMEOUT;
        $this->maxLifetime = \array_key_exists('maxLifetime', $parts) ? $parts['maxLifetime'] : self::DEFAULT_MAX_LIFETIME;
        $this->wait = $parts['wait'] ?? self::DEFAULT_WAIT;
        $this->check = $parts['check'] ?? true;
        $this->reset = $parts['reset'] ?? [];
    }
}

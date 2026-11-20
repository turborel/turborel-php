<?php

declare(strict_types=1);

namespace Turborel\Pool;

use Turborel\Internal\Misuse;
use Turborel\Internal\Pool\Shared;
use Turborel\Session\Connection;
use Turborel\TurborelException;

/**
 * A connection a pool handed out, as turborel-client's `Pooled`
 * [CLIENT Pools]: used through `connection`, and given back as it is
 * released or let go of, reset; or closed, if it is lost, its reset
 * fails, it has lived its time, or enough are idle. Once given back, the
 * connection is the pool's: a program keeps no reference to it.
 */
final class Pooled
{
    /** The connection, until it is given back. */
    private ?Connection $connection;

    /**
     * The connection `$connection`, made at `$made` in hrtime's nanoseconds, of the pool `$shared`.
     *
     * @internal
     */
    public function __construct(Connection $connection, private readonly int $made, private readonly Shared $shared)
    {
        $this->connection = $connection;
    }

    /**
     * The connection, until it is given back.
     *
     * @throws TurborelException SQLSTATE TB910 once it is given back
     */
    public function connection(): Connection
    {
        return $this->connection ?? throw Misuse::of('the pooled connection was given back');
    }

    /** Gives the connection back to the pool, reset, or closes it. */
    public function release(): void
    {
        if ($this->connection !== null) {
            [$connection, $this->connection] = [$this->connection, null];
            $this->shared->giveBack($connection, $this->made);
        }
    }

    /** Gives the connection back, if it was not. */
    public function __destruct()
    {
        $this->release();
    }
}

<?php

declare(strict_types=1);

namespace Turborel\Pool;

use Turborel\Session\Connection;
use Turborel\TurborelException;

/**
 * What makes a pool's connections, as turborel-client's `pool::Source`
 * [CLIENT Pools]. Settings make them; so does any closure a pool is made
 * of.
 */
interface Source
{
    /**
     * A new connection, logged in.
     *
     * @throws TurborelException why none can be made
     */
    public function connect(): Connection;
}

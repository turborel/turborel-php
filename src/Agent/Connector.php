<?php

declare(strict_types=1);

namespace Turborel\Agent;

use Turborel\Session\Connection;
use Turborel\TurborelException;

/**
 * What makes an SQL-agent's connections, as turborel-client's
 * `Connector` [CLIENT The SQL-agent].
 */
interface Connector
{
    /**
     * A connection to the server `$server` names, an address, or for none the default server; as `$user`, or for none the
     * user the connector takes (ISO/IEC 9075:1992 §15.1 GR8, SR1).
     *
     * @throws TurborelException the server cannot be reached, or refuses the connection
     */
    public function connect(?string $server, ?string $user): Connection;
}

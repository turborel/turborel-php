<?php

declare(strict_types=1);

namespace Turborel\Settings;

use Turborel\Internal\Settings\Roles;
use Turborel\Session\Connection;
use Turborel\TurborelException;

/**
 * What a server is, to a connection's session, as turborel-client's
 * `Role` [CLIENT Several servers].
 */
enum Role
{
    /** It takes changes. */
    case ReadWrite;

    /** It takes none: a replica following its group. */
    case ReadOnly;

    /** A replica whose role the session cannot see. */
    case Unknown;

    /**
     * The role of `$connection`'s server, as its session sees it: as its session status SERVER_READ_ONLY last told it, if the
     * server tells it; else asked of INFORMATION_SCHEMA.REPLICAS as a statement that is its own transaction.
     *
     * @throws TurborelException the query's failure, but of a server alone, which has no REPLICAS
     */
    public static function of(Connection $connection): self
    {
        return Roles::of($connection);
    }

    /** It, in words. */
    public function describe(): string
    {
        return match ($this) {
            self::ReadWrite => 'read-write',
            self::ReadOnly => 'read-only',
            self::Unknown => 'of a role this session cannot see',
        };
    }
}

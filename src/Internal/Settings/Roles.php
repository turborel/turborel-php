<?php

declare(strict_types=1);

namespace Turborel\Internal\Settings;

use Turborel\Internal\Copy\Text;
use Turborel\Session\Connection;
use Turborel\Settings\Role;
use Turborel\Statements\Outcome;
use Turborel\TurborelException;

/**
 * Whether a server takes changes, as turborel-client's `role.rs` finds
 * it [CLIENT Several servers]: told by SERVER_READ_ONLY, else read from
 * INFORMATION_SCHEMA.REPLICAS, which shows a replica's role to the
 * database's owner alone; a server alone has no such table, and takes
 * changes.
 *
 * @internal
 */
final class Roles
{
    /** The query of a server's own role in its group. */
    public const string ROLE_QUERY = "SELECT ROLE FROM INFORMATION_SCHEMA.REPLICAS WHERE IS_SELF = 'YES'";

    /** The session status of whether the server takes no changes. */
    private const string SERVER_READ_ONLY = 'SERVER_READ_ONLY';

    /** The SQLSTATE classes of a table that does not exist, as a server alone answers ROLE_QUERY. */
    private const array NO_SUCH_TABLE = ['2A', '42'];

    /**
     * The role of `$connection`'s server, as its session sees it.
     *
     * @throws TurborelException the query's failure, but of a server alone
     */
    public static function of(Connection $connection): Role
    {
        $told = $connection->setting(self::SERVER_READ_ONLY);
        if ($told !== null) {
            return $told === 'YES' ? Role::ReadOnly : Role::ReadWrite;
        }
        $autocommit = $connection->autocommit();
        $connection->setAutocommit(true);
        try {
            $answer = $connection->query(self::ROLE_QUERY);
        } catch (TurborelException $failure) {
            $answer = $failure;
        } finally {
            $connection->setAutocommit($autocommit);
        }

        return self::shown($answer);
    }

    /**
     * The role `$answer`, the outcome of ROLE_QUERY or its failure, shows.
     *
     * @throws TurborelException the failure, unless it is of a server alone, which has no REPLICAS
     */
    public static function shown(Outcome|TurborelException $answer): Role
    {
        if ($answer instanceof TurborelException) {
            if (\in_array(substr($answer->getSqlState(), 0, 2), self::NO_SUCH_TABLE, true)) {
                return Role::ReadWrite;
            }

            throw $answer;
        }
        $first = $answer->rows[0][0] ?? null;
        if ($answer->rows === []) {
            return Role::Unknown;
        }

        return rtrim(Text::of($first)) === 'PRIMARY' ? Role::ReadWrite : Role::ReadOnly;
    }
}

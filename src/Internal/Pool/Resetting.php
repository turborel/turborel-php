<?php

declare(strict_types=1);

namespace Turborel\Internal\Pool;

use Turborel\Internal\ConnectionFailure;
use Turborel\Session\Capability;
use Turborel\Session\Connection;
use Turborel\Session\Timeouts;
use Turborel\TurborelException;

/**
 * A pooled connection's check and reset, as turborel-client's
 * `pool/reset.rs` makes them [CLIENT Pools]. Granted `ping`, the check is
 * a Ping; granted `reset`, the reset is the protocol's Reset, then the
 * program's statements. Of a server that grants neither, the check is
 * ROLLBACK, and the reset one pipeline: the transaction rolled back, the
 * statements it prepared closed and destroyed, its user, schema and time
 * zone those of its login, then the program's statements. Either way
 * autocommit is off after, and each wait is at most 30 seconds.
 *
 * @internal
 */
final class Resetting
{
    /** How long a check and a reset wait on the server, at most, in seconds: as long as a step of connecting. */
    public const float RESET_WITHIN = Timeouts::CONNECT_WITHIN;

    /** The most statements a borrower may leave prepared for the connection to be reset rather than closed. */
    public const int RESET_MOST_STATEMENTS = 256;

    /** What a connection is checked by where the server takes no Ping. */
    private const string CHECK = 'ROLLBACK';

    /** Whether `$connection` answers. */
    public static function answers(Connection $connection): bool
    {
        return self::bounded($connection, static function (Connection $c): void {
            $c->has(Capability::PING) ? $c->ping() : $c->query(self::CHECK);
        });
    }

    /**
     * Gives `$connection`'s session back as it logged in, and runs `$more`: whether it could be.
     *
     * @param list<string> $more
     */
    public static function reset(Connection $connection, array $more): bool
    {
        return !$connection->isLost() && self::bounded($connection, static fn(Connection $c) => self::now($c, $more));
    }

    /**
     * Resets `$connection` as `reset` says.
     *
     * @param list<string> $more
     *
     * @throws TurborelException a statement's failure, but of those destroying statements gone, or of too many statements left prepared
     */
    private static function now(Connection $connection, array $more): void
    {
        $wire = $connection->wire();
        $connection->setAutocommit(false);
        if ($connection->has(Capability::RESET)) {
            $connection->reset();
            $wire->nextStatement = 0;
            foreach ($more as $sql) {
                $connection->query($sql);
            }

            return;
        }
        $prepared = $wire->nextStatement;
        if ($prepared > self::RESET_MOST_STATEMENTS) {
            throw ConnectionFailure::because('too many statements were left prepared to destroy');
        }
        $login = $connection->authorization();
        $pipeline = $connection->pipeline()->query('ROLLBACK');
        for ($number = 0; $number < $prepared; $number++) {
            $pipeline->release($number);
        }
        $pipeline->query('SET SESSION AUTHORIZATION ' . self::delimited($login))->query('SET SCHEMA ' . self::delimited($login))->query('SET TIME ZONE LOCAL');
        foreach ($more as $sql) {
            $pipeline->query($sql);
        }
        $outcomes = $pipeline->run();
        $kept = [$outcomes[0] ?? null, ...\array_slice($outcomes, 1 + 2 * $prepared)];
        foreach ($kept as $outcome) {
            if ($outcome?->failure !== null) {
                throw $outcome->failure;
            }
        }
        $wire->nextStatement = 0;
    }

    /**
     * Whether `$run` of `$connection` succeeds, each of its waits at most RESET_WITHIN, or the connection's own limit if
     * shorter; the limit put back after. A connection over a stream its maker bounds is run as it is bounded.
     *
     * @param \Closure(Connection): mixed $run
     */
    private static function bounded(Connection $connection, \Closure $run): bool
    {
        $before = $connection->readTimeout();
        try {
            $connection->setReadTimeout(min($before ?? self::RESET_WITHIN, self::RESET_WITHIN));
            $limited = true;
        } catch (TurborelException) {
            $limited = false;
        }
        try {
            $run($connection);
            $ran = true;
        } catch (TurborelException) {
            $ran = false;
        }
        if ($limited) {
            try {
                $connection->setReadTimeout($before);
            } catch (TurborelException) {
                return false;
            }
        }

        return $ran;
    }

    /** `$name` as a character string literal of a delimited identifier, as SET SESSION AUTHORIZATION and SET SCHEMA take one. */
    private static function delimited(string $name): string
    {
        return "'\"" . str_replace("'", "''", str_replace('"', '""', $name)) . "\"'";
    }
}

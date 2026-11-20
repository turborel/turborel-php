<?php

declare(strict_types=1);

namespace Turborel\Session;

use Turborel\Internal\ValueFailure;
use Turborel\TurborelException;

/**
 * How long a connection waits on its server [CLIENT Timeouts]: `connect`,
 * to connect, begin TLS and for each step of the login, 30 seconds by
 * default; `read`, for each read or write of a request or reply after,
 * no limit by default, as a statement may run as long as it must. A wait
 * that runs out loses the connection (SQLSTATE 08006).
 */
final readonly class Timeouts
{
    /** The default limit to connect and log in, in seconds, as turborel-client's CONNECT_WITHIN. */
    public const float CONNECT_WITHIN = 30.0;

    /** The longest Terminate waits for the server to close where the read timeout sets none, in seconds, as turborel-client's TERMINATE_WITHIN. */
    public const float TERMINATE_WITHIN = 60.0;

    /**
     * The limits `$connect` and `$read`, in seconds, none for no limit.
     *
     * @throws TurborelException SQLSTATE 22003 if a limit is not positive
     */
    public function __construct(
        /** The limit to connect, begin TLS and for each step of the login, in seconds; none for no limit. */
        public ?float $connect = self::CONNECT_WITHIN,
        /** The limit of each read or write after the login, in seconds; none for no limit. */
        public ?float $read = null,
    ) {
        foreach ([$connect, $read] as $limit) {
            if ($limit !== null && !($limit > 0.0)) {
                throw ValueFailure::outOfRange("a timeout of {$limit} seconds is not positive");
            }
        }
    }
}

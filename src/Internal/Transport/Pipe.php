<?php

declare(strict_types=1);

namespace Turborel\Internal\Transport;

use Turborel\Internal\ConnectionFailure;
use Turborel\Internal\Quietly;
use Turborel\TurborelException;

/**
 * A named pipe, the local transport on Windows, opened as a file, as
 * turborel-client's `pipe.rs` opens one: while every instance of the pipe
 * is busy, the open is tried again until the connect timeout runs out.
 * PHP sets no limit on a pipe's reads and writes once it is open
 * [CLIENT Timeouts].
 *
 * @internal
 */
final class Pipe
{
    /** How long to wait before trying a busy pipe again, in microseconds, as turborel-client's BUSY_RETRY. */
    private const int BUSY_RETRY = 10_000;

    /** What Windows says of a pipe whose every instance is busy, ERROR_PIPE_BUSY. */
    private const string BUSY = '/busy/i';

    /** The mode a pipe is opened in: read and write, binary. */
    private const string MODE = 'r+b';

    /**
     * The named pipe `$path`, tried again while busy for at most `$within` seconds, none for no limit.
     *
     * @throws TurborelException SQLSTATE 08006 if it cannot be opened, or stays busy past the limit
     */
    public static function open(string $path, ?float $within): Stream
    {
        $began = hrtime(true);
        while (true) {
            [$resource, $warning] = Quietly::call(static fn(): mixed => fopen($path, self::MODE));
            if (\is_resource($resource)) {
                return new Stream($resource, true);
            }
            if (preg_match(self::BUSY, $warning ?? '') !== 1) {
                throw ConnectionFailure::because("cannot connect to {$path}: " . ($warning ?? 'it could not be opened'));
            }
            if ($within !== null && (hrtime(true) - $began) / 1e9 >= $within) {
                throw ConnectionFailure::because("cannot connect to {$path}: the server did not answer in time (every instance of the pipe is busy)");
            }
            usleep(self::BUSY_RETRY);
        }
    }
}

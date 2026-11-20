<?php

declare(strict_types=1);

namespace Turborel\Session;

use Turborel\Internal\Codec\Request\Cancel;
use Turborel\Internal\Transport\Opener;
use Turborel\TurborelException;

/**
 * What cancels the statement a connection runs [CLIENT Running statements],
 * as turborel-client's `Canceller`: a new connection to the same server,
 * made as the first was, whose first and only message is Cancel with the
 * key the first's Ready gave; the server closes it, saying nothing, and
 * the statement fails with SQLCODE -920, SQLSTATE TB920 [PROTOCOL Cancel 'Z'].
 * A cancel as nothing runs does nothing. PHP runs one thread, so a
 * program cancels from another process, a signal handler, or between a
 * request sent and its reply read.
 */
final readonly class Canceller
{
    /** How long a cancel waits on the server before it gives up, in seconds, as turborel-client's CANCEL_WITHIN. */
    public const float CANCEL_WITHIN = 10.0;

    /** The canceller of the session at `$target` whose key is `$key`. */
    public function __construct(
        private Target $target,
        #[\SensitiveParameter]
        private string $key,
    ) {}

    /** The most bytes read at a time while waiting for the server to close. */
    private const int DRAINED = 65_536;

    /**
     * Cancels the statement running, giving up after `$within` seconds if the server does not answer.
     *
     * @throws TurborelException SQLSTATE 08006 if the server cannot be reached, or does not close in time
     */
    public function cancel(float $within = self::CANCEL_WITHIN): void
    {
        [$stream] = Opener::open($this->target, $within);
        try {
            $stream->write((new Cancel($this->key))->encode());
            // The server closes it, saying nothing; whatever it says is read and left until it closes.
            while (\strlen($stream->rest(self::DRAINED)) === self::DRAINED) {
                // Each piece dropped.
            }
        } finally {
            $stream->close();
        }
    }

    /**
     * It as var_dump() shows it, its key left out (RULES.md R-SAFE-4).
     *
     * @return array<string, string>
     */
    public function __debugInfo(): array
    {
        return ['target' => (string) $this->target, 'key' => '(secret)'];
    }
}

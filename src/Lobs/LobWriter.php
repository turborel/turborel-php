<?php

declare(strict_types=1);

namespace Turborel\Lobs;

use Turborel\Internal\Lobs\Lobs;
use Turborel\TurborelException;
use Turborel\Value\LobKind;
use Turborel\Value\LobLocator;

/**
 * A new large object written a piece at a time, as turborel-client's
 * `LobWriter` writes it [CLIENT Large objects]: sent in Appends of a chunk;
 * `finish` gives the value to pass as a parameter, by its locator. A
 * character one's octets are UTF-8, and a character may be split between
 * writes.
 */
final class LobWriter
{
    /** What is written and not yet sent. */
    private string $pending = '';

    /** The octets written. */
    private int $octets = 0;

    /** Its characters, each counted at its lead octet, so one a write splits is counted once. */
    private int $characters = 0;

    /**
     * A writer of the large object of `$locator`, of `$kind`, over `$lobs`.
     *
     * @internal
     */
    public function __construct(private readonly Lobs $lobs, private readonly LobKind $kind, private readonly int $locator) {}

    /**
     * Writes `$octets`, sent once a chunk is waiting.
     *
     * @throws TurborelException the server's, as SQLSTATE 22021 of a character one's octets not UTF-8, or SQLSTATE 08006
     */
    public function write(string $octets): void
    {
        $this->pending .= $octets;
        $this->octets += \strlen($octets);
        if ($this->kind === LobKind::Character) {
            $this->characters += \strlen($octets) - (int) preg_match_all('/[\x80-\xBF]/', $octets);
        }
        if (\strlen($this->pending) >= Lobs::CHUNK_OCTETS) {
            $this->flush();
        }
    }

    /**
     * Sends what is written and not yet sent.
     *
     * @throws TurborelException the server's, or SQLSTATE 08006
     */
    public function flush(): void
    {
        if ($this->pending === '') {
            return;
        }
        [$octets, $this->pending] = [$this->pending, ''];
        $this->lobs->append($this->locator, $octets);
    }

    /**
     * The large object written, to pass as a parameter by its locator: valid until its transaction ends.
     *
     * @throws TurborelException the server's: SQLSTATE 22021 if a character one's octets are not UTF-8, 22001 if it is longer than the longest; or SQLSTATE 08006
     */
    public function finish(): LobLocator
    {
        $this->flush();

        return new LobLocator($this->kind, $this->locator, $this->octets, $this->characters);
    }
}

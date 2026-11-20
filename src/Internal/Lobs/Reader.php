<?php

declare(strict_types=1);

namespace Turborel\Internal\Lobs;

use Turborel\Internal\ConnectionFailure;
use Turborel\TurborelException;
use Turborel\Value\Lob;
use Turborel\Value\LobLocator;

/**
 * A large object's octets read in order, a chunk at a time, as
 * turborel-client's `LobReader` reads them [CLIENT Large objects].
 *
 * @internal
 */
final class Reader
{
    /** The octet the next chunk begins at. */
    private int $at = 0;

    /** The chunk read last. */
    private string $chunk = '';

    /** The octets of the chunk already given. */
    private int $taken = 0;

    /** The reader of `$lob`, over `$lobs`. */
    public function __construct(private readonly Lobs $lobs, private readonly Lob|LobLocator $lob) {}

    /**
     * At most `$most` octets more; none at its end.
     *
     * @throws TurborelException SQLSTATE 0F001 if the locator is no longer valid; 08006 if the server sends less than its length
     */
    public function read(int $most): string
    {
        if ($this->taken >= \strlen($this->chunk)) {
            if ($this->at >= $this->length() || $most <= 0) {
                return '';
            }
            $this->chunk = $this->lobs->read($this->lob, $this->at, Lobs::CHUNK_OCTETS, false);
            if ($this->chunk === '') {
                throw ConnectionFailure::because('the server sent less of the large object than its length');
            }
            $this->at += \strlen($this->chunk);
            $this->taken = 0;
        }
        $given = substr($this->chunk, $this->taken, $most);
        $this->taken += \strlen($given);

        return $given;
    }

    /** Whether every octet has been given. */
    public function ended(): bool
    {
        return $this->taken >= \strlen($this->chunk) && $this->at >= $this->length();
    }

    /** The large object's length in octets. */
    private function length(): int
    {
        return $this->lob instanceof Lob ? \strlen($this->lob->octets) : (int) $this->lob->octets;
    }
}

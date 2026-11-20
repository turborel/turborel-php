<?php

declare(strict_types=1);

namespace Turborel\Copy;

use Turborel\Internal\Codec\Request\CopyData;
use Turborel\Internal\Codec\Request\CopyEnd;
use Turborel\Internal\Codec\Request\CopyIn;
use Turborel\Internal\Codec\ValueWriter;
use Turborel\Internal\Codec\Writer;
use Turborel\Internal\Session\Control;
use Turborel\Internal\Session\Wire;
use Turborel\Internal\Statements\Results;
use Turborel\Session\Capability;
use Turborel\TurborelException;

/**
 * A copy of rows into a table under way, as turborel-client's `Copying`
 * [CLIENT Copying and exporting]: rows sent many to a CopyData, each with
 * no reply; `finish` ends it, giving the rows inserted, or the copy's
 * failure, every row of it undone. Until it ends, the connection sends
 * nothing but its rows, its end and Ping [PROTOCOL CopyIn 'I']. A copy
 * let go of before it ends is abandoned.
 */
final class Copying
{
    /** The bytes of rows a CopyData carries at most, about, as turborel-client's CHUNK_BYTES: 256 KiB. */
    public const int CHUNK_BYTES = 262_144;

    /** @var list<list<mixed>> the rows waiting to be sent */
    private array $waiting = [];

    /** The bytes of the rows waiting. */
    private int $bytes = 0;

    /** Whether it ended, finished or abandoned. */
    private bool $ended = false;

    /** The copy over `$wire`. */
    private function __construct(private readonly Wire $wire) {}

    /**
     * Begins a copy into `$table`, as SQL names it, of `$columns`, all of the table's if none, the transaction ended with it if `$ends`.
     *
     * @param list<string> $columns
     *
     * @throws TurborelException SQLSTATE 08006 if `copy` was not granted, or the connection is lost
     *
     * @internal
     */
    public static function begin(Wire $wire, string $table, array $columns, bool $ends): self
    {
        (new Control($wire))->needs(Capability::COPY);
        $wire->send(new CopyIn($table, $columns, $ends));
        $wire->copying = true;

        return new self($wire);
    }

    /**
     * Sends `$row`, a value for each column, with those before it once they are CHUNK_BYTES.
     *
     * @param list<mixed> $row
     *
     * @throws TurborelException SQLSTATE 07006 of a value the protocol has none of, or 08006; a row the server refuses comes as `finish`'s failure
     */
    public function send(array $row): void
    {
        $this->bytes += \strlen(ValueWriter::list(new Writer(), $row)->bytesWritten());
        $this->waiting[] = $row;
        if ($this->bytes >= self::CHUNK_BYTES) {
            $this->flush();
        }
    }

    /**
     * Ends the copy: the rows inserted.
     *
     * @throws TurborelException the copy's failure, every row undone: the table or a column not found, a row of too many or too few values, a value its column refuses, its detail naming the row; or SQLSTATE 08006
     */
    public function finish(): int|string
    {
        $this->flush();

        return $this->end(false);
    }

    /**
     * Gives the copy up: every row of it undone.
     *
     * @throws TurborelException SQLSTATE 08006 if the connection is lost
     */
    public function abandon(): void
    {
        $this->end(true);
    }

    /** Abandons the copy if it has not ended, so the connection takes requests again. */
    public function __destruct()
    {
        if (!$this->ended && $this->wire->lost === null) {
            try {
                $this->end(true);
            } catch (TurborelException) {
                // A failure has lost the connection, which says so at its next request.
            }
        }
    }

    /**
     * Sends the rows waiting.
     *
     * @throws TurborelException SQLSTATE 08006 if the connection is lost
     */
    private function flush(): void
    {
        if ($this->waiting === []) {
            return;
        }
        [$rows, $this->waiting, $this->bytes] = [$this->waiting, [], 0];
        $this->wire->send(new CopyData($rows));
    }

    /**
     * Sends CopyEnd, abandoned if `$abandon`: the rows inserted.
     *
     * @throws TurborelException the copy's failure, or SQLSTATE 08006
     */
    private function end(bool $abandon): int|string
    {
        $this->ended = true;
        $this->wire->send(new CopyEnd($abandon));
        $this->wire->copying = false;

        return (new Results($this->wire))->completion()->counts->rows ?? 0;
    }
}

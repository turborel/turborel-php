<?php

declare(strict_types=1);

namespace Turborel\Copy;

use Turborel\Internal\Codec\Request\CopyColumns;
use Turborel\Internal\Codec\Request\CopyData;
use Turborel\Internal\Codec\Request\CopyEnd;
use Turborel\Internal\Codec\Request\CopyIn;
use Turborel\Internal\Codec\ValueWriter;
use Turborel\Internal\Codec\Writer;
use Turborel\Internal\Misuse;
use Turborel\Internal\Session\Control;
use Turborel\Internal\Session\Wire;
use Turborel\Internal\Statements\Results;
use Turborel\Session\Capability;
use Turborel\TurborelException;

/**
 * A copy of rows into a table under way, as turborel-client's `Copying`
 * [CLIENT Copying and exporting]: rows sent many to a CopyData, each with
 * no reply; granted `columns`, rows whose every value is a string or null
 * sent by column, as text, many to a CopyColumns, as CopyData would carry
 * them as character strings [PROTOCOL CopyColumns 'O'], the two mixed in
 * a copy in the rows' order; `finish` ends it, giving the rows inserted, or the copy's
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

    /** @var list<list<?string>> the rows waiting to be sent by column, as text, in place of `waiting` */
    private array $cells = [];

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
     * @throws TurborelException SQLSTATE 22000 of a value the protocol has none of, 08006, or TB910 once the copy has ended; a row the server refuses comes as `finish`'s failure
     */
    public function send(array $row): void
    {
        $this->going();
        if ($this->wire->told->has(Capability::COLUMNS) && self::text($row)) {
            if ($this->waiting !== [] || \count($this->cells[0] ?? $row) !== \count($row)) {
                $this->flush();
            }
            $this->bytes += array_sum(array_map(static fn(?string $cell): int => \strlen($cell ?? '') + 4, $row));
            $this->cells[] = $row;
        } else {
            if ($this->cells !== []) {
                $this->flush();
            }
            $this->bytes += \strlen(ValueWriter::list(new Writer(), $row)->bytesWritten());
            $this->waiting[] = $row;
        }
        if ($this->bytes >= self::CHUNK_BYTES) {
            $this->flush();
        }
    }

    /**
     * Ends the copy: the rows inserted.
     *
     * @throws TurborelException the copy's failure, every row undone: the table or a column not found, a row of too many or too few values, a value its column refuses, its detail naming the row; or SQLSTATE 08006, or TB910 once the copy has ended
     */
    public function finish(): int|string
    {
        $this->going();
        $this->flush();

        return $this->end(false);
    }

    /**
     * Gives the copy up: every row of it undone.
     *
     * @throws TurborelException SQLSTATE 08006 if the connection is lost, or TB910 once the copy has ended
     */
    public function abandon(): void
    {
        $this->going();
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
     * That the copy has not ended, as turborel-client's, whose ending takes it, has none that has.
     *
     * @throws TurborelException SQLSTATE TB910 if it has
     */
    private function going(): void
    {
        if ($this->ended) {
            throw Misuse::of('the copy has ended');
        }
    }

    /**
     * Sends the rows waiting, by column or by row.
     *
     * @throws TurborelException SQLSTATE 08006 if the connection is lost
     */
    private function flush(): void
    {
        [$rows, $cells, $this->waiting, $this->cells, $this->bytes] = [$this->waiting, $this->cells, [], [], 0];
        if ($cells !== []) {
            $this->wire->send(new CopyColumns($cells, \count($cells[0])));
        }
        if ($rows !== []) {
            $this->wire->send(new CopyData($rows));
        }
    }

    /**
     * Whether `$row` goes by column: a value for one column or more, each a string of UTF-8 or null.
     *
     * @param list<mixed> $row
     *
     * @phpstan-assert-if-true list<?string> $row
     */
    private static function text(array $row): bool
    {
        foreach ($row as $cell) {
            if ($cell !== null && !(\is_string($cell) && preg_match('//u', $cell) === 1)) {
                return false;
            }
        }

        return $row !== [];
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

<?php

declare(strict_types=1);

namespace Turborel\Internal\Copy;

use Turborel\Copy\Record;
use Turborel\Internal\Lobs\Lobs;
use Turborel\Session\Connection;
use Turborel\Statements\Statement;
use Turborel\TurborelException;
use Turborel\Type\DataType;
use Turborel\Type\LobType;
use Turborel\Value\Binary;
use Turborel\Value\Lob;

/**
 * A record's values sent by an import's INSERT, as turborel-client's
 * `insert.rs` sends them: each string for a character string column
 * assigned to it as INSERT assigns it; each value longer than 64 KiB for a
 * large object column sent ahead a piece at a time and given by its
 * locator, so no request holds it whole.
 *
 * @internal
 */
final readonly class InsertedRow
{
    /** The longest value a client sends whole for a large object parameter, as turborel-client's LOB_INLINE_OCTETS: 64 KiB. */
    public const int LOB_INLINE_OCTETS = 64 * 1024;

    /** The rows of `$insert`, over `$connection`. */
    public function __construct(private Connection $connection, private Statement $insert) {}

    /** What is wrong with `$record`'s shape, if it is not one value for each column. */
    public function misfit(Record $record): ?string
    {
        $wanted = \count($this->insert->parameters);

        return \count($record->values) === $wanted ? null : \count($record->values) . " fields, for {$wanted} columns";
    }

    /**
     * Inserts `$record`.
     *
     * @throws TurborelException the row's failure: a string too long, or the server's
     */
    public function insert(Record $record): void
    {
        $values = [];
        foreach ($this->insert->parameters as $at => $parameter) {
            $values[] = $this->located(Assigning::assigned($record->values[$at] ?? null, $parameter->type), $parameter->type);
        }
        $this->connection->execute($this->insert, $values);
    }

    /**
     * `$value`, for a large object column of `$type` and longer than 64 KiB, sent ahead and given by its locator.
     *
     * @throws TurborelException the server's, or SQLSTATE 08006
     */
    private function located(mixed $value, DataType $type): mixed
    {
        if (!$type instanceof LobType) {
            return $value;
        }
        $octets = match (true) {
            \is_string($value) => $value,
            $value instanceof Binary, $value instanceof Lob => $value->octets,
            default => null,
        };
        if ($octets === null || \strlen($octets) <= self::LOB_INLINE_OCTETS) {
            return $value;
        }
        $writer = $this->connection->createLob($type->kind);
        for ($at = 0; $at < \strlen($octets); $at += Lobs::CHUNK_OCTETS) {
            $writer->write(substr($octets, $at, Lobs::CHUNK_OCTETS));
        }

        return $writer->finish();
    }
}

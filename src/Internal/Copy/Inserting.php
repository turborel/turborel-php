<?php

declare(strict_types=1);

namespace Turborel\Internal\Copy;

use Turborel\Copy\Import;
use Turborel\Copy\Imported;
use Turborel\Copy\ImportFailure;
use Turborel\Copy\Position;
use Turborel\Copy\Record;
use Turborel\Copy\Rejected;
use Turborel\Copy\Source;
use Turborel\Copy\SourceFailure;
use Turborel\Session\Capability;
use Turborel\Session\Connection;
use Turborel\Statements\Statement;
use Turborel\TurborelException;
use Turborel\Type\LobType;

/**
 * A source's records inserted into a table, as turborel-client's
 * `insert.rs` inserts them: through one prepared INSERT, its columns the
 * source's headings, or else the table's; a batch committed at a time; a
 * failure rolls the batch back and stops, or, if asked, a bad row is
 * skipped and given to the program, and the import goes on. Where the
 * server grants `copy`, no bad row is to be skipped and no column is a
 * large object's, the rows go by copy instead.
 *
 * @internal
 */
final class Inserting
{
    /** SQLCODE -920, of a statement cancelled, which stops an import that skips bad rows. */
    private const int CANCELLED = -920;

    /** Rows committed. */
    private int $committed = 0;

    /** Rows inserted in the batch under way. */
    private int $inBatch = 0;

    /** Rows skipped. */
    private int $rejected = 0;

    /** The import `$how` says, over `$connection`. */
    public function __construct(private readonly Connection $connection, private readonly Import $how) {}

    /**
     * Imports `$source`'s records, each row skipped given to `$rejected`.
     *
     * @param \Closure(Rejected): void $rejected
     *
     * @throws ImportFailure why it stopped
     */
    public function import(Source $source, \Closure $rejected): Imported
    {
        [$insert, $table, $columns] = $this->prepared($source);
        if ($this->copies($insert)) {
            $this->sql(fn() => $this->connection->deallocate($insert), null);

            return (new CopiedImport($this->connection, $this->how, $insert->parameters))->import($source, $table, $columns);
        }
        try {
            $done = $this->rows($source, $insert, $rejected);
        } catch (ImportFailure $stopped) {
            if (!$this->connection->isLost()) {
                try {
                    $this->connection->deallocate($insert);
                } catch (TurborelException) {
                    // The import has failed already, which is what it gives.
                }
            }

            throw $stopped;
        }
        $this->sql(fn() => $this->connection->deallocate($insert), null, $done->rows);

        return $done;
    }

    /**
     * The INSERT that puts the source's records into the table, the table's name as SQL text, and its columns' names.
     *
     * @return array{Statement, string, list<string>}
     *
     * @throws ImportFailure if no INSERT can be made
     */
    private function prepared(Source $source): array
    {
        if ($this->connection->inTransaction()) {
            throw self::input(null, 'an import begins with no transaction active, as it commits its batches');
        }
        $table = Names::table($this->how->table) ?? throw self::input(null, "{$this->how->table} is not a <table name>");
        try {
            $headings = $source->headings();
        } catch (SourceFailure $wrong) {
            throw self::input($wrong->position, $wrong->reason);
        }
        $columns = $headings === null ? $this->described($table) : array_map(static fn($h): string => Names::column($h->name), $headings);
        $names = array_map(Names::delimited(...), $columns);
        $sql = "INSERT INTO {$table} (" . implode(', ', $names) . ') VALUES (' . implode(', ', array_fill(0, \count($names), '?')) . ')';

        return [$this->sql(fn(): Statement => $this->connection->prepare($sql), null), $table, $names];
    }

    /**
     * The names of `$table`'s columns, in order, as a prepared query of them describes them.
     *
     * @return list<string>
     *
     * @throws ImportFailure if the table cannot be read
     */
    private function described(string $table): array
    {
        $query = $this->sql(fn(): Statement => $this->connection->prepare("SELECT * FROM {$table}"), null);
        $this->sql(fn() => $this->connection->deallocate($query), null);

        return array_map(static fn($column): string => $column->name ?? '', $query->columns);
    }

    /** Whether the import goes by copy: granted, no bad row skipped, no large object column. */
    private function copies(Statement $insert): bool
    {
        foreach ($insert->parameters as $parameter) {
            if ($parameter->type instanceof LobType) {
                return false;
            }
        }

        return $this->connection->has(Capability::COPY) && !$this->how->skip;
    }

    /**
     * Inserts each of `$source`'s records, then commits the last batch.
     *
     * @param \Closure(Rejected): void $rejected
     *
     * @throws ImportFailure why it stopped
     */
    private function rows(Source $source, Statement $insert, \Closure $rejected): Imported
    {
        $row = new InsertedRow($this->connection, $insert);
        while (true) {
            try {
                $record = $source->record();
            } catch (SourceFailure $wrong) {
                throw $this->stop($wrong->position, ImportFailure::inputCause($wrong->reason), true);
            }
            if ($record === null) {
                break;
            }
            $this->row($row, $record, $rejected);
        }
        $this->commit();

        return new Imported($this->committed, $this->rejected);
    }

    /**
     * Inserts `$record`, committing its batch if it ends one.
     *
     * @param \Closure(Rejected): void $rejected
     *
     * @throws ImportFailure if it stops the import
     */
    private function row(InsertedRow $row, Record $record, \Closure $rejected): void
    {
        $misfit = $row->misfit($record);
        if ($misfit !== null) {
            $this->reject(new Rejected($record->position, ImportFailure::inputCause($misfit), true), $rejected);

            return;
        }
        try {
            $row->insert($record);
        } catch (TurborelException $failure) {
            $failure = $failure->withoutPosition();
            // A row is skipped only if it alone was undone: the batch's transaction goes on, and the connection.
            $alone = $this->inBatch === 0 || $this->connection->inTransaction();
            if (!$alone || $failure->endsConnection() || $failure->getSqlCode() === self::CANCELLED) {
                throw $this->stop($record->position, $failure, false);
            }
            $this->reject(new Rejected($record->position, $failure, false), $rejected);

            return;
        }
        ++$this->inBatch;
        if ($this->inBatch === $this->how->batch) {
            $this->commit();
        }
    }

    /**
     * Skips `$row` and gives it to `$rejected`, if the import skips bad rows; else stops.
     *
     * @param \Closure(Rejected): void $rejected
     *
     * @throws ImportFailure if it does not skip
     */
    private function reject(Rejected $row, \Closure $rejected): void
    {
        if (!$this->how->skip) {
            throw $this->stop($row->position, $row->cause, $row->input);
        }
        ++$this->rejected;
        $rejected($row);
    }

    /** Stops the import at `$position` for `$cause`: the batch under way rolled back. */
    private function stop(?Position $position, TurborelException $cause, bool $input): ImportFailure
    {
        if (!$this->connection->isLost() && $this->connection->inTransaction()) {
            try {
                $this->connection->query('ROLLBACK');
            } catch (TurborelException) {
                // A rollback that fails too loses the connection, which ends the session's transaction.
            }
        }

        return new ImportFailure($position, $cause, $input, $this->committed, $this->inBatch);
    }

    /**
     * Commits the batch under way, if a transaction is active.
     *
     * @throws ImportFailure if the commit fails
     */
    private function commit(): void
    {
        if ($this->connection->inTransaction()) {
            try {
                $this->connection->query('COMMIT');
            } catch (TurborelException $failure) {
                throw new ImportFailure(null, $failure->withoutPosition(), false, $this->committed, $this->inBatch);
            }
        }
        $this->committed += $this->inBatch;
        $this->inBatch = 0;
    }

    /**
     * What `$run` gives, a statement the import made; its failure, without its position, stopping the import, `$committed`
     * rows committed.
     *
     * @template T
     *
     * @param \Closure(): T $run
     *
     * @return T
     *
     * @throws ImportFailure if it fails
     */
    private function sql(\Closure $run, ?Position $position, int|string $committed = 0): mixed
    {
        try {
            return $run();
        } catch (TurborelException $failure) {
            throw new ImportFailure($position, $failure->withoutPosition(), false, $committed, 0);
        }
    }

    /** The input's failure `$message`, before any row. */
    private static function input(?Position $position, string $message): ImportFailure
    {
        return new ImportFailure($position, ImportFailure::inputCause($message), true, 0, 0);
    }
}

<?php

declare(strict_types=1);

namespace Turborel\Internal\Copy;

use Turborel\Copy\Copying;
use Turborel\Copy\Import;
use Turborel\Copy\Imported;
use Turborel\Copy\ImportFailure;
use Turborel\Copy\Position;
use Turborel\Copy\Source;
use Turborel\Copy\SourceFailure;
use Turborel\Session\Connection;
use Turborel\TurborelException;
use Turborel\Type\Parameter;

/**
 * An import by copy, as turborel-client's `copied.rs` runs it: its rows
 * checked as the INSERT's parameters take them, then sent a batch to a
 * copy, each copy committing its batch as it ends, with no round trip but
 * the copy's end. A row the server refuses fails its copy, every row of
 * the batch undone, the failure's detail naming the row, so the import
 * says its record as it would of a row inserted alone.
 *
 * @internal
 */
final class CopiedImport
{
    /** What the server's detail of a row a copy refused says: `row 7 of the copy`. */
    private const string ROW = '/^row (\d+) of the copy$/';

    /** Rows committed. */
    private int $committed = 0;

    /** @var list<Position> where each record of the batch under way began */
    private array $batch = [];

    /**
     * The import `$how` says, over `$connection`, into columns whose parameters are `$types`.
     *
     * @param list<Parameter> $types
     */
    public function __construct(private readonly Connection $connection, private readonly Import $how, private readonly array $types) {}

    /**
     * Imports `$source`'s records into `$table`'s `$columns`, as SQL names them.
     *
     * @param list<string> $columns
     *
     * @throws ImportFailure why it stopped
     */
    public function import(Source $source, string $table, array $columns): Imported
    {
        while ($this->copied($source, $table, $columns)) {
            // Each pass copies a batch.
        }

        return new Imported($this->committed, 0);
    }

    /**
     * Copies a batch of `$source`'s records, its copy begun at its first record and ended at its last, abandoned if a record
     * fails first: whether records may follow.
     *
     * @param list<string> $columns
     *
     * @throws ImportFailure why it stopped
     */
    private function copied(Source $source, string $table, array $columns): bool
    {
        $next = $this->checked($source);
        if ($next === null) {
            return false;
        }
        try {
            $copy = Copying::begin($this->connection->wire(), $table, $columns, true);
        } catch (TurborelException $failure) {
            throw $this->stop(null, $failure, false, 0);
        }
        while ($next !== null) {
            [$at, $values] = $next;
            try {
                $copy->send($values);
            } catch (TurborelException $failure) {
                throw $this->stop($at, $failure, false, \count($this->batch));
            }
            $this->batch[] = $at;
            if (\count($this->batch) === $this->how->batch) {
                $this->ended($copy);

                return true;
            }
            $next = $this->checked($source);
        }
        $this->ended($copy);

        return false;
    }

    /**
     * The next of `$source`'s records, checked as the INSERT's parameters take it: where it began, and its values.
     *
     * @return array{Position, list<mixed>}|null
     *
     * @throws ImportFailure if it cannot be read, does not fit, or a string is too long
     */
    private function checked(Source $source): ?array
    {
        try {
            $record = $source->record();
        } catch (SourceFailure $wrong) {
            throw $this->stop($wrong->position, ImportFailure::inputCause($wrong->reason), true, \count($this->batch));
        }
        if ($record === null) {
            return null;
        }
        $wanted = \count($this->types);
        if (\count($record->values) !== $wanted) {
            throw $this->stop($record->position, ImportFailure::inputCause(\count($record->values) . " fields, for {$wanted} columns"), true, \count($this->batch));
        }
        try {
            $values = [];
            foreach ($this->types as $at => $parameter) {
                $values[] = Assigning::assigned($record->values[$at] ?? null, $parameter->type);
            }
        } catch (TurborelException $failure) {
            throw $this->stop($record->position, $failure, false, \count($this->batch));
        }

        return [$record->position, $values];
    }

    /**
     * Ends `$copy`, the batch's copy: its rows committed, or why it failed, at the row it names.
     *
     * @throws ImportFailure if the copy fails
     */
    private function ended(Copying $copy): void
    {
        try {
            $rows = $copy->finish();
        } catch (TurborelException $failure) {
            $detail = $failure->getDiagnostics()['detail'] ?? '';
            $n = preg_match(self::ROW, $detail, $match) === 1 ? (int) $match[1] : 0;
            $named = $n > 0 && $n <= \count($this->batch);

            throw $this->stop($named ? ($this->batch[$n - 1] ?? null) : null, $failure, false, $named ? $n - 1 : \count($this->batch));
        }
        $this->committed += (int) $rows;
        $this->batch = [];
    }

    /** Stops the import at `$position` for `$cause`, `$rolledBack` rows of the batch undone. */
    private function stop(?Position $position, TurborelException $cause, bool $input, int $rolledBack): ImportFailure
    {
        return new ImportFailure($position, $input ? $cause : $cause->withoutPosition(), $input, $this->committed, $rolledBack);
    }
}

<?php

declare(strict_types=1);

namespace Turborel\Copy;

use Turborel\Internal\Copy\Inserting;
use Turborel\Session\Connection;

/**
 * An import of a source's rows into a table, as turborel-client's
 * `import` does it [CLIENT Importing]: through one prepared INSERT, or by
 * copy where the server grants it, no bad row is to be skipped and no
 * column is a large object's; a batch of rows committed at a time.
 */
final readonly class Import
{
    /** How many rows an import commits at a time unless told, as turborel-client's DEFAULT_BATCH. */
    public const int DEFAULT_BATCH = 1000;

    /** The import into `$table`, `$batch` rows a transaction, skipping a bad row if `$skip`. */
    public function __construct(
        /** The `<table name>` the rows go into, as SQL writes it. */
        public string $table,
        /** How many rows are committed at a time; 0 for all at once. */
        public int $batch = self::DEFAULT_BATCH,
        /** Whether a row refused, by the server, as a string too long for its column, or of too many or too few fields, is skipped and given to the program, rather than ending the import. */
        public bool $skip = false,
    ) {}

    /** An import into `$table`, DEFAULT_BATCH rows at a time, stopping at the first row that fails. */
    public static function into(string $table): self
    {
        return new self($table);
    }

    /**
     * Inserts `$source`'s records into the table over `$connection`, whose session has no transaction active, each row skipped
     * given to `$rejected`.
     *
     * @param (\Closure(Rejected): void)|null $rejected
     *
     * @throws ImportFailure why it stopped, where, and how many rows were committed, the batch under way rolled back
     */
    public function run(Connection $connection, Source $source, ?\Closure $rejected = null): Imported
    {
        return (new Inserting($connection, $this))->import($source, $rejected ?? static function (Rejected $row): void {});
    }
}

<?php

declare(strict_types=1);

namespace Turborel\Copy;

/**
 * What an import did [CLIENT Importing].
 */
final readonly class Imported
{
    /** The import of `$rows` rows, `$rejected` skipped. */
    public function __construct(
        /** How many rows were inserted and committed. */
        public int|string $rows,
        /** How many were skipped, each given to the program. */
        public int $rejected,
    ) {}
}

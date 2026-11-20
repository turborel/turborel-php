<?php

declare(strict_types=1);

namespace Turborel\Statements;

/**
 * What a statement whose rows went to a sink gave
 * [CLIENT Running statements]: of a query, how many rows; of another
 * statement, its completion.
 */
final readonly class Streamed
{
    /** The outcome of its parts. */
    public function __construct(
        /** The rows the sink was given, none of a statement not a query. */
        public int $rows,
        /** The completion of a statement not a query; none of a query. */
        public ?Completed $completed,
    ) {}
}

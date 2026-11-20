<?php

declare(strict_types=1);

namespace Turborel\Statements;

/**
 * Rows of a cursor, and whether it has more [CLIENT Prepared statements].
 */
final readonly class Batch
{
    /**
     * The batch of `$rows`.
     *
     * @param list<list<mixed>> $rows
     */
    public function __construct(
        /** @var list<list<mixed>> $rows the rows, each a value of each column */
        public array $rows,
        /** Whether the cursor has more, which a fetch reads; closed if not. */
        public bool $more,
    ) {}
}

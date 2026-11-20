<?php

declare(strict_types=1);

namespace Turborel\Statements;

use Turborel\Type\Column;

/**
 * What a statement gave [CLIENT Running statements]: of a query, its
 * columns and rows; of another statement, its completion.
 */
final readonly class Outcome
{
    /**
     * The outcome of its parts.
     *
     * @param list<Column>      $columns
     * @param list<list<mixed>> $rows
     */
    public function __construct(
        /** @var list<Column> $columns a query's columns; none of another statement */
        public array $columns,
        /** @var list<list<mixed>> $rows a query's rows, each a value of each column */
        public array $rows,
        /** Its completion, of a statement not a query, or a query's last. */
        public ?Completed $completed,
    ) {}

    /** Whether it is a query's: it has columns. */
    public function isRows(): bool
    {
        return $this->columns !== [];
    }
}

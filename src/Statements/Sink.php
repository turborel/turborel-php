<?php

declare(strict_types=1);

namespace Turborel\Statements;

use Turborel\Type\Column;

/**
 * What takes a query's columns and rows as they come, a batch at a
 * time, so no more than a batch is held [CLIENT Running statements].
 */
interface Sink
{
    /**
     * Takes the query's columns, before any row.
     *
     * @param list<Column> $columns
     */
    public function columns(array $columns): void;

    /**
     * Takes a batch of rows, each a value of each column.
     *
     * @param list<list<mixed>> $rows
     */
    public function rows(array $rows): void;
}

<?php

declare(strict_types=1);

namespace Turborel\Tests\Live\Statements;

use Turborel\Statements\Sink;
use Turborel\Type\Column;

/** A sink that counts what it is given. */
final class Counter implements Sink
{
    /** @var list<?string> the columns' names */
    public array $columns = [];

    /** The batches given. */
    public int $batches = 0;

    /** The rows given. */
    public int $rows = 0;

    /** Takes the columns' names. */
    public function columns(array $columns): void
    {
        $this->columns = array_map(static fn(Column $column): ?string => $column->name, $columns);
    }

    /** Counts a batch and its rows. */
    public function rows(array $rows): void
    {
        ++$this->batches;
        $this->rows += \count($rows);
    }
}

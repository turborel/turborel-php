<?php

declare(strict_types=1);

namespace Turborel\Statements;

use Turborel\Type\Column;
use Turborel\Type\Parameter;

/**
 * A statement the server prepared, of one connection, as
 * turborel-client's `Statement` [CLIENT Prepared statements]: the type
 * each of its dynamic parameters takes, and a query's columns.
 */
final readonly class Statement
{
    /**
     * The statement of its parts, as the server's Prepared described it.
     *
     * @param list<Parameter> $parameters
     * @param list<Column>    $columns
     */
    public function __construct(
        /** The connection's number of it, which each request about it names. */
        public int $number,
        /** @var list<Parameter> $parameters the type each `?` takes, in order */
        public array $parameters,
        /** @var list<Column> $columns a query's columns; none of another statement */
        public array $columns,
    ) {}
}

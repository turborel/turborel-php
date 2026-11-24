<?php

declare(strict_types=1);

namespace Turborel\Statements;

use Turborel\Type\Called;
use Turborel\Type\Column;
use Turborel\Type\Parameter;

/**
 * A statement the server prepared, of one connection, as
 * turborel-client's `Statement` [CLIENT Prepared statements]: the type
 * each of its input parameters takes, a query's columns, and, of a CALL of
 * an SQL-invoked procedure, granted `parameter-modes`, its procedure and
 * the mode and position of each of its `?` marks.
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
        /** @var list<Parameter> $parameters the type each `?` an execution gives a value for takes, in order: of a CALL, not those of output parameters alone */
        public array $parameters,
        /** @var list<Column> $columns a query's columns; of a CALL, one for each `?` of an output parameter, whose values an execution gives as a row; none of another statement */
        public array $columns,
        /** Of a CALL of an SQL-invoked procedure, granted `parameter-modes`, its procedure and each `?` with its mode and position. */
        public ?Called $called = null,
    ) {}

    /**
     * Each of its `?` marks, in order: its type and, of a CALL, the mode and ordinal position of the parameter whose argument
     * holds it, as a server granting `parameter-modes` tells them; told none, each is an input one, as turborel-client's
     * `parameters`.
     *
     * @return list<Parameter>
     */
    public function dynamicParameters(): array
    {
        return $this->called->parameters ?? $this->parameters;
    }
}

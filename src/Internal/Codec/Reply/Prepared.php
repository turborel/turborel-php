<?php

declare(strict_types=1);

namespace Turborel\Internal\Codec\Reply;

use Turborel\Type\Called;
use Turborel\Type\Column;
use Turborel\Type\Parameter;

/**
 * Prepared 'P': a statement prepared, or described [PROTOCOL Prepared 'P'].
 *
 * @internal
 */
final readonly class Prepared implements Reply
{
    /**
     * The reply of its fields.
     *
     * @param list<Parameter> $parameters
     * @param list<Column>    $columns
     */
    public function __construct(
        /** The client's number of the statement. */
        public int $statement,
        /** Its parameters, in the order of their `?` marks. */
        public array $parameters,
        /** Its columns, none of a statement not a query. */
        public array $columns,
        /** Of a CALL of an SQL-invoked procedure, granted `parameter-modes`, its procedure and its `?` marks' modes. */
        public ?Called $called = null,
    ) {}
}

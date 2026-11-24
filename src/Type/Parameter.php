<?php

declare(strict_types=1);

namespace Turborel\Type;

/**
 * A dynamic parameter of a prepared statement: whether the server gave it
 * CHARACTER VARYING of the longest length, its type not decided by the
 * statement, and its type [PROTOCOL Composite fields]; of a CALL, granted
 * `parameter-modes`, the mode and ordinal position of the procedure's
 * parameter whose argument holds it [PROTOCOL Prepared 'P'].
 */
final readonly class Parameter
{
    /** Makes it of its parts. */
    public function __construct(
        /** Whether its type is CHARACTER VARYING the statement left undecided. */
        public bool $varying,
        /** Its type. */
        public DataType $type,
        /** Of a CALL, the mode of the parameter whose argument holds it; IN of any other statement's. */
        public ParameterMode $mode = ParameterMode::In,
        /** Of a CALL of an SQL-invoked procedure, that parameter's ordinal position, from 1; none of another statement's. */
        public ?int $ordinal = null,
    ) {}
}

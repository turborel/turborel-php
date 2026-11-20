<?php

declare(strict_types=1);

namespace Turborel\Type;

/**
 * A dynamic parameter of a prepared statement: whether the server gave it CHARACTER VARYING of the longest length, its type not decided by the statement, and its type [PROTOCOL Composite fields].
 */
final readonly class Parameter
{
    /** Makes it of its parts. */
    public function __construct(
        /** Whether its type is CHARACTER VARYING the statement left undecided. */
        public bool $varying,
        /** Its type. */
        public DataType $type,
    ) {}
}

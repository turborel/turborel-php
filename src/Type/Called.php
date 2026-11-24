<?php

declare(strict_types=1);

namespace Turborel\Type;

/**
 * What a Prepared of a CALL of an SQL-invoked procedure tells, granted
 * `parameter-modes`, of the procedure and of each of the statement's `?`
 * marks [PROTOCOL Prepared 'P'].
 */
final readonly class Called
{
    /**
     * Makes it of its parts.
     *
     * @param list<Parameter> $parameters
     */
    public function __construct(
        /** The schema of the procedure's specific name. */
        public string $schema,
        /** The procedure's specific name. */
        public string $name,
        /** @var list<Parameter> $parameters each `?` of the statement, in the order written, with its mode and ordinal position */
        public array $parameters,
    ) {}
}

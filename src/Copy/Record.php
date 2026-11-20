<?php

declare(strict_types=1);

namespace Turborel\Copy;

/**
 * A record of a source [CLIENT Importing]: a value for each of its
 * fields, and where it began. A value is cast to its column's type as it
 * is inserted, so a source that knows no types gives character strings,
 * and null.
 */
final readonly class Record
{
    /**
     * The record of `$values`, begun at `$position`.
     *
     * @param list<mixed> $values
     */
    public function __construct(
        /** @var list<mixed> $values the values, one a field */
        public array $values,
        /** Where it began. */
        public Position $position,
    ) {}
}

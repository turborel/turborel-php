<?php

declare(strict_types=1);

namespace Turborel\Type;

/**
 * A column of a query's rows: its name, if it has one, its type and whether it is NOT NULL [PROTOCOL Composite fields].
 */
final readonly class Column
{
    /** Makes it of its parts. */
    public function __construct(
        /** Its name, if it is named. */
        public ?string $name,
        /** Its type. */
        public DataType $type,
        /** Whether it is NOT NULL. */
        public bool $notNull,
    ) {}
}

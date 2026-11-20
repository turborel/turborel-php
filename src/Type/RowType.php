<?php

declare(strict_types=1);

namespace Turborel\Type;

/**
 * A row type, tag 17: its fields, each a name and a type [PROTOCOL Data types].
 */
final readonly class RowType implements DataType
{
    /**
     * The row type of `$fields`, one or more.
     *
     * @param list<Field> $fields
     */
    public function __construct(
        /** @var list<Field> $fields its fields, in order */
        public array $fields,
    ) {}
}

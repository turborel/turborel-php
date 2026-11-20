<?php

declare(strict_types=1);

namespace Turborel\Copy;

use Turborel\Type\DataType;

/**
 * What a source says of one of its fields [CLIENT Importing]: a name,
 * which names a column of the table, read as an `<identifier>` if it is
 * one, a regular one in upper case, else as written; and its type, if the
 * source knows one.
 */
final readonly class Heading
{
    /** The heading of `$name`, of `$type` if known. */
    public function __construct(
        /** The name. */
        public string $name,
        /** The type of its values, if the source knows it. */
        public ?DataType $type = null,
    ) {}
}

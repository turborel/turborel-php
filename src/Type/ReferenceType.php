<?php

declare(strict_types=1);

namespace Turborel\Type;

/**
 * A reference type, tag 24: the referenced type's names, then its scope if it has one [PROTOCOL Data types].
 */
final readonly class ReferenceType implements DataType
{
    /** Makes it of its parts. */
    public function __construct(
        /** The structured type it references. */
        public StructuredType $referenced,
        /** The table it is scoped to, if any. */
        public ?UdtName $scope,
    ) {}
}

<?php

declare(strict_types=1);

namespace Turborel\Type;

/**
 * A structured type, tag 23: its schema and name, then its proper
 * supertypes' [PROTOCOL Data types].
 */
final readonly class StructuredType implements DataType
{
    /**
     * The structured type `$name`, of the proper supertypes `$supertypes`.
     *
     * @param list<UdtName> $supertypes
     */
    public function __construct(
        /** Its schema and name. */
        public UdtName $name,
        /** @var list<UdtName> $supertypes its proper supertypes */
        public array $supertypes = [],
    ) {}
}

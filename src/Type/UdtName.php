<?php

declare(strict_types=1);

namespace Turborel\Type;

/**
 * A user-defined type's schema and name [PROTOCOL Data types].
 */
final readonly class UdtName
{
    /** Makes it of its parts. */
    public function __construct(
        /** Its schema. */
        public string $schema,
        /** Its name. */
        public string $name,
    ) {}
}

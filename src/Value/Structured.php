<?php

declare(strict_types=1);

namespace Turborel\Value;

/**
 * A value of a structured type: its most specific type's schema and name,
 * then its attributes' values [PROTOCOL Values].
 */
final readonly class Structured implements SqlValue
{
    /**
     * The value of the type `$schema`.`$name` whose attributes are `$attributes`.
     *
     * @param list<mixed> $attributes
     */
    public function __construct(
        /** Its most specific type's schema. */
        public string $schema,
        /** Its most specific type's name. */
        public string $name,
        /** @var list<mixed> $attributes its attributes' values, in order */
        public array $attributes,
    ) {}
}

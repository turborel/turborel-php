<?php

declare(strict_types=1);

namespace Turborel\Value;

/**
 * An array: its elements in order [PROTOCOL Values].
 */
final readonly class ArrayValue implements SqlValue
{
    /**
     * Makes it of `$elements`.
     *
     * @param list<mixed> $elements
     */
    public function __construct(
        /** @var list<mixed> $elements its values, each as the driver gives a value */
        public array $elements,
    ) {}
}

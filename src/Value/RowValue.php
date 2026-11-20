<?php

declare(strict_types=1);

namespace Turborel\Value;

/**
 * A row: a value for each of its fields, in order [PROTOCOL Values].
 */
final readonly class RowValue implements SqlValue
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

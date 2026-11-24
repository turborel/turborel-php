<?php

declare(strict_types=1);

namespace Turborel\Debug;

/**
 * Where a frame is in its routine's text [PROTOCOL Event 'W'] *(extension)*: in characters from its beginning, and by line
 * and column from 1, each if told.
 */
final readonly class Place
{
    /** Makes it of its parts. */
    public function __construct(
        /** Its place, in characters from the beginning of the routine's text, a u64; none if not told. */
        public int|string|null $at = null,
        /** Its line, from 1; none if not told. */
        public ?int $line = null,
        /** Its column, from 1; none if not told. */
        public ?int $column = null,
    ) {}
}

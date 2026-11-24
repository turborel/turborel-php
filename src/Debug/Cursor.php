<?php

declare(strict_types=1);

namespace Turborel\Debug;

/**
 * A cursor of a frame: its name, whether it is open, and its query [PROTOCOL Event 'W'] *(extension)*.
 */
final readonly class Cursor
{
    /** Makes it of its parts. */
    public function __construct(
        /** Its name. */
        public string $name,
        /** Whether it is open. */
        public bool $open,
        /** Its query's text. */
        public string $query,
    ) {}
}

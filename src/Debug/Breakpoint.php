<?php

declare(strict_types=1);

namespace Turborel\Debug;

/**
 * A breakpoint a debugger set [PROTOCOL Debugged 'V'] *(extension)*: its
 * number, its routine by its specific name, its line, and its condition.
 */
final readonly class Breakpoint
{
    /** Makes it of its parts. */
    public function __construct(
        /** Its number, which Clear names. */
        public int $number,
        /** The schema of its routine's specific name. */
        public string $schema,
        /** Its routine's specific name, or its trigger's name. */
        public string $name,
        /** Its line of the routine's text, from 1. */
        public int $line,
        /** Its `<search condition>`, if it pauses only where that is true. */
        public ?string $condition = null,
    ) {}
}

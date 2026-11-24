<?php

declare(strict_types=1);

namespace Turborel\Debug;

/**
 * A frame of a paused target's call stack, a run of a routine's or a
 * trigger's body [PROTOCOL Event 'W'] *(extension)*: its origin, none of a
 * statement run directly; whether the debugger may see into it, a frame
 * not visible showing its origin alone; its place; and its parameters,
 * variables and cursors.
 */
final readonly class Frame
{
    /** Makes it of its parts. */
    public function __construct(
        /** Its routine or trigger; none of a statement run directly. */
        public ?Origin $origin,
        /** Whether the debugger may see into it. */
        public bool $visible,
        /** Where it is in its routine's text. */
        public Place $place,
        /** Its parameters, variables and cursors. */
        public Scope $scope,
    ) {}
}

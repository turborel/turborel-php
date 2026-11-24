<?php

declare(strict_types=1);

namespace Turborel\Debug;

/**
 * The kind of routine a breakpoint is set in, or a frame runs, as Debug
 * codes it [PROTOCOL Debug 'V'] *(extension)*.
 */
enum RoutineKind: int
{
    /** A procedure. */
    case Procedure = 0;

    /** A function. */
    case Function = 1;

    /** A trigger. */
    case Trigger = 2;
}

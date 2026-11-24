<?php

declare(strict_types=1);

namespace Turborel\Debug;

/**
 * What a debugger hears of its target, as Event codes it [PROTOCOL Event 'W'] *(extension)*.
 */
enum EventKind: int
{
    /** The target paused, at a breakpoint or at the end of a step. */
    case Paused = 0;

    /** The target, paused longer than DEBUGGER.PAUSE_TIMEOUT_MS, went on by itself. */
    case TimedOut = 1;

    /** The target's statement was cancelled while it was paused. */
    case Cancelled = 2;

    /** The target's session ended, and the debugger is detached. */
    case Ended = 3;
}

<?php

declare(strict_types=1);

namespace Turborel\Debug;

/**
 * How a paused target goes on, as Debug's resume codes it [PROTOCOL Debug 'V'] *(extension)*.
 */
enum Action: int
{
    /** To its next statement, in whatever frame: into a routine it invokes. */
    case Into = 0;

    /** To the next statement of this frame, or of one it returns to. */
    case Over = 1;

    /** To the next statement of the frame this one returns to. */
    case Out = 2;

    /** To a breakpoint. */
    case Continue = 3;
}

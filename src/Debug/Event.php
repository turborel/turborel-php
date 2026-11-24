<?php

declare(strict_types=1);

namespace Turborel\Debug;

/**
 * What a debugger hears of its target, sent unasked between requests
 * [PROTOCOL Event 'W'] *(extension)*: a pause with its call stack, a
 * timeout, a cancel, or its session's end.
 */
final readonly class Event
{
    /** Makes it of its parts. */
    public function __construct(
        /** What happened. */
        public EventKind $kind,
        /** Of a pause, the pause. */
        public ?Paused $paused = null,
    ) {}
}

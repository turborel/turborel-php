<?php

declare(strict_types=1);

namespace Turborel\Internal\Codec\Reply;

use Turborel\Debug\Event;

/**
 * Event 'W', with `debugger` *(extension)*: what a debugger hears of its
 * target, sent unasked between requests, never ending one [PROTOCOL Event 'W'].
 *
 * @internal
 */
final readonly class EventSent implements Reply
{
    /** The reply of `$event`. */
    public function __construct(
        /** What the debugger hears. */
        public Event $event,
    ) {}
}

<?php

declare(strict_types=1);

namespace Turborel\Internal\Codec\Reply;

use Turborel\Debug\Breakpoint;
use Turborel\Debug\Paused;

/**
 * Debugged 'V', with `debugger` *(extension)*: what a Debug request gave
 * back, but a completion [PROTOCOL Debugged 'V'].
 *
 * @internal
 */
final readonly class Debugged implements Reply
{
    /** A connection's ID. */
    public const int CONNECTION = 0;

    /** A breakpoint set. */
    public const int BREAKPOINT = 1;

    /** The breakpoints set. */
    public const int BREAKPOINTS = 2;

    /** The target's pause, if it is paused. */
    public const int STACK = 3;

    /**
     * The reply of `$what`, giving `$value`.
     *
     * @param int|string|Breakpoint|list<Breakpoint>|Paused|null $value
     */
    public function __construct(
        /** What it gives. */
        public int $what,
        /** @var int|string|Breakpoint|list<Breakpoint>|Paused|null $value a connection's ID, a u64; a breakpoint; the breakpoints; or the pause, none of a target running */
        public int|string|Breakpoint|array|Paused|null $value,
    ) {}
}

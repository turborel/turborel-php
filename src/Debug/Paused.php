<?php

declare(strict_types=1);

namespace Turborel\Debug;

/**
 * A target's pause [PROTOCOL Event 'W'] *(extension)*: the breakpoint it
 * paused at, none at the end of a step, and its call stack, the outermost
 * frame first.
 */
final readonly class Paused
{
    /**
     * Makes it of its parts.
     *
     * @param list<Frame> $frames
     */
    public function __construct(
        /** The number of the breakpoint it paused at; none at the end of a step. */
        public ?int $breakpoint,
        /** @var list<Frame> $frames its call stack, the outermost frame first */
        public array $frames,
    ) {}

    /** Whether it paused at the end of a step. */
    public function stepped(): bool
    {
        return $this->breakpoint === null;
    }
}

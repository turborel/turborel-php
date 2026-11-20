<?php

declare(strict_types=1);

namespace Turborel\Pipeline;

use Turborel\Statements\Outcome;
use Turborel\TurborelException;

/**
 * The outcome of one request of a pipeline, as turborel-client's `Piped`
 * [CLIENT Pipelines]: what it gave, how it failed, or that it was skipped.
 */
final readonly class Piped
{
    /** The outcome of its parts. */
    private function __construct(
        /** What the request gave, if it ran and did not fail. */
        public ?Outcome $outcome,
        /** How it failed, as the server reported it, if it did. */
        public ?TurborelException $failure,
    ) {}

    /** What a request gave. */
    public static function done(Outcome $outcome): self
    {
        return new self($outcome, null);
    }

    /** How a request failed. */
    public static function failed(TurborelException $failure): self
    {
        return new self(null, $failure);
    }

    /** A request not run, or run and undone, after an earlier one failed under OnError::Skip. */
    public static function skipped(): self
    {
        return new self(null, null);
    }

    /** Whether the request was skipped. */
    public function isSkipped(): bool
    {
        return $this->outcome === null && $this->failure === null;
    }
}

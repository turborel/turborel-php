<?php

declare(strict_types=1);

namespace Turborel\Copy;

use Turborel\TurborelException;

/**
 * A row an import skipped: where its record began, and why [CLIENT Importing].
 */
final readonly class Rejected
{
    /** The row of the record begun at `$position`, skipped for `$cause`. */
    public function __construct(
        /** Where its record began. */
        public Position $position,
        /** Why it failed: SQL's failure, or the input's, of SQLSTATE 22000. */
        public TurborelException $cause,
        /** Whether the cause is the input's. */
        public bool $input,
    ) {}

    /** As `record 2, line 3: why; skipped`. */
    public function __toString(): string
    {
        return "{$this->position}: " . ImportFailure::cause($this->cause, $this->input) . '; skipped';
    }
}

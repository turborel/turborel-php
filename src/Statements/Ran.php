<?php

declare(strict_types=1);

namespace Turborel\Statements;

use Turborel\TurborelException;

/**
 * What several statements run as one request gave
 * [CLIENT Controlling the session]: each one's outcome, in order, and the
 * failure of the one that stopped the rest, if one did.
 */
final readonly class Ran
{
    /**
     * What ran.
     *
     * @param list<Outcome> $outcomes
     */
    public function __construct(
        /** @var list<Outcome> $outcomes the outcomes of the statements that ran, in order */
        public array $outcomes,
        /** The failure of the statement that stopped the rest, if one did. */
        public ?TurborelException $failure,
    ) {}
}

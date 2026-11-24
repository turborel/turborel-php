<?php

declare(strict_types=1);

namespace Turborel\Debug;

/**
 * What a frame holds [PROTOCOL Event 'W'] *(extension)*: its parameters, a trigger's its transition variables, its
 * variables, and its cursors.
 */
final readonly class Scope
{
    /**
     * Makes it of its parts.
     *
     * @param list<Named>  $parameters
     * @param list<Named>  $variables
     * @param list<Cursor> $cursors
     */
    public function __construct(
        /** @var list<Named> $parameters its parameters, a trigger's its transition variables */
        public array $parameters = [],
        /** @var list<Named> $variables its variables */
        public array $variables = [],
        /** @var list<Cursor> $cursors its cursors */
        public array $cursors = [],
    ) {}
}

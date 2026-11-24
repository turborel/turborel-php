<?php

declare(strict_types=1);

namespace Turborel\Debug;

/**
 * The routine or trigger a frame runs the body of [PROTOCOL Event 'W'] *(extension)*.
 */
final readonly class Origin
{
    /** Makes it of its parts. */
    public function __construct(
        /** Its kind. */
        public RoutineKind $kind,
        /** The schema of its specific name. */
        public string $schema,
        /** Its specific name. */
        public string $specificName,
        /** The schema of its name. */
        public string $shownSchema,
        /** Its name. */
        public string $name,
    ) {}
}

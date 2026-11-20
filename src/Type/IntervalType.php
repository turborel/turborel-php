<?php

declare(strict_types=1);

namespace Turborel\Type;

use Turborel\Value\Qualifier;

/**
 * INTERVAL, tag 13: its qualifier [PROTOCOL Data types].
 */
final readonly class IntervalType implements DataType
{
    /** Makes it of its parts. */
    public function __construct(
        /** Its qualifier. */
        public Qualifier $qualifier,
    ) {}
}

<?php

declare(strict_types=1);

namespace Turborel\Internal\Codec;

/**
 * Where a failure points: the rule of a standard it cites, and its
 * position in characters into the statement, each if given
 * [PROTOCOL Composite fields].
 *
 * @internal
 */
final readonly class Cited
{
    /** The rule `$rule` and the position `$position`, each if given. */
    public function __construct(
        /** The rule it cites, as messages cite one. */
        public ?string $rule = null,
        /** Its position, characters into the statement, a u64. */
        public int|string|null $position = null,
    ) {}
}

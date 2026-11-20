<?php

declare(strict_types=1);

namespace Turborel\Internal\Agent;

/**
 * A `<simple value specification>` of a connection statement: the text of
 * a character string literal, or of another value, which a client's
 * connection statement does not take.
 *
 * @internal
 */
final readonly class Value
{
    /** The value of `$text`, a literal's if `$literal`. */
    public function __construct(
        /** The literal's string, or the value as written. */
        public string $text,
        /** Whether it is a character string literal. */
        public bool $literal,
    ) {}
}

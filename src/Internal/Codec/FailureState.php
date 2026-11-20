<?php

declare(strict_types=1);

namespace Turborel\Internal\Codec;

/**
 * What a failure leaves: whether the session's transaction is active
 * after it, and its diagnostics, if granted [PROTOCOL Composite fields].
 *
 * @internal
 */
final readonly class FailureState
{
    /** Active if `$active`, saying `$diagnostics` more. */
    public function __construct(
        /** Whether a transaction is active after it. */
        public bool $active = false,
        /** Tail 7 to 15, where `diagnostics` was granted and it says any. */
        public ?Diagnostics $diagnostics = null,
    ) {}
}

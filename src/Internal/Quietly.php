<?php

declare(strict_types=1);

namespace Turborel\Internal;

/**
 * A call into PHP's own functions with any warning or notice they raise
 * taken, never printed, so the caller makes it the driver's failure
 * (RULES.md R-ERR-3): PHP's stream and zlib functions report trouble by
 * warnings, beside returning false.
 *
 * @internal
 */
final class Quietly
{
    /**
     * What `$call` gives, and the message of the last warning it raised, if any.
     *
     * @template T
     *
     * @param \Closure(): T $call
     *
     * @return array{T, ?string}
     */
    public static function call(\Closure $call): array
    {
        $warned = null;
        set_error_handler(static function (int $level, string $message) use (&$warned): bool {
            $warned = $message;

            return true;
        });
        try {
            $result = $call();
        } finally {
            restore_error_handler();
        }

        return [$result, $warned];
    }
}

<?php

declare(strict_types=1);

namespace Turborel\Internal\Pdo;

use Turborel\Internal\Misuse;
use Turborel\Pdo\Pdo;
use Turborel\TurborelException;

/**
 * Parameters bound to a PDO-style statement (RULES.md D2): by position,
 * from 1, as `?` parameters are; each value taken as its type says.
 *
 * @internal
 */
final class Binding
{
    /**
     * The position `$param` names, from 1.
     *
     * @throws TurborelException SQLSTATE TB910 of a name, or a position below 1
     */
    public static function position(int|string $param): int
    {
        if (\is_string($param) || $param < 1) {
            throw Misuse::of("parameters are bound by their position, from 1, not by {$param}");
        }

        return $param;
    }

    /**
     * The values of `$bound` and `$references`, in their positions' order, each as its type says.
     *
     * @param array<int, array{mixed, int}> $bound
     * @param array<int, array{mixed, int}> $references
     *
     * @return list<mixed>
     *
     * @throws TurborelException SQLSTATE TB910 if a position from 1 to the last bound is not bound
     */
    public static function values(array $bound, array $references): array
    {
        $all = $references + $bound;
        $values = [];
        for ($at = 1; $at <= ($all === [] ? 0 : max(array_keys($all))); $at++) {
            [$value, $type] = $all[$at] ?? throw Misuse::of("the parameter {$at} is not bound");
            $values[] = self::typed($value, $type);
        }

        return $values;
    }

    /** `$value` as `$type` takes it: null; an integer of an integer's digits; a string of a scalar; a truth value; a stream's contents; else as it is. */
    private static function typed(mixed $value, int $type): mixed
    {
        return match (true) {
            $value === null || $type === Pdo::PARAM_NULL => null,
            $type === Pdo::PARAM_INT && \is_string($value) && preg_match('/^-?\d+$/', $value) === 1 && (string) (int) $value === $value => (int) $value,
            $type === Pdo::PARAM_INT && \is_bool($value) => (int) $value,
            $type === Pdo::PARAM_BOOL && \is_scalar($value) => (bool) $value,
            $type === Pdo::PARAM_STR && (\is_int($value) || \is_float($value) || \is_bool($value)) => (string) $value,
            $type === Pdo::PARAM_LOB && \is_resource($value) => (string) stream_get_contents($value),
            default => $value,
        };
    }
}

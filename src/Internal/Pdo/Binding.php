<?php

declare(strict_types=1);

namespace Turborel\Internal\Pdo;

use Turborel\Internal\Misuse;
use Turborel\Pdo\Pdo;
use Turborel\TurborelException;
use Turborel\Type\Parameter;
use Turborel\Value\Exact;

/**
 * Parameters bound to a PDO-style statement (RULES.md D2): by position,
 * from 1, as `?` parameters are; each value taken as its type says, its
 * PARAM_INPUT_OUTPUT flag aside. Of a CALL whose `?` marks' modes the
 * server told, granted `parameter-modes`, a value is sent for each IN or
 * INOUT one alone, and the CALL's row of OUT and INOUT values is written
 * back to the variables bound at their positions.
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
     * The values of `$bound` and `$references`, in their positions' order, each as its type says; of a CALL whose `?` marks
     * are `$marks`, of its IN and INOUT ones alone.
     *
     * @param array<int, array{mixed, int}> $bound
     * @param array<int, array{mixed, int}> $references
     * @param list<Parameter>|null          $marks
     *
     * @return list<mixed>
     *
     * @throws TurborelException SQLSTATE TB910 if a position from 1 to the last bound, or of an input, is not bound
     */
    public static function values(array $bound, array $references, ?array $marks = null): array
    {
        $all = $references + $bound;
        $values = [];
        $last = $marks === null ? ($all === [] ? 0 : max(array_keys($all))) : \count($marks);
        for ($at = 1; $at <= $last; $at++) {
            if ($marks !== null && ($marks[$at - 1] ?? null)?->mode->takesValue() === false) {
                continue;
            }
            [$value, $type] = $all[$at] ?? throw Misuse::of("the parameter {$at} is not bound");
            $values[] = self::typed($value, $type);
        }

        return $values;
    }

    /**
     * Writes the CALL's row `$row`, a value for each of its OUT and INOUT `?` marks of `$marks` in order, back to the
     * variables of `$references` bound at their positions.
     *
     * @param array<int, array{mixed, int}> $references
     * @param list<Parameter>               $marks
     * @param list<mixed>                   $row
     */
    public static function writeBack(array &$references, array $marks, array $row): void
    {
        $out = 0;
        foreach ($marks as $at => $mark) {
            if (!$mark->mode->givesValue()) {
                continue;
            }
            $value = $row[$out++] ?? null;
            if (isset($references[$at + 1])) {
                $references[$at + 1][0] = self::given($value, $references[$at + 1][1]);
            }
        }
    }

    /** A value given back, `$value`, as the type `$type` bound it takes it: an integer of an exact integer that fits, a string of an exact number, else as it is. */
    private static function given(mixed $value, int $type): mixed
    {
        $type &= ~Pdo::PARAM_INPUT_OUTPUT;

        return match (true) {
            $value instanceof Exact && $type === Pdo::PARAM_INT && $value->scale === 0 && (string) (int) $value->units === $value->units => (int) $value->units,
            $value instanceof Exact && $type === Pdo::PARAM_STR => (string) $value,
            default => $value,
        };
    }

    /** `$value` as `$type` takes it: null; an integer of an integer's digits; a string of a scalar; a truth value; a stream's contents; else as it is. */
    private static function typed(mixed $value, int $type): mixed
    {
        $type &= ~Pdo::PARAM_INPUT_OUTPUT;

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

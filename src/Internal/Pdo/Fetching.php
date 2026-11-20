<?php

declare(strict_types=1);

namespace Turborel\Internal\Pdo;

use Turborel\Internal\Misuse;
use Turborel\Pdo\Pdo;
use Turborel\TurborelException;
use Turborel\Type\Column;

/**
 * Rows in the fetch modes of the PDO-style API (RULES.md D2): by column
 * name, by number, both, as a \stdClass, one column's value, or as an
 * object of a class named.
 *
 * @internal
 */
final class Fetching
{
    /** The modes taken. */
    private const array MODES = [Pdo::FETCH_ASSOC, Pdo::FETCH_NUM, Pdo::FETCH_BOTH, Pdo::FETCH_OBJ, Pdo::FETCH_COLUMN, Pdo::FETCH_CLASS];

    /**
     * `$mode`, a mode taken.
     *
     * @throws TurborelException SQLSTATE TB910 of another
     */
    public static function mode(int $mode): int
    {
        if (!\in_array($mode, self::MODES, true)) {
            throw Misuse::of("the fetch mode {$mode} is not taken");
        }

        return $mode;
    }

    /**
     * `$row` of `$columns` in `$mode`: of FETCH_COLUMN the column `$argument`, from 0; of FETCH_CLASS an object of the class
     * `$argument`, constructed with `$arguments`.
     *
     * @param list<mixed>  $row
     * @param list<Column> $columns
     * @param list<mixed>  $arguments
     *
     * @throws TurborelException SQLSTATE TB910 of a column not there, or a class that cannot be made
     */
    public static function row(array $row, array $columns, int $mode, mixed $argument, array $arguments): mixed
    {
        $named = [];
        foreach ($columns as $at => $column) {
            $named[$column->name ?? ''] = $row[$at] ?? null;
        }

        return match ($mode) {
            Pdo::FETCH_ASSOC => $named,
            Pdo::FETCH_NUM => $row,
            Pdo::FETCH_OBJ => (object) $named,
            Pdo::FETCH_COLUMN => \array_key_exists(\is_int($argument) ? $argument : 0, $row) ? $row[\is_int($argument) ? $argument : 0] : throw Misuse::of('the column is not there'),
            Pdo::FETCH_CLASS => self::object($named, $argument, $arguments),
            default => $named + $row,
        };
    }

    /**
     * An object of the class `$class`, each property it declares of `$named` set before its constructor runs with `$arguments`.
     *
     * @param array<string, mixed> $named
     * @param list<mixed>          $arguments
     *
     * @throws TurborelException SQLSTATE TB910 if the class cannot be made
     */
    private static function object(array $named, mixed $class, array $arguments): object
    {
        if (!\is_string($class) || !class_exists($class)) {
            throw Misuse::of('FETCH_CLASS names a class');
        }
        $reflection = new \ReflectionClass($class);
        $object = $reflection->newInstanceWithoutConstructor();
        foreach ($named as $name => $value) {
            if ($reflection->hasProperty($name)) {
                $reflection->getProperty($name)->setValue($object, $value);
            }
        }
        $reflection->getConstructor()?->invokeArgs($object, $arguments);

        return $object;
    }
}

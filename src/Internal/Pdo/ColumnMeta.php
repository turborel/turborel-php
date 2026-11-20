<?php

declare(strict_types=1);

namespace Turborel\Internal\Pdo;

use Turborel\Pdo\Pdo;
use Turborel\Type\ApproximateType;
use Turborel\Type\BinaryType;
use Turborel\Type\BooleanType;
use Turborel\Type\CharacterType;
use Turborel\Type\Column;
use Turborel\Type\DataType;
use Turborel\Type\DatetimeType;
use Turborel\Type\DecFloatType;
use Turborel\Type\ExactType;
use Turborel\Type\LobType;
use Turborel\Value\LobKind;

/**
 * What PDO's getColumnMeta gives of a column (RULES.md D2): its name, its
 * SQL type's name, the one it was declared with where `declared-types` was
 * granted, as INTEGER, else its type's kind; the PDO type it reads as, its
 * length and precision, `not_null` among its flags where it takes no null,
 * and `turborel:decl_type`, the driver's own key, its whole type as SQL
 * writes it, as DECIMAL(9,2).
 *
 * @internal
 */
final class ColumnMeta
{
    /**
     * The meta of `$column`.
     *
     * @return array{name: string, native_type: string, 'turborel:decl_type': string, pdo_type: int, len: int, precision: int, flags: list<string>}
     */
    public static function of(Column $column): array
    {
        $type = $column->type;

        return [
            'name' => $column->name ?? '',
            'native_type' => $column->declared->name ?? self::name($type),
            'turborel:decl_type' => $column->typeWritten(),
            'pdo_type' => match (true) {
                $type instanceof BooleanType => Pdo::PARAM_BOOL,
                $type instanceof LobType => Pdo::PARAM_LOB,
                default => Pdo::PARAM_STR,
            },
            'len' => match (true) {
                $type instanceof CharacterType, $type instanceof BinaryType => $type->length,
                $type instanceof LobType => (int) min($type->length, \PHP_INT_MAX),
                default => -1,
            },
            'precision' => match (true) {
                $type instanceof ExactType => $type->scale,
                $type instanceof DatetimeType => $type->precision,
                default => 0,
            },
            'flags' => $column->notNull ? ['not_null'] : [],
        ];
    }

    /** The name of `$type`, as SQL writes its kind. */
    private static function name(DataType $type): string
    {
        return match (true) {
            $type instanceof CharacterType => $type->varying ? 'CHARACTER VARYING' : 'CHARACTER',
            $type instanceof BinaryType => $type->varying ? 'BINARY VARYING' : 'BINARY',
            $type instanceof ExactType => 'DECIMAL',
            $type instanceof ApproximateType => 'DOUBLE PRECISION',
            $type instanceof DecFloatType => 'DECFLOAT',
            $type instanceof BooleanType => 'BOOLEAN',
            $type instanceof DatetimeType => strtoupper($type->moment->name) . ($type->zoned ? ' WITH TIME ZONE' : ''),
            $type instanceof LobType => $type->kind === LobKind::Character ? 'CHARACTER LARGE OBJECT' : 'BINARY LARGE OBJECT',
            default => strtoupper(preg_replace('/Type$/', '', (new \ReflectionClass($type))->getShortName()) ?? ''),
        };
    }
}

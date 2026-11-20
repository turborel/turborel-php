<?php

declare(strict_types=1);

namespace Turborel\Internal\Codec;

use Turborel\Type\ApproximateType;
use Turborel\Type\ArrayType;
use Turborel\Type\BinaryType;
use Turborel\Type\BooleanType;
use Turborel\Type\CharacterType;
use Turborel\Type\DataType;
use Turborel\Type\DatetimeType;
use Turborel\Type\DecFloatType;
use Turborel\Type\ExactType;
use Turborel\Type\IntervalType;
use Turborel\Type\JsonType;
use Turborel\Type\LobType;
use Turborel\Type\MultisetType;
use Turborel\Type\ReferenceType;
use Turborel\Type\RowType;
use Turborel\Type\StructuredType;
use Turborel\Type\UuidType;
use Turborel\Type\XmlPrimary;
use Turborel\Type\XmlSchemaName;
use Turborel\Type\XmlSecondary;
use Turborel\Type\XmlType;
use Turborel\Value\CharUnits;
use Turborel\Value\IntervalField;
use Turborel\Value\LobKind;
use Turborel\Value\Moment;
use Turborel\Value\Qualifier;

/**
 * A data type as SQL writes it, as turborel's `DataType` displays one a
 * client read: an exact numeric type as NUMERIC(p,s), an approximate one
 * as FLOAT(p), a plug-in's as UNREGISTERED, its name not registered with
 * the client.
 *
 * @internal
 */
final class Written
{
    /** `$type` as SQL writes it. */
    public static function of(DataType $type): string
    {
        return match (true) {
            $type instanceof CharacterType => self::sized($type->varying ? 'CHARACTER VARYING' : 'CHARACTER', $type->length, $type->units),
            $type instanceof ExactType => "NUMERIC({$type->precision},{$type->scale})",
            $type instanceof ApproximateType => "FLOAT({$type->precision})",
            $type instanceof BinaryType => ($type->varying ? 'BINARY VARYING' : 'BINARY') . "({$type->length})",
            $type instanceof DatetimeType => self::datetime($type),
            $type instanceof IntervalType => 'INTERVAL ' . self::qualifier($type->qualifier),
            $type instanceof BooleanType => 'BOOLEAN',
            $type instanceof DecFloatType => "DECFLOAT({$type->precision})",
            $type instanceof LobType => $type->kind === LobKind::Character ? self::sized('CHARACTER LARGE OBJECT', $type->length, $type->units) : "BINARY LARGE OBJECT({$type->length})",
            $type instanceof ArrayType => self::of($type->element) . " ARRAY[{$type->maxCardinality}]",
            $type instanceof MultisetType => self::of($type->element) . ' MULTISET',
            $type instanceof RowType => 'ROW(' . implode(', ', array_map(static fn($f): string => "{$f->name} " . self::of($f->type), $type->fields)) . ')',
            $type instanceof StructuredType => "{$type->name->schema}.{$type->name->name}",
            $type instanceof ReferenceType => self::reference($type),
            $type instanceof JsonType => 'JSON',
            $type instanceof XmlType => self::xml($type),
            $type instanceof UuidType => 'UUID',
            default => 'UNREGISTERED',
        };
    }

    /** `$name` of `$length` in `$units`, OCTETS written of octets. */
    private static function sized(string $name, int|string $length, CharUnits $units): string
    {
        return $units === CharUnits::Octets ? "{$name}({$length} OCTETS)" : "{$name}({$length})";
    }

    /** A datetime type, its precision but of DATE. */
    private static function datetime(DatetimeType $type): string
    {
        $zone = $type->zoned ? ' WITH TIME ZONE' : '';

        return match ($type->moment) {
            Moment::Date => 'DATE',
            Moment::Time => "TIME({$type->precision}){$zone}",
            Moment::Timestamp => "TIMESTAMP({$type->precision}){$zone}",
        };
    }

    /** An interval qualifier, as turborel's Qualifier displays it. */
    private static function qualifier(Qualifier $q): string
    {
        $start = strtoupper($q->start->name);
        if ($q->start === $q->end) {
            return $q->end === IntervalField::Second ? "SECOND({$q->leading}, {$q->fraction})" : "{$start}({$q->leading})";
        }

        return "{$start}({$q->leading}) TO " . strtoupper($q->end->name) . ($q->end === IntervalField::Second ? "({$q->fraction})" : '');
    }

    /** A reference type, REF of its referenced type's name, and its scope if it has one. */
    private static function reference(ReferenceType $type): string
    {
        $referenced = "REF({$type->referenced->name->schema}.{$type->referenced->name->name})";

        return $type->scope === null ? $referenced : "{$referenced} SCOPE {$type->scope->schema}.{$type->scope->name}";
    }

    /** An XML type, its modifiers, and of XMLSCHEMA the schema it names. */
    private static function xml(XmlType $type): string
    {
        $primary = strtoupper($type->primary->name);
        if ($type->schema !== null) {
            return "XML({$primary}(XMLSCHEMA " . self::schema($type->schema) . '))';
        }
        if ($type->primary === XmlPrimary::Sequence) {
            return 'XML(SEQUENCE)';
        }

        return "XML({$primary}(" . ($type->secondary === XmlSecondary::Any ? 'ANY' : 'UNTYPED') . '))';
    }

    /** A registered XML Schema an XML type names, as turborel's according_written writes it. */
    private static function schema(XmlSchemaName $named): string
    {
        $out = "ID {$named->schema}.{$named->name}";
        if ($named->namespace !== null) {
            $out .= $named->namespace === '' ? ' NO NAMESPACE' : " NAMESPACE '" . str_replace("'", "''", $named->namespace) . "'";
        }

        return $named->element === null ? $out : $out . ' ELEMENT "' . str_replace('"', '""', $named->element) . '"';
    }
}

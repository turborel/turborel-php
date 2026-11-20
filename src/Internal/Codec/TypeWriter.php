<?php

declare(strict_types=1);

namespace Turborel\Internal\Codec;

use Turborel\Internal\ValueFailure;
use Turborel\TurborelException;
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
use Turborel\Type\PluginType;
use Turborel\Type\ReferenceType;
use Turborel\Type\RowType;
use Turborel\Type\StructuredType;
use Turborel\Type\UuidType;
use Turborel\Type\XmlType;
use Turborel\Value\LobKind;
use Turborel\Value\Moment;

/**
 * Data types written as the protocol writes them, a tag then what its kind
 * writes after it [PROTOCOL Data types], as an export file of native
 * frames holds them and the codec's tests read them back.
 *
 * @internal
 */
final class TypeWriter
{
    /**
     * Writes `$type`.
     *
     * @throws TurborelException SQLSTATE 22003 if a field is out of its range, 22021 if a name is not UTF-8
     */
    public static function write(Writer $writer, DataType $type): Writer
    {
        return match (true) {
            $type instanceof CharacterType => $writer->u8($type->varying ? Tag::VARYING : Tag::CHARACTER)->u32($type->length)->u8($type->units->value),
            $type instanceof ExactType => $writer->u8(Tag::EXACT)->u32($type->precision)->u32($type->scale),
            $type instanceof ApproximateType => $writer->u8(Tag::APPROXIMATE)->u32($type->precision),
            $type instanceof DecFloatType => $writer->u8(Tag::DECFLOAT)->u32($type->precision),
            $type instanceof PluginType => self::plugin($writer, $type),
            $type instanceof BinaryType => $writer->u8($type->varying ? Tag::BINARY_VARYING : Tag::BINARY)->u32($type->length),
            $type instanceof DatetimeType => $writer->u8(self::datetimeTag($type))->u32($type->precision),
            $type instanceof IntervalType => Qualifiers::write($writer->u8(Tag::INTERVAL), $type->qualifier),
            $type instanceof BooleanType => $writer->u8(Tag::BOOLEAN),
            $type instanceof JsonType => $writer->u8(Tag::JSON),
            $type instanceof UuidType => $writer->u8(Tag::UUID),
            $type instanceof ArrayType => self::write($writer->u8(Tag::ARRAY)->u32($type->maxCardinality), $type->element),
            $type instanceof MultisetType => self::write($writer->u8(Tag::MULTISET), $type->element),
            $type instanceof RowType => self::row($writer, $type),
            $type instanceof LobType => $writer->u8($type->kind === LobKind::Character ? Tag::CLOB : Tag::BLOB)->u64($type->length)->u8($type->units->value),
            $type instanceof StructuredType => UdtNames::write($writer->u8(Tag::STRUCTURED), $type),
            $type instanceof ReferenceType => self::reference($writer, $type),
            $type instanceof XmlType => XmlTypes::write($writer->u8(Tag::XML), $type),
            default => throw ValueFailure::restricted('no data type is a ' . $type::class),
        };
    }

    /**
     * The tag of a datetime type.
     *
     * @throws TurborelException SQLSTATE 22003 of a DATE WITH TIME ZONE, which no tag is
     */
    private static function datetimeTag(DatetimeType $type): int
    {
        return match ($type->moment) {
            Moment::Date => $type->zoned ? throw ValueFailure::outOfRange('DATE is never WITH TIME ZONE') : Tag::DATE,
            Moment::Time => $type->zoned ? Tag::TIME_ZONED : Tag::TIME,
            Moment::Timestamp => $type->zoned ? Tag::TIMESTAMP_ZONED : Tag::TIMESTAMP,
        };
    }

    /**
     * A plug-in's type: its name, then its parameters.
     *
     * @throws TurborelException SQLSTATE 22003 if a parameter is not a u32
     */
    private static function plugin(Writer $writer, PluginType $type): Writer
    {
        $writer->u8(Tag::PLUGIN)->string($type->name)->u32(\count($type->parameters));
        foreach ($type->parameters as $parameter) {
            $writer->u32($parameter);
        }

        return $writer;
    }

    /**
     * A row type: its fields, each a name and a type.
     *
     * @throws TurborelException SQLSTATE 22003 if a field's type is out of range
     */
    private static function row(Writer $writer, RowType $type): Writer
    {
        $writer->u8(Tag::ROW)->u32(\count($type->fields));
        foreach ($type->fields as $field) {
            self::write($writer->string($field->name), $field->type);
        }

        return $writer;
    }

    /**
     * A reference type: the referenced type's names, then its scope.
     *
     * @throws TurborelException SQLSTATE 22021 if a name is not UTF-8
     */
    private static function reference(Writer $writer, ReferenceType $type): Writer
    {
        UdtNames::write($writer->u8(Tag::REFERENCE), $type->referenced);
        if ($type->scope === null) {
            return $writer->u8(0);
        }

        return $writer->u8(1)->string($type->scope->schema)->string($type->scope->name);
    }
}

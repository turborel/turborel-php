<?php

declare(strict_types=1);

namespace Turborel\Internal\Codec;

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
use Turborel\Type\Field;
use Turborel\Type\IntervalType;
use Turborel\Type\JsonType;
use Turborel\Type\LobType;
use Turborel\Type\MultisetType;
use Turborel\Type\PluginType;
use Turborel\Type\ReferenceType;
use Turborel\Type\RowType;
use Turborel\Type\UuidType;
use Turborel\Value\CharUnits;
use Turborel\Value\LobKind;
use Turborel\Value\Moment;

/**
 * Data types read from a message, a tag then what its kind writes after
 * it, as turborel's `fields.rs` reads them [PROTOCOL Data types].
 *
 * @internal
 */
final class TypeCodec
{
    /** [DESIGN] The most parameters a plug-in's type has, as turborel reads them. */
    public const int MAX_PLUGIN_PARAMETERS = 2;

    /** [DESIGN] The greatest maximum cardinality of an array type, as turborel takes it. */
    public const int MAX_CARDINALITY = 65_535;

    /** The datetime types by their tags: each its moment and whether it is WITH TIME ZONE. */
    private const array DATETIMES = [
        Tag::DATE => [Moment::Date, false],
        Tag::TIME => [Moment::Time, false],
        Tag::TIME_ZONED => [Moment::Time, true],
        Tag::TIMESTAMP => [Moment::Timestamp, false],
        Tag::TIMESTAMP_ZONED => [Moment::Timestamp, true],
    ];

    /**
     * The data type `$reader` reads next.
     *
     * @throws TurborelException SQLSTATE 08006 if it is malformed
     */
    public static function read(Reader $reader): DataType
    {
        $tag = $reader->u8();
        if (isset(self::DATETIMES[$tag])) {
            [$moment, $zoned] = self::DATETIMES[$tag];

            return new DatetimeType($moment, $zoned, $reader->u32());
        }

        return match ($tag) {
            Tag::CHARACTER, Tag::VARYING => new CharacterType($tag === Tag::VARYING, $reader->u32(), self::units($reader)),
            Tag::EXACT => new ExactType($reader->u32(), $reader->u32()),
            Tag::APPROXIMATE => new ApproximateType($reader->u32()),
            Tag::DECFLOAT => new DecFloatType($reader->u32()),
            Tag::PLUGIN => self::plugin($reader),
            Tag::BINARY, Tag::BINARY_VARYING => new BinaryType($tag === Tag::BINARY_VARYING, $reader->u32()),
            Tag::INTERVAL => new IntervalType(Qualifiers::read($reader)),
            Tag::BOOLEAN => new BooleanType(),
            Tag::JSON => new JsonType(),
            Tag::UUID => new UuidType(),
            Tag::ARRAY => $reader->nested(self::array(...)),
            Tag::MULTISET => $reader->nested(static fn(Reader $r): MultisetType => new MultisetType(self::read($r))),
            Tag::ROW => $reader->nested(self::row(...)),
            Tag::CLOB, Tag::BLOB => self::lob($reader, $tag === Tag::CLOB ? LobKind::Character : LobKind::Binary),
            Tag::STRUCTURED => UdtNames::structured($reader),
            Tag::REFERENCE => self::reference($reader),
            Tag::XML => XmlTypes::read($reader),
            default => throw $reader->malformed("no data type has the tag {$tag}"),
        };
    }

    /**
     * A length's units: 0 characters, 1 octets.
     *
     * @throws TurborelException SQLSTATE 08006 if they are neither
     */
    public static function units(Reader $reader): CharUnits
    {
        return CharUnits::tryFrom($reader->u8()) ?? throw $reader->malformed('a length\'s units are neither characters nor octets');
    }

    /**
     * A plug-in's type: its name, then its parameters.
     *
     * @throws TurborelException SQLSTATE 08006 if it is malformed or has too many parameters
     */
    private static function plugin(Reader $reader): PluginType
    {
        $name = $reader->string();
        $parameters = [];
        for ($count = $reader->u32(); $count > 0; $count--) {
            $parameters[] = $reader->u32();
            if (\count($parameters) > self::MAX_PLUGIN_PARAMETERS) {
                throw $reader->malformed('a type has too many parameters');
            }
        }

        return new PluginType($name, $parameters);
    }

    /**
     * An array type: its maximum cardinality, 1 to MAX_CARDINALITY, then its element type.
     *
     * @throws TurborelException SQLSTATE 08006 if it is malformed
     */
    private static function array(Reader $reader): ArrayType
    {
        $max = $reader->u32();
        if ($max < 1 || $max > self::MAX_CARDINALITY) {
            throw $reader->malformed('an array type\'s maximum cardinality is out of range');
        }

        return new ArrayType($max, self::read($reader));
    }

    /**
     * A row type: its fields, one or more, each a name and a type.
     *
     * @throws TurborelException SQLSTATE 08006 if it is malformed or has no field
     */
    private static function row(Reader $reader): RowType
    {
        $fields = [];
        for ($count = $reader->u32(); $count > 0; $count--) {
            $fields[] = new Field($reader->string(), self::read($reader));
        }
        if ($fields === []) {
            throw $reader->malformed('a row type has no fields');
        }

        return new RowType($fields);
    }

    /**
     * A large object type of `$kind`: its length, a u64, then its units.
     *
     * @throws TurborelException SQLSTATE 08006 if it is malformed
     */
    private static function lob(Reader $reader, LobKind $kind): LobType
    {
        return new LobType($kind, $reader->u64(), self::units($reader));
    }

    /**
     * A reference type: the referenced type's names, then 0, or 1 and its scope's.
     *
     * @throws TurborelException SQLSTATE 08006 if it is malformed
     */
    private static function reference(Reader $reader): ReferenceType
    {
        $referenced = UdtNames::structured($reader);
        $scope = match ($reader->u8()) {
            0 => null,
            1 => UdtNames::read($reader),
            default => throw $reader->malformed('a reference type\'s scope flag is 0 or 1'),
        };

        return new ReferenceType($referenced, $scope);
    }
}

<?php

declare(strict_types=1);

namespace Turborel\Tests\Unit\Internal\Codec;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\CoversNamespace;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Turborel\Internal\Codec\Reader;
use Turborel\Internal\Codec\TypeCodec;
use Turborel\Internal\Codec\TypeWriter;
use Turborel\Internal\ConnectionFailure;
use Turborel\Internal\ValueFailure;
use Turborel\Tests\Support\CodecTestCase;
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
use Turborel\Type\StructuredType;
use Turborel\Type\UdtName;
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
 * Data types, each tag 1 to 27 written as the spec lays it out and read
 * back, and the types a reader must refuse.
 */
#[CoversNamespace('Turborel\Internal\Codec')]
#[CoversNamespace('Turborel\Type')]
#[CoversNamespace('Turborel\Value')]
#[CoversClass(ConnectionFailure::class)]
#[CoversClass(ValueFailure::class)]
#[CoversClass(TurborelException::class)]
final class TypeCodecTest extends CodecTestCase
{
    /**
     * Verifies: [PROTOCOL Data types]: each data type is its tag, then
     * what its kind writes after it, byte for byte, and reads back as the
     * same type; UUID is tag 27 with nothing after it.
     *
     * @throws TurborelException never, every type being well formed
     */
    #[Test]
    #[DataProvider('types')]
    public function eachTypeIsItsTagThenItsParameters(DataType $type, string $bytes): void
    {
        $written = TypeWriter::write(self::writer(), $type)->bytesWritten();
        self::assertSame(self::hex($bytes), $written);
        $reader = self::reader($written);
        self::assertEquals($type, TypeCodec::read($reader));
        $reader->finish();
    }

    /**
     * Every type tag, each a type and its bytes.
     *
     * @return iterable<string, array{DataType, string}>
     *
     * @throws TurborelException never, every type being well formed
     */
    public static function types(): iterable
    {
        yield '1 CHARACTER' => [new CharacterType(false, 10, CharUnits::Characters), '01 0A 00 00 00 00'];
        yield '2 exact numeric' => [new ExactType(9, 2), '02 09 00 00 00 02 00 00 00'];
        yield '3 approximate numeric' => [new ApproximateType(53), '03 35 00 00 00'];
        yield '4 a plug-in\'s type' => [new PluginType('P', [7, 8]), '04 01 00 00 00 50 02 00 00 00 07 00 00 00 08 00 00 00'];
        yield '5 CHARACTER VARYING' => [new CharacterType(true, 20, CharUnits::Octets), '05 14 00 00 00 01'];
        yield '6 BINARY' => [new BinaryType(false, 4), '06 04 00 00 00'];
        yield '7 BINARY VARYING' => [new BinaryType(true, 8), '07 08 00 00 00'];
        yield '8 DATE' => [new DatetimeType(Moment::Date, false, 0), '08 00 00 00 00'];
        yield '9 TIME' => [new DatetimeType(Moment::Time, false, 3), '09 03 00 00 00'];
        yield '10 TIME WITH TIME ZONE' => [new DatetimeType(Moment::Time, true, 6), '0A 06 00 00 00'];
        yield '11 TIMESTAMP' => [new DatetimeType(Moment::Timestamp, false, 6), '0B 06 00 00 00'];
        yield '12 TIMESTAMP WITH TIME ZONE' => [new DatetimeType(Moment::Timestamp, true, 0), '0C 00 00 00 00'];
        yield '13 INTERVAL DAY TO SECOND' => [new IntervalType(new Qualifier(IntervalField::Day, IntervalField::Second, 4, 6)), '0D 02 00 00 00 05 00 00 00 04 00 00 00 06 00 00 00'];
        yield '14 BOOLEAN' => [new BooleanType(), '0E'];
        yield '15 DECFLOAT' => [new DecFloatType(34), '0F 22 00 00 00'];
        yield '16 array' => [new ArrayType(65_535, new BooleanType()), '10 FF FF 00 00 0E'];
        yield '17 row' => [new RowType([new Field('A', new BooleanType()), new Field('B', new JsonType())]), '11 02 00 00 00 01 00 00 00 41 0E 01 00 00 00 42 19'];
        yield '18 CLOB' => [new LobType(LobKind::Character, '18446744073709551615', CharUnits::Characters), '12 FF FF FF FF FF FF FF FF 00'];
        yield '19 BLOB' => [new LobType(LobKind::Binary, 1024, CharUnits::Octets), '13 00 04 00 00 00 00 00 00 01'];
        yield '22 multiset' => [new MultisetType(new UuidType()), '16 1B'];
        yield '23 structured type' => [new StructuredType(new UdtName('S', 'T'), [new UdtName('S', 'U')]), '17 01 00 00 00 53 01 00 00 00 54 01 00 00 00 01 00 00 00 53 01 00 00 00 55'];
        yield '24 reference, unscoped' => [new ReferenceType(new StructuredType(new UdtName('S', 'T')), null), '18 01 00 00 00 53 01 00 00 00 54 00 00 00 00 00'];
        yield '24 reference, scoped' => [new ReferenceType(new StructuredType(new UdtName('S', 'T')), new UdtName('S', 'R')), '18 01 00 00 00 53 01 00 00 00 54 00 00 00 00 01 01 00 00 00 53 01 00 00 00 52'];
        yield '25 JSON' => [new JsonType(), '19'];
        yield '26 XML(CONTENT(ANY))' => [new XmlType(XmlPrimary::Content, XmlSecondary::Any), '1A 01 01'];
        yield '26 XML(SEQUENCE)' => [new XmlType(XmlPrimary::Sequence), '1A 02 00'];
        yield '26 XML(DOCUMENT(XMLSCHEMA))' => [new XmlType(XmlPrimary::Document, XmlSecondary::XmlSchema, new XmlSchemaName('S', 'X', 'urn:n', null)), '1A 00 02 01 00 00 00 53 01 00 00 00 58 01 05 00 00 00 75 72 6E 3A 6E 00'];
        yield '27 UUID' => [new UuidType(), '1B'];
    }

    /**
     * Verifies: [PROTOCOL Data types]: an interval's fields are numbered
     * 0 YEAR to 5 SECOND, and every qualifier reads back.
     *
     * @throws TurborelException never, every field being one
     */
    #[Test]
    public function everyIntervalQualifierReadsBack(): void
    {
        foreach (IntervalField::cases() as $start) {
            foreach (IntervalField::cases() as $end) {
                $type = new IntervalType(new Qualifier($start, $end, 9, 6));
                self::assertEquals($type, TypeCodec::read(self::reader(TypeWriter::write(self::writer(), $type)->bytesWritten())));
            }
        }
    }

    /**
     * Verifies: [PROTOCOL Data types] [PROTOCOL Frames]: a type a reader
     * cannot take fails the connection: a tag of no type, units neither 0
     * nor 1, an array's maximum cardinality out of 1 to 65535, a row of no
     * field, a plug-in's type of three parameters or one of u32::MAX, an interval field past
     * SECOND, XML's modifiers out of range or SEQUENCE typed, a reference's
     * scope flag of 2, and types nested past sixteen levels.
     */
    #[Test]
    #[DataProvider('malformed')]
    public function aMalformedTypeFailsTheConnection(string $bytes): void
    {
        self::assertConnectionFails(static fn(): DataType => TypeCodec::read(self::reader(self::hex($bytes))));
    }

    /**
     * Types a reader must refuse.
     *
     * @return iterable<string, array{string}>
     */
    public static function malformed(): iterable
    {
        yield 'tag 20, a value\'s alone' => ['14'];
        yield 'tag 28' => ['1C'];
        yield 'units of 2' => ['01 01 00 00 00 02'];
        yield 'a plug-in parameter of u32::MAX' => ['04 01 00 00 00 50 01 00 00 00 FF FF FF FF'];
        yield 'a cardinality of 0' => ['10 00 00 00 00 0E'];
        yield 'a cardinality of 65536' => ['10 00 00 01 00 0E'];
        yield 'a row of no field' => ['11 00 00 00 00'];
        yield 'three parameters' => ['04 01 00 00 00 50 03 00 00 00 01 00 00 00 02 00 00 00 03 00 00 00'];
        yield 'a field of 6' => ['0D 06 00 00 00 05 00 00 00 02 00 00 00 00 00 00 00'];
        yield 'a primary modifier of 3' => ['1A 03 00'];
        yield 'a secondary modifier of 3' => ['1A 00 03'];
        yield 'SEQUENCE typed' => ['1A 02 01'];
        yield 'a namespace marked 2' => ['1A 00 02 01 00 00 00 53 01 00 00 00 58 02'];
        yield 'a scope flag of 2' => ['18 01 00 00 00 53 01 00 00 00 54 00 00 00 00 02'];
        yield 'arrays seventeen deep' => [str_repeat('16 ', 17) . '0E'];
    }

    /**
     * Verifies: [PROTOCOL Data types] [DESIGN] D8: a DATE WITH TIME ZONE,
     * which no tag is, is refused before it is written (SQLSTATE 22003).
     */
    #[Test]
    public function aDateWithTimeZoneIsRefused(): void
    {
        self::assertFailure(ValueFailure::OUT_OF_RANGE, ValueFailure::DATA, static fn(): mixed => TypeWriter::write(self::writer(), new DatetimeType(Moment::Date, true, 0)));
    }

    /**
     * Verifies: [PROTOCOL Data types] [PROTOCOL Values and fields]: arrays
     * nested sixteen deep are read.
     *
     * @throws TurborelException never, the nesting being within bound
     */
    #[Test]
    public function typesNestedSixteenDeepAreRead(): void
    {
        $reader = self::reader(self::hex(str_repeat('16 ', Reader::MAX_NESTING) . '0E'));
        $type = TypeCodec::read($reader);
        for ($level = 0; $level < Reader::MAX_NESTING; $level++) {
            self::assertInstanceOf(MultisetType::class, $type);
            $type = $type->element;
        }
        self::assertInstanceOf(BooleanType::class, $type);
    }
}

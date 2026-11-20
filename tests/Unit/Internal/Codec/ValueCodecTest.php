<?php

declare(strict_types=1);

namespace Turborel\Tests\Unit\Internal\Codec;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\CoversNamespace;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Turborel\Internal\Codec\ValueCodec;
use Turborel\Internal\Codec\ValueWriter;
use Turborel\Internal\Codec\Wide;
use Turborel\Internal\ConnectionFailure;
use Turborel\Internal\ValueFailure;
use Turborel\Tests\Support\CodecTestCase;
use Turborel\TurborelException;
use Turborel\Value\ArrayValue;
use Turborel\Value\Binary;
use Turborel\Value\Datetime;
use Turborel\Value\DecFloat;
use Turborel\Value\Exact;
use Turborel\Value\Interval;
use Turborel\Value\IntervalField;
use Turborel\Value\Json;
use Turborel\Value\Lob;
use Turborel\Value\LobKind;
use Turborel\Value\LobLocator;
use Turborel\Value\Moment;
use Turborel\Value\Multiset;
use Turborel\Value\PluginValue;
use Turborel\Value\Qualifier;
use Turborel\Value\Reference;
use Turborel\Value\RowValue;
use Turborel\Value\Structured;
use Turborel\Value\Uuid;
use Turborel\Value\Xml;

/**
 * Values, each tag 0 to 27 written as the spec lays it out and read back
 * at its edges, and the values a reader must refuse.
 */
#[CoversNamespace('Turborel\Internal\Codec')]
#[CoversNamespace('Turborel\Value')]
#[CoversClass(ConnectionFailure::class)]
#[CoversClass(ValueFailure::class)]
#[CoversClass(TurborelException::class)]
final class ValueCodecTest extends CodecTestCase
{
    /**
     * Verifies: [PROTOCOL Values]: each value is its tag, then its body,
     * byte for byte, and reads back as the same value.
     *
     * @throws TurborelException never, every value being well formed
     */
    #[Test]
    #[DataProvider('values')]
    public function eachValueIsItsTagThenItsBody(mixed $value, string $bytes): void
    {
        $written = ValueWriter::write(self::writer(), $value)->bytesWritten();
        self::assertSame(bin2hex(self::hex($bytes)), bin2hex($written));
        $reader = self::reader($written);
        self::assertEquals($value, ValueCodec::read($reader));
        $reader->finish();
    }

    /**
     * Every value tag, each a value and its bytes.
     *
     * @return iterable<string, array{mixed, string}>
     *
     * @throws TurborelException never, every value being well formed
     */
    public static function values(): iterable
    {
        $units16 = static fn(string $digits): string => implode(' ', str_split(bin2hex(Wide::i128ToBytes($digits)), 2));
        $uuid = hex2bin('919108f752d143209bacf847db4148a8');
        yield '0 null' => [null, '00'];
        yield '1 character string' => ['jam', '01 03 00 00 00 6A 61 6D'];
        yield '2 exact number, the worked example\'s 1' => [new Exact('1', 0), '02 01 ' . str_repeat('00 ', 15) . '00 00 00 00'];
        yield '2 exact number, -0.1' => [new Exact('-1', 1), '02 ' . $units16('-1') . ' 01 00 00 00'];
        yield '2 exact number, the greatest i128 and scale' => [new Exact(Wide::I128_MAX, 0xFFFF_FFFF), '02 ' . $units16(Wide::I128_MAX) . ' FF FF FF FF'];
        yield '2 exact number, the least i128' => [new Exact(Wide::I128_MIN, 0), '02 ' . $units16(Wide::I128_MIN) . ' 00 00 00 00'];
        yield '3 approximate number, 1.5' => [1.5, '03 00 00 00 00 00 00 F8 3F'];
        yield '4 a plug-in\'s value' => [new PluginValue('P', '7', "\x07"), '04 01 00 00 00 50 01 00 00 00 37 01 00 00 00 07'];
        yield '6 binary string' => [new Binary("\x00\xFF"), '06 02 00 00 00 00 FF'];
        yield '8 date' => [new Datetime(Moment::Date, 86_400_000_000), '08 00 00 00 00 00 00 00 60 D7 1D 14 00 00 00'];
        yield '9 time, zoned at -12:59' => [new Datetime(Moment::Time, 1, -779, true, 6), '09 01 06 F5 FC 00 00 01 00 00 00 00 00 00 00'];
        yield '11 timestamp at +14:00' => [new Datetime(Moment::Timestamp, -1, 840, true, 0), '0B 01 00 48 03 00 00 FF FF FF FF FF FF FF FF'];
        yield '13 interval' => [new Interval(new Qualifier(IntervalField::Year, IntervalField::Month, 2, 0), '-14'), '0D 00 00 00 00 01 00 00 00 02 00 00 00 00 00 00 00 ' . $units16('-14')];
        yield '14 truth value, false' => [false, '0E 00'];
        yield '14 truth value, true' => [true, '0E 01'];
        yield '15 decimal floating point' => [new DecFloat('15', -9), '0F ' . $units16('15') . ' F7 FF FF FF'];
        yield '16 array, nested' => [new ArrayValue([new ArrayValue(['a']), null]), '10 02 00 00 00 10 01 00 00 00 01 01 00 00 00 61 00'];
        yield '17 row' => [new RowValue([true]), '11 01 00 00 00 0E 01'];
        yield '20 large object, whole' => [new Lob(LobKind::Character, 'é'), '14 00 02 00 00 00 C3 A9'];
        yield '21 large object, by locator' => [new LobLocator(LobKind::Binary, 7, 70_000, '18446744073709551615'), '15 01 07 00 00 00 70 11 01 00 00 00 00 00 FF FF FF FF FF FF FF FF'];
        yield '22 multiset' => [new Multiset([null]), '16 01 00 00 00 00'];
        yield '23 structured value' => [new Structured('S', 'T', [null]), '17 01 00 00 00 53 01 00 00 00 54 01 00 00 00 00'];
        yield '24 reference' => [new Reference("\x01"), '18 01 00 00 00 01'];
        yield '25 JSON, true' => [new Json("\x01\x02"), '19 02 00 00 00 01 02'];
        yield '26 XML' => [new Xml('<a/>'), '1A 04 00 00 00 3C 61 2F 3E'];
        yield '27 UUID' => [new Uuid((string) $uuid), '1B 10 00 00 00 ' . implode(' ', str_split(bin2hex((string) $uuid), 2))];
    }

    /**
     * Verifies: [PROTOCOL Values]: a PHP int is written as an exact number
     * of scale 0, the whole of an i64 among them.
     *
     * @throws TurborelException never, an int being an i128
     */
    #[Test]
    public function anIntIsAnExactNumberOfScaleZero(): void
    {
        $written = ValueWriter::write(self::writer(), \PHP_INT_MIN)->bytesWritten();
        self::assertEquals(new Exact((string) \PHP_INT_MIN, 0), ValueCodec::read(self::reader($written)));
    }

    /**
     * Verifies: [PROTOCOL Values]: an approximate number keeps its bits,
     * NaN and -0.0 among them.
     *
     * @throws TurborelException never, the bits being a double's
     */
    #[Test]
    public function anApproximateNumberKeepsItsBits(): void
    {
        foreach (['000000000000F87F', '0000000000000080', 'FFFFFFFFFFFFEF7F', '0100000000000000'] as $bits) {
            $bytes = self::hex('03 ' . $bits);
            $value = ValueCodec::read(self::reader($bytes));
            self::assertIsFloat($value);
            self::assertSame(bin2hex($bytes), bin2hex(ValueWriter::write(self::writer(), $value)->bytesWritten()));
        }
    }

    /**
     * Verifies: [PROTOCOL Values]: a value's tag says how it is written,
     * not its column's type; turborel's reader takes the varying and zoned
     * types' tags as their values too.
     *
     * @throws TurborelException never, the values being well formed
     */
    #[Test]
    public function typeTagsOfTheSameBodyAreTaken(): void
    {
        self::assertSame('a', ValueCodec::read(self::reader(self::hex('05 01 00 00 00 61'))));
        self::assertEquals(new Binary('b'), ValueCodec::read(self::reader(self::hex('07 01 00 00 00 62'))));
        $zoned = '01 00 00 00 00 00 00 00 00 00 00 00 00 00';
        self::assertEquals(new Datetime(Moment::Time, 0, 0, true), ValueCodec::read(self::reader(self::hex('0A ' . $zoned))));
        self::assertEquals(new Datetime(Moment::Timestamp, 0, 0, true), ValueCodec::read(self::reader(self::hex('0C ' . $zoned))));
    }

    /**
     * Verifies: [PROTOCOL Values] [PROTOCOL Frames]: a value a reader
     * cannot take fails the connection: a tag of no value, a truth value
     * of 2, a displacement past an i16, a DECFLOAT not in its one form, a
     * large object of no kind or a character one not UTF-8, damaged JSON,
     * a UUID not of 16 octets, a multiset past 65535 elements.
     */
    #[Test]
    #[DataProvider('malformed')]
    public function aMalformedValueFailsTheConnection(string $bytes): void
    {
        self::assertConnectionFails(static fn(): mixed => ValueCodec::read(self::reader(self::hex($bytes))));
    }

    /**
     * Values a reader must refuse.
     *
     * @return iterable<string, array{string}>
     */
    public static function malformed(): iterable
    {
        yield 'tag 18, a type\'s alone' => ['12 00'];
        yield 'tag 28' => ['1C'];
        yield 'a truth value of 2' => ['0E 02'];
        yield 'a displacement past an i16' => ['0B 00 00 00 00 01 00 00 00 00 00 00 00 00 00'];
        yield 'a DECFLOAT with a trailing zero' => ['0F 0A ' . str_repeat('00 ', 15) . '00 00 00 00'];
        yield 'a DECFLOAT out of range' => ['0F 01 ' . str_repeat('00 ', 15) . '01 18 00 00'];
        yield 'a large object of kind 2' => ['14 02 00 00 00 00'];
        yield 'a character large object not UTF-8' => ['14 00 01 00 00 00 FF'];
        yield 'damaged JSON' => ['19 01 00 00 00 01'];
        yield 'a UUID of 15 octets' => ['1B 0F 00 00 00 ' . str_repeat('00 ', 15)];
        yield 'a multiset past 65535 elements' => ['16 00 00 01 00 ' . str_repeat('00 ', 65_536)];
        yield 'an array\'s count past its body' => ['10 FF FF FF FF'];
    }

    /**
     * Verifies: [PROTOCOL Values] [DESIGN] D8: a PHP value the protocol has
     * no value of is refused before it is written (SQLSTATE 22000).
     */
    #[Test]
    public function aPhpValueOfNoSqlValueIsRefused(): void
    {
        self::assertFailure(ValueFailure::RESTRICTED, ValueFailure::DATA, static fn(): mixed => ValueWriter::write(self::writer(), [1]));
        self::assertFailure(ValueFailure::RESTRICTED, ValueFailure::DATA, static fn(): mixed => ValueWriter::write(self::writer(), new \stdClass()));
    }
}

<?php

declare(strict_types=1);

namespace Turborel\Tests\Unit\Copy;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\CoversNamespace;
use PHPUnit\Framework\Attributes\Test;
use Turborel\Copy\ExportFormat;
use Turborel\Internal\ConnectionFailure;
use Turborel\Internal\Copy\Exporter;
use Turborel\Internal\Copy\Moments;
use Turborel\Internal\Copy\Numbers;
use Turborel\Internal\Copy\Text;
use Turborel\Internal\Quietly;
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
 * Values as an export's CSV writes them: as turborel's `Value` shows each.
 */
#[CoversClass(Text::class)]
#[CoversClass(Numbers::class)]
#[CoversClass(Moments::class)]
#[CoversClass(Exporter::class)]
#[CoversClass(Quietly::class)]
#[CoversClass(ConnectionFailure::class)]
#[CoversClass(ValueFailure::class)]
#[CoversClass(TurborelException::class)]
#[CoversNamespace('Turborel\Value')]
#[CoversNamespace('Turborel\Internal\Codec')]
final class TextTest extends CodecTestCase
{
    /**
     * Verifies: [CLIENT Copying and exporting]: an approximate number is
     * written as Rust's `{:E}` writes it, the shortest digits that read
     * back; a decimal floating-point number plainly from an adjusted
     * exponent of -6 to 33, else in scientific notation.
     *
     * @throws TurborelException never, the numbers being well formed
     */
    #[Test]
    public function numbersAreWrittenAsTurborelShowsThem(): void
    {
        foreach ([[1.5, '1.5E0'], [100.0, '1E2'], [0.1, '1E-1'], [1e-7, '1E-7'], [123456.789, '1.23456789E5']] as [$value, $text]) {
            self::assertSame($text, Numbers::upperExp($value));
        }
        self::assertSame(['0E0', '-0E0', '-2.5E0', 'NaN', 'inf', '-inf', '1.7976931348623157E308', '5E-324'], array_map(Numbers::upperExp(...), [0.0, -0.0, -2.5, \NAN, \INF, -\INF, \PHP_FLOAT_MAX, 5e-324]));
        self::assertSame(['1.5', '0.000001', '1E40', '1.234E-7', '0', '-12'], [Numbers::decFloat(new DecFloat('150', -2)), Numbers::decFloat(new DecFloat('1', -6)),
            Numbers::decFloat(new DecFloat('1', 40)), Numbers::decFloat(new DecFloat('1234', -10)), Numbers::decFloat(new DecFloat('0', 5)), Numbers::decFloat(new DecFloat('-12', 0))]);
    }

    /**
     * Verifies: [CLIENT Copying and exporting]: a datetime is written in the
     * zone it is shown in, its fraction to its precision, its displacement
     * if WITH TIME ZONE; an interval as its literal's string, its leading
     * field all it holds of it.
     *
     * @throws TurborelException never, the values being well formed
     */
    #[Test]
    public function datetimesAndIntervalsAreTheirLiteralsStrings(): void
    {
        $day = 86_400_000_000;
        $days = 719_162 + intdiv((int) gmmktime(0, 0, 0, 11, 19, 2026), 86_400);
        $date = new Datetime(Moment::Date, $days * $day);
        self::assertSame('2026-11-19', Text::of($date));
        self::assertSame('0001-01-01 00:00:00', Text::of(new Datetime(Moment::Timestamp, 0)));
        self::assertSame('2026-11-19 02:30:00.12+02:30', Text::of(new Datetime(Moment::Timestamp, $days * $day + 120_000, 150, true, 2)));
        self::assertSame('23:59:59', Text::of(new Datetime(Moment::Time, $day - 1_000_000)));
        self::assertSame('00:30:00-01:00', Text::of(new Datetime(Moment::Time, 5_400_000_000, -60, true)));
        $hm = new Interval(new Qualifier(IntervalField::Hour, IntervalField::Minute, 3), '-3720000000');
        self::assertSame(['-1:02', "INTERVAL -'1:02' HOUR(3) TO MINUTE"], [Text::of($hm), Text::literal($hm)]);
        $ym = new Interval(new Qualifier(IntervalField::Year, IntervalField::Month), '27');
        self::assertSame(['2-03', "INTERVAL '2-03' YEAR(2) TO MONTH"], [Text::of($ym), Text::literal($ym)]);
        $ds = new Interval(new Qualifier(IntervalField::Day, IntervalField::Second, 9, 3), '100000000000000000123456');
        self::assertSame('1157407407407 09:46:40.123', Text::of($ds));
        $s = new Interval(new Qualifier(IntervalField::Second, IntervalField::Second, 2, 6), '1500000');
        self::assertSame(['1.500000', "INTERVAL '1.500000' SECOND(2, 6)"], [Text::of($s), Text::literal($s)]);
        self::assertSame("INTERVAL '5' DAY(2)", Text::literal(new Interval(new Qualifier(IntervalField::Day, IntervalField::Day), (string) (5 * $day))));
    }

    /**
     * Verifies: [CLIENT Copying and exporting]: every other value is written
     * as turborel shows it, and a composite as its literal, each part a
     * literal, a JSON value with no text as what is wrong; a CSV field is
     * quoted where it holds a comma, a quote, a line break or spaces at
     * either end, its quotes doubled; a null empty; a stream that takes no
     * write fails the export (SQLSTATE 08006).
     *
     * @throws TurborelException never, the values being well formed
     */
    #[Test]
    public function otherValuesAndCsvFields(): void
    {
        $uuid = Uuid::fromText('919108f7-52d1-4320-9bac-f847db4148a8');
        $shown = [null, 'tea', true, false, 7, new Exact('125', 2), new Binary("\x0A\xFF"), new Reference("\x01"), new Lob(LobKind::Character, 'jam'),
            new Lob(LobKind::Binary, "\x01"), new LobLocator(LobKind::Character, 1, 9, 8), new LobLocator(LobKind::Binary, 1, 9, 0), Json::fromText('{"a":1}'),
            $uuid, new Xml('<a/>'), new PluginValue('POINT', 'POINT(1 2)', '')];
        self::assertSame(['NULL', 'tea', 'TRUE', 'FALSE', '7', '1.25', '0AFF', 'REF(01)', 'jam', '01', '<CHARACTER LARGE OBJECT of 8 characters>',
            '<BINARY LARGE OBJECT of 9 octets>', '{"a":1}', '919108f7-52d1-4320-9bac-f847db4148a8', '<a/>', 'POINT(1 2)'], array_map(Text::of(...), $shown));
        $parts = [null, "it's", new Binary("\x01"), new Datetime(Moment::Date, 0), new DecFloat('15', -1), new Lob(LobKind::Character, 'x'), new Lob(LobKind::Binary, "\x02"),
            Json::fromText('"q\'"'), new Reference("\x03"), $uuid, new Xml("<a b='c'/>"), new LobLocator(LobKind::Binary, 1, 4, 0), 1.5];
        self::assertSame("ARRAY[NULL, 'it''s', X'01', DATE '0001-01-01', CAST('1.5' AS DECFLOAT), 'x', X'02', JSON('\"q''\"'), REF(03), "
            . "UUID '919108f7-52d1-4320-9bac-f847db4148a8', XMLPARSE(CONTENT '<a b=''c''/>' PRESERVE WHITESPACE), <BINARY LARGE OBJECT of 4 octets>, 1.5E0]", Text::of(new ArrayValue($parts)));
        self::assertSame(['MULTISET[1]', 'ROW(1, TRUE)', 'S.ADDRESS(\'x\')'], [Text::of(new Multiset([new Exact('1', 0)])), Text::of(new RowValue([new Exact('1', 0), true])),
            Text::of(new Structured('S', 'ADDRESS', ['x']))]);
        self::assertSame(['plain', '"a,b"', '"say ""hi"""', '" pad"', "\"two\nlines\"", '""', '', "\"\r\""], [Exporter::field('plain'), Exporter::field('a,b'),
            Exporter::field('say "hi"'), Exporter::field(' pad'), Exporter::field("two\nlines"), Exporter::value(''), Exporter::value(null), Exporter::field("\r")]);
        self::assertStringStartsWith('<', Text::of(Json::fromValue([new Datetime(Moment::Date, 0)])));
        $closed = fopen('php://memory', 'rb');
        self::assertIsResource($closed);
        $refused = new Exporter($closed, ExportFormat::Csv);
        $refused->rows([['1']]);
        self::assertConnectionFails(static fn() => $refused->end());
        $out = fopen('php://memory', 'w+b');
        self::assertIsResource($out);
        $exporter = new Exporter($out, ExportFormat::Csv);
        $exporter->rows([['1', null]]);
        $exporter->end();
        self::assertSame("1,\r\n", stream_get_contents($out, -1, 0));
    }
}

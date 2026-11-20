<?php

declare(strict_types=1);

namespace Turborel\Tests\Unit\Value;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\CoversNamespace;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Turborel\Internal\ValueFailure;
use Turborel\Tests\Support\CodecTestCase;
use Turborel\TurborelException;
use Turborel\Value\Datetime;
use Turborel\Value\DecFloat;
use Turborel\Value\Exact;
use Turborel\Value\Interval;
use Turborel\Value\IntervalField;
use Turborel\Value\Lob;
use Turborel\Value\LobKind;
use Turborel\Value\Moment;
use Turborel\Value\Qualifier;
use Turborel\Value\Uuid;

/**
 * The values PHP has no type of its own for, made, checked and shown.
 */
#[CoversNamespace('Turborel\Value')]
#[CoversNamespace('Turborel\Internal\Codec')]
#[CoversClass(ValueFailure::class)]
#[CoversClass(TurborelException::class)]
final class ValuesTest extends CodecTestCase
{
    /**
     * Verifies: [PROTOCOL Values]: an exact number is units divided by ten
     * to the scale, written in digits with its scale's digits after the
     * point, and read back from them with no loss.
     *
     * @throws TurborelException never, the numbers being in range
     */
    #[Test]
    #[DataProvider('exacts')]
    public function anExactNumberIsItsUnitsOverTenToTheScale(string $text, string $units, int $scale, string $shown): void
    {
        $exact = Exact::fromString($text);
        self::assertSame([$units, $scale, $shown], [$exact->units, $exact->scale, (string) $exact]);
    }

    /**
     * Exact numbers, each its text, units, scale and how it is shown.
     *
     * @return iterable<string, array{string, string, int, string}>
     */
    public static function exacts(): iterable
    {
        yield 'a fraction' => ['-12.340', '-12340', 3, '-12.340'];
        yield 'under one' => ['-0.005', '-5', 3, '-0.005'];
        yield 'whole' => ['+42', '42', 0, '42'];
        yield 'a point alone after' => ['7.', '7', 0, '7'];
        yield 'a point first' => ['.5', '5', 1, '0.5'];
        yield 'minus zero' => ['-0.00', '0', 2, '0.00'];
        yield 'the greatest units' => ['17014118346046923173168730371588410572.7', '170141183460469231731687303715884105727', 1, '17014118346046923173168730371588410572.7'];
    }

    /**
     * Verifies: [PROTOCOL Values] [DESIGN] D8: text that is no exact
     * number, units past an i128 and a scale past a u32 are refused
     * (SQLSTATE 22003); a scale far past its digits is shown with an
     * exponent.
     *
     * @throws TurborelException never from the number in range
     */
    #[Test]
    public function anExactNumberOutOfRangeIsRefused(): void
    {
        foreach (['', '.', '1e3', '1,5', '170141183460469231731687303715884105728'] as $text) {
            self::assertFailure(ValueFailure::OUT_OF_RANGE, ValueFailure::DATA, static fn(): Exact => Exact::fromString($text));
        }
        self::assertFailure(ValueFailure::OUT_OF_RANGE, ValueFailure::DATA, static fn(): Exact => new Exact('1', -1));
        self::assertFailure(ValueFailure::OUT_OF_RANGE, ValueFailure::DATA, static fn(): Exact => new Exact('1', 0x1_0000_0000));
        self::assertSame('-5E-2000', (string) new Exact('-5', 2000));
    }

    /**
     * Verifies: [PROTOCOL Values]: a decimal floating-point number is kept
     * in turborel's one form, its trailing zeros in its exponent, at most
     * 34 digits and decimal128's exponents, and shown plain.
     *
     * @throws TurborelException never from those in range
     */
    #[Test]
    public function aDecFloatIsKeptInItsOneForm(): void
    {
        $value = new DecFloat('-1500', -2);
        self::assertSame(['-15', 0, '-15'], [$value->coefficient, $value->exponent, (string) $value]);
        self::assertSame(['0', 0, '0'], [(new DecFloat('0', 99))->coefficient, (new DecFloat('0', 99))->exponent, (string) new DecFloat('0', 5)]);
        self::assertSame('-0.000000015', (string) new DecFloat('-15', -9));
        self::assertSame('15' . str_repeat('0', 40), (string) new DecFloat('15', 40));
        self::assertSame(DecFloat::MAX_EXPONENT, (new DecFloat('1', DecFloat::MAX_EXPONENT))->exponent);
        foreach ([['1', DecFloat::MAX_EXPONENT + 1], ['1', DecFloat::MIN_EXPONENT - 1], [str_repeat('1', 35), 0], ['1.5', 0]] as [$coefficient, $exponent]) {
            self::assertFailure(ValueFailure::OUT_OF_RANGE, ValueFailure::DATA, static fn(): DecFloat => new DecFloat($coefficient, $exponent));
        }
    }

    /**
     * Verifies: [PROTOCOL Values] [DESIGN] D8: a datetime's precision is a
     * u8 and its displacement an i16's; an interval's precisions u32s and
     * its units an i128; a character large object's octets UTF-8; a UUID
     * 16 octets, its text 32 hexadecimal digits in groups.
     *
     * @throws TurborelException never from those in range
     */
    #[Test]
    public function fieldsOfValuesAreCheckedAsTheyAreMade(): void
    {
        $refused = [
            [ValueFailure::OUT_OF_RANGE, static fn(): Datetime => new Datetime(Moment::Date, 0, 0, false, 256)],
            [ValueFailure::OUT_OF_RANGE, static fn(): Datetime => new Datetime(Moment::Date, 0, -32_769)],
            [ValueFailure::OUT_OF_RANGE, static fn(): Qualifier => new Qualifier(IntervalField::Day, IntervalField::Day, -1)],
            [ValueFailure::OUT_OF_RANGE, static fn(): Interval => new Interval(new Qualifier(IntervalField::Day, IntervalField::Day), '1.5')],
            [ValueFailure::OUT_OF_RANGE, static fn(): Uuid => new Uuid('short')],
            [ValueFailure::OUT_OF_RANGE, static fn(): Uuid => Uuid::fromText('919108f7-52d1-4320-9bac-f847db4148a')],
            [ValueFailure::NOT_IN_REPERTOIRE, static fn(): Lob => new Lob(LobKind::Character, "\xFF")],
        ];
        foreach ($refused as [$sqlState, $attempt]) {
            self::assertFailure($sqlState, ValueFailure::DATA, $attempt);
        }
        $text = '919108f7-52d1-4320-9bac-f847db4148a8';
        self::assertSame($text, (string) Uuid::fromText(strtoupper($text)));
        self::assertSame("\xFF", (new Lob(LobKind::Binary, "\xFF"))->octets);
    }
}

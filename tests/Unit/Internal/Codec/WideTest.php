<?php

declare(strict_types=1);

namespace Turborel\Tests\Unit\Internal\Codec;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Turborel\Internal\Codec\Wide;
use Turborel\Internal\ValueFailure;
use Turborel\Tests\Support\CodecTestCase;
use Turborel\TurborelException;

/**
 * The protocol's wide integers, an i128 and a u64, as the strings of their
 * digits, converted exactly at every edge.
 */
#[CoversClass(Wide::class)]
#[CoversClass(ValueFailure::class)]
#[CoversClass(TurborelException::class)]
final class WideTest extends CodecTestCase
{
    /**
     * Verifies: [PROTOCOL Values and fields]: an i128 is 16 bytes, two's
     * complement, least significant first; its whole range round-trips
     * through its digits, the least and the greatest among them.
     *
     * @throws TurborelException never, every value being an i128's
     */
    #[Test]
    #[DataProvider('i128s')]
    public function anI128RoundTripsThroughItsDigits(string $digits, string $bytes): void
    {
        self::assertSame($bytes, bin2hex(Wide::i128ToBytes($digits)));
        self::assertSame($digits, Wide::i128FromBytes((string) hex2bin($bytes)));
    }

    /**
     * Edges of an i128, each its digits and its bytes.
     *
     * @return iterable<string, array{string, string}>
     */
    public static function i128s(): iterable
    {
        yield 'zero' => ['0', str_repeat('00', 16)];
        yield 'one' => ['1', '01' . str_repeat('00', 15)];
        yield 'minus one' => ['-1', str_repeat('ff', 16)];
        yield 'greatest' => [Wide::I128_MAX, str_repeat('ff', 15) . '7f'];
        yield 'least' => [Wide::I128_MIN, str_repeat('00', 15) . '80'];
        yield 'past 64 bits' => ['18446744073709551616', '0000000000000000' . '01' . str_repeat('00', 7)];
        yield 'a billion' => ['1000000000', '00ca9a3b' . str_repeat('00', 12)];
        yield 'minus 2^64' => ['-18446744073709551616', '0000000000000000' . 'ff' . str_repeat('ff', 7)];
    }

    /**
     * Verifies: [PROTOCOL Values and fields] [DESIGN] D8: a number past an
     * i128, or text that is no integer, is refused with SQLSTATE 22003,
     * never wrapped.
     */
    #[Test]
    #[DataProvider('notI128s')]
    public function aNumberPastAnI128IsRefused(string $digits): void
    {
        self::assertFailure(ValueFailure::OUT_OF_RANGE, ValueFailure::DATA, static fn(): string => Wide::i128ToBytes($digits));
    }

    /**
     * What no i128 is.
     *
     * @return iterable<string, array{string}>
     */
    public static function notI128s(): iterable
    {
        yield 'greatest plus one' => ['170141183460469231731687303715884105728'];
        yield 'least minus one' => ['-170141183460469231731687303715884105729'];
        yield '2^128' => ['340282366920938463463374607431768211456'];
        yield 'a leading zero' => ['01'];
        yield 'minus zero' => ['-0'];
        yield 'a point' => ['1.5'];
        yield 'empty' => [''];
    }

    /**
     * Verifies: [PROTOCOL Values and fields] [DESIGN] D8: a u64 past
     * PHP_INT_MAX is the string of its digits, and round-trips; within it,
     * an int; a negative number or one past a u64 is refused.
     *
     * @throws TurborelException never from the u64s
     */
    #[Test]
    public function aU64PastPhpIntMaxIsItsDigits(): void
    {
        $largest = str_repeat("\xFF", 8);
        self::assertSame('18446744073709551615', Wide::u64FromBytes($largest));
        self::assertSame($largest, Wide::u64ToBytes('18446744073709551615'));
        self::assertSame(\PHP_INT_MAX, Wide::u64FromBytes(Wide::u64ToBytes(\PHP_INT_MAX)));
        self::assertSame("\x00\x00\x00\x00\x00\x00\x00\x80", Wide::u64ToBytes('9223372036854775808'));
        self::assertSame(str_repeat("\x00", 8), Wide::u64ToBytes('0'));
        self::assertFailure(ValueFailure::OUT_OF_RANGE, ValueFailure::DATA, static fn(): string => Wide::u64ToBytes(-1));
        self::assertFailure(ValueFailure::OUT_OF_RANGE, ValueFailure::DATA, static fn(): string => Wide::u64ToBytes('-1'));
        self::assertFailure(ValueFailure::OUT_OF_RANGE, ValueFailure::DATA, static fn(): string => Wide::u64ToBytes('18446744073709551616'));
    }

    /**
     * Verifies: [PROTOCOL Values and fields]: `i32 as u32` and `i64 as u64`
     * are a signed number's bits in an unsigned field of its size.
     *
     * @throws TurborelException never, the numbers being in range
     */
    #[Test]
    public function signedNumbersAreTheirBitsInUnsignedFields(): void
    {
        self::assertSame(-1, Wide::i32(0xFFFF_FFFF));
        self::assertSame(-0x8000_0000, Wide::i32(0x8000_0000));
        self::assertSame(0x7FFF_FFFF, Wide::i32(0x7FFF_FFFF));
        self::assertSame(\PHP_INT_MIN, Wide::i64FromBytes(Wide::i64ToBytes(\PHP_INT_MIN)));
        self::assertSame(-1, Wide::i64FromBytes(str_repeat("\xFF", 8)));
        self::assertSame("\x04\x03\x02\x01", Wide::u32ToBytes(0x0102_0304));
        self::assertFailure(ValueFailure::OUT_OF_RANGE, ValueFailure::DATA, static fn(): string => Wide::u32ToBytes(0x1_0000_0000));
    }
}

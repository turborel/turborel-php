<?php

declare(strict_types=1);

namespace Turborel\Tests\Unit\Internal\Codec;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Turborel\Internal\Codec\Reader;
use Turborel\Internal\Codec\Utf8;
use Turborel\Internal\Codec\Wide;
use Turborel\Internal\Codec\Writer;
use Turborel\Internal\ConnectionFailure;
use Turborel\Internal\ValueFailure;
use Turborel\Tests\Support\CodecTestCase;
use Turborel\TurborelException;

/**
 * The fields messages are made of, written and read back, and the fields
 * a reader must refuse.
 */
#[CoversClass(Reader::class)]
#[CoversClass(Writer::class)]
#[CoversClass(Wide::class)]
#[CoversClass(Utf8::class)]
#[CoversClass(ConnectionFailure::class)]
#[CoversClass(ValueFailure::class)]
#[CoversClass(TurborelException::class)]
final class FieldsTest extends CodecTestCase
{
    /**
     * Verifies: [PROTOCOL Values and fields]: every integer is
     * little-endian; a flag is 0 or 1; a string is its length in bytes
     * then its UTF-8; bytes their length then them; strings a list of
     * strings; a key 16 bytes with no length.
     *
     * @throws TurborelException never, every field being in range
     */
    #[Test]
    public function fieldsAreWrittenAndReadAsTheSpecLaysThemOut(): void
    {
        $key = str_repeat("\xAB", Reader::KEY);
        $bytes = self::writer()->u8(0xFE)->u32(0x0102_0304)->u64(0x0102_0304_0506_0708)->i64(-2)->i128('-2')
            ->flag(true)->flag(false)->string('ANN')->bytes("\x00\x01")->strings(['a', 'bc'])->raw($key)->bytesWritten();

        self::assertSame(self::hex(
            'FE 04 03 02 01 08 07 06 05 04 03 02 01 FE FF FF FF FF FF FF FF FE' . str_repeat(' FF', 15)
            . ' 01 00 03 00 00 00 41 4E 4E 02 00 00 00 00 01 02 00 00 00 01 00 00 00 61 02 00 00 00 62 63',
        ) . $key, $bytes);
        $reader = self::reader($bytes);
        self::assertSame([0xFE, 0x0102_0304, 0x0102_0304_0506_0708, -2, '-2'], [$reader->u8(), $reader->u32(), $reader->u64(), $reader->i64(), $reader->i128()]);
        self::assertSame([true, false, 'ANN', "\x00\x01", ['a', 'bc'], $key], [$reader->flag(), $reader->flag(), $reader->string(), $reader->bytes(), $reader->strings(), $reader->key()]);
        self::assertTrue($reader->atEnd());
        $reader->finish();
    }

    /**
     * Verifies: [PROTOCOL Values and fields] [PROTOCOL Frames]: a field cut
     * short, a flag neither 0 nor 1, a string not UTF-8, a count larger
     * than what follows, and bytes left after the last field each fail the
     * connection (SQLCODE -919, SQLSTATE 08006).
     *
     * @param \Closure(Reader): mixed $read what reads the field
     */
    #[Test]
    #[DataProvider('malformed')]
    public function aMalformedFieldFailsTheConnection(string $bytes, \Closure $read): void
    {
        self::assertConnectionFails(static fn(): mixed => $read(self::reader($bytes)));
    }

    /**
     * Fields a reader must refuse, each with what reads it.
     *
     * @return iterable<string, array{string, \Closure(Reader): mixed}>
     */
    public static function malformed(): iterable
    {
        yield 'a u32 cut short' => ["\x01\x02\x03", static fn(Reader $r): int => $r->u32()];
        yield 'an empty body' => ['', static fn(Reader $r): int => $r->u8()];
        yield 'a flag of 2' => ["\x02", static fn(Reader $r): bool => $r->flag()];
        yield 'a string not UTF-8' => ["\x02\x00\x00\x00\xC3\x28", static fn(Reader $r): string => $r->string()];
        yield 'bytes longer than the body' => ["\x05\x00\x00\x00abc", static fn(Reader $r): string => $r->bytes()];
        yield 'a count of strings past the body' => ["\xFF\xFF\xFF\xFF", static fn(Reader $r): array => $r->strings()];
        yield 'a key cut short' => [str_repeat("\x00", 15), static fn(Reader $r): string => $r->key()];
        yield 'a byte left over' => ["\x01\x00", static function (Reader $r): void {
            $r->u8();
            $r->finish();
        }];
    }

    /**
     * Verifies: [PROTOCOL Values and fields]: a count of four billion
     * strings with none after it fails as the body runs out, having
     * reserved nothing for the count.
     */
    #[Test]
    public function aFalseCountReservesNoMemory(): void
    {
        $before = memory_get_usage();
        self::assertConnectionFails(static fn(): array => self::reader("\xFF\xFF\xFF\xFF\x00\x00\x00\x00")->strings());
        self::assertLessThan(1 << 20, memory_get_usage() - $before);
    }

    /**
     * Verifies: [PROTOCOL Values and fields] [DESIGN] D8: a field the
     * program gives out of its range, or a string not UTF-8, is refused
     * before it is written (SQLSTATE 22003, 22021).
     */
    #[Test]
    public function aFieldOutOfRangeIsRefusedBeforeItIsWritten(): void
    {
        self::assertFailure(ValueFailure::OUT_OF_RANGE, ValueFailure::DATA, static fn(): Writer => self::writer()->u8(256));
        self::assertFailure(ValueFailure::OUT_OF_RANGE, ValueFailure::DATA, static fn(): Writer => self::writer()->u8(-1));
        self::assertFailure(ValueFailure::OUT_OF_RANGE, ValueFailure::DATA, static fn(): Writer => self::writer()->u32(-1));
        self::assertFailure(ValueFailure::NOT_IN_REPERTOIRE, ValueFailure::DATA, static fn(): Writer => self::writer()->string("\xFF"));
    }

    /**
     * Verifies: [PROTOCOL Values and fields]: a nested value or type past
     * sixteen levels fails the connection, as turborel's reader does.
     *
     * @throws TurborelException never at sixteen levels
     */
    #[Test]
    public function nestingPastSixteenLevelsFails(): void
    {
        $reader = self::reader('');
        $descend = static function (Reader $r, int $levels) use (&$descend): int {
            return $levels === 0 ? 0 : 1 + $r->nested(static fn(Reader $inner): int => $descend($inner, $levels - 1));
        };
        self::assertSame(Reader::MAX_NESTING, $descend($reader, Reader::MAX_NESTING));
        self::assertConnectionFails(static fn(): int => $descend($reader, Reader::MAX_NESTING + 1));
    }

    /**
     * Verifies: [PROTOCOL Values and fields]: a code point is written as
     * UTF-8 in one to four octets, as a `\u` escape's character is.
     */
    #[Test]
    public function codePointsAreWrittenAsUtf8(): void
    {
        self::assertSame(['A', "\u{E9}", "\u{20AC}", "\u{1F600}"], array_map(Utf8::encode(...), [0x41, 0xE9, 0x20AC, 0x1F600]));
    }
}

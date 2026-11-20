<?php

declare(strict_types=1);

namespace Turborel\Tests\Unit\Internal\Codec;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Turborel\Internal\Codec\FrameHeader;
use Turborel\Internal\ConnectionFailure;
use Turborel\Tests\Support\CodecTestCase;
use Turborel\TurborelException;

/**
 * The frame header, encoded and decoded byte for byte as the spec writes
 * it, and the headers a reader must refuse.
 */
#[CoversClass(FrameHeader::class)]
#[CoversClass(ConnectionFailure::class)]
#[CoversClass(TurborelException::class)]
final class FrameHeaderTest extends CodecTestCase
{
    /**
     * Verifies: [PROTOCOL Frames] [PROTOCOL A worked example]: a header is
     * the kind, then the body's length as a u32 least significant byte
     * first; the worked example's Hello, Ping and Query headers are read
     * and written back as the spec prints them.
     *
     * @param string $bytes  the header as the spec prints it
     * @param int    $kind   its kind
     * @param int    $length its body's length
     *
     * @throws TurborelException never, the headers being well formed
     */
    #[Test]
    #[DataProvider('workedExample')]
    public function headersOfTheWorkedExampleRoundTrip(string $bytes, int $kind, int $length): void
    {
        $header = FrameHeader::decode($bytes);

        self::assertSame($kind, $header->kind);
        self::assertSame($length, $header->length);
        self::assertSame($bytes, (new FrameHeader($kind, $length))->encode());
    }

    /**
     * The worked example's headers: Hello 'H' of 36 bytes, Ping 'G' of
     * none, Query 'Q' of 13 [PROTOCOL A worked example].
     *
     * @return iterable<string, array{string, int, int}>
     */
    public static function workedExample(): iterable
    {
        yield 'Hello' => ["\x48\x24\x00\x00\x00", 0x48, 36];
        yield 'Ping' => ["\x47\x00\x00\x00\x00", 0x47, 0];
        yield 'Query' => ["\x51\x0D\x00\x00\x00", 0x51, 13];
    }

    /**
     * Verifies: [PROTOCOL Frames] [PROTOCOL Values and fields]: every byte
     * of the length counts, least significant first, up to the largest u32.
     *
     * @throws TurborelException never, the headers being well formed
     */
    #[Test]
    public function theLengthIsLittleEndianToTheLargestU32(): void
    {
        $largest = new FrameHeader(0xFF, 0xFFFF_FFFF);
        self::assertSame("\xFF\xFF\xFF\xFF\xFF", $largest->encode());
        self::assertSame(0x0403_0201, FrameHeader::decode("\x00\x01\x02\x03\x04", 0x0403_0201)->length);
    }

    /**
     * Verifies: [PROTOCOL Frames]: a body longer than the reader takes
     * fails the connection (SQLCODE -919, SQLSTATE 08006) from its header
     * alone; one exactly as long is taken.
     *
     * @throws TurborelException never from the header exactly as long
     */
    #[Test]
    public function aBodyPastTheLimitFailsTheConnection(): void
    {
        $header = (new FrameHeader(0x51, FrameHeader::MAX_BODY))->encode();
        self::assertSame(FrameHeader::MAX_BODY, FrameHeader::decode($header)->length);

        $tooLong = (new FrameHeader(0x51, FrameHeader::MAX_BODY + 1))->encode();
        self::assertConnectionFails(static fn(): FrameHeader => FrameHeader::decode($tooLong));
    }

    /**
     * Verifies: [PROTOCOL Frames]: a header cut short, or with a byte past
     * its five, fails the connection rather than being guessed at.
     *
     * @param string $bytes a header of the wrong length
     */
    #[Test]
    #[DataProvider('wrongLengths')]
    public function aHeaderOfTheWrongLengthFailsTheConnection(string $bytes): void
    {
        self::assertConnectionFails(static fn(): FrameHeader => FrameHeader::decode($bytes));
    }

    /**
     * Headers too short or too long.
     *
     * @return iterable<string, array{string}>
     */
    public static function wrongLengths(): iterable
    {
        yield 'empty' => [''];
        yield 'four bytes' => ["\x47\x00\x00\x00"];
        yield 'six bytes' => ["\x47\x00\x00\x00\x00\x00"];
    }

    /**
     * Verifies: [PROTOCOL Frames]: a kind is a u8 and a length a u32, so
     * a header of anything else is refused before a byte is written.
     *
     * @param int $kind   the kind
     * @param int $length the body's length
     */
    #[Test]
    #[DataProvider('outOfRange')]
    public function aHeaderNoFrameCarriesIsRefused(int $kind, int $length): void
    {
        self::assertConnectionFails(static fn(): FrameHeader => new FrameHeader($kind, $length));
    }

    /**
     * Kinds and lengths just past what their fields hold.
     *
     * @return iterable<string, array{int, int}>
     */
    public static function outOfRange(): iterable
    {
        yield 'negative kind' => [-1, 0];
        yield 'kind past a byte' => [0x100, 0];
        yield 'negative length' => [0x51, -1];
        yield 'length past a u32' => [0x51, 0x1_0000_0000];
    }

}

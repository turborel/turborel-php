<?php

declare(strict_types=1);

namespace Turborel\Tests\Unit\Internal\Codec;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\CoversNamespace;
use PHPUnit\Framework\Attributes\Test;
use Turborel\Internal\Codec\FrameHeader;
use Turborel\Internal\Codec\Frames;
use Turborel\Internal\Codec\Kind;
use Turborel\Internal\Codec\Request\Query;
use Turborel\Internal\ConnectionFailure;
use Turborel\Internal\ValueFailure;
use Turborel\Tests\Support\CodecTestCase;
use Turborel\TurborelException;

/**
 * Frames whole, and compressed frames, kind `z`: written past the
 * client's threshold where shorter, read within the length they declare.
 */
#[CoversNamespace('Turborel\Internal\Codec')]
#[CoversClass(ConnectionFailure::class)]
#[CoversClass(ValueFailure::class)]
#[CoversClass(TurborelException::class)]
final class FramesTest extends CodecTestCase
{
    /**
     * Verifies: [PROTOCOL Compressed frames]: a frame of at least the
     * threshold is sent as `z`, its kind, its body's length and a zlib
     * stream of its body, and reads back as the frame it holds; a shorter
     * one, or one compressing would not shorten, is sent as it is.
     *
     * @throws TurborelException never, zlib being loaded and the frames whole
     */
    #[Test]
    public function aFramePastTheThresholdIsCompressedWhereShorter(): void
    {
        $long = (new Query(str_repeat('SELECT 1; ', 200)))->encode();
        $short = (new Query('VALUES 1'))->encode();
        // Digests one after another do not compress: zlib's stream comes out longer.
        $random = Frames::frame(Kind::QUERY, implode('', array_map(static fn(int $i): string => hash('sha256', (string) $i, true), range(1, 128))));
        $sent = Frames::compress($long . $short . $random, Frames::DEFAULT_THRESHOLD);

        $header = FrameHeader::decode(substr($sent, 0, FrameHeader::SIZE));
        self::assertSame(Kind::COMPRESSED, $header->kind);
        self::assertLessThan(\strlen($long), FrameHeader::SIZE + $header->length);
        $held = substr($sent, FrameHeader::SIZE, $header->length);
        self::assertSame([Kind::QUERY, substr($long, FrameHeader::SIZE)], Frames::unwrap($header->kind, $held, true));
        self::assertSame("\x78", $held[5]);
        self::assertSame($short . $random, substr($sent, FrameHeader::SIZE + $header->length));
        self::assertSame($long, Frames::compress($long, \strlen($long) + 1));
        self::assertSame([Kind::PONG, ''], Frames::unwrap(Kind::PONG, '', false));
    }

    /**
     * Verifies: [PROTOCOL Compressed frames]: a `z` frame received when
     * compression was not granted, one holding another `z`, one declaring
     * more than the longest body taken, one inflating to another length or
     * past what it declares, and one that is no zlib stream or has bytes
     * after it, each fail the connection.
     *
     * @throws TurborelException never from writing the streams
     */
    #[Test]
    public function aMalformedCompressedFrameFailsTheConnection(): void
    {
        $stream = (string) zlib_encode('abcdef', \ZLIB_ENCODING_DEFLATE);
        $held = static fn(int $kind, int $length, string $data): string => (new FrameHeader($kind, $length))->encode() . $data;
        self::assertSame([Kind::ROWS, 'abcdef'], Frames::unwrap(Kind::COMPRESSED, $held(Kind::ROWS, 6, $stream), true));

        $refused = [
            'not granted' => [$held(Kind::ROWS, 6, $stream), false, FrameHeader::MAX_BODY],
            'a z in a z' => [$held(Kind::COMPRESSED, 6, $stream), true, FrameHeader::MAX_BODY],
            'past the body taken' => [$held(Kind::ROWS, 6, $stream), true, 5],
            'shorter than declared' => [$held(Kind::ROWS, 7, $stream), true, FrameHeader::MAX_BODY],
            'longer than declared' => [$held(Kind::ROWS, 5, $stream), true, FrameHeader::MAX_BODY],
            'no zlib stream' => [$held(Kind::ROWS, 6, 'abcdef'), true, FrameHeader::MAX_BODY],
            'bytes after the stream' => [$held(Kind::ROWS, 6, $stream . 'x'), true, FrameHeader::MAX_BODY],
            'a stream cut short' => [$held(Kind::ROWS, 6, substr($stream, 0, -2)), true, FrameHeader::MAX_BODY],
            'a prefix cut short' => ["\x42\x06", true, FrameHeader::MAX_BODY],
        ];
        foreach ($refused as [$body, $granted, $max]) {
            self::assertConnectionFails(static fn(): array => Frames::unwrap(Kind::COMPRESSED, $body, $granted, $max));
        }
    }

    /**
     * Verifies: [PROTOCOL Compressed frames]: a stream that would inflate
     * far past its declared length is refused before it is held whole.
     *
     * @throws TurborelException never from writing the stream
     */
    #[Test]
    public function aDecompressionBombIsRefusedAsItGrows(): void
    {
        $zeros = str_repeat("\x00", 32 << 20);
        $bomb = (string) zlib_encode($zeros, \ZLIB_ENCODING_DEFLATE);
        unset($zeros);
        memory_reset_peak_usage();
        $before = memory_get_peak_usage();
        self::assertConnectionFails(static fn(): array => Frames::unwrap(Kind::COMPRESSED, (new FrameHeader(Kind::ROWS, 1024))->encode() . $bomb, true));
        self::assertLessThan(8 << 20, memory_get_peak_usage() - $before);
    }

    /**
     * Verifies: [PROTOCOL Compressed frames]: frames to compress that are
     * cut short are refused rather than sent part way.
     */
    #[Test]
    public function framesCutShortAreNotCompressed(): void
    {
        self::assertConnectionFails(static fn(): string => Frames::compress("\x51\x05\x00\x00\x00ab"));
    }
}

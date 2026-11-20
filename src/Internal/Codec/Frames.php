<?php

declare(strict_types=1);

namespace Turborel\Internal\Codec;

use Turborel\Internal\ConnectionFailure;
use Turborel\TurborelException;

/**
 * Frames whole: a kind and a body framed by their header, and compressed
 * frames, kind `z`, held frame's kind, its body's length and a zlib
 * stream of the body, written where they come out shorter and read
 * within the length they declare [PROTOCOL Frames] [PROTOCOL Compressed frames].
 *
 * @internal
 */
final class Frames
{
    /** [DESIGN] The client's threshold, as turborel's: a frame shorter than this is never compressed. */
    public const int DEFAULT_THRESHOLD = 1024;

    /** The bytes a compressed frame's body takes before its stream: the held kind and length. */
    private const int PREFIX = 5;

    /**
     * The frame of kind `$kind` whose body is `$body`, its header first.
     *
     * @throws TurborelException SQLSTATE 08006 if the body is longer than a frame's length holds
     */
    public static function frame(int $kind, string $body): string
    {
        return (new FrameHeader($kind, \strlen($body)))->encode() . $body;
    }

    /**
     * `$frames`, whole frames one after another, each of at least
     * `$threshold` bytes, its header counted, compressed where that makes
     * it shorter, the others as they are.
     *
     * @throws TurborelException SQLSTATE 08006 if a frame is cut short, or zlib is not loaded
     */
    public static function compress(string $frames, int $threshold = self::DEFAULT_THRESHOLD): string
    {
        $out = '';
        $at = 0;
        while ($at < \strlen($frames)) {
            $header = FrameHeader::decode(substr($frames, $at, FrameHeader::SIZE), \PHP_INT_MAX);
            $body = substr($frames, $at + FrameHeader::SIZE, $header->length);
            if (\strlen($body) !== $header->length) {
                throw ConnectionFailure::because('a frame to compress is cut short');
            }
            $out .= self::shorter($header, $body, $threshold);
            $at += FrameHeader::SIZE + $header->length;
        }

        return $out;
    }

    /**
     * The frame of `$header` and `$body`, compressed if it is at least
     * `$threshold` bytes and comes out shorter, else as it is.
     *
     * @throws TurborelException SQLSTATE 08006 if zlib is not loaded
     */
    private static function shorter(FrameHeader $header, string $body, int $threshold): string
    {
        if (FrameHeader::SIZE + $header->length < $threshold) {
            return $header->encode() . $body;
        }
        $stream = self::zlib()->deflate($body);
        if (\strlen($stream) + self::PREFIX >= $header->length) {
            return $header->encode() . $body;
        }

        return self::frame(Kind::COMPRESSED, $header->encode() . $stream);
    }

    /**
     * The kind and body of the frame of kind `$kind` and body `$body`: of
     * a compressed frame, the frame it holds, inflated to no more than it
     * declares, itself at most `$maxBody`.
     *
     * @return array{int, string}
     *
     * @throws TurborelException SQLSTATE 08006 if a compressed frame was not granted, holds another, declares too much, or inflates to another length
     */
    public static function unwrap(int $kind, string $body, bool $compression, int $maxBody = FrameHeader::MAX_BODY): array
    {
        if ($kind !== Kind::COMPRESSED) {
            return [$kind, $body];
        }
        if (!$compression) {
            throw ConnectionFailure::because('a compressed frame came when compression was not granted');
        }
        $held = FrameHeader::decode(substr($body, 0, self::PREFIX), $maxBody);
        if ($held->kind === Kind::COMPRESSED) {
            throw ConnectionFailure::because('a compressed frame holds another');
        }
        $inflated = self::zlib()->inflate(substr($body, self::PREFIX), $held->length);
        if (\strlen($inflated) !== $held->length) {
            throw ConnectionFailure::because('a compressed frame inflates to another length than it declares');
        }

        return [$held->kind, $inflated];
    }

    /**
     * The zlib streams of PHP's zlib extension.
     *
     * @throws TurborelException SQLSTATE 08006 if it is not loaded
     */
    private static function zlib(): Zlib
    {
        return new Zlib();
    }
}

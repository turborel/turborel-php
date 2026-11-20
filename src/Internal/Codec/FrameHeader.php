<?php

declare(strict_types=1);

namespace Turborel\Internal\Codec;

use Turborel\Internal\ConnectionFailure;
use Turborel\TurborelException;

/**
 * A frame's header: the kind of message it carries, a u8, then the length
 * of its body, a u32 least significant byte first, the header not counted
 * [PROTOCOL Frames].
 *
 * Every message, either way, is a frame. The header is read before the
 * body, so a body longer than the reader takes is refused from the header
 * alone, before any of it is held.
 *
 * @internal
 */
final readonly class FrameHeader
{
    /** The header's bytes: the kind's one and the length's four [PROTOCOL Frames]. */
    public const int SIZE = 5;

    /** The longest body the client takes unless told otherwise: 16 MiB [PROTOCOL Frames]. */
    public const int MAX_BODY = 16 * 1024 * 1024;

    /** The largest kind: a kind is a u8 [PROTOCOL Frames]. */
    private const int MAX_KIND = 0xFF;

    /** The largest length: a length is a u32 [PROTOCOL Values and fields]. */
    private const int MAX_LENGTH = 0xFFFF_FFFF;

    /** pack()'s format of a header: an unsigned byte, then an unsigned 32-bit little-endian. */
    private const string PACK = 'CV';

    /** The bits of a byte, by which each byte of the length is shifted past the one before it. */
    private const int BYTE_BITS = 8;

    /** Where the length begins: after the kind's one byte. */
    private const int LENGTH_AT = 1;

    /**
     * The header of a frame of kind `$kind` whose body is `$length` bytes.
     *
     * @throws TurborelException SQLSTATE 08006 if the kind is not a u8 or
     *                           the length not a u32, since no frame could
     *                           carry them
     */
    public function __construct(
        /** The kind of message, read in the direction it travels [PROTOCOL Frames]. */
        public int $kind,
        /** The bytes of the body, the header not counted [PROTOCOL Frames]. */
        public int $length,
    ) {
        if ($kind < 0 || $kind > self::MAX_KIND) {
            throw ConnectionFailure::because("a frame's kind is a byte, not {$kind}");
        }
        if ($length < 0 || $length > self::MAX_LENGTH) {
            throw ConnectionFailure::because("a frame's body cannot be {$length} bytes long");
        }
    }

    /**
     * Reads a header from its five bytes, `$bytes`, taking a body of at
     * most `$maxBody` bytes.
     *
     * @throws TurborelException SQLSTATE 08006 if `$bytes` is not five
     *                           bytes long, or the body would be longer
     *                           than `$maxBody` [PROTOCOL Frames]
     */
    public static function decode(string $bytes, int $maxBody = self::MAX_BODY): self
    {
        if (\strlen($bytes) !== self::SIZE) {
            throw ConnectionFailure::because('a frame\'s header is ' . self::SIZE . ' bytes, not ' . \strlen($bytes));
        }
        $length = 0;
        // Least significant byte first: read from the last byte back.
        for ($at = self::SIZE - 1; $at >= self::LENGTH_AT; $at--) {
            $length = ($length << self::BYTE_BITS) | \ord($bytes[$at]);
        }
        if ($length > $maxBody) {
            throw ConnectionFailure::because("a message of {$length} bytes is longer than the {$maxBody} taken");
        }

        return new self(\ord($bytes[0]), $length);
    }

    /** The header's five bytes, as a frame begins with them [PROTOCOL Frames]. */
    public function encode(): string
    {
        return pack(self::PACK, $this->kind, $this->length);
    }
}

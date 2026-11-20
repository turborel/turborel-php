<?php

declare(strict_types=1);

namespace Turborel\Internal\Codec;

use Turborel\Internal\ValueFailure;
use Turborel\TurborelException;

/**
 * Writes a message's fields in order, little-endian, as the spec writes
 * them [PROTOCOL Values and fields].
 *
 * @internal
 */
final class Writer
{
    /** The largest u8. */
    private const int MAX_U8 = 0xFF;

    /** The bytes written so far. */
    private string $bytes = '';

    /** The bytes written. */
    public function bytesWritten(): string
    {
        return $this->bytes;
    }

    /**
     * A u8.
     *
     * @throws TurborelException SQLSTATE 22003 if `$value` is not a u8
     */
    public function u8(int $value): self
    {
        if ($value < 0 || $value > self::MAX_U8) {
            throw ValueFailure::outOfRange("{$value} is not a u8");
        }
        $this->bytes .= \chr($value);

        return $this;
    }

    /**
     * A u32.
     *
     * @throws TurborelException SQLSTATE 22003 if `$value` is not a u32
     */
    public function u32(int $value): self
    {
        $this->bytes .= Wide::u32ToBytes($value);

        return $this;
    }

    /**
     * A u64, an int or a string of its digits.
     *
     * @throws TurborelException SQLSTATE 22003 if `$value` is not a u64
     */
    public function u64(int|string $value): self
    {
        $this->bytes .= Wide::u64ToBytes($value);

        return $this;
    }

    /** An i64, as a u64 of its bits. */
    public function i64(int $value): self
    {
        $this->bytes .= Wide::i64ToBytes($value);

        return $this;
    }

    /**
     * An i128 of the digits `$digits`.
     *
     * @throws TurborelException SQLSTATE 22003 if they are no i128
     */
    public function i128(string $digits): self
    {
        $this->bytes .= Wide::i128ToBytes($digits);

        return $this;
    }

    /** A flag: 1 if `$set`, else 0. */
    public function flag(bool $set): self
    {
        $this->bytes .= $set ? "\x01" : "\x00";

        return $this;
    }

    /**
     * Bytes: their length, then them.
     *
     * @throws TurborelException SQLSTATE 22003 if they are longer than a u32 counts
     */
    public function bytes(string $bytes): self
    {
        $this->u32(\strlen($bytes));
        $this->bytes .= $bytes;

        return $this;
    }

    /**
     * A string: its length in bytes, then its UTF-8.
     *
     * @throws TurborelException SQLSTATE 22021 if it is not UTF-8
     */
    public function string(string $text): self
    {
        if (!Utf8::valid($text)) {
            throw ValueFailure::notInRepertoire('a string is not UTF-8');
        }

        return $this->bytes($text);
    }

    /**
     * Strings: their count, then each.
     *
     * @param list<string> $texts
     *
     * @throws TurborelException SQLSTATE 22021 if one is not UTF-8
     */
    public function strings(array $texts): self
    {
        $this->u32(\count($texts));
        foreach ($texts as $text) {
            $this->string($text);
        }

        return $this;
    }

    /** Bytes as they are, with no length: a key, or a body already written. */
    public function raw(string $bytes): self
    {
        $this->bytes .= $bytes;

        return $this;
    }
}

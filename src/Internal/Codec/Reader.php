<?php

declare(strict_types=1);

namespace Turborel\Internal\Codec;

use Turborel\Internal\ConnectionFailure;
use Turborel\TurborelException;

/**
 * Reads a received message's fields in order, little-endian, as the spec
 * writes them [PROTOCOL Values and fields].
 *
 * Whatever is malformed, a field cut short, a flag neither 0 nor 1, a
 * string not UTF-8, fails the connection, never guessed at. A count is
 * never trusted to reserve memory: what is held grows only with what is
 * read, so a count larger than what follows fails as the body runs out.
 *
 * @internal
 */
final class Reader
{
    /** The bytes of a u32. */
    private const int U32 = 4;

    /** The bytes of a u64 or an i64. */
    private const int U64 = 8;

    /** The bytes of an i128. */
    private const int I128 = 16;

    /** The bytes of a key, which no length goes before [PROTOCOL Values and fields]. */
    public const int KEY = 16;

    /** [DESIGN] The deepest arrays, rows, multisets and structured values nest, as turborel reads them. */
    public const int MAX_NESTING = 16;

    /** Where the next field begins. */
    private int $at = 0;

    /** How many nested values or types are being read. */
    private int $depth = 0;

    /**
     * A reader of `$bytes`, a received `$what`, as "reply", named in its failures.
     */
    public function __construct(
        private readonly string $bytes,
        private readonly string $what,
    ) {}

    /**
     * The failure of a malformed message, saying where and `$why`.
     */
    public function malformed(string $why): TurborelException
    {
        return ConnectionFailure::because("a {$this->what} received is malformed at byte {$this->at}: {$why}");
    }

    /**
     * The next `$count` bytes.
     *
     * @throws TurborelException SQLSTATE 08006 if fewer are left
     */
    public function take(int $count): string
    {
        if ($count < 0 || $count > \strlen($this->bytes) - $this->at) {
            throw $this->malformed('it ends too soon');
        }
        $taken = substr($this->bytes, $this->at, $count);
        $this->at += $count;

        return $taken;
    }

    /**
     * A u8.
     *
     * @throws TurborelException SQLSTATE 08006 if the body has ended
     */
    public function u8(): int
    {
        return \ord($this->take(1));
    }

    /**
     * A u32.
     *
     * @throws TurborelException SQLSTATE 08006 if the body ends within it
     */
    public function u32(): int
    {
        return Wide::u32FromBytes($this->take(self::U32));
    }

    /**
     * A u64: an int, or a string of its digits past PHP_INT_MAX (RULES.md R-NUM-1).
     *
     * @throws TurborelException SQLSTATE 08006 if the body ends within it
     */
    public function u64(): int|string
    {
        return Wide::u64FromBytes($this->take(self::U64));
    }

    /**
     * An i64 written as a u64, its bits read back as signed.
     *
     * @throws TurborelException SQLSTATE 08006 if the body ends within it
     */
    public function i64(): int
    {
        return Wide::i64FromBytes($this->take(self::U64));
    }

    /**
     * An i128, as a string of its digits.
     *
     * @throws TurborelException SQLSTATE 08006 if the body ends within it
     */
    public function i128(): string
    {
        return Wide::i128FromBytes($this->take(self::I128));
    }

    /**
     * A flag: 0 or 1, anything else failing the connection [PROTOCOL Values and fields].
     *
     * @throws TurborelException SQLSTATE 08006 if it is neither
     */
    public function flag(): bool
    {
        return match ($this->u8()) {
            0 => false,
            1 => true,
            default => throw $this->malformed('a flag is neither 0 nor 1'),
        };
    }

    /**
     * Bytes: their length, a u32, then them.
     *
     * @throws TurborelException SQLSTATE 08006 if the body ends within them
     */
    public function bytes(): string
    {
        return $this->take($this->u32());
    }

    /**
     * A string: its length in bytes, then its UTF-8.
     *
     * @throws TurborelException SQLSTATE 08006 if it is cut short or not UTF-8
     */
    public function string(): string
    {
        $text = $this->bytes();
        if (!Utf8::valid($text)) {
            throw $this->malformed('a string is not UTF-8');
        }

        return $text;
    }

    /**
     * Strings: their count, then each.
     *
     * @return list<string>
     *
     * @throws TurborelException SQLSTATE 08006 if one is malformed
     */
    public function strings(): array
    {
        $all = [];
        for ($count = $this->u32(); $count > 0; $count--) {
            $all[] = $this->string();
        }

        return $all;
    }

    /**
     * A key: 16 bytes, with no length before them.
     *
     * @throws TurborelException SQLSTATE 08006 if it is cut short
     */
    public function key(): string
    {
        return $this->take(self::KEY);
    }

    /**
     * What `$read` reads one level deeper, failing past MAX_NESTING levels.
     *
     * @template T
     *
     * @param \Closure(self): T $read
     *
     * @return T
     *
     * @throws TurborelException SQLSTATE 08006 if nested too deep, or as `$read` fails
     */
    public function nested(\Closure $read): mixed
    {
        if ($this->depth >= self::MAX_NESTING) {
            throw $this->malformed('its arrays and rows are nested too deep');
        }
        $this->depth++;
        try {
            return $read($this);
        } finally {
            $this->depth--;
        }
    }

    /** Whether every byte has been read: the tail ends at the body's end [PROTOCOL The tail]. */
    public function atEnd(): bool
    {
        return $this->at >= \strlen($this->bytes);
    }

    /**
     * That every byte was read [PROTOCOL Frames].
     *
     * @throws TurborelException SQLSTATE 08006 if bytes are left over
     */
    public function finish(): void
    {
        if (!$this->atEnd()) {
            throw $this->malformed('bytes follow its end');
        }
    }
}

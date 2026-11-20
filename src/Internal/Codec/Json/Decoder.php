<?php

declare(strict_types=1);

namespace Turborel\Internal\Codec\Json;

use Turborel\Internal\Codec\Utf8;
use Turborel\Internal\Codec\Wide;
use Turborel\Internal\ValueFailure;
use Turborel\TurborelException;
use Turborel\Value\Datetime;
use Turborel\Value\DecFloat;
use Turborel\Value\Exact;
use Turborel\Value\Moment;

/**
 * A JSON value's stored form (Node) checked whole and read: each node
 * within the document, every string UTF-8, every offset an element's or a
 * member's, an object's directory sorted by key and listing each member
 * once, as turborel checks a value it reads (its `binary::validate`).
 *
 * Damaged octets fail with SQLSTATE 22032, never a guess; the value codec
 * makes that a lost connection when the octets came from the server.
 *
 * @internal
 */
final class Decoder
{
    /** unpack()'s format of an f64, little-endian. */
    private const string F64 = 'e';

    /** unpack()'s format of an i16, as an unsigned 16-bit little-endian. */
    private const string U16 = 'v';

    /** The sign bit of an i16. */
    private const int I16_SIGN = 0x8000;

    /** The values of an i16's bits. */
    private const int I16_RANGE = 0x1_0000;

    /** The digit from which a dropped digit rounds up, or to even. */
    private const string HALF = '5';

    /** The moments of a datetime node, by its moment octet. */
    private const array MOMENTS = [Moment::Date, Moment::Time, Moment::Timestamp];

    /** A document's octets. */
    private function __construct(private readonly string $bytes) {}

    /**
     * The item the document `$bytes` holds: null, a bool, an int, an
     * Exact, a DecFloat, a float, a string, a Datetime, a list, or a
     * JsonObject.
     *
     * @throws TurborelException SQLSTATE 22032 if its octets are damaged
     */
    public static function decode(string $bytes): mixed
    {
        if (\strlen($bytes) < 2 || \ord($bytes[0]) !== Node::VERSION) {
            throw self::damaged('it does not begin with its format\'s version');
        }
        [$item, $length] = (new self($bytes))->node(1, 0);
        if ($length + 1 !== \strlen($bytes)) {
            throw self::damaged('octets follow its root');
        }

        return $item;
    }

    /** The failure of damaged octets, saying `$why`. */
    private static function damaged(string $why): TurborelException
    {
        return ValueFailure::invalidJson("a JSON value's octets are damaged: {$why}");
    }

    /**
     * The item of the node at `$at`, `$depth` arrays and objects in, and the octets it takes.
     *
     * @return array{mixed, int}
     *
     * @throws TurborelException SQLSTATE 22032 if it is damaged
     */
    private function node(int $at, int $depth): array
    {
        if ($depth > Node::MAX_NESTING) {
            throw self::damaged('arrays and objects nest too deep');
        }
        $body = $at + 1;

        return match (\ord($this->slice($at, 1))) {
            Node::NULL => [null, 1],
            Node::FALSE => [false, 1],
            Node::TRUE => [true, 1],
            Node::INTEGER => [$this->long($body), 1 + Node::LONG],
            Node::DECIMAL => [new Exact((string) $this->long($body), \ord($this->slice($body + Node::LONG, 1))), 2 + Node::LONG],
            Node::WIDE => [$this->wide($body), 1 + Node::WIDE_WORD + Node::WORD],
            Node::DECFLOAT => [$this->decFloat($body), 1 + Node::WIDE_WORD + Node::WORD],
            Node::APPROXIMATE => [$this->float($body), 1 + Node::LONG],
            Node::STRING => $this->string($at),
            Node::DATETIME => [$this->datetime($body), 1 + Node::DATETIME_BODY],
            Node::ARRAY => $this->array($at, $depth),
            Node::OBJECT => $this->object($at, $depth),
            default => throw self::damaged('a node\'s tag is no kind\'s'),
        };
    }

    /**
     * The `$count` octets at `$at`.
     *
     * @throws TurborelException SQLSTATE 22032 if they run past the document
     */
    private function slice(int $at, int $count): string
    {
        if ($count < 0 || $at + $count > \strlen($this->bytes)) {
            throw self::damaged('a node runs past its document');
        }

        return substr($this->bytes, $at, $count);
    }

    /**
     * The u32 at `$at`.
     *
     * @throws TurborelException SQLSTATE 22032 if it runs past the document
     */
    private function word(int $at): int
    {
        return Wide::u32FromBytes($this->slice($at, Node::WORD));
    }

    /**
     * The i64 at `$at`.
     *
     * @throws TurborelException SQLSTATE 22032 if it runs past the document
     */
    private function long(int $at): int
    {
        return Wide::i64FromBytes($this->slice($at, Node::LONG));
    }

    /**
     * The wide decimal whose body is at `$at`.
     *
     * @throws TurborelException SQLSTATE 22032 if it runs past the document
     */
    private function wide(int $at): Exact
    {
        $units = Wide::i128FromBytes($this->slice($at, Node::WIDE_WORD));

        return new Exact($units, $this->word($at + Node::WIDE_WORD));
    }

    /**
     * The approximate number whose bits are at `$at`.
     *
     * @throws TurborelException SQLSTATE 22032 if it runs past the document
     */
    private function float(int $at): float
    {
        $value = unpack(self::F64, $this->slice($at, Node::LONG));

        return \is_array($value) && \is_float($value[1] ?? null) ? $value[1] : 0.0;
    }

    /**
     * The string node at `$at`, and the octets it takes.
     *
     * @return array{string, int}
     *
     * @throws TurborelException SQLSTATE 22032 if it is cut short or not UTF-8
     */
    private function string(int $at): array
    {
        $text = $this->text($at + 1);

        return [$text, 1 + Node::WORD + \strlen($text)];
    }

    /**
     * The UTF-8 string whose length is at `$at`.
     *
     * @throws TurborelException SQLSTATE 22032 if it is cut short or not UTF-8
     */
    private function text(int $at): string
    {
        $text = $this->slice($at + Node::WORD, $this->word($at));
        if (!Utf8::valid($text)) {
            throw self::damaged('a string is not UTF-8');
        }

        return $text;
    }

    /**
     * The datetime whose body is at `$at`.
     *
     * @throws TurborelException SQLSTATE 22032 if its moment or zoned flag is none
     */
    private function datetime(int $at): Datetime
    {
        $body = $this->slice($at, Node::DATETIME_BODY);
        $moment = self::MOMENTS[\ord($body[0])] ?? throw self::damaged('a datetime\'s moment is no moment');
        $zoned = match (\ord($body[11])) {
            0 => false,
            1 => true,
            default => throw self::damaged('a datetime\'s zoned flag is neither 0 nor 1'),
        };
        $zone = unpack(self::U16, substr($body, 1 + Node::LONG, 2));
        $zone = \is_array($zone) && \is_int($zone[1] ?? null) ? $zone[1] : 0;
        $displacement = $zone >= self::I16_SIGN ? $zone - self::I16_RANGE : $zone;

        return new Datetime($moment, Wide::i64FromBytes(substr($body, 1, Node::LONG)), $displacement, $zoned, \ord($body[12]));
    }

    /**
     * The decimal floating-point number whose body is at `$at`, rounded
     * half to even to 34 digits as turborel reads one.
     *
     * @throws TurborelException SQLSTATE 22032 if it is out of range
     */
    private function decFloat(int $at): DecFloat
    {
        $coefficient = Wide::i128FromBytes($this->slice($at, Node::WIDE_WORD));
        $exponent = Wide::i32($this->word($at + Node::WIDE_WORD));
        try {
            return self::nearest($coefficient, $exponent);
        } catch (TurborelException) {
            throw self::damaged('a number is out of range');
        }
    }

    /**
     * `$coefficient` times ten to `$exponent`, rounded half to even to
     * DecFloat's 34 digits.
     *
     * @throws TurborelException SQLSTATE 22003 if it is out of range
     */
    public static function nearest(string $coefficient, int $exponent): DecFloat
    {
        $sign = str_starts_with($coefficient, '-') ? '-' : '';
        $digits = ltrim($coefficient, '-0');
        $excess = \strlen($digits) - DecFloat::MAX_DIGITS;
        if ($excess <= 0) {
            return new DecFloat($coefficient, $exponent);
        }
        $kept = substr($digits, 0, DecFloat::MAX_DIGITS);
        $dropped = substr($digits, DecFloat::MAX_DIGITS);
        $half = str_pad(self::HALF, $excess, '0');
        $odd = ((int) substr($kept, -1)) % 2 === 1;
        if (strcmp($dropped, $half) > 0 || ($dropped === $half && $odd)) {
            $kept = self::increment($kept);
        }

        return new DecFloat($sign . $kept, $exponent + $excess);
    }

    /** The digits `$digits` plus one. */
    private static function increment(string $digits): string
    {
        $at = \strlen($digits) - 1;
        while ($at >= 0 && $digits[$at] === '9') {
            $digits[$at] = '0';
            $at--;
        }

        return $at < 0 ? '1' . $digits : substr_replace($digits, (string) ((int) $digits[$at] + 1), $at, 1);
    }

    /**
     * The array node at `$at`, its elements one after another where their
     * offsets say, filling its size; and the octets it takes.
     *
     * @return array{list<mixed>, int}
     *
     * @throws TurborelException SQLSTATE 22032 if it is damaged
     */
    private function array(int $at, int $depth): array
    {
        [$count, $size, $first, $length] = $this->container($at);
        $elements = [];
        $expected = 0;
        for ($i = 0; $i < $count; $i++) {
            if ($this->word($at + 1 + Node::CONTAINER_HEAD + $i * Node::WORD) !== $expected) {
                throw self::damaged('an element\'s offset is not where it is');
            }
            [$elements[], $taken] = $this->node($first + $expected, $depth + 1);
            $expected += $taken;
        }
        if ($expected !== $size) {
            throw self::damaged('an array\'s size is not its elements\'');
        }

        return [$elements, $length];
    }

    /**
     * An array's or an object's count, size, where its first element or
     * member begins, and the octets it takes, all within the document.
     *
     * @return array{int, int, int, int}
     *
     * @throws TurborelException SQLSTATE 22032 if it runs past the document
     */
    private function container(int $at): array
    {
        [$count, $size] = [$this->word($at + 1), $this->word($at + 1 + Node::WORD)];
        $first = $at + 1 + Node::CONTAINER_HEAD + $count * Node::WORD;
        $length = $first - $at + $size;
        $this->slice($at, $length);

        return [$count, $size, $first, $length];
    }

    /**
     * The object node at `$at`, its members one after another filling its
     * size, its directory listing each once, sorted by key and then by
     * place; and the octets it takes.
     *
     * @return array{JsonObject, int}
     *
     * @throws TurborelException SQLSTATE 22032 if it is damaged
     */
    private function object(int $at, int $depth): array
    {
        [$count, $size, $first, $length] = $this->container($at);
        $members = [];
        $starts = [];
        $offset = 0;
        for ($i = 0; $i < $count; $i++) {
            $key = $this->text($first + $offset);
            $starts[$offset] = $key;
            [$value, $taken] = $this->node($first + $offset + Node::WORD + \strlen($key), $depth + 1);
            $members[] = [$key, $value];
            $offset += Node::WORD + \strlen($key) + $taken;
        }
        if ($offset !== $size) {
            throw self::damaged('an object\'s size is not its members\'');
        }
        $this->directory($at, $count, $starts);

        return [new JsonObject($members), $length];
    }

    /**
     * That the object at `$at`'s directory of `$count` offsets lists each
     * member of `$starts` once, sorted by key and then by place.
     *
     * @param array<int, string> $starts each member's key, by where it begins
     *
     * @throws TurborelException SQLSTATE 22032 if it does not
     */
    private function directory(int $at, int $count, array $starts): void
    {
        $previous = null;
        for ($i = 0; $i < $count; $i++) {
            $offset = $this->word($at + 1 + Node::CONTAINER_HEAD + $i * Node::WORD);
            $key = $starts[$offset] ?? null;
            $sorted = $key !== null && ($previous === null || strcmp($previous[0], $key) < 0
                || ($previous[0] === $key && $previous[1] < $offset));
            if (!$sorted) {
                throw self::damaged('an object\'s key directory is not sorted');
            }
            $previous = [$key, $offset];
        }
    }
}

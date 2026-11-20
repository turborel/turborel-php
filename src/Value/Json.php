<?php

declare(strict_types=1);

namespace Turborel\Value;

use Turborel\Internal\Codec\Json\Decoder;
use Turborel\Internal\Codec\Json\Encoder;
use Turborel\Internal\Codec\Json\JsonObject;
use Turborel\Internal\Codec\Json\Parser;
use Turborel\Internal\Codec\Json\Text;
use Turborel\TurborelException;

/**
 * A value of the JSON type: its stored form, the binary tree of turborel's
 * RULES.md D89, which the protocol carries as its body [PROTOCOL Values].
 * The driver checks it whole as it makes one, and reads it as JSON text or
 * as PHP's values.
 */
final readonly class Json implements SqlValue
{
    /**
     * The value whose stored form is `$bytes`, checked whole.
     *
     * @throws TurborelException SQLSTATE TB916 if they are damaged, 0A000 if they nest past 128 arrays and objects
     */
    public function __construct(
        /** Its stored form: a version octet, 1, then its root node. */
        public string $bytes,
    ) {
        Decoder::decode($bytes);
    }

    /**
     * The value of `$value`: null, a bool, an int, an Exact, a DecFloat, a
     * float, a string, a Datetime, a list as an array, an array of string
     * keys or a \stdClass as an object, a \JsonSerializable as what it
     * serializes to, or a Json.
     *
     * @throws TurborelException SQLSTATE 22032 if JSON has no item of it; 0A000 if it nests past 128 arrays and objects, or its stored form is past 256 MiB
     */
    public static function fromValue(mixed $value): self
    {
        return new self(Encoder::encode($value));
    }

    /**
     * The value JSON text `$text` writes, as turborel parses it: a number
     * with no exponent exact, one with an exponent approximate; an
     * object's members all kept, in order.
     *
     * @throws TurborelException SQLSTATE 22032 if it is no JSON text, 22003 if a number is beyond turborel's, 0A000 if it nests past 128
     */
    public static function fromText(string $text): self
    {
        return new self(Encoder::encode(Parser::parse($text)));
    }

    /**
     * Its JSON text, as turborel writes it, with no white space between tokens.
     *
     * @throws TurborelException SQLSTATE 22032 if it is or holds a datetime, or an approximate number not finite
     */
    public function text(): string
    {
        return Text::of(Decoder::decode($this->bytes));
    }

    /**
     * Its item as PHP's values: null, a bool, an int, an Exact, a DecFloat,
     * a float, a string, a Datetime, a list for an array, and a \stdClass
     * for an object, which keeps the first member of a key, as turborel's
     * accessors take it.
     *
     * @throws TurborelException never, its octets having been checked
     */
    public function value(): mixed
    {
        return self::plain(Decoder::decode($this->bytes));
    }

    /** `$item` with its objects as \stdClass. */
    private static function plain(mixed $item): mixed
    {
        if (\is_array($item)) {
            return array_map(self::plain(...), $item);
        }
        if (!$item instanceof JsonObject) {
            return $item;
        }
        // An array cast to an object, as a key may be empty, which a property set by name may not.
        $members = [];
        foreach ($item->members as [$key, $value]) {
            if (!\array_key_exists($key, $members)) {
                $members[$key] = self::plain($value);
            }
        }

        return (object) $members;
    }
}

<?php

declare(strict_types=1);

namespace Turborel\Internal\Codec\Json;

use Turborel\Internal\Codec\Utf8;
use Turborel\Internal\ValueFailure;
use Turborel\TurborelException;
use Turborel\Value\DecFloat;
use Turborel\Value\Exact;

/**
 * JSON text parsed to an item (the Encoder's), by RFC 8259's grammar, as
 * turborel parses it, WITHOUT UNIQUE KEYS: a number with no exponent is
 * exact, of at most 18 digits, or else a decimal floating-point number of
 * at most 34 significant digits; one with an exponent is approximate. A
 * number turborel holds no number of is SQLSTATE 22003; text that does not
 * parse, SQLSTATE 22032.
 *
 * @internal
 */
final class Parser
{
    /** The white space between tokens [RFC 8259 §2]. */
    private const string SPACE = " \t\n\r";

    /** A number [RFC 8259 §6]: its sign, whole digits, fraction and exponent. */
    private const string NUMBER = '/\G(-?)(0|[1-9][0-9]*)(?:\.([0-9]+))?([eE][+-]?[0-9]+)?/';

    /** A string's characters up to its next quotation mark or escape, none a control character. */
    private const string PLAIN = '/\G[^"\\\\\x00-\x1F]*/';

    /** The literal names [RFC 8259 §3] and their values. */
    private const array LITERALS = ['null' => null, 'true' => true, 'false' => false];

    /** The single-character escapes [RFC 8259 §7]. */
    private const array ESCAPES = ['"' => '"', '\\' => '\\', '/' => '/', 'b' => "\x08", 'f' => "\x0C", 'n' => "\n", 'r' => "\r", 't' => "\t"];

    /** A `\u` escape's four hexadecimal digits. */
    private const string HEX4 = '/\G[0-9a-fA-F]{4}/';

    /** The most digits of an exact number turborel parses from JSON: its MAX_EXACT_PRECISION. */
    private const int MAX_EXACT_DIGITS = 18;

    /** The first and last high surrogates of a UTF-16 pair. */
    private const array HIGH = [0xD800, 0xDBFF];

    /** The first and last low surrogates. */
    private const array LOW = [0xDC00, 0xDFFF];

    /** The first code point a surrogate pair counts from. */
    private const int SUPPLEMENTARY = 0x1_0000;

    /** The bits of a character each surrogate holds. */
    private const int SURROGATE_BITS = 10;

    /** Where the next character is. */
    private int $at = 0;

    /** The text parsed. */
    private function __construct(private readonly string $text) {}

    /**
     * The item `$text` is.
     *
     * @throws TurborelException SQLSTATE 22032 if it is no JSON text, 22003 if it has a number no number of turborel's is
     */
    public static function parse(string $text): mixed
    {
        if (!Utf8::valid($text)) {
            throw ValueFailure::invalidJson('JSON text is not UTF-8');
        }
        $parser = new self($text);
        $item = $parser->value(0);
        $parser->space();
        if ($parser->at !== \strlen($text)) {
            throw $parser->invalid('text follows the JSON value');
        }

        return $item;
    }

    /** The failure of text that does not parse, saying where and `$why`. */
    private function invalid(string $why): TurborelException
    {
        return ValueFailure::invalidJson("invalid JSON text at byte {$this->at}: {$why}");
    }

    /** Skips white space. */
    private function space(): void
    {
        $this->at += strspn($this->text, self::SPACE, $this->at);
    }

    /**
     * The next character, after white space, or none at the end.
     *
     * @phpstan-impure
     */
    private function peek(): string
    {
        $this->space();

        return $this->text[$this->at] ?? '';
    }

    /**
     * A value [RFC 8259 §3], `$depth` arrays and objects in.
     *
     * @throws TurborelException SQLSTATE 22032 if it does not parse, 0A000 if it opens a 129th array or object
     */
    private function value(int $depth): mixed
    {
        $opens = $this->peek() === '{' || $this->peek() === '[';
        if ($opens && $depth + 1 > Node::MAX_NESTING) {
            throw ValueFailure::beyondJson('arrays and objects nest too deep');
        }

        return match ($this->peek()) {
            '{' => $this->object($depth + 1),
            '[' => $this->array($depth + 1),
            '"' => $this->string(),
            default => $this->scalar(),
        };
    }

    /**
     * A literal name or a number.
     *
     * @throws TurborelException SQLSTATE 22032 if it is neither
     */
    private function scalar(): mixed
    {
        foreach (self::LITERALS as $name => $value) {
            if (substr_compare($this->text, $name, $this->at, \strlen($name)) === 0) {
                $this->at += \strlen($name);

                return $value;
            }
        }
        if (preg_match(self::NUMBER, $this->text, $parts, 0, $this->at) !== 1) {
            throw $this->invalid('no value begins here');
        }
        $this->at += \strlen($parts[0]);

        return self::number($parts[1], $parts[2], $parts[3] ?? '', $parts[4] ?? '');
    }

    /**
     * The number of the sign `$sign`, whole digits `$whole`, fraction
     * `$fraction` and exponent `$exponent`, as turborel takes it.
     *
     * @throws TurborelException SQLSTATE 22003 if no number of turborel's is it
     */
    private static function number(string $sign, string $whole, string $fraction, string $exponent): mixed
    {
        if ($exponent !== '') {
            return self::approximate($sign . $whole . ($fraction === '' ? '' : '.' . $fraction) . $exponent);
        }
        $digits = $whole . $fraction;
        $significant = rtrim($fraction, '0');
        $trimmed = ltrim($whole . $significant, '0');
        [$units, $scale] = self::fits($digits, $fraction) ? [$digits, \strlen($fraction)] : [$trimmed, \strlen($significant)];
        if (self::fits($units, '') && $scale <= self::MAX_EXACT_DIGITS) {
            return Exact::fromString($sign . self::point(ltrim($units, '0'), $scale));
        }
        if (\strlen(rtrim($trimmed, '0')) > DecFloat::MAX_DIGITS) {
            throw ValueFailure::outOfRange('the number has more than 34 significant digits');
        }

        return new DecFloat($sign . ($trimmed === '' ? '0' : $trimmed), -\strlen($significant));
    }

    /** Whether `$digits`, `$fraction` of them after the point, are few enough for an exact number. */
    private static function fits(string $digits, string $fraction): bool
    {
        return \strlen($digits) <= self::MAX_EXACT_DIGITS && \strlen($fraction) <= self::MAX_EXACT_DIGITS;
    }

    /** `$digits` with a point before the last `$scale` of them. */
    private static function point(string $digits, int $scale): string
    {
        $digits = str_pad($digits, $scale + 1, '0', \STR_PAD_LEFT);

        return $scale === 0 ? $digits : substr($digits, 0, -$scale) . '.' . substr($digits, -$scale);
    }

    /**
     * The approximate number `$text` writes.
     *
     * @throws TurborelException SQLSTATE 22003 if it is too great, or too small to be other than zero
     */
    private static function approximate(string $text): float
    {
        $value = (float) $text;
        if (!is_finite($value)) {
            throw ValueFailure::outOfRange('the number is too great for an approximate number');
        }
        $mantissa = preg_split('/[eE]/', $text)[0] ?? '';
        if ($value === 0.0 && strpbrk($mantissa, '123456789') !== false) {
            throw ValueFailure::outOfRange('the number is too small for an approximate number, but not zero');
        }

        return $value;
    }

    /**
     * An array [RFC 8259 §5].
     *
     * @return list<mixed>
     *
     * @throws TurborelException SQLSTATE 22032 if it does not parse
     */
    private function array(int $depth): array
    {
        $this->at++;
        $elements = [];
        if ($this->peek() === ']') {
            $this->at++;

            return $elements;
        }
        do {
            $elements[] = $this->value($depth);
        } while ($this->separator(']'));

        return $elements;
    }

    /**
     * An object [RFC 8259 §4], its members all kept, in order.
     *
     * @throws TurborelException SQLSTATE 22032 if it does not parse
     */
    private function object(int $depth): JsonObject
    {
        $this->at++;
        $members = [];
        if ($this->peek() === '}') {
            $this->at++;

            return new JsonObject($members);
        }
        do {
            if ($this->peek() !== '"') {
                throw $this->invalid('a member begins with its name');
            }
            $key = $this->string();
            if ($this->peek() !== ':') {
                throw $this->invalid('a member\'s name is followed by a colon');
            }
            $this->at++;
            $members[] = [$key, $this->value($depth)];
        } while ($this->separator('}'));

        return new JsonObject($members);
    }

    /**
     * Whether a comma comes next, another element or member following; else `$end`, which ends them.
     *
     * @throws TurborelException SQLSTATE 22032 if neither does
     */
    private function separator(string $end): bool
    {
        $next = $this->peek();
        $this->at++;
        if ($next === ',') {
            return true;
        }
        if ($next !== $end) {
            $this->at--;

            throw $this->invalid("a comma or '{$end}' comes next");
        }

        return false;
    }

    /**
     * A string [RFC 8259 §7], its escapes read.
     *
     * @throws TurborelException SQLSTATE 22032 if it does not parse
     */
    private function string(): string
    {
        $this->at++;
        $text = '';
        while (true) {
            preg_match(self::PLAIN, $this->text, $plain, 0, $this->at);
            $text .= $plain[0] ?? '';
            $this->at += \strlen($plain[0] ?? '');
            $next = $this->text[$this->at] ?? '';
            $this->at++;
            if ($next === '"') {
                return $text;
            }
            if ($next !== '\\') {
                $this->at--;

                throw $this->invalid('a string is not closed, or holds a control character');
            }
            $text .= $this->escape();
        }
    }

    /**
     * The character an escape after its `\` stands for.
     *
     * @throws TurborelException SQLSTATE 22032 if it is none
     */
    private function escape(): string
    {
        $next = $this->text[$this->at] ?? '';
        $this->at++;
        if ($next !== 'u') {
            return self::ESCAPES[$next] ?? throw $this->invalid('no escape is of this character');
        }
        $unit = $this->hex();
        if ($unit >= self::LOW[0] && $unit <= self::LOW[1]) {
            throw $this->invalid('a low surrogate stands alone');
        }
        if ($unit >= self::HIGH[0] && $unit <= self::HIGH[1]) {
            $unit = $this->paired($unit);
        }

        return Utf8::encode($unit);
    }

    /**
     * The character of the high surrogate `$high` and the `\u` escape of
     * the low one that must follow it.
     *
     * @throws TurborelException SQLSTATE 22032 if none follows
     */
    private function paired(int $high): int
    {
        if (substr($this->text, $this->at, 2) !== '\u') {
            throw $this->invalid('a high surrogate stands alone');
        }
        $this->at += 2;
        $low = $this->hex();
        if ($low < self::LOW[0] || $low > self::LOW[1]) {
            throw $this->invalid('a high surrogate is not followed by a low one');
        }

        return self::SUPPLEMENTARY + (($high - self::HIGH[0]) << self::SURROGATE_BITS) + ($low - self::LOW[0]);
    }

    /**
     * The code unit of a `\u` escape's four hexadecimal digits.
     *
     * @throws TurborelException SQLSTATE 22032 if they are not four
     */
    private function hex(): int
    {
        if (preg_match(self::HEX4, $this->text, $digits, 0, $this->at) !== 1) {
            throw $this->invalid('a \u escape has four hexadecimal digits');
        }
        $this->at += 4;

        return (int) hexdec($digits[0]);
    }
}

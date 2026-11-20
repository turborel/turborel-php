<?php

declare(strict_types=1);

namespace Turborel\Tests\Unit\Value;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\CoversNamespace;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Turborel\Internal\Codec\Json\Decoder;
use Turborel\Internal\Codec\Json\Node;
use Turborel\Internal\ValueFailure;
use Turborel\Tests\Support\CodecTestCase;
use Turborel\TurborelException;
use Turborel\Value\Datetime;
use Turborel\Value\DecFloat;
use Turborel\Value\Exact;
use Turborel\Value\Json;
use Turborel\Value\Moment;

/**
 * A JSON value's stored form, turborel's RULES.md D89, read and written
 * node by node, checked whole, and read as JSON text and PHP's values.
 */
#[CoversNamespace('Turborel\Internal\Codec')]
#[CoversNamespace('Turborel\Value')]
#[CoversClass(ValueFailure::class)]
#[CoversClass(TurborelException::class)]
final class JsonTest extends CodecTestCase
{
    /**
     * Verifies: [PROTOCOL Values]: each node is its tag and its body, an
     * exact number in the narrowest of its three nodes, the document a
     * version octet, 1, then its root.
     *
     * @throws TurborelException never, every value having an item
     */
    #[Test]
    #[DataProvider('nodes')]
    public function eachNodeIsItsTagThenItsBody(mixed $value, string $bytes): void
    {
        $json = Json::fromValue($value);
        self::assertSame(bin2hex(self::hex('01 ' . $bytes)), bin2hex($json->bytes));
        self::assertEquals($value, $json->value());
    }

    /**
     * Every node, each a value and its node's bytes.
     *
     * @return iterable<string, array{mixed, string}>
     *
     * @throws TurborelException never, every value being well formed
     */
    public static function nodes(): iterable
    {
        yield '0 null' => [null, '00'];
        yield '1 false' => [false, '01'];
        yield '2 true' => [true, '02'];
        yield '3 integer' => [7, '03 07 00 00 00 00 00 00 00'];
        yield '4 decimal' => [new Exact('125', 2), '04 7D 00 00 00 00 00 00 00 02'];
        yield '5 wide decimal, units past an i64' => [new Exact('9223372036854775808', 1), '05 00 00 00 00 00 00 00 80 ' . str_repeat('00 ', 8) . '01 00 00 00'];
        yield '5 wide decimal, scale past a u8' => [new Exact('1', 256), '05 01 ' . str_repeat('00 ', 15) . '00 01 00 00'];
        yield '6 decimal floating point' => [new DecFloat('15', -9), '06 0F ' . str_repeat('00 ', 15) . 'F7 FF FF FF'];
        yield '7 approximate' => [1.5, '07 00 00 00 00 00 00 F8 3F'];
        yield '8 string' => ['é', '08 02 00 00 00 C3 A9'];
        yield '9 datetime' => [new Datetime(Moment::Timestamp, 1, -60, true, 6), '09 02 01 00 00 00 00 00 00 00 C4 FF 01 06'];
        yield '10 array' => [[true, null], '0A 02 00 00 00 02 00 00 00 00 00 00 00 01 00 00 00 02 00'];
        yield '10 array, empty' => [[], '0A 00 00 00 00 00 00 00 00'];
    }

    /**
     * Verifies: [PROTOCOL Values]: an object's directory is sorted by key
     * octets, members of one key in the order written, and its members
     * stay in the order written, those of one key all, as JSON text reads
     * back.
     *
     * @throws TurborelException never, the text being JSON
     */
    #[Test]
    public function anObjectKeepsItsMembersAndSortsItsDirectory(): void
    {
        $json = Json::fromText('{"b":1,"a":2,"b":3}');
        $member = static fn(string $key, int $n): string => '01 00 00 00 ' . bin2hex($key) . " 03 0{$n} 00 00 00 00 00 00 00";
        self::assertSame(bin2hex(self::hex('01 0B 03 00 00 00 2A 00 00 00 0E 00 00 00 00 00 00 00 1C 00 00 00 '
            . $member('b', 1) . ' ' . $member('a', 2) . ' ' . $member('b', 3))), bin2hex($json->bytes));
        self::assertSame('{"b":1,"a":2,"b":3}', $json->text());
        self::assertEquals((object) ['b' => 1, 'a' => 2], $json->value());
    }

    /**
     * Verifies: [PROTOCOL Values]: JSON text is written as turborel writes
     * it: no white space between tokens, control characters, `"` and `\`
     * escaped and nothing else, an exact number's digits and its scale's,
     * an approximate number's shortest literal with an exponent, a
     * decimal floating-point number plain.
     *
     * @throws TurborelException never, the texts being JSON
     */
    #[Test]
    #[DataProvider('texts')]
    public function textIsWrittenAsTurborelWritesIt(string $text, string $written): void
    {
        self::assertSame($written, Json::fromText($text)->text());
    }

    /**
     * JSON texts, each as turborel writes it back.
     *
     * @return iterable<string, array{string, string}>
     */
    public static function texts(): iterable
    {
        yield 'white space' => [" [ 1 ,\n\t{ } ] ", '[1,{}]'];
        yield 'an empty array' => ['[ ]', '[]'];
        yield 'an exact number keeps its scale' => ['-12.50', '-12.50'];
        yield 'an approximate number' => ['1.5e0', '1.5E0'];
        yield 'an approximate zero' => ['-0e3', '0E0'];
        yield 'a small approximate number' => ['-1E-3', '-1.0E-3'];
        yield 'past eighteen digits, a DECFLOAT plain' => ['1234567890.123456789', '1234567890.123456789'];
        yield 'escapes' => ['"\u0000\b\f\n\r\t\"\\\\\/\u00e9\ud83d\ude00\u001f"', "\"\\u0000\\b\\f\\n\\r\\t\\\"\\\\/\u{E9}\u{1F600}\\u001f\""];
        yield 'literals' => ['[null,true,false]', '[null,true,false]'];
        yield 'an empty key' => ['{"":1}', '{"":1}'];
    }

    /**
     * Verifies: [PROTOCOL Values]: a number with no exponent is exact, of
     * at most 18 digits, else a decimal floating-point number of at most
     * 34 significant digits; one with an exponent is approximate; an exact
     * number of scale 0 an i64 holds is stored as an integer node, read
     * as an int.
     *
     * @throws TurborelException never, the texts being JSON
     */
    #[Test]
    public function numbersAreReadAsTurborelReadsThem(): void
    {
        self::assertEquals(new Exact('10', 2), Json::fromText('0.10')->value());
        self::assertSame(123456789012345678, Json::fromText('123456789012345678')->value());
        self::assertEquals(new DecFloat('1234567890123456789', -9), Json::fromText('1234567890.123456789')->value());
        self::assertEquals(new Exact('5', 1), Json::fromText('0.5000000000000000000000')->value());
        self::assertSame(100.0, Json::fromText('1E2')->value());
        self::assertSame(0.0, Json::fromText('0E-400')->value());
        self::assertSame(0, Json::fromText('-0')->value());
    }

    /**
     * Verifies: [PROTOCOL Values]: text that is no JSON is refused with
     * SQLSTATE 22032, and a number no number of turborel's is with 22003.
     */
    #[Test]
    #[DataProvider('invalid')]
    public function invalidTextIsRefused(string $text, string $sqlState): void
    {
        self::assertFailure($sqlState, ValueFailure::DATA, static fn(): Json => Json::fromText($text));
    }

    /**
     * Texts turborel refuses, each with its SQLSTATE.
     *
     * @return iterable<string, array{string, string}>
     */
    public static function invalid(): iterable
    {
        foreach (['', '[1,]', '{"a" 1}', '{1:2}', '01', "\"\x01\"", 'tru', '[1] x', '"\ud800"', '"\ud800A"', '"\udc00"', '"\q"', '"\u12"', '"open', "\"\xFF\"", '[1 2]'] as $text) {
            yield 'invalid ' . bin2hex($text) => [$text, ValueFailure::INVALID_JSON];
        }
        yield 'too great' => ['1E400', ValueFailure::OUT_OF_RANGE];
        yield 'too small, not zero' => ['1E-400', ValueFailure::OUT_OF_RANGE];
        yield '35 significant digits' => ['1234567890123456789012345678901234.5', ValueFailure::OUT_OF_RANGE];
    }

    /**
     * Verifies: [PROTOCOL Values]: damaged octets are refused, never read
     * as something else: another version, nothing after it, a byte after
     * the root, a tag of no node, a string not UTF-8, a node past the
     * document, an offset or a size not its elements', a directory not
     * sorted or not listing each member, a datetime's moment or zoned flag
     * of none (SQLSTATE TB916, SQLCODE -916, as turborel's Code::Corrupt).
     */
    #[Test]
    #[DataProvider('damaged')]
    public function damagedOctetsAreRefused(string $bytes): void
    {
        self::assertFailure(ValueFailure::CORRUPT, ValueFailure::CORRUPT_CODE, static fn(): Json => new Json(self::hex($bytes)));
    }

    /**
     * Damaged documents.
     *
     * @return iterable<string, array{string}>
     */
    public static function damaged(): iterable
    {
        yield 'version 2' => ['02 00'];
        yield 'nothing after the version' => ['01'];
        yield 'a byte after the root' => ['01 00 00'];
        yield 'tag 12' => ['01 0C'];
        yield 'a string not UTF-8' => ['01 08 01 00 00 00 FF'];
        yield 'an integer cut short' => ['01 03 00'];
        yield 'an offset not where it is' => ['01 0A 01 00 00 00 01 00 00 00 01 00 00 00 02'];
        yield 'a size not its elements\'' => ['01 0A 01 00 00 00 02 00 00 00 00 00 00 00 02 00'];
        yield 'a count past the document' => ['01 0A FF FF FF FF 00 00 00 00'];
        yield 'a directory not sorted' => ['01 0B 02 00 00 00 0C 00 00 00 06 00 00 00 00 00 00 00 01 00 00 00 61 00 01 00 00 00 62 00'];
        yield 'a directory naming no member' => ['01 0B 01 00 00 00 06 00 00 00 01 00 00 00 01 00 00 00 61 00'];
        yield 'an object\'s size not its members\'' => ['01 0B 01 00 00 00 07 00 00 00 00 00 00 00 01 00 00 00 61 00 00'];
        yield 'a member with no value' => ['01 0B 01 00 00 00 05 00 00 00 00 00 00 00 01 00 00 00 61'];
        yield 'a moment of 3' => ['01 09 03 00 00 00 00 00 00 00 00 00 00 00 00'];
        yield 'a zoned flag of 2' => ['01 09 02 00 00 00 00 00 00 00 00 00 00 02 00'];
        yield 'a DECFLOAT out of range' => ['01 06 01 ' . str_repeat('00 ', 15) . '01 18 00 00'];
    }

    /**
     * Verifies: [PROTOCOL JSON values]: the page's examples are written
     * octet for octet: `{"a":[1,"x"]}` in 51 octets, and `{"b":1,"a":2}`
     * with its members in that order and `a` first in its directory; a
     * decimal keeps the scale it was written with, in the narrowest of
     * the three exact nodes.
     *
     * @throws TurborelException never, the values being JSON's
     */
    #[Test]
    public function thePagesExamplesAreWrittenOctetForOctet(): void
    {
        $example = '01 0B 01 00 00 00 25 00 00 00 00 00 00 00 01 00 00 00 61 0A 02 00 00 00 0F 00 00 00 00 00 00 00 09 00 00 00 '
            . '03 01 00 00 00 00 00 00 00 08 01 00 00 00 78';
        self::assertSame(self::hex($example), Json::fromText('{"a":[1,"x"]}')->bytes);
        self::assertSame(51, \strlen(Json::fromText('{"a":[1,"x"]}')->bytes));
        $two = Json::fromText('{"b":1,"a":2}');
        self::assertSame(self::hex('0E 00 00 00 00 00 00 00'), substr($two->bytes, 10, 8));
        self::assertSame('{"b":1,"a":2}', $two->text());
        self::assertSame(self::hex('01 04 96 00 00 00 00 00 00 00 02'), Json::fromText('1.50')->bytes);
        self::assertSame(self::hex('01 03 2A 00 00 00 00 00 00 00'), Json::fromValue(new Exact('42', 0))->bytes);
        self::assertSame(self::hex('01 05 01' . str_repeat(' 00', 15) . ' 2C 01 00 00'), Json::fromValue(new Exact('1', 300))->bytes);
        self::assertSame(self::hex('01 03 00 00 00 00 00 00 00 80'), Json::fromValue(new Exact('-9223372036854775808', 0))->bytes);
        self::assertSame(self::hex('01 05 00 00 00 00 00 00 00 80' . str_repeat(' 00', 8) . ' 00 00 00 00'), Json::fromValue(new Exact('9223372036854775808', 0))->bytes);
        self::assertSame('1.50', Json::fromText('1.50')->text());
    }

    /**
     * Verifies: [PROTOCOL JSON values]: arrays and objects nest at most 128
     * deep in JSON text, as turborel parses it, and a stored form's nodes
     * at most 128 within its root; past either, SQLSTATE 0A000, SQLCODE
     * -900, turborel's limits.
     *
     * @throws TurborelException never from the values within the limits
     */
    #[Test]
    public function nestingIsBoundedAsTurborelBoundsIt(): void
    {
        Json::fromText(str_repeat('[', 128) . str_repeat(']', 128));
        self::assertFailure(ValueFailure::UNSUPPORTED, ValueFailure::UNSUPPORTED_CODE, static fn(): Json => Json::fromText(str_repeat('[', 129) . str_repeat(']', 129)));
        $deep = [];
        for ($level = 1; $level < 129; $level++) {
            $deep = [$deep];
        }
        $stored = Json::fromValue($deep)->bytes;
        $inner = substr($stored, 1);
        $deeper = "\x01\x0A" . pack('V3', 1, \strlen($inner), 0) . $inner;
        self::assertFailure(ValueFailure::UNSUPPORTED, ValueFailure::UNSUPPORTED_CODE, static fn(): Json => new Json($deeper));
    }

    /**
     * Verifies: [PROTOCOL Values]: a decimal floating-point node of more
     * than 34 digits reads rounded half to even, as turborel reads one.
     *
     * @throws TurborelException never, the numbers being in range
     */
    #[Test]
    public function aWideDecFloatNodeIsRoundedHalfToEven(): void
    {
        self::assertEquals(new DecFloat(str_repeat('1', 33) . '2', 1), Decoder::nearest(str_repeat('1', 33) . '25', 0));
        self::assertEquals(new DecFloat(str_repeat('1', 33) . '4', 1), Decoder::nearest(str_repeat('1', 33) . '35', 0));
        self::assertEquals(new DecFloat('1', 35), Decoder::nearest(str_repeat('9', 35), 0));
        self::assertEquals(new DecFloat('-' . str_repeat('1', 33) . '3', 1), Decoder::nearest('-' . str_repeat('1', 33) . '251', -1));
    }

    /**
     * Verifies: [PROTOCOL Values]: PHP's values are written as their
     * items: an array of string keys and a \stdClass as objects, a
     * \JsonSerializable as what it serializes to, a Json as its item; a
     * value JSON has no item of, a datetime's or a non-finite number's
     * text are refused (SQLSTATE 22032), and nesting past turborel's limit
     * with SQLSTATE 0A000.
     *
     * @throws TurborelException never from the values JSON has items of
     */
    #[Test]
    public function phpValuesAreWrittenAsTheirItems(): void
    {
        $serializable = new class implements \JsonSerializable {
            public function jsonSerialize(): mixed
            {
                return ['k' => 'v'];
            }
        };
        self::assertSame('{"a":1}', Json::fromValue(['a' => 1])->text());
        self::assertSame('{"a":[{}]}', Json::fromValue((object) ['a' => [new \stdClass()]])->text());
        self::assertSame('[{"k":"v"}]', Json::fromValue([$serializable])->text());
        self::assertSame('[true,true]', Json::fromValue([Json::fromValue(true), true])->text());
        self::assertSame(Node::VERSION, \ord(Json::fromValue(null)->bytes[0]));
        $deep = [];
        for ($level = 0; $level < 130; $level++) {
            $deep = [$deep];
        }
        self::assertFailure(ValueFailure::INVALID_JSON, ValueFailure::DATA, static fn(): Json => Json::fromValue(fopen('php://memory', 'r')));
        self::assertFailure(ValueFailure::UNSUPPORTED, ValueFailure::UNSUPPORTED_CODE, static fn(): Json => Json::fromValue($deep));
        self::assertFailure(ValueFailure::INVALID_JSON, ValueFailure::DATA, static fn(): string => Json::fromValue(new Datetime(Moment::Date, 0))->text());
        self::assertFailure(ValueFailure::INVALID_JSON, ValueFailure::DATA, static fn(): string => Json::fromValue(\NAN)->text());
        self::assertFailure(ValueFailure::NOT_IN_REPERTOIRE, ValueFailure::DATA, static fn(): Json => Json::fromValue("\xFF"));
    }
}

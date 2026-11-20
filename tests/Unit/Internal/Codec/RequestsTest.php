<?php

declare(strict_types=1);

namespace Turborel\Tests\Unit\Internal\Codec;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\CoversNamespace;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Turborel\Internal\Codec\Request\Answer;
use Turborel\Internal\Codec\Request\Cancel;
use Turborel\Internal\Codec\Request\ChangeUser;
use Turborel\Internal\Codec\Request\Close;
use Turborel\Internal\Codec\Request\CopyData;
use Turborel\Internal\Codec\Request\CopyEnd;
use Turborel\Internal\Codec\Request\CopyIn;
use Turborel\Internal\Codec\Request\Deallocate;
use Turborel\Internal\Codec\Request\Describe;
use Turborel\Internal\Codec\Request\Execute;
use Turborel\Internal\Codec\Request\Extras;
use Turborel\Internal\Codec\Request\Fetch;
use Turborel\Internal\Codec\Request\Hello;
use Turborel\Internal\Codec\Request\Lob;
use Turborel\Internal\Codec\Request\Ping;
use Turborel\Internal\Codec\Request\Prepare;
use Turborel\Internal\Codec\Request\Query;
use Turborel\Internal\Codec\Request\Request;
use Turborel\Internal\Codec\Request\Reset;
use Turborel\Internal\Codec\Request\Terminate;
use Turborel\Internal\ConnectionFailure;
use Turborel\Internal\ValueFailure;
use Turborel\Tests\Support\CodecTestCase;
use Turborel\TurborelException;
use Turborel\Value\LobKind;

/**
 * Every request the client sends, encoded byte for byte as the spec lays
 * it out, its tail empty where it says nothing.
 */
#[CoversNamespace('Turborel\Internal\Codec')]
#[CoversNamespace('Turborel\Value')]
#[CoversClass(ConnectionFailure::class)]
#[CoversClass(ValueFailure::class)]
#[CoversClass(TurborelException::class)]
final class RequestsTest extends CodecTestCase
{
    /**
     * Verifies: [PROTOCOL A worked example] [PROTOCOL Hello 'H']: the Hello
     * of `trust` as ANN asking for `ping` is 41 bytes, its tail empty.
     *
     * @throws TurborelException never, its fields being in range
     */
    #[Test]
    public function theWorkedExamplesHelloIs41Bytes(): void
    {
        $hello = (new Hello('ANN', 'trust', '', ['ping']))->encode();

        self::assertSame(bin2hex(self::hex('48 24 00 00 00 01 00 00 00 03 00 00 00 41 4E 4E 05 00 00 00 74 72 75 73 74
            00 00 00 00 01 00 00 00 04 00 00 00 70 69 6E 67')), bin2hex($hello));
        self::assertSame(41, \strlen($hello));
    }

    /**
     * Verifies: [PROTOCOL A worked example] [PROTOCOL Ping 'G'] [PROTOCOL Query 'Q']:
     * a Ping is a header alone, and the Query of `VALUES 1` with `ends`
     * set is the bytes the spec prints.
     *
     * @throws TurborelException never, the fields being in range
     */
    #[Test]
    public function theWorkedExamplesPingAndQuery(): void
    {
        self::assertSame(self::hex('47 00 00 00 00'), (new Ping())->encode());
        self::assertSame(self::hex('51 0D 00 00 00 08 00 00 00 56 41 4C 55 45 53 20 31 01'), (new Query('VALUES 1', true))->encode());
    }

    /**
     * Verifies: [PROTOCOL Requests] [PROTOCOL The tail]: each request is
     * its kind, its body's length, then its fields; tail fields only where
     * they say something, in increasing order of tag.
     *
     * @param \Closure(): Request $request what makes the request
     *
     * @throws TurborelException never, every field being in range
     */
    #[Test]
    #[DataProvider('requests')]
    public function eachRequestIsItsKindThenItsFields(\Closure $request, string $kind, string $body): void
    {
        $body = self::hex($body);
        self::assertSame(bin2hex(self::hex($kind) . pack('V', \strlen($body)) . $body), bin2hex($request()->encode()));
    }

    /**
     * Every request, each with its kind and its body.
     *
     * @return iterable<string, array{\Closure(): Request, string, string}>
     *
     * @throws TurborelException never, every field being in range
     */
    public static function requests(): iterable
    {
        $key = str_repeat('AB ', 16);
        yield 'Hello with attributes, tag 1' => [static fn(): Request => new Hello('A', 'trust', 'x', [], [['app', 'p']]), '48', '01 00 00 00 01 00 00 00 41 05 00 00 00 74 72 75 73 74 01 00 00 00 78 00 00 00 00 01 01 00 00 00 03 00 00 00 61 70 70 01 00 00 00 70'];
        yield 'Answer' => [static fn(): Request => new Answer("\x01\x02"), '41', '02 00 00 00 01 02'];
        yield 'Query with attributes and multi, tags 1 and 2' => [static fn(): Request => new Query('X', false, [['a', 'b']], true), '51', '01 00 00 00 58 00 01 01 00 00 00 01 00 00 00 61 01 00 00 00 62 02'];
        yield 'Prepare' => [static fn(): Request => new Prepare(1, 'SELECT ID FROM T'), '50', '01 00 00 00 10 00 00 00 53 45 4C 45 43 54 20 49 44 20 46 52 4F 4D 20 54'];
        yield 'Execute, its first form' => [static fn(): Request => new Execute(1, [], 2, true), '45', '01 00 00 00 00 00 00 00 02 00 00 00 01'];
        yield 'Execute with a value, attributes and cursor 3, tags 1 and 3' => [static fn(): Request => new Execute(1, [null], 0, false, new Extras([['a', 'b']], 3)), '45', '01 00 00 00 01 00 00 00 00 00 00 00 00 00 01 01 00 00 00 01 00 00 00 61 01 00 00 00 62 03 03 00 00 00'];
        yield 'Fetch' => [static fn(): Request => new Fetch(1, 2), '46', '01 00 00 00 02 00 00 00'];
        yield 'Fetch of cursor 2, tag 3' => [static fn(): Request => new Fetch(1, 0, 2), '46', '01 00 00 00 00 00 00 00 03 02 00 00 00'];
        yield 'Close' => [static fn(): Request => new Close(4), '43', '04 00 00 00'];
        yield 'Close of cursor 5, tag 3' => [static fn(): Request => new Close(4, 5), '43', '04 00 00 00 03 05 00 00 00'];
        yield 'Deallocate' => [static fn(): Request => new Deallocate(1), '44', '01 00 00 00'];
        yield 'Lob read' => [static fn(): Request => Lob::read(7, 0, 262_144, true), '4C', '00 07 00 00 00 00 00 00 00 00 00 00 00 00 00 04 00 00 00 00 00 01'];
        yield 'Lob create' => [static fn(): Request => Lob::create(LobKind::Binary), '4C', '01 01'];
        yield 'Lob append' => [static fn(): Request => Lob::append(7, 'ab'), '4C', '02 07 00 00 00 02 00 00 00 61 62'];
        yield 'Lob free' => [static fn(): Request => Lob::free(7), '4C', '03 07 00 00 00'];
        yield 'Lob hold' => [static fn(): Request => Lob::hold(7), '4C', '04 07 00 00 00'];
        yield 'Cancel' => [static fn(): Request => new Cancel(self::hex($key)), '5A', $key];
        yield 'Terminate' => [static fn(): Request => new Terminate(), '58', ''];
        yield 'Describe a statement' => [static fn(): Request => new Describe(1), '59', '00 01 00 00 00 00 00 00 00'];
        yield 'Describe a cursor' => [static fn(): Request => new Describe(1, 2), '59', '01 01 00 00 00 02 00 00 00'];
        yield 'Reset' => [static fn(): Request => new Reset(), '57', ''];
        yield 'ChangeUser' => [static fn(): Request => new ChangeUser('B', 'trust', ''), '55', '01 00 00 00 42 05 00 00 00 74 72 75 73 74 00 00 00 00'];
        yield 'CopyIn' => [static fn(): Request => new CopyIn('T', ['ID'], true), '49', '01 00 00 00 54 01 00 00 00 02 00 00 00 49 44 01'];
        yield 'CopyData' => [static fn(): Request => new CopyData([[true], [null]]), '4A', '02 00 00 00 01 00 00 00 0E 01 01 00 00 00 00'];
        yield 'CopyEnd' => [static fn(): Request => new CopyEnd(true), '4E', '01'];
    }

    /**
     * Verifies: [PROTOCOL The tail] [DESIGN] D8: more than 32 attributes,
     * a name empty or past 64 bytes, or a value past 1024 are refused before
     * they are sent, with SQLSTATE 08006, as the server would fail the
     * connection for them, which is kept; a cancel key not of 16 bytes with
     * SQLSTATE 22003.
     *
     * @throws TurborelException never from 32 attributes
     */
    #[Test]
    public function attributesPastTheirBoundsAreRefused(): void
    {
        $many = array_fill(0, 33, ['a', 'b']);
        foreach ([$many, [['', 'b']], [[str_repeat('n', 65), 'b']], [['a', str_repeat('v', 1025)]]] as $attributes) {
            self::assertConnectionFails(static fn(): string => (new Query('X', false, $attributes))->encode());
        }
        self::assertSame(1 + 4 + 4 + 1 + 1 + 4 + 32 * 10, \strlen((new Query('', false, array_fill(0, 32, ['a', 'b'])))->encode()));
        self::assertFailure(ValueFailure::OUT_OF_RANGE, ValueFailure::DATA, static fn(): string => (new Cancel('short'))->encode());
    }

    /**
     * Verifies: [PROTOCOL Ready 'R'] [PROTOCOL Hello 'H']: a secret, a
     * cancel key, an answer or a mechanism's first bytes, is never shown
     * by var_dump().
     */
    #[Test]
    public function secretsAreNotShown(): void
    {
        foreach ([new Cancel(str_repeat('K', 16)), new Answer('PROOF'), new Hello('A', 'password', 'PASS', []), new ChangeUser('A', 'password', 'PASS')] as $request) {
            self::assertStringNotContainsString('PASS', print_r($request->__debugInfo(), true));
            self::assertStringNotContainsString('PROOF', print_r($request->__debugInfo(), true));
            self::assertStringNotContainsString('KKKK', print_r($request->__debugInfo(), true));
        }
    }
}

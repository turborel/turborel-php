<?php

declare(strict_types=1);

namespace Turborel\Tests\Unit\Internal\Codec;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\CoversNamespace;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Turborel\Internal\Codec\Completion;
use Turborel\Internal\Codec\Kind;
use Turborel\Internal\Codec\Replies;
use Turborel\Internal\Codec\Reply\Challenge;
use Turborel\Internal\Codec\Reply\Complete;
use Turborel\Internal\Codec\Reply\Description;
use Turborel\Internal\Codec\Reply\Error;
use Turborel\Internal\Codec\Reply\LobCreated;
use Turborel\Internal\Codec\Reply\LobData;
use Turborel\Internal\Codec\Reply\Notice;
use Turborel\Internal\Codec\Reply\Pong;
use Turborel\Internal\Codec\Reply\Prepared;
use Turborel\Internal\Codec\Reply\Ready;
use Turborel\Internal\Codec\Reply\Rows;
use Turborel\Internal\Codec\Reply\Status;
use Turborel\Internal\Codec\Reply\Suspended;
use Turborel\Internal\Codec\Writer;
use Turborel\Internal\ConnectionFailure;
use Turborel\Internal\ValueFailure;
use Turborel\Tests\Support\CodecTestCase;
use Turborel\TurborelException;
use Turborel\Type\BooleanType;
use Turborel\Type\CharacterType;
use Turborel\Type\Column;
use Turborel\Type\ExactType;
use Turborel\Type\Parameter;
use Turborel\Value\CharUnits;
use Turborel\Value\Exact;

/**
 * Every reply the client receives, decoded from the bytes the spec lays
 * out, and the replies it must refuse.
 */
#[CoversNamespace('Turborel\Internal\Codec')]
#[CoversNamespace('Turborel\Type')]
#[CoversNamespace('Turborel\Value')]
#[CoversClass(ConnectionFailure::class)]
#[CoversClass(ValueFailure::class)]
#[CoversClass(TurborelException::class)]
final class RepliesTest extends CodecTestCase
{
    /**
     * Verifies: [PROTOCOL A worked example] [PROTOCOL Rows 'B'] [PROTOCOL Complete 'K']:
     * the Rows reply of the exact number 1, units 1 and scale 0, and the
     * Complete with an empty tail, read from the bytes the spec prints.
     *
     * @throws TurborelException never, the replies being well formed
     */
    #[Test]
    public function theWorkedExamplesRowsAndComplete(): void
    {
        $rows = Replies::decode(Kind::ROWS, self::hex('01 00 00 00 01 00 00 00 02 01' . str_repeat(' 00', 15) . ' 00 00 00 00'));
        self::assertEquals(new Rows([[new Exact('1', 0)]]), $rows);
        $complete = Replies::decode(Kind::COMPLETE, self::hex('00 00 00 00 05 00 00 00 30 30 30 30 30 00'));
        self::assertEquals(new Complete(new Completion(0, '00000', false)), $complete);
        $counted = Replies::decode(Kind::COMPLETE, self::hex('00 00 00 00 05 00 00 00 30 30 30 30 30 00 06 01 00 00 00 00 00 00 00 12'
            . ' B4 00 00 00 00 00 00 00 04 00 00 00 00 00 00 00 00 00 00 00 00 00 00 00'));
        self::assertInstanceOf(Complete::class, $counted);
        self::assertSame([1, 180, 4, 0], [$counted->completion->counts->rows, $counted->completion->counts->time?->total,
            $counted->completion->counts->time?->queued, $counted->completion->counts->time?->locks]);
    }

    /**
     * Verifies: [PROTOCOL Replies]: each reply is read from its kind and
     * its body.
     *
     * @throws TurborelException never, the replies being well formed
     */
    #[Test]
    public function eachReplyIsReadFromItsKindAndBody(): void
    {
        $key = str_repeat("\x5A", 16);
        $ready = self::writer()->string('ANN')->raw($key)->strings(['ping'])->string('turborel 0.0.0 (alpha)')->bytesWritten();
        self::assertEquals(new Challenge('r='), Replies::decode(Kind::CHALLENGE, self::hex('02 00 00 00 72 3D')));
        self::assertEquals(new Ready('ANN', $key, ['ping'], 'turborel 0.0.0 (alpha)'), Replies::decode(Kind::READY, $ready));
        self::assertEquals(new Suspended(), Replies::decode(Kind::SUSPENDED, ''));
        self::assertEquals(new Pong(), Replies::decode(Kind::PONG, ''));
        self::assertEquals(new LobData('ab'), Replies::decode(Kind::LOB, self::hex('00 02 00 00 00 61 62')));
        self::assertEquals(new LobCreated(9), Replies::decode(Kind::LOB, self::hex('01 09 00 00 00')));
        self::assertEquals(new Status('READ_ONLY', 'NO'), Replies::decode(Kind::STATUS, self::hex('09 00 00 00 52 45 41 44 5F 4F 4E 4C 59 02 00 00 00 4E 4F')));
        self::assertStringNotContainsString('ZZZZ', print_r((new Ready('A', $key, [], 's'))->__debugInfo(), true));
    }

    /**
     * Verifies: [PROTOCOL Prepared 'P'] [PROTOCOL Description 'D'] [PROTOCOL Composite fields]:
     * a column is its named flag, its name if named, its type and its not
     * null flag; a parameter its varying flag and its type.
     *
     * @throws TurborelException never, the replies being well formed
     */
    #[Test]
    public function columnsAndParametersAreRead(): void
    {
        $column = '01 02 00 00 00 49 44 02 0A 00 00 00 00 00 00 00 01';
        $unnamed = '00 0E 00';
        $prepared = Replies::decode(Kind::PREPARED, self::hex("01 00 00 00 02 00 00 00 01 05 FF FF FF FF 00 00 0E 02 00 00 00 {$column} {$unnamed}"));
        self::assertEquals(new Prepared(1, [new Parameter(true, new CharacterType(true, 0xFFFF_FFFF, CharUnits::Characters)), new Parameter(false, new BooleanType())], [
            new Column('ID', new ExactType(10, 0), true), new Column(null, new BooleanType(), false),
        ]), $prepared);
        self::assertEquals(new Description([new Column(null, new BooleanType(), false)]), Replies::decode(Kind::DESCRIPTION, self::hex("01 00 00 00 {$unnamed}")));
    }

    /**
     * Verifies: [PROTOCOL Error 'E'] [PROTOCOL Composite fields] [PROTOCOL Errors and notices]:
     * a failure is its SQLCODE as a u32, its SQLSTATE, its message, its
     * rule and position where cited and placed, its active flag, and with
     * `diagnostics` tags 7 to 15.
     *
     * @throws TurborelException never, the replies being well formed
     */
    #[Test]
    public function aFailureIsReadWithItsDiagnostics(): void
    {
        $body = self::failure(self::writer())->u8(7)->string('ERROR')->u8(12)->string('T')->u8(15)->string('PK')->bytesWritten();
        $error = Replies::decode(Kind::ERROR, $body);
        self::assertInstanceOf(Error::class, $error);
        $failure = $error->failure;
        self::assertSame([-103, '2A000', 'no T', 'X3.135-1986 §5.7', 12, true], [$failure->sqlcode, $failure->sqlstate, $failure->message,
            $failure->cited->rule, $failure->cited->position, $failure->state->active]);
        self::assertSame(['ERROR', 'T', 'PK', null], [$failure->state->diagnostics?->get('severity'), $failure->state->diagnostics?->get('table'),
            $failure->state->diagnostics?->get('constraint'), $failure->state->diagnostics?->get('hint')]);
        $plain = Replies::decode(Kind::ERROR, self::hex('63 FC FF FF 05 00 00 00 30 38 30 30 36 00 00 00 00 00 00 00'));
        self::assertInstanceOf(Error::class, $plain);
        self::assertSame([-925, null, null, null], [$plain->failure->sqlcode, $plain->failure->cited->rule, $plain->failure->cited->position, $plain->failure->state->diagnostics]);
    }

    /**
     * Verifies: [PROTOCOL Notice 'N'] [PROTOCOL Errors and notices]: a
     * notice is its SQLSTATE, its message and tags 7 to 15.
     *
     * @throws TurborelException never, the reply being well formed
     */
    #[Test]
    public function aNoticeIsRead(): void
    {
        $notice = Replies::decode(Kind::NOTICE, self::writer()->string('01T01')->string('rolled back')->u8(7)->string('NOTICE')->bytesWritten());
        self::assertInstanceOf(Notice::class, $notice);
        self::assertSame(['01T01', 'rolled back', 'NOTICE'], [$notice->sqlstate, $notice->message, $notice->diagnostics?->get('severity')]);
    }

    /**
     * Verifies: [PROTOCOL Complete 'K'] [PROTOCOL The tail]: a Complete's
     * tail, tags 4, 5, 6, 16, 17 and 18, each in turn; a condition is a
     * failure as bytes, its own tail ending where its length does.
     *
     * @throws TurborelException never, the reply being well formed
     */
    #[Test]
    public function aCompletesWholeTailIsRead(): void
    {
        $condition = self::failure(self::writer())->u8(7)->string('WARNING')->bytesWritten();
        $body = self::writer()->u32(100)->string('02000')->flag(true)->u8(4)->u64('18446744073709551615')->u8(5)->u8(6)->u64(3)
            ->u8(16)->strings(['3', '4'])->u8(17)->u32(1)->bytes($condition)->u8(18)->u64(1)->u64(2)->u64(3)->bytesWritten();
        $complete = Replies::decode(Kind::COMPLETE, $body);
        self::assertInstanceOf(Complete::class, $complete);
        $c = $complete->completion;
        self::assertSame([100, '02000', true, '18446744073709551615', true, 3, ['3', '4']], [$c->sqlcode, $c->sqlstate, $c->active, $c->counts->position, $c->counts->more, $c->counts->rows, $c->raised[0]]);
        self::assertSame('WARNING', ($c->raised[1][0] ?? null)?->state->diagnostics?->get('severity'));
    }

    /**
     * Verifies: [PROTOCOL Frames] [PROTOCOL The tail] [PROTOCOL Errors and notices]:
     * a reply a client cannot read fails the connection (SQLCODE -919,
     * SQLSTATE 08006): a kind no reply has, a request's kind, a body cut
     * short, bytes after the last field, a tail tag out of order or not
     * taken, a Lob reply of no kind.
     */
    #[Test]
    #[DataProvider('malformed')]
    public function aMalformedReplyFailsTheConnection(int $kind, string $body): void
    {
        self::assertConnectionFails(static fn(): mixed => Replies::decode($kind, self::hex($body)));
    }

    /**
     * Verifies: [PROTOCOL Values and fields] [PROTOCOL Complete 'K']: a
     * Complete whose condition has a byte after it fails as turborel reads
     * a condition (SQLCODE -916), its head still read: its codes, whether
     * a transaction is active, its position and whether more replies
     * follow; a Description, Rows, a Notice or a Status has more after it,
     * a Complete only if it says so, any other reply none.
     */
    #[Test]
    public function aConditionThatCannotBeTakenLeavesItsHead(): void
    {
        $body = self::hex('00 00 00 00 05 00 00 00 30 30 30 30 30 01 04 07 00 00 00 00 00 00 00 05 11 01 00 00 00 13 00 00 00 00 00 00 00 05 00 00 00 30 30 30 30 30 00 00 00 00 00 00 FF');
        self::assertFailure(ValueFailure::CORRUPT, ValueFailure::CORRUPT_CODE, static fn(): mixed => Replies::decode(Kind::COMPLETE, $body));
        $head = Completion::head(Kind::COMPLETE, $body);
        self::assertSame(['00000', true, 7, true], [$head?->sqlstate, $head?->active, $head?->counts->position, $head?->counts->more]);
        self::assertNull(Completion::head(Kind::ROWS, $body));
        self::assertNull(Completion::head(Kind::COMPLETE, self::hex('00 00')));
        self::assertNull(Completion::head(Kind::COMPLETE, self::hex('00 00 00 00 05 00 00 00 30 30 30 30 30 00 04 07')));
        self::assertFalse(Replies::endsRequest(Kind::COMPLETE, $body));
        self::assertTrue(Replies::endsRequest(Kind::COMPLETE, self::hex('00 00 00 00 05 00 00 00 30 30 30 30 30 00')));
        foreach ([Kind::DESCRIPTION, Kind::ROWS, Kind::NOTICE, Kind::STATUS] as $kind) {
            self::assertFalse(Replies::endsRequest($kind, ''));
        }
        self::assertTrue(Replies::endsRequest(Kind::ERROR, ''));
    }

    /**
     * Replies a client must refuse, each its kind and body.
     *
     * @return iterable<string, array{int, string}>
     */
    public static function malformed(): iterable
    {
        $complete = '00 00 00 00 05 00 00 00 30 30 30 30 30 00';
        yield 'kind Q, a request\'s' => [Kind::QUERY, ''];
        yield 'kind 0' => [0, ''];
        yield 'a Complete cut short' => [Kind::COMPLETE, '00 00 00 00 05 00 00 00 30 30'];
        yield 'a Pong with a byte' => [Kind::PONG, '00'];
        yield 'tags out of order' => [Kind::COMPLETE, "{$complete} 06 01 00 00 00 00 00 00 00 04 01 00 00 00 00 00 00 00"];
        yield 'a tag repeated' => [Kind::COMPLETE, "{$complete} 05 05"];
        yield 'a tag a Complete does not take' => [Kind::COMPLETE, "{$complete} 07 00 00 00 00"];
        yield 'a failure\'s tail tag 16' => [Kind::ERROR, '00 00 00 00 05 00 00 00 30 30 30 30 30 00 00 00 00 00 00 10'];
        yield 'a Lob reply of kind 2' => [Kind::LOB, '02'];
        yield 'a flag of 2' => [Kind::COMPLETE, '00 00 00 00 05 00 00 00 30 30 30 30 30 02'];
    }

    /**
     * Writes a failure's fields before its tail: SQLCODE -103, 2A000, its rule and position, active.
     *
     * @throws TurborelException never, the fields being in range
     */
    private static function failure(Writer $writer): Writer
    {
        return $writer->u32(0xFFFF_FF99)->string('2A000')->string('no T')->flag(true)->string('X3.135-1986 §5.7')->flag(true)->u64(12)->flag(true);
    }
}

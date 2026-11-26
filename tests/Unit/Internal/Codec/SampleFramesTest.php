<?php

declare(strict_types=1);

namespace Turborel\Tests\Unit\Internal\Codec;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\CoversNamespace;
use PHPUnit\Framework\Attributes\Test;
use Turborel\Debug\Action;
use Turborel\Debug\RoutineKind;
use Turborel\Internal\Codec\FrameHeader;
use Turborel\Internal\Codec\Frames;
use Turborel\Internal\Codec\Kind;
use Turborel\Internal\Codec\Replies;
use Turborel\Internal\Codec\Request\Answer;
use Turborel\Internal\Codec\Request\Cancel;
use Turborel\Internal\Codec\Request\ChangeUser;
use Turborel\Internal\Codec\Request\Close;
use Turborel\Internal\Codec\Request\CopyColumns;
use Turborel\Internal\Codec\Request\CopyData;
use Turborel\Internal\Codec\Request\CopyEnd;
use Turborel\Internal\Codec\Request\CopyIn;
use Turborel\Internal\Codec\Request\Deallocate;
use Turborel\Internal\Codec\Request\Debug;
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
use Turborel\Session\Capability;
use Turborel\Tests\Support\CodecTestCase;
use Turborel\Tests\Unit\Internal\Codec\Frames\Said;
use Turborel\TurborelException;
use Turborel\Value\LobKind;

/**
 * Turborel's sample frames, `doc/client/05-protocol-frames.tsv`, copied
 * into the fixtures at Turborel's commit 45cb0d7f (RULES.md D12): every
 * server frame decoded as its meaning says, every client frame encoded
 * byte for byte, and the compressed ones inflated.
 */
#[CoversNamespace('Turborel\\Internal')]
#[CoversNamespace('Turborel\\Type')]
#[CoversNamespace('Turborel\\Value')]
#[CoversNamespace('Turborel\\Debug')]
#[CoversClass(TurborelException::class)]
final class SampleFramesTest extends CodecTestCase
{
    /** The copy of the file, at Turborel's commit 45cb0d7f. */
    private const string FILE = __DIR__ . '/../../../Fixtures/05-protocol-frames.tsv';

    /**
     * Verifies: [PROTOCOL Sample frames]: every line not a comment has
     * five fields apart by tabs: a direction, a name unique in the file,
     * the capabilities it needs or `-`, the whole frame in upper-case hex,
     * its header's length that of its body, and its meaning.
     *
     * @throws TurborelException never, the headers being well formed
     */
    #[Test]
    public function everyLineIsReadAsThePageLaysItOut(): void
    {
        $names = [];
        foreach (self::samples() as [$direction, $name, $needs, $hex]) {
            self::assertContains($direction, ['client', 'server']);
            self::assertMatchesRegularExpression('/^(?:[0-9A-F]{2})+$/', $hex, $name);
            $bytes = (string) hex2bin($hex);
            self::assertSame(\strlen($bytes) - FrameHeader::SIZE, FrameHeader::decode(substr($bytes, 0, FrameHeader::SIZE), \PHP_INT_MAX >> 1)->length, $name);
            foreach ($needs === '-' ? [] : explode(',', $needs) as $capability) {
                self::assertContains($capability, Capability::ALL, $name);
            }
            $names[] = $name;
        }
        self::assertSame(array_unique($names), $names, 'each name once');
        self::assertCount(167, $names);
    }

    /**
     * Verifies: [PROTOCOL Sample frames] [PROTOCOL Replies] [PROTOCOL Declared types]:
     * every server frame decodes, inflated where compressed, to what its
     * meaning says, each data type and value tag, tail field and Status.
     *
     * @throws TurborelException never, the frames being well formed
     */
    #[Test]
    public function everyServerFrameDecodesAsItsMeaningSays(): void
    {
        $expected = [...self::replies(), ...self::types(), ...self::values(), ...self::debugged()];
        $decoded = [];
        foreach (self::samples() as [$direction, $name, , $hex]) {
            if ($direction === 'server') {
                $bytes = (string) hex2bin($hex);
                $header = FrameHeader::decode(substr($bytes, 0, FrameHeader::SIZE), FrameHeader::MAX_BODY);
                [$kind, $body] = Frames::unwrap($header->kind, substr($bytes, FrameHeader::SIZE), true);
                $decoded[$name] = [Said::of(Replies::decode($kind, $body))];
            }
        }
        ksort($expected);
        ksort($decoded);
        self::assertSame($expected, array_intersect_key($decoded, $expected));
        self::assertSame(array_keys($decoded), array_keys(array_intersect_key($decoded, $expected)), 'every server frame expected');
    }

    /**
     * Verifies: [PROTOCOL Sample frames] [PROTOCOL Requests] [PROTOCOL Compressed frames]:
     * every client frame the driver sends is encoded byte for byte as the
     * file holds it; the compressed one inflates to the Query its meaning
     * names, as the compressed Rows inflates to its 64 rows.
     *
     * @throws TurborelException never, the requests being well formed
     */
    #[Test]
    public function everyClientFrameIsEncodedByteForByte(): void
    {
        $requests = [...self::requests(), ...self::debugRequests()];
        foreach (self::samples() as [$direction, $name, , $hex]) {
            if ($direction !== 'client') {
                continue;
            }
            if ($name === 'query-compressed') {
                $bytes = (string) hex2bin($hex);
                [$kind, $body] = Frames::unwrap(Kind::COMPRESSED, substr($bytes, FrameHeader::SIZE), true);
                self::assertSame((new Query("SELECT '" . str_repeat('x', 2000) . "' FROM T", true))->encode(), Frames::frame($kind, $body));

                continue;
            }
            self::assertArrayHasKey($name, $requests);
            self::assertSame($hex, strtoupper(bin2hex($requests[$name]->encode())), $name);
            unset($requests[$name]);
        }
        self::assertSame([], $requests, 'every request a sample');
    }

    /**
     * The requests of the client samples, by name.
     *
     * @return array<string, Request>
     *
     * @throws TurborelException never, the requests being well formed
     */
    private static function requests(): array
    {
        $attributes = array_map(static fn(int $n): array => ["a{$n}", ''], range(0, 31));

        return [
            'hello-ping' => new Hello('ANN', 'trust', '', [Capability::PING]),
            'hello-none' => new Hello('ANN', 'trust', '', []),
            'hello-every' => new Hello('ANN', 'trust', '', [Capability::STATUS, Capability::DESCRIBE, Capability::CURSORS, Capability::COMPRESSION, Capability::DECLARED_TYPES], [['application', 'app']]),
            'hello-attribute-longest' => new Hello('ANN', 'trust', '', [], [[str_repeat('a', 64), str_repeat('v', 1024)]]),
            'hello-attributes-most' => new Hello('ANN', 'trust', '', [], $attributes),
            'hello-scram' => new Hello('ANN', 'scram-sha-256', 'n,,n=,r=abc', []),
            'answer' => new Answer('c=biws,r=abc,p=xyz'),
            'answer-empty' => new Answer(''),
            'cancel' => new Cancel(str_repeat("\xA5", 16)),
            'query' => new Query('VALUES 1', true),
            'query-empty' => new Query(''),
            'query-attributes' => new Query("VALUES QUERY_ATTRIBUTE('trace')", false, [['trace', 't1']]),
            'query-multi' => new Query('VALUES 1; VALUES 2', true, [], true),
            'prepare' => new Prepare(1, 'SELECT K FROM T WHERE K > ?'),
            'execute' => new Execute(1, [null, '', true], 2),
            'execute-cursor' => new Execute(1, [], 0, true, new Extras([['a', 'b']], 7)),
            'fetch' => new Fetch(1, 100),
            'fetch-cursor' => new Fetch(1, 0xFFFF_FFFF, 7),
            'close' => new Close(1),
            'close-cursor' => new Close(1, 7),
            'deallocate' => new Deallocate(0xFFFF_FFFF),
            'terminate' => new Terminate(),
            'lob-read' => Lob::read(3, 0, 65536, true),
            'lob-create' => Lob::create(LobKind::Binary),
            'lob-append' => Lob::append(4, "\xC3"),
            'lob-free' => Lob::free(4),
            'lob-hold' => Lob::hold(3),
            'describe-statement' => new Describe(1),
            'describe-cursor' => new Describe(1, 7),
            'ping' => new Ping(),
            'reset' => new Reset(),
            'change-user' => new ChangeUser('BOB', 'password', 'secret'),
            'copy-in' => new CopyIn('S.T', ['ID', 'NAME'], true),
            'copy-in-every-column' => new CopyIn('T'),
            'copy-data' => new CopyData([['1', null], ['2', 'b']]),
            'copy-columns' => new CopyColumns([['1', 'b'], [null, '']], 2),
            'copy-end' => new CopyEnd(),
            'copy-end-abandon' => new CopyEnd(true),
        ];
    }

    /**
     * The debugger's requests of the client samples, by name.
     *
     * @return array<string, Request>
     *
     * @throws TurborelException never, the requests being well formed
     */
    private static function debugRequests(): array
    {
        return [
            'debug-identify' => Debug::of(Debug::IDENTIFY),
            'debug-attach' => Debug::attach(2),
            'debug-detach' => Debug::of(Debug::DETACH),
            'debug-break' => Debug::break(RoutineKind::Procedure, 'ANN', 'RAISE_PAY', 4, 'PCT > 5'),
            'debug-clear' => Debug::clear(1),
            'debug-breakpoints' => Debug::of(Debug::BREAKPOINTS),
            'debug-resume' => Debug::resume(Action::Over),
            'debug-interrupt' => Debug::of(Debug::INTERRUPT),
            'debug-stack' => Debug::of(Debug::STACK),
        ];
    }

    /**
     * The samples of the file, each its direction, name, needs, hex and meaning.
     *
     * @return list<array{string, string, string, string, string}>
     */
    private static function samples(): array
    {
        $lines = file(self::FILE, \FILE_IGNORE_NEW_LINES);
        self::assertIsArray($lines);
        $samples = [];
        foreach ($lines as $line) {
            if (!str_starts_with($line, '#')) {
                $fields = explode("\t", $line);
                self::assertCount(5, $fields, $line);
                $samples[] = $fields;
            }
        }

        return $samples;
    }

    /**
     * The meanings of the server frames but of types and values.
     *
     * @return iterable<string, array{string}>
     */
    private static function replies(): iterable
    {
        yield 'challenge' => ['Challenge r=abcdef,s=c2FsdA==,i=4096'];
        yield 'ready' => ['Ready ANN, key A5A5A5A5A5A5A5A5A5A5A5A5A5A5A5A5, ping, turborel 0.0.0 (alpha)'];
        yield 'prepared' => ['Prepared 1, parameters CHARACTER VARYING(32767) varying, NUMERIC(10,0); columns K NUMERIC(10,0) NOT NULL, ? CHARACTER VARYING(5)'];
        yield 'prepared-empty' => ['Prepared 2, parameters ; columns '];
        yield 'prepared-declared' => ['Prepared 1, parameters CHARACTER VARYING(32767) varying, NUMERIC(10,0); columns K NUMERIC(10,0) NOT NULL declared INTEGER/INTEGER'];
        yield 'prepared-called' => ['Prepared 3, parameters NUMERIC(10,0); columns G NUMERIC(10,0) NOT NULL; called S.P: IN 1 NUMERIC(10,0), OUT 2 NUMERIC(10,0)'];
        yield 'description' => ['Description columns K NUMERIC(10,0) NOT NULL, ? CHARACTER VARYING(5)'];
        yield 'description-empty' => ['Description columns '];
        yield 'description-declared' => ['Description columns I NUMERIC(10,0) NOT NULL declared INTEGER/INTEGER, S NUMERIC(5,0) declared SMALLINT/SMALLINT, B NUMERIC(18,0) declared BIGINT/BIGINT, D NUMERIC(9,2) declared DECIMAL/DECIMAL(9,2), R FLOAT(24) declared REAL/REAL, P FLOAT(53) declared DOUBLE PRECISION/DOUBLE PRECISION, M NUMERIC(9,2) declared USER-DEFINED/S.MONEY'];
        yield 'rows' => ['Rows (1, \'a\') (NULL, \'\')'];
        yield 'rows-none' => ['Rows'];
        yield 'rows-empty-row' => ['Rows ()'];
        yield 'suspended' => ['Suspended'];
        yield 'complete' => ['Complete 0 00000'];
        yield 'complete-active' => ['Complete 0 00000 active'];
        yield 'complete-no-data' => ['Complete 100 02000'];
        yield 'complete-position' => ['Complete 0 00000, position 4711'];
        yield 'complete-more' => ['Complete 0 00000, more'];
        yield 'complete-rows' => ['Complete 0 00000, rows 1'];
        yield 'complete-every' => ['Complete 0 00000, position 4711, more, rows 3, generated 7 8, time 1500 20 300'];
        yield 'complete-conditions' => ['Complete 0 01004 active, conditions 0 01004 string data, right truncation active'];
        yield 'error' => ['Error -803 23505 a unique constraint is broken'];
        yield 'error-cited' => ['Error -803 23505 a unique constraint is broken [ISO/IEC 9075-2:2023 §11.7] at 12 active'];
        yield 'error-diagnostics' => ['Error -803 23505 a unique constraint is broken [ISO/IEC 9075-2:2023 §11.7] at 12 active {severity=ERROR, detail=key (K)=(1), hint=choose another K, context=trigger S.TR, schema=S, table=T, column=K, dataType=INTEGER, constraint=T_PK}'];
        yield 'error-connection' => ['Error -919 08006 a malformed message'];
        yield 'lob-data' => ['Lob data 61C3A9'];
        yield 'lob-data-empty' => ['Lob data '];
        yield 'lob-locator' => ['Lob locator 4'];
        yield 'notice' => ['Notice 01004 string data, right truncation'];
        yield 'notice-diagnostics' => ['Notice 01004 string data, right truncation {severity=WARNING, detail=cut to 4}'];
        yield 'pong' => ['Pong'];
        yield 'status-server-version' => ['Status SERVER_VERSION=turborel 0.0.0 (alpha)'];
        yield 'status-authorization' => ['Status AUTHORIZATION=ANN'];
        yield 'status-schema' => ['Status SCHEMA=ANN'];
        yield 'status-read-only' => ['Status READ_ONLY=NO'];
        yield 'status-server-read-only' => ['Status SERVER_READ_ONLY=YES'];
        yield 'status-catalog' => ['Status CATALOG=TURBOREL'];
        yield 'status-owner' => ['Status OWNER=YES'];
        yield 'status-time-zone' => ['Status SESSION.TIME_ZONE=+05:30'];
        yield 'status-null' => ['Status STATEMENT.TIMEOUT_MS='];
        yield 'rows-compressed' => ['Rows ' . implode(' ', array_map(static fn(int $n): string => '(' . $n . ", '" . str_repeat('abcdefgh', 4) . "')", range(0, 63)))];
    }

    /**
     * The meanings of the Descriptions of one column of each data type.
     *
     * @return iterable<string, array{string}>
     */
    private static function types(): iterable
    {
        yield 'description-character' => ['Description columns C CHARACTER(4)'];
        yield 'description-character-octets' => ['Description columns C CHARACTER(16 OCTETS)'];
        yield 'description-exact' => ['Description columns C NUMERIC(9,2)'];
        yield 'description-approximate' => ['Description columns C FLOAT(53)'];
        yield 'description-plugin-type' => ['Description columns C UNREGISTERED'];
        yield 'description-varying' => ['Description columns C CHARACTER VARYING(20)'];
        yield 'description-binary' => ['Description columns C BINARY(16)'];
        yield 'description-binary-varying' => ['Description columns C BINARY VARYING(255)'];
        yield 'description-date' => ['Description columns C DATE'];
        yield 'description-time' => ['Description columns C TIME(3)'];
        yield 'description-time-zoned' => ['Description columns C TIME(0) WITH TIME ZONE'];
        yield 'description-timestamp' => ['Description columns C TIMESTAMP(6)'];
        yield 'description-timestamp-zoned' => ['Description columns C TIMESTAMP(6) WITH TIME ZONE'];
        yield 'description-interval-year-month' => ['Description columns C INTERVAL YEAR(4) TO MONTH'];
        yield 'description-interval-day-second' => ['Description columns C INTERVAL DAY(2) TO SECOND(6)'];
        yield 'description-boolean' => ['Description columns C BOOLEAN'];
        yield 'description-decfloat' => ['Description columns C DECFLOAT(34)'];
        yield 'description-clob' => ['Description columns C CHARACTER LARGE OBJECT(1048576)'];
        yield 'description-blob' => ['Description columns C BINARY LARGE OBJECT(65536)'];
        yield 'description-json' => ['Description columns C JSON'];
        yield 'description-uuid' => ['Description columns C UUID'];
        yield 'description-array' => ['Description columns C BOOLEAN ARRAY[3]'];
        yield 'description-row' => ['Description columns C ROW(X CHARACTER VARYING(3), Y BOOLEAN)'];
        yield 'description-multiset' => ['Description columns C CHARACTER(2) MULTISET'];
        yield 'description-structured' => ['Description columns C S.SHAPE'];
        yield 'description-reference' => ['Description columns C REF(S.SHAPE)'];
        yield 'description-reference-scoped' => ['Description columns C REF(S.SHAPE) SCOPE S.SHAPES'];
        yield 'description-xml-document' => ['Description columns C XML(DOCUMENT(UNTYPED))'];
        yield 'description-xml-content' => ['Description columns C XML(CONTENT(ANY))'];
        yield 'description-xml-sequence' => ['Description columns C XML(SEQUENCE)'];
        yield 'description-xml-schema' => ['Description columns C XML(DOCUMENT(XMLSCHEMA ID S.PO NAMESPACE \'urn:po\'))'];
        yield 'description-distinct' => ['Description columns C CHARACTER VARYING(4)'];
        yield 'description-declared-array' => ['Description columns C NUMERIC(10,0) ARRAY[3] declared ARRAY/INTEGER ARRAY[3]'];
    }

    /**
     * The meanings of the Rows of one value of each value tag.
     *
     * @return iterable<string, array{string}>
     */
    private static function values(): iterable
    {
        yield 'value-null' => ['Rows (NULL)'];
        yield 'value-character' => ['Rows (\'abc\')'];
        yield 'value-character-empty' => ['Rows (\'\')'];
        yield 'value-character-utf8' => ['Rows (\'é€\')'];
        yield 'value-exact' => ['Rows (123.45)'];
        yield 'value-exact-negative' => ['Rows (-1)'];
        yield 'value-exact-largest' => ['Rows (170141183460469231731687303715884105727)'];
        yield 'value-approximate' => ['Rows (1.5E0)'];
        yield 'value-approximate-negative-zero' => ['Rows (-0E0)'];
        yield 'value-plugin' => ['Rows (POINT (1 2))'];
        yield 'value-binary' => ['Rows (X\'DEADBEEF\')'];
        yield 'value-binary-empty' => ['Rows (X\'\')'];
        yield 'value-date' => ['Rows (DATE \'2024-02-29\')'];
        yield 'value-time' => ['Rows (TIME \'12:34:56.5\')'];
        yield 'value-time-zoned' => ['Rows (TIME \'12:34:56+05:30\')'];
        yield 'value-timestamp' => ['Rows (TIMESTAMP \'2024-02-29 12:34:56.123456\')'];
        yield 'value-timestamp-zoned' => ['Rows (TIMESTAMP \'2024-02-29 12:34:56-08:00\')'];
        yield 'value-timestamp-earliest' => ['Rows (TIMESTAMP \'0001-01-01 00:00:00\')'];
        yield 'value-interval-year-month' => ['Rows (INTERVAL -\'1-02\' YEAR(2) TO MONTH)'];
        yield 'value-interval-day-second' => ['Rows (INTERVAL \'1 02:03:04.500000\' DAY(2) TO SECOND(6))'];
        yield 'value-true' => ['Rows (TRUE)'];
        yield 'value-false' => ['Rows (FALSE)'];
        yield 'value-decfloat' => ['Rows (CAST(\'-1.5\' AS DECFLOAT))'];
        yield 'value-array' => ['Rows (ARRAY[1, NULL])'];
        yield 'value-array-empty' => ['Rows (ARRAY[])'];
        yield 'value-row' => ['Rows (ROW(\'x\', TRUE))'];
        yield 'value-lob-character' => ['Rows (\'aé\')'];
        yield 'value-lob-binary' => ['Rows (X\'00FF\')'];
        yield 'value-lob-locator' => ['Rows (<CHARACTER LARGE OBJECT of 200000 characters>)'];
        yield 'value-multiset' => ['Rows (MULTISET[\'a\', \'a\'])'];
        yield 'value-structured' => ['Rows (S.SHAPE(3, NULL))'];
        yield 'value-reference' => ['Rows (REF(0102030405060708))'];
        yield 'value-json' => ['Rows (JSON(\'{"a":[1,"x"]}\'))'];
        yield 'value-json-scalars' => ['Rows (JSON(\'[null,true,false,-2,1.50,"",{}]\'))'];
        yield 'value-xml' => ['Rows (XMLPARSE(CONTENT \'<a>&lt;</a>\' PRESERVE WHITESPACE))'];
        yield 'value-uuid' => ['Rows (UUID \'00112233-4455-6677-8899-aabbccddeeff\')'];
    }

    /**
     * The meanings of the debugger's Debugged and Event samples.
     *
     * @return iterable<string, array{string}>
     */
    private static function debugged(): iterable
    {
        yield 'debugged-connection' => ['Debugged connection 2'];
        yield 'debugged-breakpoint' => ['Debugged breakpoint #1 ANN.RAISE_PAY line 4'];
        yield 'debugged-breakpoints' => ['Debugged breakpoints #1 ANN.RAISE_PAY line 4'];
        yield 'debugged-running' => ['Debugged stack running'];
        yield 'debugged-stack' => ['Debugged stack breakpoint 1: procedure ANN.RAISE_PAY (ANN.RAISE_PAY) at 1 line 4:3 [PCT INTEGER 10] [N INTEGER NULL] [C closed SELECT ID FROM STAFF]'];
        yield 'event-paused' => ['Event paused breakpoint 1: procedure ANN.RAISE_PAY (ANN.RAISE_PAY) at 1 line 4:3 [PCT INTEGER 10] [N INTEGER NULL] [C closed SELECT ID FROM STAFF]'];
        yield 'event-timed-out' => ['Event timedout'];
        yield 'event-cancelled' => ['Event cancelled'];
        yield 'event-ended' => ['Event ended'];
    }
}

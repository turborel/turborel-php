<?php

declare(strict_types=1);

namespace Turborel\Tests\Unit\Internal\Auth;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Turborel\Internal\Auth\ScramExchange;
use Turborel\Internal\Auth\ScramFailure;
use Turborel\Internal\ConnectionFailure;
use Turborel\Session\KeyCache;
use Turborel\Tests\Support\CodecTestCase;
use Turborel\TurborelException;

/**
 * The client's side of SCRAM-SHA-256: RFC 7677's test vector, and the
 * server messages it refuses.
 */
#[CoversClass(ScramExchange::class)]
#[CoversClass(ScramFailure::class)]
#[CoversClass(KeyCache::class)]
#[CoversClass(ConnectionFailure::class)]
#[CoversClass(TurborelException::class)]
final class ScramExchangeTest extends CodecTestCase
{
    /** RFC 7677's server-first message. */
    private const string SERVER_FIRST = 'r=rOprNGfwEbeRWgbNEkqO%hvYDpWUa2RaTCAfuxFIlj)hNlF$k0,s=W22ZaJ0SNY7soEsUEjb6gQ==,i=4096';

    /**
     * Verifies: [PROTOCOL Mechanisms] [RFC 7677 §3]: user `user`, password
     * `pencil`, the RFC's nonces and salt give its client-final message
     * byte for byte, and its server signature verifies, once: a second
     * server-final is out of turn (SQLSTATE 28000); iterations are read as
     * turborel's u32, a sign and leading zeros taken.
     *
     * @throws TurborelException never, the vector being right
     */
    #[Test]
    public function rfc7677sTestVector(): void
    {
        $exchange = new ScramExchange(null, false, 'user');
        self::assertSame('n,,n=user,r=rOprNGfwEbeRWgbNEkqO', $exchange->first('rOprNGfwEbeRWgbNEkqO'));
        $first = $exchange->read(self::SERVER_FIRST);
        self::assertSame(4096, $first[3]);
        $final = $exchange->prove($first, ...KeyCache::derive('pencil', $first[2], $first[3]));
        self::assertSame('c=biws,r=rOprNGfwEbeRWgbNEkqO%hvYDpWUa2RaTCAfuxFIlj)hNlF$k0,p=dHzbZapWIk4jUhN+Ute9ytag9zjfMHgsqmmiz7AndVQ=', $final);
        self::assertFalse($exchange->verified());
        $exchange->verify('v=6rriTRBi23WpRR/wtup+mMhUZUn/dB5nLTJRsjl95G4=');
        self::assertTrue($exchange->verified());
        self::assertFailure(ConnectionFailure::REFUSED, ConnectionFailure::SQLCODE, static fn() => $exchange->verify('v=6rriTRBi23WpRR/wtup+mMhUZUn/dB5nLTJRsjl95G4='));
        self::assertSame('n,,n=a=3Db=2Cc,r=x', (new ScramExchange(null, false, 'a=b,c'))->first('x'));
        foreach (['+5' => 5, '010' => 10, '4294967295' => 0xFFFF_FFFF] as $iterations => $read) {
            $other = new ScramExchange();
            $other->first('nonce');
            self::assertSame($read, $other->read("r=nonceX,s=AAAA,i={$iterations}")[3]);
        }
    }

    /**
     * Verifies: [PROTOCOL Mechanisms] [RFC 5802 §7]: a server-first message
     * out of turn, of a field missing, extra or misnamed, a nonce that does
     * not extend the client's, a salt not canonical base 64, iterations no
     * positive number, or text not UTF-8 is refused with SQLSTATE 28000.
     */
    #[Test]
    #[DataProvider('serverFirsts')]
    public function aMalformedServerFirstIsRefused(string $message): void
    {
        $exchange = new ScramExchange();
        $exchange->first('nonce');
        self::assertFailure(ConnectionFailure::REFUSED, ConnectionFailure::SQLCODE, static fn(): array => $exchange->read($message));
    }

    /**
     * Server-first messages the client refuses.
     *
     * @return iterable<string, array{string}>
     */
    public static function serverFirsts(): iterable
    {
        yield 'a field missing' => ['r=nonceX,s=AAAA'];
        yield 'a field extra' => ['r=nonceX,s=AAAA,i=1,x=1'];
        yield 'misnamed' => ['n=nonceX,s=AAAA,i=1'];
        yield 'a nonce not extended' => ['r=other,s=AAAA,i=1'];
        yield 'the same nonce' => ['r=nonce,s=AAAA,i=1'];
        yield 'a salt not base 64' => ['r=nonceX,s=*,i=1'];
        yield 'a salt unpadded' => ['r=nonceX,s=Zg,i=1'];
        yield 'a salt of spare bits' => ['r=nonceX,s=Zh==,i=1'];
        yield 'a salt spaced' => ['r=nonceX,s=Z g==,i=1'];
        yield 'not UTF-8' => ["r=nonceX,s=AAAA,i=1\xFF"];
        yield 'iterations signed' => ['r=nonceX,s=AAAA,i=-1'];
        yield 'iterations of a sign alone' => ['r=nonceX,s=AAAA,i=+'];
        yield 'no iterations' => ['r=nonceX,s=AAAA,i=0'];
        yield 'iterations of zeros' => ['r=nonceX,s=AAAA,i=000'];
        yield 'iterations past a u32' => ['r=nonceX,s=AAAA,i=4294967296'];
    }

    /**
     * Verifies: [PROTOCOL Mechanisms] [RFC 5802 §7]: messages out of turn,
     * the server's error, and a signature not base 64 or wrong are refused
     * with SQLSTATE 28000.
     *
     * @throws TurborelException never from the messages in turn
     */
    #[Test]
    public function aServerFinalMustProveTheServerKnowsTheUser(): void
    {
        $refused = static fn(\Closure $attempt) => self::assertFailure(ConnectionFailure::REFUSED, ConnectionFailure::SQLCODE, $attempt);
        $unbegun = new ScramExchange();
        $refused(static fn(): array => $unbegun->read(self::SERVER_FIRST));
        $refused(static fn() => $unbegun->verify('v=AAAA'));
        foreach (['e=invalid-proof', 'v=*', 'v=AAAA', 'x=AAAA'] as $final) {
            $exchange = new ScramExchange(null, false, 'user');
            $exchange->first('rOprNGfwEbeRWgbNEkqO');
            $first = $exchange->read(self::SERVER_FIRST);
            $exchange->prove($first, ...KeyCache::derive('pencil', $first[2], $first[3]));
            $refused(static fn(): array => $exchange->read(self::SERVER_FIRST));
            $refused(static fn() => $exchange->verify($final));
        }
    }
}

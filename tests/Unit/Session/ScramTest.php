<?php

declare(strict_types=1);

namespace Turborel\Tests\Unit\Session;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\CoversNamespace;
use PHPUnit\Framework\Attributes\Test;
use Turborel\Internal\ConnectionFailure;
use Turborel\Session\KeyCache;
use Turborel\Session\Scram;
use Turborel\Session\TlsChannel;
use Turborel\Tests\Support\CodecTestCase;
use Turborel\TurborelException;

/**
 * The mechanism SCRAM-SHA-256 over its exchange: bound, proved, refused.
 */
#[CoversNamespace('Turborel\Session')]
#[CoversNamespace('Turborel\Internal')]
#[CoversClass(TurborelException::class)]
final class ScramTest extends CodecTestCase
{
    /**
     * Verifies: [CLIENT Connecting] [PROTOCOL Mechanisms] [CLIENT The SCRAM key cache]:
     * the mechanism is SCRAM-SHA-256-PLUS bound by `tls-server-end-point`
     * over a certificate that defines one, says it could bind where one
     * defines none, binds the next connection afresh, refuses a login whose server did not prove it knows the
     * verifier (SQLSTATE 08006), forgets the keys of a refused exchange,
     * and never shows its password.
     *
     * @throws TurborelException never from the exchange in turn
     */
    #[Test]
    public function theMechanismBindsProvesAndForgets(): void
    {
        $scram = new Scram('pencil');
        self::assertConnectionFails(static fn(): string => $scram->answer('r=x'));
        $scram->bind(new TlsChannel(null));
        self::assertStringStartsWith('y,,n=,r=', $scram->initial());
        self::assertSame(Scram::NAME, $scram->name());
        self::assertStringStartsWith('n,,n=,r=', $scram->initial(), 'the next connection binds afresh');
        $cache = new KeyCache();
        $scram = (new Scram('pencil'))->withCache($cache);
        $nonce = substr($scram->initial(), \strlen('n,,n=,r='));
        self::assertConnectionFails($scram->ready(...));
        $scram->answer("r={$nonce}X,s=W22ZaJ0SNY7soEsUEjb6gQ==,i=4096");
        self::assertSame(1, $cache->count());
        self::assertFailure(ConnectionFailure::REFUSED, ConnectionFailure::SQLCODE, static fn(): string => $scram->answer('v=AAAA'));
        self::assertSame(0, $cache->count());
        self::assertStringNotContainsString('pencil', print_r($scram->__debugInfo(), true));
    }
}

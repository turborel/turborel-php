<?php

declare(strict_types=1);

namespace Turborel\Tests\Live\Session;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\CoversNamespace;
use PHPUnit\Framework\Attributes\Test;
use Turborel\Internal\ConnectionFailure;
use Turborel\Session\ChannelBinding;
use Turborel\Session\Connection;
use Turborel\Session\KeyCache;
use Turborel\Session\Scram;
use Turborel\Session\Trust;
use Turborel\Tests\Server\LiveServer;
use Turborel\Tests\Support\LiveTestCase;
use Turborel\TurborelException;

/**
 * SCRAM against a live server: its key cache, and its channel binding as
 * each mode says.
 */
#[CoversNamespace('Turborel\Session')]
#[CoversNamespace('Turborel\Internal')]
#[CoversClass(TurborelException::class)]
final class ScramTest extends LiveTestCase
{
    /**
     * Verifies: [CLIENT The SCRAM key cache]: a cache given to the
     * mechanism keeps the keys a password derived, so a second login of
     * the same salt and iterations finds them; a login with a wrong
     * password that the server refuses leaves no entry of its own.
     *
     * @throws TurborelException never from the logins with the right password
     */
    #[Test]
    public function aKeyCacheSkipsTheDerivationOfALaterLogin(): void
    {
        $cache = new KeyCache();
        foreach ([1, 2] as $login) {
            Connection::connect(self::socket(), 'bob', (new Scram(LiveServer::BOB_PASSWORD))->withCache($cache))->terminate();
        }
        self::assertSame([[1, 1], 1], [$cache->lookups(), $cache->count()]);
        self::assertFailure(ConnectionFailure::REFUSED, ConnectionFailure::SQLCODE, static fn(): Connection => Connection::connect(self::socket(), 'bob', (new Scram('wrong'))->withCache($cache)));
        self::assertSame([[1, 2], 1], [$cache->lookups(), $cache->count()]);
    }

    /**
     * Verifies: [CLIENT TLS by mode] [CLIENT Connecting] [PROTOCOL Mechanisms]:
     * binding required over TLS 1.3 binds by `tls-server-end-point`, PHP's
     * streams exporting no keying material (RULES.md D8); over a socket, which binds
     * nothing, it refuses the exchange (SQLSTATE 28000); disabled over TLS,
     * the exchange is SCRAM-SHA-256, unbound, and the server takes it; a
     * mechanism that bound one login binds the next afresh.
     *
     * @throws TurborelException never from the bound and the unbound logins
     */
    #[Test]
    public function bindingIsAsItsModeSays(): void
    {
        $required = (new Scram(LiveServer::BOB_PASSWORD))->channelBinding(ChannelBinding::Require);
        $bound = Connection::connect(self::tls(), 'bob', $required);
        self::assertSame(['TLSv1.3', 'BOB'], [$bound->wire()->stream->tlsProtocol(), $bound->authorization()]);
        $bound->terminate();
        self::assertSame(Scram::NAME, $required->name(), 'the next connection binds afresh');
        self::assertNull(Connection::connect(self::tcp(), 'ann', new Trust())->wire()->stream->tlsProtocol());
        $refused = (new Scram(LiveServer::BOB_PASSWORD))->channelBinding(ChannelBinding::Require);
        self::assertFailure(ConnectionFailure::REFUSED, ConnectionFailure::SQLCODE, static fn(): Connection => Connection::connect(self::socket(), 'bob', $refused));
        $disabled = (new Scram(LiveServer::BOB_PASSWORD))->channelBinding(ChannelBinding::Disable);
        Connection::connect(self::tls(), 'bob', $disabled)->terminate();
        self::assertSame(Scram::NAME, $disabled->name());
    }
}

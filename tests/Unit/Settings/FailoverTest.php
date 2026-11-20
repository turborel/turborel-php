<?php

declare(strict_types=1);

namespace Turborel\Tests\Unit\Settings;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\CoversNamespace;
use PHPUnit\Framework\Attributes\Test;
use Turborel\Internal\ConnectionFailure;
use Turborel\Internal\Settings\Failover;
use Turborel\Internal\Settings\Modes;
use Turborel\Internal\Settings\Roles;
use Turborel\Internal\Transport\Opener;
use Turborel\Session\Target;
use Turborel\Session\Tls;
use Turborel\Settings\Role;
use Turborel\Settings\TlsFiles;
use Turborel\Settings\TlsMode;
use Turborel\Statements\Outcome;
use Turborel\Tests\Support\CodecTestCase;
use Turborel\TurborelException;

/**
 * Connecting by settings with no server: why no server was kept, a
 * server's role read from REPLICAS, TLS by mode, and `prefer` falling
 * back to plain TCP after a failed handshake.
 */
#[CoversNamespace('Turborel\Internal')]
#[CoversNamespace('Turborel\Settings')]
#[CoversNamespace('Turborel\Session')]
#[CoversNamespace('Turborel\Statements')]
#[CoversClass(TurborelException::class)]
final class FailoverTest extends CodecTestCase
{
    /**
     * Verifies: [CLIENT Several servers]: of one server tried, its own
     * failure is given; of several, SQLSTATE 08001 names each and why, or
     * 28000 where the last refused the user; of none, 08001.
     */
    #[Test]
    public function noServerKeptSaysWhyEachWasNot(): void
    {
        $lost = ConnectionFailure::because('cannot connect');
        $refused = ConnectionFailure::refused('no');
        self::assertSame('28000', Failover::noneKept([['a', $refused]])->getSqlState());
        $both = Failover::noneKept([['a', $refused], ['b', $lost]]);
        self::assertSame(['08001', -919, 'no server was kept: a: no; b: cannot connect'], [$both->getSqlState(), $both->getSqlCode(), $both->getMessage()]);
        self::assertSame('28000', Failover::noneKept([['b', $lost], ['a', $refused]])->getSqlState());
        self::assertSame(['08001', 'no server is named'], [Failover::noneKept([])->getSqlState(), Failover::noneKept([])->getMessage()]);
    }

    /**
     * Verifies: [CLIENT Several servers]: a server alone, which has no
     * REPLICAS (class 2A or 42), takes changes; of a replica, PRIMARY is
     * read-write, BACKUP or UNKNOWN read-only; no row, as a session not the
     * owner's sees, is unknown; another failure is the query's.
     *
     * @throws TurborelException never from the outcomes and the failures of no table
     */
    #[Test]
    public function aServersRoleIsReadFromReplicas(): void
    {
        $shown = static fn(string $role): Outcome => new Outcome([], [[$role]], null);
        self::assertSame([Role::ReadWrite, Role::ReadWrite, Role::ReadWrite, Role::ReadOnly, Role::ReadOnly, Role::Unknown, Role::Unknown], [
            Roles::shown(new TurborelException('no table', '2A000', -103)), Roles::shown(new TurborelException('no table', '42S02', -103)),
            Roles::shown($shown('PRIMARY')), Roles::shown($shown('BACKUP ')), Roles::shown($shown('UNKNOWN')), Roles::shown(new Outcome([], [], null)),
            Roles::shown(new Outcome([], [], null))]);
        self::assertConnectionFails(static fn(): Role => Roles::shown(ConnectionFailure::because('lost')));
        self::assertSame('of a role this session cannot see', Role::Unknown->describe());
    }

    /**
     * Verifies: [CLIENT TLS by mode]: `disable` makes no TLS; `prefer` and
     * `require` check no certificate; `verify-ca` and `verify-full` need an
     * authority trusted, else fail; a client certificate is presented with
     * its key, and one named without a key fails.
     *
     * @throws TurborelException never from the modes that make TLS
     */
    #[Test]
    public function tlsIsMadeAsItsModeSays(): void
    {
        self::assertNull(Modes::tls(TlsMode::Disable, new TlsFiles()));
        self::assertSame('require', Modes::tls(TlsMode::Prefer, new TlsFiles())?->verification->value);
        self::assertSame('require', Modes::tls(TlsMode::Require, new TlsFiles('/no/such/roots'))?->verification->value, 'no roots read');
        foreach ([TlsMode::VerifyCa, TlsMode::VerifyFull] as $mode) {
            self::assertConnectionFails(static fn(): ?Tls => Modes::tls($mode, new TlsFiles()));
        }
        self::assertConnectionFails(static fn(): ?Tls => Modes::tls(TlsMode::Require, new TlsFiles(null, false, '/a/chain.pem')));
    }

    /**
     * Verifies: [CLIENT TLS by mode]: under `prefer`, a server reached
     * whose TLS handshake fails is connected to again over plain TCP; one
     * that cannot be reached is not tried twice.
     *
     * @throws TurborelException never from the server listening
     */
    #[Test]
    public function preferFallsBackToPlainTcpAfterAFailedHandshake(): void
    {
        $listener = stream_socket_server('tcp://127.0.0.1:0');
        self::assertIsResource($listener);
        $port = (int) substr((string) strrchr((string) stream_socket_get_name($listener, false), ':'), 1);
        [$stream, $channel, $reached] = Opener::preferring(Target::tls('127.0.0.1', Tls::unverified(), $port), 0.3);
        self::assertSame([null, null, true], [$channel, $reached->tls, $reached->isPlainTcp()]);
        $stream->close();
        fclose($listener);
        self::assertConnectionFails(static fn(): array => Opener::preferring(Target::tls('127.0.0.1', Tls::unverified(), $port), 0.3));
        self::assertConnectionFails(static fn(): array => Opener::preferring(Target::tcp('127.0.0.1', $port), 0.3));
    }
}

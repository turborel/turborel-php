<?php

declare(strict_types=1);

namespace Turborel\Tests\Live\Session;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\CoversNamespace;
use PHPUnit\Framework\Attributes\Test;
use Turborel\Internal\ConnectionFailure;
use Turborel\Internal\Quietly;
use Turborel\Session\Certificate;
use Turborel\Session\ChannelBinding;
use Turborel\Session\Connection;
use Turborel\Session\Password;
use Turborel\Session\Scram;
use Turborel\Session\Target;
use Turborel\Session\Timeouts;
use Turborel\Session\Tls;
use Turborel\Session\TlsChannel;
use Turborel\Session\Trust;
use Turborel\Tests\Server\LiveServer;
use Turborel\Tests\Support\LiveTestCase;
use Turborel\TurborelException;

/**
 * Connecting to a live server over each transport, by each mechanism,
 * and the logins it refuses.
 */
#[CoversNamespace('Turborel\Session')]
#[CoversNamespace('Turborel\Internal')]
#[CoversNamespace('Turborel\Statements')]
#[CoversNamespace('Turborel\Value')]
#[CoversNamespace('Turborel\Type')]
#[CoversClass(TurborelException::class)]
final class ConnectingTest extends LiveTestCase
{
    /**
     * Verifies: [CLIENT Connecting] [PROTOCOL The Native Protocol] [PROTOCOL Mechanisms]:
     * a connection over TCP by `trust`, the server trusting a client of its
     * own machine, gives the server's product and the session's
     * authorization identifier as Ready told them, the user read as an
     * identifier.
     *
     * @throws TurborelException never, the server trusting local clients
     */
    #[Test]
    public function aConnectionOverTcpIsTrustedAsALocalClient(): void
    {
        $connection = self::owner();
        self::assertSame(['ANN', 'turborel 0.0.0 (alpha)'], [$connection->authorization(), $connection->server()]);
        self::assertSame('127.0.0.1:17436', (string) $connection->target());
        $connection->terminate();
    }

    /**
     * Verifies: [CLIENT Connecting] [PROTOCOL The Native Protocol] [PROTOCOL Mechanisms]:
     * over the server's Unix socket, SCRAM-SHA-256 proves BOB's password
     * with no TLS, the server proving it knows the verifier; over TLS,
     * begun on the TCP port, it is SCRAM-SHA-256-PLUS bound by
     * `tls-server-end-point`; and `password` is sent whole over TLS.
     *
     * @throws TurborelException never, the passwords being right
     */
    #[Test]
    public function aPasswordIsProvedOverASocketAndOverTls(): void
    {
        foreach ([self::socket(), self::tls()] as $target) {
            // Binding required over TLS, so the login is SCRAM-SHA-256-PLUS or none.
            $scram = (new Scram(LiveServer::BOB_PASSWORD))->channelBinding($target->tls === null ? ChannelBinding::Prefer : ChannelBinding::Require);
            $connection = Connection::connect($target, 'bob', $scram);
            self::assertSame('BOB', $connection->authorization());
            $connection->terminate();
        }
        $connection = Connection::connect(self::tls(), 'ann', new Password(LiveServer::ANN_PASSWORD));
        self::assertSame('ANN', $connection->authorization());
        $connection->terminate();
    }

    /**
     * Verifies: [CLIENT Connecting] [PROTOCOL Mechanisms]: `certificate`
     * logs ANN in by the certificate her TLS presents, of the test
     * authority, its common name hers; as another user it is refused
     * (SQLSTATE 28000).
     *
     * @throws TurborelException never, the certificate being ANN's
     */
    #[Test]
    public function aClientCertificateProvesItsUser(): void
    {
        $pki = self::server()->pki;
        $target = Target::tls('localhost', Tls::presenting($pki->authority, $pki->clientCertificate, $pki->clientKey), LiveServer::PORT);
        $connection = Connection::connect($target, 'ann', new Certificate());
        self::assertSame('ANN', $connection->authorization());
        $connection->terminate();
        self::assertFailure(ConnectionFailure::REFUSED, ConnectionFailure::SQLCODE, static fn(): Connection => Connection::connect($target, 'bob', new Certificate()));
    }

    /**
     * Verifies: [CLIENT Connecting] [PROTOCOL Opening a connection]: a wrong
     * password is refused with SQLSTATE 28000, by an Error after which the
     * server closes; a user name that is no identifier, and a mechanism
     * the server does not serve, are refused before any exchange.
     */
    #[Test]
    public function aLoginIsRefusedAsTheServerRefusesIt(): void
    {
        $refused = [
            static fn(): Connection => Connection::connect(self::tls(), 'ann', new Password('wrong')),
            static fn(): Connection => Connection::connect(self::socket(), 'bob', new Scram('wrong')),
            static fn(): Connection => Connection::connect(self::tcp(), 'no user!', new Trust()),
            static fn(): Connection => Connection::connect(self::tcp(), 'ann', new Unserved()),
        ];
        foreach ($refused as $attempt) {
            self::assertFailure(ConnectionFailure::REFUSED, ConnectionFailure::SQLCODE, $attempt);
        }
    }

    /**
     * Verifies: [CLIENT Connecting]: a password, or SCRAM, is refused over
     * plain TCP before anything is sent (SQLSTATE 28000); SCRAM in the
     * clear is sent where the program says so, and refused by a server
     * whose SERVER.SCRAM_WITHOUT_TLS is OFF.
     */
    #[Test]
    public function aSecretIsNeverSentOverPlainTcp(): void
    {
        foreach ([new Password('x'), new Scram('x')] as $mechanism) {
            self::assertFailure(ConnectionFailure::REFUSED, ConnectionFailure::SQLCODE, static fn(): Connection => Connection::connect(Target::tcp('127.0.0.1', 1), 'ann', $mechanism));
        }
        $clear = (new Scram(LiveServer::BOB_PASSWORD))->inTheClear();
        self::assertFalse($clear->secret());
        self::assertFailure(ConnectionFailure::REFUSED, ConnectionFailure::SQLCODE, static fn(): Connection => Connection::connect(self::tcp(), 'bob', $clear));
    }

    /**
     * Verifies: [CLIENT Connecting] [PROTOCOL The Native Protocol]: TLS
     * trusting authorities that did not issue the server's certificate, or
     * checking it against another name, fails the handshake (SQLSTATE
     * 08006); the platform's authorities, as SSL_CERT_FILE names them, and
     * a file's and the platform's both, are trusted.
     *
     * @throws TurborelException never from the authorities that issued it
     */
    #[Test]
    public function tlsTrustsTheAuthoritiesItIsGiven(): void
    {
        $pki = self::server()->pki;
        $untrusted = Tls::trusting($pki->clientCertificate);
        foreach ([Target::tls('localhost', $untrusted, LiveServer::PORT), Target::tls('127.0.0.1', Tls::trusting($pki->authority)->forServerName('db.example'), LiveServer::PORT)] as $target) {
            self::assertConnectionFails(static fn(): Connection => Connection::connect($target, 'ann', new Trust()));
        }
        $previous = getenv('SSL_CERT_FILE');
        putenv("SSL_CERT_FILE={$pki->authority}");
        try {
            foreach ([Tls::roots(null, true), Tls::roots($pki->authority, true)] as $tls) {
                Connection::connect(Target::tls('localhost', $tls, LiveServer::PORT), 'ann', new Trust())->terminate();
            }
        } finally {
            putenv($previous === false ? 'SSL_CERT_FILE' : "SSL_CERT_FILE={$previous}");
        }
        self::assertSame('ANN', Connection::connect(Target::tls('127.0.0.1', Tls::trusting($pki->authority)->forServerName('localhost'), LiveServer::PORT), 'ann', new Trust())->authorization());
    }

    /**
     * Verifies: [CLIENT Connecting] [CLIENT Timeouts]: a TLS stream the
     * program opened is bound before SCRAM-SHA-256-PLUS, and logs in; such
     * a connection has no canceller, and its maker bounds it, Terminate's
     * wait too; opened within Timeouts, the driver bounds it as they say,
     * its read timeout settable as a connection's.
     *
     * @throws TurborelException never, the stream being the server's
     */
    #[Test]
    public function aStreamTheProgramOpenedLogsIn(): void
    {
        $context = stream_context_create(['ssl' => ['cafile' => self::server()->pki->authority, 'peer_name' => 'localhost', 'capture_peer_cert' => true]]);
        [$stream] = Quietly::call(static fn(): mixed => stream_socket_client('tls://127.0.0.1:' . LiveServer::PORT, $code, $message, 5.0, \STREAM_CLIENT_CONNECT, $context));
        self::assertIsResource($stream);
        $scram = (new Scram(LiveServer::BOB_PASSWORD))->channelBinding(ChannelBinding::Require);
        $scram->bind(TlsChannel::of($stream));
        self::assertSame(Scram::NAME_PLUS, $scram->name());
        $connection = Connection::open($stream, 'bob', $scram);
        self::assertSame(['BOB', null, null, null], [$connection->authorization(), $connection->canceller(), $connection->target(), $connection->readTimeout()]);
        self::assertConnectionFails(static fn() => $connection->setReadTimeout(1.0));
        $connection->terminate();
        [$own] = Quietly::call(static fn(): mixed => stream_socket_client('unix://' . self::server()->socket(), $code, $message, 5.0));
        self::assertIsResource($own);
        $within = Connection::open($own, 'ann', new Trust(), null, new Timeouts(5.0, 2.0));
        self::assertSame(2.0, $within->readTimeout());
        $within->setReadTimeout(3.0);
        self::assertSame([3.0, 'ANN'], [$within->readTimeout(), $within->authorization()]);
        $within->query('VALUES 1');
        $within->terminate();
    }

    /**
     * Verifies: [PROTOCOL Opening a connection]: a Hello of a version other
     * than 1 is refused with SQLSTATE 08004.
     *
     * @throws TurborelException never from the raw connection
     */
    #[Test]
    public function aVersionOtherThanOneIsRefused(): void
    {
        $hello = self::writer()->u32(2)->string('ann')->string(Trust::NAME)->bytes('')->strings([])->bytesWritten();
        $error = Raw::exchange(self::tcp(), 'H', $hello);
        self::assertSame('08004', $error->sqlstate);
    }
}

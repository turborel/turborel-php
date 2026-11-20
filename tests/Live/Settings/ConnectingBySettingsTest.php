<?php

declare(strict_types=1);

namespace Turborel\Tests\Live\Settings;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\CoversNamespace;
use PHPUnit\Framework\Attributes\Test;
use Turborel\Session\Connection;
use Turborel\Session\Offer;
use Turborel\Session\Trust;
use Turborel\Settings\Options;
use Turborel\Settings\Role;
use Turborel\Settings\Settings;
use Turborel\Tests\Server\LiveServer;
use Turborel\Tests\Support\LiveTestCase;
use Turborel\TurborelException;

/**
 * Connections made as settings say, to a live server: by a URI, a service
 * file and a password file; to several hosts; by each TLS mode and channel
 * binding; and of the role asked.
 */
#[CoversNamespace('Turborel\Settings')]
#[CoversNamespace('Turborel\Session')]
#[CoversNamespace('Turborel\Internal')]
#[CoversNamespace('Turborel\Statements')]
#[CoversNamespace('Turborel\Value')]
#[CoversNamespace('Turborel\Type')]
#[CoversClass(TurborelException::class)]
final class ConnectingBySettingsTest extends LiveTestCase
{
    /** A port no server listens on. */
    private const int NOWHERE = 1;

    /**
     * Verifies: [CLIENT Connecting by settings] [CLIENT Several servers]
     * [CLIENT Where settings come from]: a connection is made as a URI says,
     * its hosts tried in order, each address in turn, a server that cannot
     * be reached passed for the next, in a random order where asked; with none kept, the failure names each
     * and why (SQLSTATE 08001); the program's name is sent as the
     * connection attribute `application`.
     *
     * @throws TurborelException never from the servers reached
     */
    #[Test]
    public function hostsAreTriedInTurn(): void
    {
        self::server();
        $uri = 'turborel://ann@127.0.0.1:' . self::NOWHERE . ',localhost:' . LiveServer::PORT . '/?tls_mode=disable&connect_timeout=5&application_name=reports&load_balance=random';
        $connection = Settings::resolveIn(Options::fromUri($uri), self::none())->connect();
        self::assertSame('ANN', $connection->authorization());
        $shown = $connection->query("SELECT ATTRIBUTE_VALUE FROM INFORMATION_SCHEMA.CONNECTION_ATTRIBUTES WHERE ATTRIBUTE_NAME = 'application'")->rows;
        self::assertContains(['reports'], $shown);
        $connection->terminate();
        try {
            Settings::resolveIn(Options::fromUri('turborel://ann@127.0.0.1:' . self::NOWHERE . ',no-such-host.invalid/?tls_mode=disable'), self::none())->connect();
            self::fail('no server');
        } catch (TurborelException $none) {
            self::assertSame('08001', $none->getSqlState());
            self::assertStringContainsString('127.0.0.1 (127.0.0.1:1)', $none->getMessage());
            self::assertStringContainsString('no-such-host.invalid:7422', $none->getMessage());
        }
    }

    /**
     * Verifies: [CLIENT Connecting by settings] [CLIENT The password file]
     * [CLIENT The SCRAM key cache] [CLIENT TLS by mode]: a password the
     * password file gives is proved by SCRAM-SHA-256, bound over TLS
     * verified against `server_name`; a local socket's is matched by its
     * path; the settings' one key cache serves each connection they make; a
     * secret is never sent over plain TCP (SQLSTATE 28000).
     *
     * @throws TurborelException never from the logins proved
     */
    #[Test]
    public function aPasswordFileProvesItsUser(): void
    {
        $server = self::server();
        $passfile = "{$server->directory}/passfile";
        file_put_contents($passfile, "127.0.0.1:" . LiveServer::PORT . ':bob:' . LiveServer::BOB_PASSWORD . "\n" . $server->socket() . ':7422:bob:' . LiveServer::BOB_PASSWORD . "\n");
        $options = (new Options())->set(Options::USER, 'bob')->set(Options::HOST, '127.0.0.1')->set(Options::PORT, (string) LiveServer::PORT)
            ->set(Options::PASSFILE, $passfile)->set(Options::TLS_MODE, 'verify-full')->set(Options::TLS_ROOTS, $server->pki->authority)
            ->set(Options::SERVER_NAME, 'localhost')->set(Options::CHANNEL_BINDING, 'require');
        $settings = Settings::resolveIn($options, self::none());
        foreach ([1, 2] as $login) {
            $connection = $settings->connect();
            self::assertSame(['BOB', 'TLSv1.3'], [$connection->authorization(), $connection->wire()->stream->tlsProtocol()]);
            $connection->terminate();
        }
        self::assertSame([1, 1], $settings->keyCache->lookups());
        $local = Settings::resolveIn((clone $options)->set(Options::HOST, $server->socket())->set(Options::CHANNEL_BINDING, 'prefer'), self::none())->connect();
        self::assertSame('BOB', $local->authorization());
        $local->terminate();
        $plain = (clone $options)->set(Options::TLS_MODE, 'disable')->set(Options::CHANNEL_BINDING, 'prefer');
        self::assertFailure('28000', -919, static fn(): Connection => Settings::resolveIn($plain, self::none())->connect());
    }

    /**
     * Verifies: [CLIENT TLS by mode] [CLIENT Connecting by settings]: each
     * mode connects as it says: `disable` plain, `prefer` and `require` TLS
     * checking no certificate, `verify-ca` an authority but not the name,
     * `verify-full` both, failing on another name; a Unix socket uses none;
     * with no password, the client certificate named proves the user;
     * channel binding required of a mechanism that binds nothing, or over a
     * socket, is refused (SQLSTATE 28000).
     *
     * @throws TurborelException never from the modes that connect
     */
    #[Test]
    public function eachTlsModeConnectsAsItSays(): void
    {
        $server = self::server();
        $base = (new Options())->set(Options::USER, 'ann')->set(Options::HOST, '127.0.0.1')->set(Options::PORT, (string) LiveServer::PORT)
            ->set(Options::TLS_ROOTS, $server->pki->authority)->set(Options::SERVER_NAME, 'wrong.example');
        $modes = ['disable' => null, 'prefer' => 'TLSv1.3', 'require' => 'TLSv1.3', 'verify-ca' => 'TLSv1.3'];
        foreach ($modes as $mode => $protocol) {
            $connection = Settings::resolveIn((clone $base)->set(Options::TLS_MODE, $mode), self::none())->connect();
            self::assertSame($protocol, $connection->wire()->stream->tlsProtocol(), $mode);
            $connection->terminate();
        }
        self::assertConnectionFails(static fn(): Connection => Settings::resolveIn((clone $base)->set(Options::TLS_MODE, 'verify-full'), self::none())->connect());
        $local = Settings::resolveIn((clone $base)->set(Options::TLS_MODE, 'require')->set(Options::HOST, $server->socket()), self::none())->connect();
        self::assertNull($local->wire()->stream->tlsProtocol());
        $local->terminate();
        $certified = (clone $base)->set(Options::TLS_MODE, 'verify-full')->set(Options::SERVER_NAME, 'localhost')
            ->set(Options::TLS_CERTIFICATE, $server->pki->clientCertificate)->set(Options::TLS_KEY, $server->pki->clientKey);
        $byCertificate = Settings::resolveIn($certified, self::none())->connect();
        self::assertSame('ANN', $byCertificate->authorization());
        $byCertificate->terminate();
        $bound = (clone $base)->set(Options::TLS_MODE, 'require')->set(Options::CHANNEL_BINDING, 'require');
        self::assertFailure('28000', -919, static fn(): Connection => Settings::resolveIn($bound, self::none())->connect());
    }

    /**
     * Verifies: [CLIENT Several servers] [CLIENT The service file]: `target`
     * keeps a server of the role asked, told by SERVER_READ_ONLY: a server
     * alone takes changes, so `read-write` keeps it and `read-only` passes
     * it (SQLSTATE 08001); of a server that does not tell it, the role is
     * read from INFORMATION_SCHEMA.REPLICAS; a service file's section names
     * the settings.
     *
     * @throws TurborelException never from the roles kept
     */
    #[Test]
    public function aServerOfTheRoleAskedIsKept(): void
    {
        $server = self::server();
        $services = "{$server->directory}/services.conf";
        file_put_contents($services, "[writer]\nhost = 127.0.0.1\nport = " . LiveServer::PORT . "\nuser = ann\ntls_mode = disable\ntarget = read-write\n");
        $options = (new Options())->set(Options::SERVICE, 'writer')->set(Options::SERVICE_FILE, $services);
        $writer = Settings::resolveIn($options, self::none())->connect();
        self::assertSame(Role::ReadWrite, Role::of($writer));
        $writer->terminate();
        $reader = (clone $options)->set(Options::TARGET, 'read-only');
        try {
            Settings::resolveIn($reader, self::none())->connect();
            self::fail('a server alone is read-write');
        } catch (TurborelException $passed) {
            self::assertSame(['08001', 'the server is read-write, and read-only is asked'], [$passed->getSqlState(), $passed->getMessage()]);
        }
        $untold = Connection::connect(self::tcp(), 'ann', new Trust(), null, Offer::none());
        self::assertSame(Role::ReadWrite, Role::of($untold));
        self::assertFalse($untold->inTransaction());
        $untold->terminate();
    }

    /**
     * An environment of no variable.
     *
     * @return \Closure(string): null
     */
    private static function none(): \Closure
    {
        return static fn(string $name): mixed => null;
    }
}

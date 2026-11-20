<?php

declare(strict_types=1);

namespace Turborel\Tests\Unit\Session;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\CoversNamespace;
use PHPUnit\Framework\Attributes\Test;
use Turborel\Internal\Misuse;
use Turborel\Session\Capability;
use Turborel\Session\Certificate;
use Turborel\Session\Defaults;
use Turborel\Session\Offer;
use Turborel\Session\Password;
use Turborel\Session\Target;
use Turborel\Session\Timeouts;
use Turborel\Session\Tls;
use Turborel\Session\Trust;
use Turborel\Session\Verification;
use Turborel\Tests\Server\Pki;
use Turborel\Tests\Support\CodecTestCase;
use Turborel\TurborelException;

/**
 * What a connection is made of before it connects: its target, timeouts,
 * offer, TLS and mechanism.
 */
#[CoversNamespace('Turborel\Session')]
#[CoversNamespace('Turborel\Internal')]
#[CoversClass(TurborelException::class)]
final class ConfigurationTest extends CodecTestCase
{
    /** The test authority's certificates, once made. */
    private static ?Pki $pki = null;

    /**
     * Verifies: [PROTOCOL The Native Protocol] [CLIENT Connecting]: a target
     * is TCP, on port 7422 unless told another, a Unix socket or named
     * pipe, or TLS on the TCP port; a port out of range is refused
     * (SQLSTATE TB910).
     *
     * @throws TurborelException never from the ports in range
     */
    #[Test]
    public function aTargetIsTcpLocalOrTls(): void
    {
        $tls = Tls::trusting(self::authority());
        self::assertSame(['db:7422', '[::1]:7500', '/run/s', 'tls://db:7422'], [(string) Target::tcp('db'), (string) Target::tcp('::1', 7500),
            (string) Target::local('/run/s'), (string) Target::tls('db', $tls)]);
        self::assertSame([true, false, false], [Target::tcp('db')->isPlainTcp(), Target::local('/s')->isPlainTcp(), Target::tls('db', $tls)->isPlainTcp()]);
        foreach ([0, 65_536] as $port) {
            self::assertFailure(Misuse::SQLSTATE, Misuse::SQLCODE, static fn(): Target => Target::tcp('db', $port));
        }
    }

    /**
     * Verifies: [CLIENT Timeouts]: a connection waits 30 seconds to connect
     * and log in, and on each read after with no limit, by default; a
     * limit not positive is refused (SQLSTATE TB910).
     *
     * @throws TurborelException never from the defaults
     */
    #[Test]
    public function timeoutsDefaultAsTurborelClientsDo(): void
    {
        $default = new Timeouts();
        self::assertSame([30.0, null, 60.0], [$default->connect, $default->read, Timeouts::TERMINATE_WITHIN]);
        self::assertFailure(Misuse::SQLSTATE, Misuse::SQLCODE, static fn(): Timeouts => new Timeouts(0.0));
        self::assertFailure(Misuse::SQLSTATE, Misuse::SQLCODE, static fn(): Timeouts => new Timeouts(null, -1.0));
    }

    /**
     * Verifies: [CLIENT What a connection asks for]: an offer asks for every
     * capability but compression by default; none; compression added once;
     * and connection attributes.
     */
    #[Test]
    public function anOfferAsksWhatItIsTold(): void
    {
        $default = new Offer();
        self::assertSame(array_values(array_diff(Capability::ALL, [Capability::COMPRESSION])), $default->capabilities);
        self::assertSame(Defaults::CAPABILITIES, $default->capabilities);
        self::assertSame([], Offer::none()->capabilities);
        self::assertSame([Capability::COMPRESSION], Offer::none()->compressed()->compressed()->capabilities);
        self::assertSame([['app', 'php']], $default->attribute('app', 'php')->attributes);
    }

    /**
     * Verifies: [CLIENT Connecting]: TLS trusts the authorities of a file
     * that holds one, made for the run, refusing a file not there or holding none, and no
     * authority at all (SQLSTATE 08006); it presents a chain and key that
     * are there; it checks the server's certificate as told.
     *
     * @throws TurborelException never from the fixture's authorities
     */
    #[Test]
    public function tlsTrustsReadableAuthorities(): void
    {
        $fixture = self::authority();
        foreach ([static fn(): Tls => Tls::trusting('/no/such/file'), static fn(): Tls => Tls::trusting(__FILE__), static fn(): Tls => Tls::roots(null, false),
            static fn(): Tls => Tls::presenting($fixture, '/no/chain', '/no/key')] as $refused) {
            self::assertConnectionFails($refused);
        }
        $tls = Tls::trusting($fixture)->presentingAlso($fixture, $fixture)->verifying(Verification::Authority)->forServerName('db');
        self::assertSame([Verification::Authority, 'db', $fixture], [$tls->verification, $tls->serverName, $tls->identity?->chain]);
        self::assertSame($fixture, Tls::roots($fixture, false)->authorities->path);
    }

    /**
     * Verifies: [PROTOCOL Mechanisms] [CLIENT Connecting]: `trust`,
     * `password` and `certificate` are named as the spec names them, have
     * no answer to a challenge (SQLSTATE 08006), and only `password` sends
     * a secret, which it never shows.
     *
     * @throws TurborelException never from ready and refused
     */
    #[Test]
    public function theSimpleMechanismsAreAsTheSpecNamesThem(): void
    {
        foreach ([[new Trust(), 'trust', '', false], [new Password('pw'), 'password', 'pw', true], [new Certificate(), 'certificate', '', false]] as [$mechanism, $name, $initial, $secret]) {
            self::assertSame([$name, $initial, $secret], [$mechanism->name(), $mechanism->initial(), $mechanism->secret()]);
            self::assertConnectionFails(static fn(): string => $mechanism->answer('x'));
            $mechanism->ready();
            $mechanism->refused();
        }
        self::assertStringNotContainsString('pw', print_r((new Password('pw'))->__debugInfo(), true));
    }

    /** The PEM file of a test authority, made once for the run. */
    private static function authority(): string
    {
        if (self::$pki === null) {
            $directory = sys_get_temp_dir() . '/turborel-php-unit-' . getmypid();
            if (!is_dir($directory)) {
                mkdir($directory);
            }
            self::$pki = Pki::make($directory);
            register_shutdown_function(static function () use ($directory): void {
                $files = glob("{$directory}/*");
                array_map(unlink(...), $files === false ? [] : $files);
                rmdir($directory);
            });
        }

        return self::$pki->authority;
    }
}

<?php

declare(strict_types=1);

namespace Turborel\Tests\Unit\Settings;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\CoversNamespace;
use PHPUnit\Framework\Attributes\Test;
use Turborel\Internal\Settings\Files;
use Turborel\Internal\Settings\Invalid;
use Turborel\Session\ChannelBinding;
use Turborel\Settings\Host;
use Turborel\Settings\LoadBalance;
use Turborel\Settings\Options;
use Turborel\Settings\Settings;
use Turborel\Settings\TargetRole;
use Turborel\Settings\TlsMode;
use Turborel\Tests\Support\CodecTestCase;
use Turborel\TurborelException;

/**
 * Connection settings: the URI, the service file, the password file, the
 * environment, and the order the layers are laid in; turborel-client's
 * settings tests, case for case (RULES.md R-TEST-9).
 */
#[CoversNamespace('Turborel\Settings')]
#[CoversNamespace('Turborel\Internal\Settings')]
#[CoversNamespace('Turborel\Internal')]
#[CoversNamespace('Turborel\Session')]
#[CoversClass(TurborelException::class)]
final class SettingsTest extends CodecTestCase
{
    /** @var list<string> the scratch files a test made, removed after it */
    private array $scratch = [];

    protected function tearDown(): void
    {
        foreach ($this->scratch as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
    }

    /**
     * Verifies: [CLIENT The connection URI] [CLIENT Where settings come from]:
     * a URI gives its user, password, hosts each of its port, an IPv6
     * address in brackets, a local socket percent-encoded, and its query's
     * settings, each part percent-decoded; the password is never shown; a
     * URI of no host names localhost.
     *
     * @throws TurborelException never, the URIs being well formed
     */
    #[Test]
    public function aUriGivesItsUserHostsAndSettings(): void
    {
        $options = Options::fromUri('turborel://ann:p%40ss@db1:7000,[::1]:7001,db3,%2Frun%2Fs.sock/?tls_mode=verify-full&target=read-write&connect_timeout=5');
        self::assertSame(['ann', 'p@ss'], [$options->get(Options::USER), $options->get(Options::PASSWORD)]);
        $settings = Settings::resolveIn($options, self::environment([]));
        self::assertEquals([Host::tcp('db1', 7000), Host::tcp('::1', 7001), Host::tcp('db3', Settings::DEFAULT_PORT), Host::local('/run/s.sock')], $settings->hosts);
        self::assertSame([TlsMode::VerifyFull, TargetRole::ReadWrite, 5.0], [$settings->tlsMode, $settings->target, $settings->connectTimeout]);
        self::assertStringNotContainsString('p@ss', print_r($options, true) . print_r($settings, true), 'the password is never shown');
        $bare = Settings::resolveIn(Options::fromUri('turborel://ann@/'), self::environment([]));
        self::assertEquals([Host::tcp(Settings::DEFAULT_HOST, Settings::DEFAULT_PORT)], $bare->hosts);
    }

    /**
     * Verifies: [CLIENT The connection URI] [CLIENT Where settings come from]:
     * a URI of another scheme, of a path, of a key that names no setting, of
     * a pair without `=`, or of a `%` not followed by two hexadecimal digits
     * is refused (SQLSTATE 08001); so is a word no setting takes, a port
     * that is no number, and no user.
     */
    #[Test]
    public function aMalformedUriOrSettingIsRefused(): void
    {
        foreach (['other://a@b', 'turborel://a@b/db', 'turborel://a@b/?colour=red', 'turborel://a@b/?tls_mode', 'turborel://a%4@b', 'turborel://a%FF@b'] as $uri) {
            self::assertFailure(Invalid::SQLSTATE, Invalid::SQLCODE, static fn(): Options => Options::fromUri($uri));
        }
        foreach (['turborel://a@b/?tls_mode=always', 'turborel://a@b:port', 'turborel://a@b/?connect_timeout=-1', 'turborel://b'] as $uri) {
            self::assertFailure(Invalid::SQLSTATE, Invalid::SQLCODE, static fn(): Settings => Settings::resolveIn(Options::fromUri($uri), self::environment([])));
        }
    }

    /**
     * Verifies: [CLIENT Where settings come from] [CLIENT The service file]:
     * a setting the program gives prevails over the service's, which
     * prevails over the environment's, which prevails over the default; the
     * environment names the service and its file when the program does not;
     * an empty variable gives nothing.
     *
     * @throws TurborelException never, the settings being well formed
     */
    #[Test]
    public function settingsAreLaidProgramServiceEnvironmentDefault(): void
    {
        $file = $this->scratch('precedence', "# servers\n[prod]\nhost = prod-db\nport = 7500\ntls_mode = require\n\n[test]\nhost = test-db\n");
        $environment = self::environment(['TURBOREL_SERVICE' => 'prod', 'TURBOREL_SERVICE_FILE' => $file, 'TURBOREL_USER' => 'env-user',
            'TURBOREL_PORT' => '7600', 'TURBOREL_TLS_MODE' => 'disable', 'TURBOREL_APPLICATION_NAME' => '']);
        $explicit = (new Options())->set(Options::TLS_MODE, 'verify-ca');
        $settings = Settings::resolveIn($explicit, $environment);
        self::assertEquals([Host::tcp('prod-db', 7500)], $settings->hosts, 'the service over the environment');
        self::assertSame([TlsMode::VerifyCa, 'env-user', null, ChannelBinding::Prefer], [$settings->tlsMode, $settings->user, $settings->applicationName, $settings->channelBinding]);
        $explicit->set(Options::SERVICE, 'test');
        self::assertEquals([Host::tcp('test-db', 7600)], Settings::resolveIn($explicit, $environment)->hosts, 'the program names the service');
    }

    /**
     * Verifies: [CLIENT The service file]: a section is its lines'
     * settings; a section that is not there, a setting before any section,
     * a line without `=`, a key that names no setting, and a service or
     * service file set by a service are refused, naming the line.
     *
     * @throws TurborelException never from the files well formed
     */
    #[Test]
    public function aServiceFileIsReadBySection(): void
    {
        $options = Files::section("[a]\nuser=ann\n[b]\nuser = bob\n  host =  h  \n", 'b', 'services.conf');
        self::assertSame(['bob', 'h'], [$options->get(Options::USER), $options->get(Options::HOST)]);
        self::assertStringContainsString('has no service b', self::refusal(static fn() => Files::section("[a]\nuser=ann\n", 'b', 'services.conf')));
        $cases = ["user=ann\n" => 'line 1: a setting comes before any section', "[a]\nuser\n" => 'line 2: a setting is <key> = <value>',
            "[a]\ncolour=red\n" => 'line 2: colour is no connection setting', "[a]\nservice=b\n" => 'line 2: a service cannot set service',
            "[a\n" => "line 1: a section's name ends with ]"];
        foreach ($cases as $text => $why) {
            self::assertStringContainsString($why, self::refusal(static fn() => Files::section($text, 'a', 'services.conf')));
        }
    }

    /**
     * Verifies: [CLIENT The password file]: the password of its first line
     * matching host, port and user, `*` matching any, `\:` and `\\` taken
     * as they are, an escaped `\*` matching only `*`; comments and short
     * lines skipped; the settings' own password prevails; a file that
     * cannot be read gives none.
     *
     * @throws TurborelException never, the settings being well formed
     */
    #[Test]
    public function aPasswordFileMatchesByHostPortAndUser(): void
    {
        $text = "# passwords\nshort:line\ndb1:7422:ann:first\n*:*:ann:any\\:host\ndb\\:2:*:bob:c:\\\\d\n\\*:1:carl:star\n";
        self::assertSame(['first', 'any:host', 'c:\d', null, 'star', null], [Files::password($text, 'db1', '7422', 'ann'), Files::password($text, 'db9', '1', 'ann'),
            Files::password($text, 'db:2', '7', 'bob'), Files::password($text, 'x', '1', 'carl'), Files::password($text, '*', '1', 'carl'), Files::password($text, 'db1', '7422', 'nobody')]);
        $file = $this->scratch('passfile', $text);
        $explicit = (new Options())->set(Options::USER, 'ann')->set(Options::PASSFILE, $file)->set(Options::HOST, 'db1');
        $settings = Settings::resolveIn($explicit, self::environment([]));
        self::assertSame('first', $settings->passwordFor(Host::tcp('db1', 7422)));
        $given = Settings::resolveIn((clone $explicit)->set(Options::PASSWORD, 'given'), self::environment([]));
        self::assertSame('given', $given->passwordFor(Host::tcp('db1', 7422)));
        unlink($file);
        self::assertNull($settings->passwordFor(Host::tcp('db1', 7422)), 'a file that cannot be read gives none');
    }

    /**
     * Verifies: [CLIENT Where settings come from]: every variable of the
     * environment gives its setting, and the words of each kind are read as
     * named; a list of hosts of a list of ports pairs them.
     *
     * @throws TurborelException never, the words being known
     */
    #[Test]
    public function theEnvironmentAndWordsAreRead(): void
    {
        $options = Options::environment(self::environment(array_fill_keys(array_keys(Options::ENVIRONMENT), 'v')));
        foreach (Options::ENVIRONMENT as $key) {
            self::assertSame('v', $options->get($key));
        }
        self::assertTrue(TlsMode::VerifyCa->verifies() && !TlsMode::Require->verifies());
        $refused = self::refusal(static fn() => Settings::resolveIn((new Options())->set(Options::USER, 'u')->set(Options::TARGET, 'primary'), self::environment([])));
        self::assertStringContainsString('any, read-write, read-only', $refused);
        $both = (new Options())->set(Options::HOST, 'a,b')->set(Options::PORT, '1,2')->set(Options::USER, 'u')->set(Options::TLS_SYSTEM_ROOTS, 'true')
            ->set(Options::CHANNEL_BINDING, 'require')->set(Options::LOAD_BALANCE, 'random');
        $settings = Settings::resolveIn($both, self::environment([]));
        self::assertEquals([Host::tcp('a', 1), Host::tcp('b', 2)], $settings->hosts);
        self::assertSame([true, ChannelBinding::Require, LoadBalance::Random], [$settings->tls->systemRoots, $settings->channelBinding, $settings->loadBalance]);
    }

    /**
     * Verifies: [CLIENT Where settings come from]: a list of hosts with an
     * empty one, an IPv6 address whose bracket is not closed or whose port
     * is no number, a list of ports with one that is no number, and a word
     * no channel binding, target, load balance or truth takes are each
     * refused (SQLSTATE 08001); several hosts of one port each take it; a
     * service file that cannot be read is refused.
     *
     * @throws TurborelException never from the settings well formed
     */
    #[Test]
    public function malformedHostsAndWordsAreRefused(): void
    {
        $settings = self::withPairs(...);
        foreach (['a,,b', '[::1', '[::1]:x'] as $host) {
            self::assertFailure(Invalid::SQLSTATE, Invalid::SQLCODE, static fn() => $settings([Options::HOST => $host]));
        }
        self::assertFailure(Invalid::SQLSTATE, Invalid::SQLCODE, static fn() => $settings([Options::HOST => 'a', Options::PORT => '1,x']));
        foreach ([Options::CHANNEL_BINDING, Options::TARGET, Options::LOAD_BALANCE, Options::TLS_SYSTEM_ROOTS] as $key) {
            self::assertFailure(Invalid::SQLSTATE, Invalid::SQLCODE, static fn() => $settings([$key => 'sometimes']));
        }
        self::assertEquals([Host::tcp('a', 9), Host::tcp('b', 9), Host::tcp('::1', 9)], $settings([Options::HOST => 'a,b,[::1]', Options::PORT => '9'])->hosts);
        self::assertFailure(Invalid::SQLSTATE, Invalid::SQLCODE, static fn() => Settings::service(sys_get_temp_dir() . '/turborel-php-no-service-' . getmypid(), 's'));
        self::assertFailure(Invalid::SQLSTATE, Invalid::SQLCODE, static fn() => $settings([Options::PORT => '65536']));
        self::assertEquals([Host::tcp('h', 7), Host::tcp('::1', 7422)], $settings([Options::HOST => 'h:+0007,::1'])->hosts);
    }

    /**
     * Verifies: [CLIENT The password file]: a local socket is matched by its
     * path and the default port.
     *
     * @throws TurborelException never, the settings being well formed
     */
    #[Test]
    public function aPasswordFileMatchesALocalSocketByItsPath(): void
    {
        $file = $this->scratch('passfile-local', '/run/s.sock:' . Settings::DEFAULT_PORT . ":ann:local\n");
        $settings = Settings::resolveIn((new Options())->set(Options::USER, 'ann')->set(Options::PASSFILE, $file), self::environment([]));
        self::assertSame('local', $settings->passwordFor(Host::local('/run/s.sock')));
    }

    /**
     * The settings of user `u` and `$pairs`.
     *
     * @param array<string, string> $pairs
     *
     * @throws TurborelException SQLSTATE 08001 if they cannot be read
     */
    private static function withPairs(array $pairs): Settings
    {
        $options = (new Options())->set(Options::USER, 'u');
        foreach ($pairs as $key => $value) {
            $options->set($key, $value);
        }

        return Settings::resolveIn($options, self::environment([]));
    }

    /**
     * An environment of `$pairs`, as `Settings::resolveIn` reads one.
     *
     * @param array<string, string> $pairs
     *
     * @return \Closure(string): ?string
     */
    private static function environment(array $pairs): \Closure
    {
        return static fn(string $name): ?string => $pairs[$name] ?? null;
    }

    /** A scratch file of `$text`, named for `$test`, in the system's temporary directory, removed after the test. */
    private function scratch(string $test, string $text): string
    {
        $path = sys_get_temp_dir() . "/turborel-php-{$test}-" . getmypid();
        file_put_contents($path, $text);
        $this->scratch[] = $path;

        return $path;
    }

    /**
     * The message of the failure `$attempt` gives.
     *
     * @param \Closure(): mixed $attempt
     */
    private static function refusal(\Closure $attempt): string
    {
        try {
            $attempt();
        } catch (TurborelException $failure) {
            return $failure->getMessage();
        }
        self::fail('no failure');
    }
}

<?php

declare(strict_types=1);

namespace Turborel\Settings;

use Turborel\Internal\Settings\Failover;
use Turborel\Internal\Settings\Files;
use Turborel\Internal\Settings\HostList;
use Turborel\Internal\Settings\Invalid;
use Turborel\Internal\Settings\Words;
use Turborel\Session\ChannelBinding;
use Turborel\Session\Connection;
use Turborel\Session\KeyCache;
use Turborel\Session\Timeouts;
use Turborel\TurborelException;

/**
 * How to connect, as settings from outside the program say, as
 * turborel-client's `Settings` [CLIENT Connecting by settings]: where the
 * servers are, as whom, how, and what a server must be, read from layers
 * of Options: the program's own, or a URI's; the service's section of the
 * service file; the environment; the defaults. A password none of them
 * gives is looked up in the password file. One SCRAM key cache serves
 * every connection the settings make [CLIENT The SCRAM key cache].
 */
final readonly class Settings
{
    /** The port of the native protocol, where a server listens unless told otherwise. */
    public const int DEFAULT_PORT = 7422;

    /** The host connected to when none is named. */
    public const string DEFAULT_HOST = 'localhost';

    /** The service file read when none is named, in the home directory. */
    public const string DEFAULT_SERVICE_FILE = '.turborel_service.conf';

    /** The password file read when none is named, in the home directory. */
    public const string DEFAULT_PASSFILE = '.turborel_pass';

    /** The SCRAM keys of its connections, so a reconnection skips their derivation. */
    public KeyCache $keyCache;

    /**
     * The settings of their parts.
     *
     * @param list<Host> $hosts
     */
    private function __construct(
        /** @var list<Host> $hosts the servers, tried in turn */
        public array $hosts,
        /** The user to connect as. */
        public string $user,
        /** The user's password, if given; else the password file's. */
        #[\SensitiveParameter]
        private ?string $password,
        /** How TLS is used. */
        public TlsMode $tlsMode,
        /** Whether SCRAM binds its channel. */
        public ChannelBinding $channelBinding,
        /** The program's name, as it tells the server. */
        public ?string $applicationName,
        /** How long each step of connecting waits, in seconds; none, no limit. */
        public ?float $connectTimeout,
        /** The password file, if any. */
        public ?string $passfile,
        /** What a server must be. */
        public TargetRole $target,
        /** Whether the servers are tried in order. */
        public LoadBalance $loadBalance,
        /** The files TLS is made of. */
        public TlsFiles $tls,
    ) {
        $this->keyCache = new KeyCache();
    }

    /**
     * The settings `$explicit` gives, then the service it or the environment names, then the environment, then the defaults.
     *
     * @throws TurborelException SQLSTATE 08001 if a setting cannot be read, a service named cannot be read, or no user is named
     */
    public static function resolve(Options $explicit): self
    {
        return self::resolveIn($explicit, static fn(string $name): string|false => getenv($name));
    }

    /**
     * The settings of the connection URI `$uri`, as `resolve` lays them [CLIENT The connection URI].
     *
     * @throws TurborelException SQLSTATE 08001 as `Options::fromUri` and `resolve` do
     */
    public static function fromUri(#[\SensitiveParameter] string $uri): self
    {
        return self::resolve(Options::fromUri($uri));
    }

    /**
     * The settings as `resolve` lays them, `$variable` reading the environment's variables.
     *
     * @param \Closure(string): (string|null|false) $variable
     *
     * @throws TurborelException SQLSTATE 08001 as `resolve` does
     */
    public static function resolveIn(Options $explicit, \Closure $variable): self
    {
        $environment = Options::environment($variable);
        $named = $explicit->over($environment);
        $name = $named->get(Options::SERVICE);
        $service = $name === null ? new Options() : self::service($named->get(Options::SERVICE_FILE) ?? self::atHome(self::DEFAULT_SERVICE_FILE) ?? '', $name);

        return self::of($explicit->over($service)->over($environment));
    }

    /**
     * The settings of the section `$name` of the service file `$file` [CLIENT The service file].
     *
     * @throws TurborelException SQLSTATE 08001 if it cannot be read, a line is malformed, or it has no section `$name`
     */
    public static function service(string $file, string $name): Options
    {
        return Files::service($file, $name);
    }

    /** The password to prove at `$host`: the settings', else the password file's for the host, its port and the user. */
    public function passwordFor(Host $host): ?string
    {
        if ($this->password !== null) {
            return $this->password;
        }
        $text = $this->passfile === null ? null : Files::readRegular($this->passfile);
        if ($text === null) {
            return null;
        }
        [$name, $port] = $host->isLocal() ? [$host->path, (string) self::DEFAULT_PORT] : [$host->name, (string) $host->port];

        return Files::password($text, $name, $port, $this->user);
    }

    /**
     * A connection to the first server, of the hosts in the order the settings say, that can be reached, logs the user in and
     * is of the role asked [CLIENT Several servers].
     *
     * @throws TurborelException the one server's failure, if one was tried; else SQLSTATE 08001 saying why each was not kept,
     *                           28000 where the last refused the user; 28000 for a channel binding required that cannot be
     */
    public function connect(): Connection
    {
        return (new Failover($this))->connect();
    }

    /**
     * The timeouts of a connection the settings make: the connect timeout, and no limit on a read.
     *
     * @throws TurborelException never, a connect timeout read being positive or none
     */
    public function timeouts(): Timeouts
    {
        return new Timeouts($this->connectTimeout);
    }

    /**
     * Its state as var_dump() shows it: never the password.
     *
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return ['hosts' => $this->hosts, 'user' => $this->user, 'tlsMode' => $this->tlsMode, 'target' => $this->target];
    }

    /**
     * The settings `$options` give, laid already, the defaults for the rest.
     *
     * @throws TurborelException SQLSTATE 08001 if one cannot be read, or no user is named
     */
    private static function of(Options $options): self
    {
        $user = $options->get(Options::USER) ?? throw Invalid::because('no user is named: by the program, a service, or TURBOREL_USER');

        // Read in turborel-client's order, so a failure names the first setting it does.
        return new self(
            HostList::of($options->get(Options::HOST) ?? self::DEFAULT_HOST, $options->get(Options::PORT) ?? ''),
            $user,
            $options->get(Options::PASSWORD),
            self::read($options, Options::TLS_MODE, static fn(string $k, string $t): TlsMode => Words::word(TlsMode::class, $k, $t), TlsMode::Prefer),
            self::read($options, Options::CHANNEL_BINDING, static fn(string $k, string $t): ChannelBinding => Words::word(ChannelBinding::class, $k, $t), ChannelBinding::Prefer),
            $options->get(Options::APPLICATION_NAME),
            self::read($options, Options::CONNECT_TIMEOUT, Words::seconds(...), Timeouts::CONNECT_WITHIN),
            $options->get(Options::PASSFILE) ?? self::atHome(self::DEFAULT_PASSFILE),
            self::read($options, Options::TARGET, static fn(string $k, string $t): TargetRole => Words::word(TargetRole::class, $k, $t), TargetRole::Any),
            self::read($options, Options::LOAD_BALANCE, static fn(string $k, string $t): LoadBalance => Words::word(LoadBalance::class, $k, $t), LoadBalance::Disable),
            new TlsFiles(
                $options->get(Options::TLS_ROOTS),
                self::read($options, Options::TLS_SYSTEM_ROOTS, Words::boolean(...), false),
                $options->get(Options::TLS_CERTIFICATE),
                $options->get(Options::TLS_KEY),
                $options->get(Options::SERVER_NAME),
            ),
        );
    }

    /**
     * The value of `$key` in `$options` read by `$parse`, or `$default`.
     *
     * @template T
     *
     * @param \Closure(string, string): T $parse
     * @param T                         $default
     *
     * @return T
     *
     * @throws TurborelException SQLSTATE 08001 if it cannot be read
     */
    private static function read(Options $options, string $key, \Closure $parse, mixed $default): mixed
    {
        $text = $options->get($key);

        return $text === null ? $default : $parse($key, $text);
    }

    /**
     * The file `$name` in the home directory, if there is one, as Rust's `home_dir` finds it: on Windows USERPROFILE's, unless
     * empty; else HOME's if it is set, even empty, else the user's own, where PHP's posix extension reads it.
     */
    private static function atHome(string $name): ?string
    {
        $home = getenv(\PHP_OS_FAMILY === 'Windows' ? 'USERPROFILE' : 'HOME');
        if (!\is_string($home) && \PHP_OS_FAMILY !== 'Windows' && \function_exists('posix_getpwuid')) {
            $user = posix_getpwuid(posix_geteuid());
            $home = \is_array($user) ? $user['dir'] : false;
        }
        if (!\is_string($home) || (\PHP_OS_FAMILY === 'Windows' && $home === '')) {
            return null;
        }

        return $home === '' ? $name : rtrim($home, '/\\') . \DIRECTORY_SEPARATOR . $name;
    }
}

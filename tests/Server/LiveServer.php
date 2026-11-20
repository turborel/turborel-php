<?php

declare(strict_types=1);

namespace Turborel\Tests\Server;

use Turborel\Session\Connection;
use Turborel\Session\Target;
use Turborel\Session\Trust;
use Turborel\TurborelException;

/**
 * The live tests' server (RULES.md R-TEST-8): turboreld, started once for
 * the run on the driver's own port, 17436, with a data directory, a Unix
 * socket and certificates of its own, TLS offered on the TCP port, client
 * certificates of the test authority taken, SERVER.TRUST_LOCAL ON, and
 * two users with passwords, ANN, the owner, and BOB, ANN's schema made. It is stopped as the
 * run ends, its own process alone waited on.
 *
 * TURBOREL_TEST_SERVER names it: the path of a turboreld binary, or
 * `docker:` and an image, as `docker:ghcr.io/turborel/turborel:nightly`,
 * run with the host's network so its clients are local to it. Unset, the
 * live tests are skipped, so the unit tests need no server (R-TEST-5).
 */
final class LiveServer
{
    /** The variable that names the server. */
    public const string VARIABLE = 'TURBOREL_TEST_SERVER';

    /** The driver's own port for live servers. */
    public const int PORT = 17436;

    /** ANN's password; ANN is the owner. */
    public const string ANN_PASSWORD = 'ann-pass-1';

    /** BOB's password. */
    public const string BOB_PASSWORD = 'bob-pass-2';

    /** How long the server has to start, in seconds. */
    private const int START_WITHIN = 60;

    /** The prefix of a docker image's name in the variable. */
    private const string DOCKER = 'docker:';

    /** The server of this run, once started. */
    private static ?self $started = null;

    /**
     * The running server at `$directory`.
     *
     * @param resource $process
     */
    private function __construct(
        /** The server's directory: its database, socket, log and certificates. */
        public readonly string $directory,
        /** The certificates. */
        public readonly Pki $pki,
        /** @var resource $process the server's process */
        private readonly mixed $process,
    ) {}

    /**
     * The server of this run, started if it is not; none if the variable is unset.
     *
     * @throws TurborelException if it cannot be set up
     */
    public static function get(): ?self
    {
        $named = getenv(self::VARIABLE);
        if (!\is_string($named) || $named === '') {
            return null;
        }
        if (self::$started === null) {
            self::$started = self::start($named);
            register_shutdown_function(static fn() => self::$started?->stop());
        }

        return self::$started;
    }

    /** The path of its Unix socket. */
    public function socket(): string
    {
        return "{$this->directory}/s";
    }

    /**
     * Starts the server `$named` names, its users made first.
     *
     * @throws TurborelException if ANN's schema cannot be made
     */
    private static function start(string $named): self
    {
        $directory = sys_get_temp_dir() . '/turborel-php-live-' . getmypid();
        if (!is_dir($directory)) {
            mkdir($directory, 0o777, true);
        }
        chmod($directory, 0o777);
        $pki = Pki::make($directory);
        $command = self::command($named, $directory);
        self::run([...$command, '--dir', "{$directory}/db", '--log', "{$directory}/log", 'user', 'add', 'ann', '--owner'], self::ANN_PASSWORD);
        self::run([...$command, '--dir', "{$directory}/db", '--log', "{$directory}/log", 'user', 'add', 'bob'], self::BOB_PASSWORD);
        $process = proc_open([...$command, '--dir', "{$directory}/db", '--log', "{$directory}/log", ...self::settings($directory, $pki)], [
            0 => ['pipe', 'r'], 1 => ['file', "{$directory}/stdout", 'a'], 2 => ['pipe', 'w'],
        ], $pipes);
        if (!\is_resource($process)) {
            throw new \RuntimeException('turboreld cannot be started');
        }
        self::awaitListening($pipes[2] ?? throw new \RuntimeException('turboreld has no standard error'));
        $owner = Connection::connect(Target::tcp('127.0.0.1', self::PORT), 'ann', new Trust());
        $owner->query('CREATE SCHEMA AUTHORIZATION ANN');
        $owner->query('COMMIT');
        $owner->terminate();

        return new self($directory, $pki, $process);
    }

    /**
     * The command that runs turboreld, as `$named` names it.
     *
     * @return list<string>
     */
    private static function command(string $named, string $directory): array
    {
        if (!str_starts_with($named, self::DOCKER)) {
            return [$named];
        }
        $user = posix_getuid() . ':' . posix_getgid();

        return ['docker', 'run', '--rm', '-i', '--network', 'host', '--user', $user, '-v', "{$directory}:{$directory}", substr($named, \strlen(self::DOCKER))];
    }

    /**
     * The settings the server starts with.
     *
     * @return list<string>
     */
    private static function settings(string $directory, Pki $pki): array
    {
        $settings = [
            'SERVER.HOST' => '127.0.0.1', 'SERVER.PORT' => (string) self::PORT, 'SERVER.SOCKET' => "{$directory}/s",
            'SERVER.TLS' => 'ON', 'SERVER.TLS_CERTIFICATE' => $pki->serverCertificate, 'SERVER.TLS_KEY' => $pki->serverKey,
            'SERVER.TLS_CLIENT_CA' => $pki->authority, 'SERVER.TRUST_LOCAL' => 'ON', 'SERVER.LOGIN_DELAY_MS' => '0',
        ];
        $arguments = [];
        foreach ($settings as $name => $value) {
            array_push($arguments, '--set', "{$name}={$value}");
        }

        return $arguments;
    }

    /**
     * Runs `$command` to its end, `$input` its standard input.
     *
     * @param list<string> $command
     */
    private static function run(array $command, string $input): void
    {
        $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!\is_resource($process)) {
            throw new \RuntimeException('turboreld cannot be run');
        }
        [$in, $out, $err] = [$pipes[0] ?? null, $pipes[1] ?? null, $pipes[2] ?? null];
        if ($in === null || $out === null || $err === null) {
            throw new \RuntimeException('turboreld has no pipes');
        }
        fwrite($in, $input . "\n");
        fclose($in);
        $output = stream_get_contents($out) . stream_get_contents($err);
        if (proc_close($process) !== 0) {
            throw new \RuntimeException("turboreld failed: {$output}");
        }
    }

    /**
     * Waits for the server to say it listens, on `$output`, its standard error, at most START_WITHIN seconds.
     *
     * @param resource $output
     */
    private static function awaitListening(mixed $output): void
    {
        $deadline = time() + self::START_WITHIN;
        $said = '';
        while (!str_contains($said, 'listening') && time() < $deadline && !feof($output)) {
            $read = [$output];
            $none = null;
            if (stream_select($read, $none, $none, 1) === 1) {
                $said .= (string) fgets($output);
            }
        }
        if (!str_contains($said, 'listening')) {
            throw new \RuntimeException("turboreld did not start: {$said}");
        }
    }

    /** Stops the server, waiting on its own process, and removes its directory. */
    private function stop(): void
    {
        proc_terminate($this->process);
        proc_close($this->process);
        self::$started = null;
        self::remove($this->directory);
    }

    /** Removes `$path`, and what it holds. */
    private static function remove(string $path): void
    {
        if (is_dir($path) && !is_link($path)) {
            $entries = scandir($path);
            foreach ($entries === false ? [] : $entries as $entry) {
                if ($entry !== '.' && $entry !== '..') {
                    self::remove("{$path}/{$entry}");
                }
            }
            @rmdir($path);

            return;
        }
        @unlink($path);
    }
}

<?php

declare(strict_types=1);

namespace Turborel\Internal\Settings;

use Turborel\Internal\ConnectionFailure;
use Turborel\Internal\Session\Connecting;
use Turborel\Internal\Transport\Opener;
use Turborel\Session\Certificate;
use Turborel\Session\ChannelBinding;
use Turborel\Session\Connection;
use Turborel\Session\Mechanism;
use Turborel\Session\Offer;
use Turborel\Session\Scram;
use Turborel\Session\Target;
use Turborel\Session\Tls;
use Turborel\Session\Trust;
use Turborel\Settings\Host;
use Turborel\Settings\LoadBalance;
use Turborel\Settings\Role;
use Turborel\Settings\Settings;
use Turborel\Settings\TargetRole;
use Turborel\Settings\TlsMode;
use Turborel\TurborelException;

/**
 * Connecting as settings say, to one of several servers, as
 * turborel-client's `failover.rs` connects [CLIENT Several servers]: each
 * host tried in the order given, or in an order drawn at random for each
 * connection, and each address a host's name resolves to in turn, every
 * step within the connect timeout. A server reached, logged in to and of
 * the role asked is kept; another is left and the next tried. No server
 * kept, the failure says why each was not.
 *
 * @internal
 */
final readonly class Failover
{
    /** The connection attribute of the program's name. */
    private const string APPLICATION = 'application';

    /** The connections `$settings` make. */
    public function __construct(private Settings $settings) {}

    /**
     * A connection to the first server kept.
     *
     * @throws TurborelException as Settings::connect says
     */
    public function connect(): Connection
    {
        $hosts = $this->settings->hosts;
        if ($this->settings->loadBalance === LoadBalance::Random) {
            $hosts = (new \Random\Randomizer())->shuffleArray($hosts);
        }
        $tls = Modes::tls($this->settings->tlsMode, $this->settings->tls);
        $tried = [];
        foreach ($hosts as $host) {
            foreach (Addresses::targets($host, $this->settings->tls->serverName, $tls) as [$at, $target]) {
                try {
                    if ($target instanceof TurborelException) {
                        throw $target;
                    }

                    return $this->attempt($host, $target);
                } catch (TurborelException $failure) {
                    $tried[] = [$at, $failure];
                }
            }
        }

        throw self::noneKept($tried);
    }

    /**
     * The failure of a connection none of `$tried` gave: the one failure, if one server was tried; else SQLSTATE 08001 naming
     * each and why, or 28000 where the last refused the user.
     *
     * @param list<array{string, TurborelException}> $tried
     */
    public static function noneKept(array $tried): TurborelException
    {
        if (\count($tried) === 1) {
            return $tried[0][1];
        }
        $last = end($tried);
        $refused = $last !== false && str_starts_with($last[1]->getSqlState(), '28');
        $reasons = array_map(static fn(array $one): string => "{$one[0]}: {$one[1]->getMessage()}", $tried);
        $message = $reasons === [] ? 'no server is named' : 'no server was kept: ' . implode('; ', $reasons);

        return $refused ? ConnectionFailure::refused($message) : Invalid::because($message);
    }

    /**
     * A connection to `$host` over `$target`, logged in and of the role asked; under `prefer`, over plain TCP if TLS cannot be begun.
     *
     * @throws TurborelException why it was not kept
     */
    private function attempt(Host $host, Target $target): Connection
    {
        $timeouts = $this->settings->timeouts();
        $opened = $this->settings->tlsMode === TlsMode::Prefer ? Opener::preferring($target, $timeouts->connect) : [...Opener::open($target, $timeouts->connect), $target];
        try {
            $mechanism = $this->proof($host, $opened[2]);
        } catch (TurborelException $refused) {
            $opened[0]->close();

            throw $refused;
        }
        $connection = Connecting::over($opened, $this->settings->user, $mechanism, $timeouts, $this->offer());
        $this->ofRole($connection);

        return $connection;
    }

    /**
     * What proves the user at `$host` over `$target`: a password by SCRAM-SHA-256; with none, the certificate named, else trust.
     *
     * @throws TurborelException SQLSTATE 28000 if channel binding is required and cannot be had
     */
    private function proof(Host $host, Target $target): Mechanism
    {
        $password = $this->settings->passwordFor($host);
        $proof = match (true) {
            $password !== null => (new Scram($password))->channelBinding($this->settings->channelBinding)->withCache($this->settings->keyCache),
            $this->settings->tls->certificate !== null => new Certificate(),
            default => new Trust(),
        };
        $binds = $proof instanceof Scram && $target->tls !== null && !$target->isLocal();
        if ($this->settings->channelBinding === ChannelBinding::Require && !$binds) {
            throw ConnectionFailure::refused('channel binding is required: SCRAM over TLS alone binds one');
        }

        return $proof;
    }

    /** What a connection of the settings asks for: every capability but compression, and the program's name, if given. */
    private function offer(): Offer
    {
        $name = $this->settings->applicationName;

        return $name === null ? new Offer() : (new Offer())->attribute(self::APPLICATION, $name);
    }

    /**
     * Refuses `$connection`, closed, if its server is not of the role the settings ask (SQLSTATE 08001).
     *
     * @throws TurborelException SQLSTATE 08001 if it is not, or the role cannot be read
     */
    private function ofRole(Connection $connection): void
    {
        $wanted = match ($this->settings->target) {
            TargetRole::Any => null,
            TargetRole::ReadWrite => Role::ReadWrite,
            TargetRole::ReadOnly => Role::ReadOnly,
        };
        if ($wanted === null) {
            return;
        }
        try {
            $found = Role::of($connection);
        } catch (TurborelException $failure) {
            $connection->wire()->stream->close();

            throw $failure;
        }
        if ($found !== $wanted) {
            $connection->wire()->stream->close();

            throw Invalid::because("the server is {$found->describe()}, and {$wanted->describe()} is asked");
        }
    }
}

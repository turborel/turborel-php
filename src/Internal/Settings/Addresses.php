<?php

declare(strict_types=1);

namespace Turborel\Internal\Settings;

use Turborel\Internal\ConnectionFailure;
use Turborel\Internal\Quietly;
use Turborel\Session\Target;
use Turborel\Session\Tls;
use Turborel\Settings\Host;
use Turborel\TurborelException;

/**
 * The targets a host gives, each named, as turborel-client's
 * `failover.rs` lists them [CLIENT Several servers]: a local socket's;
 * or each address its name resolves to, over TLS where the mode asks,
 * the server's certificate of the name `server_name`, else the host's.
 *
 * @internal
 */
final class Addresses
{
    /**
     * The targets of `$host`, each with its name: a target, or why it cannot be one.
     *
     * @return list<array{string, Target|TurborelException}>
     *
     * @throws TurborelException never, a name that does not resolve being given as its target
     */
    public static function targets(Host $host, ?string $serverName, ?Tls $tls): array
    {
        if ($host->isLocal()) {
            return [[$host->path, Target::local($host->path)]];
        }
        $addresses = self::resolved($host->name);
        if ($addresses === []) {
            return [["{$host->name}:{$host->port}", ConnectionFailure::because("cannot resolve the name: {$host->name} is not known")]];
        }
        $targets = [];
        foreach ($addresses as $address) {
            $shown = str_contains($address, ':') ? "[{$address}]:{$host->port}" : "{$address}:{$host->port}";
            $target = $tls === null ? Target::tcp($address, $host->port) : Target::tls($address, $tls->forServerName($serverName ?? $host->name), $host->port);
            $targets[] = ["{$host->name} ({$shown})", $target];
        }

        return $targets;
    }

    /**
     * The addresses `$name` resolves to: itself if it is one; else its IPv4 addresses, as the system resolves them, then its
     * IPv6 ones, as DNS gives them.
     *
     * @return list<string>
     */
    private static function resolved(string $name): array
    {
        if (filter_var($name, \FILTER_VALIDATE_IP) !== false) {
            return [$name];
        }
        [$v4] = Quietly::call(static fn(): array|false => gethostbynamel($name));
        [$v6] = Quietly::call(static fn(): array|false => \function_exists('dns_get_record') ? dns_get_record($name, \DNS_AAAA) : false);
        $six = [];
        foreach (\is_array($v6) ? $v6 : [] as $record) {
            if (\is_string($record['ipv6'] ?? null)) {
                $six[] = $record['ipv6'];
            }
        }

        return [...(\is_array($v4) ? $v4 : []), ...$six];
    }
}

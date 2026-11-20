<?php

declare(strict_types=1);

namespace Turborel\Internal\Settings;

use Turborel\Settings\Host;
use Turborel\Settings\Options;
use Turborel\Settings\Settings;
use Turborel\TurborelException;

/**
 * The hosts a `host` setting names, as turborel-client's `settings.rs`
 * reads them [CLIENT Where settings come from]: comma-separated, each a
 * path of a local socket, `[<address>]` or `[<address>]:<port>` of IPv6,
 * `<name>:<port>`, or a name; each of its own port, else the port of the
 * same place in `port`, else the one port `port` gives, else 7422.
 *
 * @internal
 */
final class HostList
{
    /** What separates the hosts of a list, and their ports. */
    private const string LIST = ',';

    /**
     * The hosts `$hosts` names, of the ports `$ports` gives.
     *
     * @return list<Host>
     *
     * @throws TurborelException SQLSTATE 08001 if a host or a port cannot be read
     */
    public static function of(string $hosts, string $ports): array
    {
        $numbers = [];
        foreach (explode(self::LIST, $ports) as $port) {
            if (trim($port) !== '') {
                $numbers[] = Words::port(Options::PORT, $port);
            }
        }
        $list = [];
        foreach (array_map(trim(...), explode(self::LIST, $hosts)) as $at => $name) {
            $list[] = self::host($name, $numbers[$at] ?? (\count($numbers) === 1 ? $numbers[0] : Settings::DEFAULT_PORT));
        }

        return $list;
    }

    /**
     * The host `$name` is, at `$port` if it names none.
     *
     * @throws TurborelException SQLSTATE 08001 if it is empty, or malformed
     */
    private static function host(string $name, int $port): Host
    {
        if ($name === '') {
            throw Invalid::because('a host of the list is empty');
        }
        if (str_starts_with($name, '/') || str_starts_with($name, '\\')) {
            return Host::local($name);
        }
        if (str_starts_with($name, '[')) {
            $close = strpos($name, ']');
            if ($close === false) {
                throw Invalid::because("{$name} has no ]");
            }
            $after = substr($name, $close + 1);

            return Host::tcp(substr($name, 1, $close - 1), str_starts_with($after, ':') ? Words::port(Options::HOST, substr($after, 1)) : $port);
        }
        // A name of two colons or more is an IPv6 address unbracketed, of no port.
        if (substr_count($name, ':') === 1) {
            [$host, $number] = explode(':', $name, 2) + ['', ''];

            return Host::tcp($host, Words::port(Options::HOST, $number));
        }

        return Host::tcp($name, $port);
    }
}

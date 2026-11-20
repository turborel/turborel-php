<?php

declare(strict_types=1);

namespace Turborel\Internal\Settings;

use Turborel\Settings\Options;
use Turborel\TurborelException;

/**
 * A connection URI read into settings, as turborel-client's `uri.rs`
 * reads it [CLIENT The connection URI]:
 * `turborel://[<user>[:<password>]@][<host>[:<port>][,...]][/][?<key>=<value>[&...]]`,
 * its parts percent-encoded (RFC 3986 §2.1), an IPv6 address in brackets;
 * a server serves one database, so it has no path.
 *
 * @internal
 */
final class Uri
{
    /** The scheme a connection URI begins with. */
    public const string SCHEME = 'turborel://';

    /**
     * The settings `$uri` gives.
     *
     * @throws TurborelException SQLSTATE 08001 if it is not a connection URI
     */
    public static function options(#[\SensitiveParameter] string $uri): Options
    {
        if (!str_starts_with($uri, self::SCHEME)) {
            throw Invalid::because('a connection URI begins with ' . self::SCHEME);
        }
        $rest = substr($uri, \strlen(self::SCHEME));
        [$before, $query] = explode('?', $rest, 2) + ['', ''];
        [$authority, $path] = explode('/', $before, 2) + ['', ''];
        if ($path !== '') {
            throw Invalid::because("a connection URI names no database, so has no path, not /{$path}");
        }
        $options = new Options();
        $at = strrpos($authority, '@');
        [$userinfo, $hosts] = $at === false ? ['', $authority] : [substr($authority, 0, $at), substr($authority, $at + 1)];
        if ($userinfo !== '') {
            $parts = explode(':', $userinfo, 2);
            $options->set(Options::USER, self::decode($parts[0]));
            if (isset($parts[1])) {
                $options->set(Options::PASSWORD, self::decode($parts[1]));
            }
        }
        if ($hosts !== '') {
            $options->set(Options::HOST, self::decode($hosts));
        }
        foreach (explode('&', $query) as $pair) {
            if ($pair === '') {
                continue;
            }
            if (!str_contains($pair, '=')) {
                throw Invalid::because("{$pair} in a connection URI has no =");
            }
            [$key, $value] = explode('=', $pair, 2) + ['', ''];
            $options->set(self::decode($key), self::decode($value));
        }

        return $options;
    }

    /**
     * `$text` percent-decoded, as UTF-8.
     *
     * @throws TurborelException SQLSTATE 08001 if a `%` is not followed by two hexadecimal digits, or the octets are not UTF-8
     */
    public static function decode(#[\SensitiveParameter] string $text): string
    {
        if (preg_match('/%(?![0-9A-Fa-f]{2})/', $text) === 1) {
            throw Invalid::because("{$text} has a % without two hexadecimal digits");
        }
        $decoded = rawurldecode($text);
        if (preg_match('//u', $decoded) !== 1) {
            throw Invalid::because("{$text} is not UTF-8 once decoded");
        }

        return $decoded;
    }
}

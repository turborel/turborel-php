<?php

declare(strict_types=1);

namespace Turborel\Session;

use Turborel\Internal\Auth\ScramExchange;

/**
 * The SCRAM key cache [CLIENT The SCRAM key cache], as turborel-client's:
 * after a login the server proved, the keys a password derived under the
 * server's salt and iterations, ClientKey and ServerKey, are kept in the
 * process's memory alone, so a later login meeting the same salt and
 * iterations skips PBKDF2, the slow step.
 *
 * An entry is found by the salt, the iterations and a tag of the password:
 * an HMAC of it under a random secret of the cache's own, so a wrong
 * password finds nothing, and the cache holds neither the password nor
 * anything it could be guessed from offline. It holds 64 entries at most,
 * the oldest dropped first, and drops an entry when a login that used it
 * fails. Each object is one cache; share it by passing it.
 */
final class KeyCache
{
    /** The most entries held, as turborel-client's KEY_CACHE_ENTRIES. */
    public const int ENTRIES = 64;

    /** The bytes of the cache's secret. */
    private const int SECRET_BYTES = 18;

    /** The keys of the PBKDF2 derivation, labelled [RFC 5802 §3]. */
    private const string CLIENT_KEY = 'Client Key';

    /** ServerKey's label. */
    private const string SERVER_KEY = 'Server Key';

    /** The cache's secret, random, which tags passwords. */
    private readonly string $secret;

    /** @var list<array{string, string, string}> the entries, oldest first: each index, ClientKey and ServerKey */
    private array $held = [];

    /** The lookups that found their keys. */
    private int $hits = 0;

    /** The lookups that did not. */
    private int $misses = 0;

    /** An empty cache, its secret drawn from PHP's random source. */
    public function __construct()
    {
        $this->secret = random_bytes(self::SECRET_BYTES);
    }

    /**
     * The keys `$password` derives under `$salt` and `$iterations`: ClientKey and ServerKey, from the cache if held.
     *
     * @return array{string, string}
     *
     * @internal
     */
    public function derived(#[\SensitiveParameter] string $password, string $salt, int $iterations): array
    {
        $index = $this->index($password, $salt, $iterations);
        foreach ($this->held as [$held, $client, $server]) {
            if (hash_equals($held, $index)) {
                $this->hits++;

                return [$client, $server];
            }
        }
        $this->misses++;
        $keys = self::derive($password, $salt, $iterations);
        if (\count($this->held) >= self::ENTRIES) {
            array_shift($this->held);
        }
        $this->held[] = [$index, $keys[0], $keys[1]];

        return $keys;
    }

    /**
     * Drops the entry `$password`, `$salt` and `$iterations` find, as a login that used it failed.
     *
     * @internal
     */
    public function forget(#[\SensitiveParameter] string $password, string $salt, int $iterations): void
    {
        $index = $this->index($password, $salt, $iterations);
        $this->held = array_values(array_filter($this->held, static fn(array $entry): bool => !hash_equals($entry[0], $index)));
    }

    /** The entries held. */
    public function count(): int
    {
        return \count($this->held);
    }

    /**
     * Its lookups so far: those that found their keys, and those that did not.
     *
     * @return array{int, int}
     */
    public function lookups(): array
    {
        return [$this->hits, $this->misses];
    }

    /** Drops every entry. */
    public function clear(): void
    {
        $this->held = [];
    }

    /**
     * ClientKey and ServerKey of `$password` under `$salt` and `$iterations`, by PBKDF2-HMAC-SHA-256.
     *
     * @return array{string, string}
     *
     * @internal
     */
    public static function derive(#[\SensitiveParameter] string $password, string $salt, int $iterations): array
    {
        $salted = hash_pbkdf2(ScramExchange::HASH, $password, $salt, max(1, $iterations), ScramExchange::KEY_BYTES, true);

        return [hash_hmac(ScramExchange::HASH, self::CLIENT_KEY, $salted, true), hash_hmac(ScramExchange::HASH, self::SERVER_KEY, $salted, true)];
    }

    /** An entry's index: the password's tag, the salt and the iterations. */
    private function index(#[\SensitiveParameter] string $password, string $salt, int $iterations): string
    {
        return hash_hmac(ScramExchange::HASH, $password, $this->secret, true) . pack('N', $iterations) . $salt;
    }

    /**
     * Its state as var_dump() shows it: its counts, never its keys (RULES.md R-SAFE-4).
     *
     * @return array<string, int>
     */
    public function __debugInfo(): array
    {
        return ['entries' => \count($this->held), 'hits' => $this->hits, 'misses' => $this->misses];
    }
}

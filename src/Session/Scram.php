<?php

declare(strict_types=1);

namespace Turborel\Session;

use Turborel\Internal\Auth\ScramExchange;
use Turborel\Internal\Auth\ScramFailure;
use Turborel\Internal\ConnectionFailure;
use Turborel\Internal\Tls\EndPoint;
use Turborel\TurborelException;

/**
 * SCRAM-SHA-256 of a password (RFC 5802, RFC 7677), as turborel-client's
 * `Scram` [CLIENT Connecting]: the password never travels, and the
 * connection is refused (SQLSTATE 08006) unless the server proves it knows
 * the user's verifier. Over TLS it is SCRAM-SHA-256-PLUS, bound to the
 * connection by `tls-server-end-point`, the hash of the certificate the
 * server presented, so a server in the middle cannot relay it; PHP's
 * streams export no keying material for `tls-exporter` (RULES.md D1). It
 * is used only over TLS, a Unix socket or a named pipe, unless
 * `inTheClear` says otherwise. A `KeyCache` given to it keeps the keys a
 * password derives [CLIENT The SCRAM key cache].
 */
final class Scram implements Mechanism
{
    /** The mechanism's name, unbound. */
    public const string NAME = 'scram-sha-256';

    /** The mechanism's name, bound to the TLS channel. */
    public const string NAME_PLUS = 'scram-sha-256-plus';

    /** The bytes of a nonce, as turborel's NONCE_BYTES. */
    private const int NONCE_BYTES = 18;

    /** What the server's final message begins with: its signature, or an error. */
    private const array FINAL_PREFIXES = ['v=', 'e='];

    /** The exchange under way. */
    private ?ScramExchange $exchange = null;

    /** Whether it may be used over plain TCP. */
    private bool $clear = false;

    /** Whether it binds the channel. */
    private ChannelBinding $binding = ChannelBinding::Prefer;

    /** The cache of derived keys, if given. */
    private ?KeyCache $cache = null;

    /** The channel's `tls-server-end-point` data, once bound; false where the channel offers none. */
    private string|false|null $endPoint = null;

    /** @var array{string, int}|null the salt and iterations the exchange under way derived keys of, for the cache to forget if it fails */
    private ?array $used = null;

    /** The mechanism of the password `$password`. */
    public function __construct(
        #[\SensitiveParameter]
        private readonly string $password,
    ) {}

    /** It, used over plain TCP too, for a server whose SERVER.SCRAM_WITHOUT_TLS is ON. */
    public function inTheClear(): self
    {
        $this->clear = true;

        return $this;
    }

    /** It, binding the channel as `$binding` says. */
    public function channelBinding(ChannelBinding $binding): self
    {
        $this->binding = $binding;

        return $this;
    }

    /** It, keeping derived keys in `$cache`. */
    public function withCache(KeyCache $cache): self
    {
        $this->cache = $cache;

        return $this;
    }

    /** {@inheritDoc} */
    public function name(): string
    {
        return \is_string($this->endPoint) ? self::NAME_PLUS : self::NAME;
    }

    /** {@inheritDoc} */
    public function initial(): string
    {
        $endPoint = \is_string($this->endPoint) ? $this->endPoint : null;
        $this->exchange = new ScramExchange($endPoint, $this->endPoint === false);
        // The next connection binds afresh, as turborel-client's initial() leaves it.
        [$this->endPoint, $this->used] = [null, null];

        return $this->exchange->first(base64_encode(random_bytes(self::NONCE_BYTES)));
    }

    /**
     * {@inheritDoc}
     *
     * @throws TurborelException SQLSTATE 28000 if the server's message is malformed, or its signature wrong
     */
    public function answer(string $challenge): string
    {
        $exchange = $this->exchange ?? throw ConnectionFailure::because('SCRAM was not begun');
        try {
            if (\in_array(substr($challenge, 0, 2), self::FINAL_PREFIXES, true)) {
                $exchange->verify($challenge);

                return '';
            }

            return $this->prove($exchange, $challenge);
        } catch (TurborelException $refused) {
            $this->refused();

            throw $refused;
        }
    }

    /** {@inheritDoc} */
    public function secret(): bool
    {
        return !$this->clear;
    }

    /**
     * {@inheritDoc}
     *
     * @throws TurborelException SQLSTATE 08006 unless the server proved it knows the user's verifier
     */
    public function ready(): void
    {
        if ($this->exchange?->verified() !== true) {
            throw ConnectionFailure::because('the server did not prove it knows the user\'s verifier');
        }
    }

    /** {@inheritDoc} */
    public function bind(TlsChannel $channel): void
    {
        if ($this->binding === ChannelBinding::Disable) {
            return;
        }
        $this->endPoint = $channel->certificate === null ? false : (EndPoint::of($channel->certificate) ?? false);
    }

    /** {@inheritDoc} */
    public function refused(): void
    {
        if ($this->cache !== null && $this->used !== null) {
            $this->cache->forget($this->password, $this->used[0], $this->used[1]);
        }
        $this->used = null;
    }

    /**
     * Its proof, answering the server's first message `$challenge`.
     *
     * @throws TurborelException SQLSTATE 28000 if binding is required and the exchange binds nothing, or the message is malformed
     */
    private function prove(ScramExchange $exchange, string $challenge): string
    {
        if ($this->binding === ChannelBinding::Require && !$exchange->isBound()) {
            throw ScramFailure::because('channel binding is required, and this connection is not bound');
        }
        $first = $exchange->read($challenge);
        [$salt, $iterations] = [$first[2], $first[3]];
        $keys = $this->cache?->derived($this->password, $salt, $iterations) ?? KeyCache::derive($this->password, $salt, $iterations);
        $this->used = [$salt, $iterations];

        return $exchange->prove($first, ...$keys);
    }

    /**
     * Its state as var_dump() shows it, the password left out (RULES.md R-SAFE-4).
     *
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return ['password' => '(secret)', 'binding' => $this->binding, 'clear' => $this->clear];
    }
}

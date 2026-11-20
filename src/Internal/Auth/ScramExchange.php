<?php

declare(strict_types=1);

namespace Turborel\Internal\Auth;

use Turborel\TurborelException;

/**
 * The client's side of SCRAM-SHA-256 and SCRAM-SHA-256-PLUS (RFC 5802 §3,
 * §7; RFC 7677), as turborel's `scram/client.rs` speaks it: its first
 * message, its proof answering the server's first, bound to the channel
 * its GS2 header names, and the server's signature checked, so the client
 * knows the server knew the verifier [PROTOCOL Mechanisms].
 *
 * ```text
 * client-first:  <GS2 header>n=<user>,r=<client nonce>
 * server-first:  r=<both nonces>,s=<salt, base 64>,i=<iterations>
 * client-final:  c=<GS2 header and the data bound, base 64>,r=<both nonces>,p=<ClientProof, base 64>
 * server-final:  v=<ServerSignature, base 64>
 * ```
 *
 * @internal
 */
final class ScramExchange
{
    /** The hash of SCRAM-SHA-256. */
    public const string HASH = 'sha256';

    /** The bytes of a key. */
    public const int KEY_BYTES = 32;

    /** The GS2 header of a client that binds nothing. */
    private const string GS2_NONE = 'n,,';

    /** The GS2 header of a client that could bind, but sees the server offer no binding. */
    private const string GS2_UNOFFERED = 'y,,';

    /** The GS2 header of a client bound by `tls-server-end-point`. */
    private const string GS2_END_POINT = 'p=tls-server-end-point,,';

    /** The printable characters of a nonce, a comma aside [RFC 5802 §7]. */
    private const string NONCE = '/^[\x21-\x2B\x2D-\x7E]+$/';

    /** The client's first message without its GS2 header, once sent. */
    private ?string $bare = null;

    /** The client's nonce, once sent. */
    private string $nonce = '';

    /** The server's signature expected, once the proof is sent. */
    private ?string $expected = null;

    /** Whether the server's signature was right. */
    private bool $verified = false;

    /**
     * An exchange bound by `tls-server-end-point` to `$endPoint`, if
     * given; else unbound, or, where `$unoffered`, of a client that could
     * bind but sees no binding offered. Its user, `$user`, is read and left
     * aside by the server, which takes Hello's; the driver sends none.
     */
    public function __construct(
        #[\SensitiveParameter]
        private readonly ?string $endPoint = null,
        private readonly bool $unoffered = false,
        private readonly string $user = '',
    ) {}

    /** Whether it is bound to the channel. */
    public function isBound(): bool
    {
        return $this->endPoint !== null;
    }

    /** The client's first message, of the nonce `$nonce`, its user's `=` and `,` escaped [RFC 5802 §5.1]. */
    public function first(string $nonce): string
    {
        $this->bare = 'n=' . strtr($this->user, ['=' => '=3D', ',' => '=2C']) . ",r={$nonce}";
        $this->nonce = $nonce;

        return $this->header() . $this->bare;
    }

    /**
     * The server's first message read: both nonces, the salt and the iterations.
     *
     * @return array{string, string, string, int} the message, both nonces, the salt and the iterations
     *
     * @throws TurborelException SQLSTATE 28000 if it is out of turn or malformed
     */
    public function read(string $serverFirst): array
    {
        if ($this->bare === null || $this->expected !== null) {
            throw ScramFailure::because('the server\'s first message came out of turn');
        }
        $fields = explode(',', self::text($serverFirst));
        [$both, $salt, $iterations] = [self::attribute($fields[0], 'r'), self::attribute($fields[1] ?? null, 's'), self::attribute($fields[2] ?? null, 'i')];
        if (\count($fields) > 3) {
            throw ScramFailure::because('the server\'s first message has more than it should');
        }
        if (preg_match(self::NONCE, $both) !== 1 || \strlen($both) <= \strlen($this->nonce) || !str_starts_with($both, $this->nonce)) {
            throw ScramFailure::because('the server\'s nonce does not extend the client\'s');
        }
        $decoded = self::base64($salt) ?? throw ScramFailure::because('the salt is not base 64');
        $digits = ltrim(preg_match('/^\+?[0-9]+$/', $iterations) === 1 ? ltrim($iterations, '+') : 'x', '0');
        if ($digits === '' || \strlen($digits) > 10 || !ctype_digit($digits) || (int) $digits > 0xFFFF_FFFF) {
            throw ScramFailure::because('the iterations are no positive number');
        }
        $iterations = $digits;

        return [$serverFirst, $both, $decoded, (int) $iterations];
    }

    /**
     * The client's final message, proving from the derived keys `$client` and `$server`.
     *
     * @param array{string, string, string, int} $first the server's first message, as `read` gave it
     */
    public function prove(array $first, #[\SensitiveParameter] string $client, #[\SensitiveParameter] string $server): string
    {
        $withoutProof = 'c=' . base64_encode($this->header() . ($this->endPoint ?? '')) . ",r={$first[1]}";
        $auth = "{$this->bare},{$first[0]},{$withoutProof}";
        $proof = $client ^ hash_hmac(self::HASH, $auth, hash(self::HASH, $client, true), true);
        $this->expected = hash_hmac(self::HASH, $auth, $server, true);

        return "{$withoutProof},p=" . base64_encode($proof);
    }

    /**
     * Checks the server's final message: its signature must be the one expected.
     *
     * @throws TurborelException SQLSTATE 28000 if it is out of turn, an error, or the wrong signature
     */
    public function verify(string $serverFinal): void
    {
        if ($this->expected === null || $this->verified) {
            throw ScramFailure::because('the server\'s final message came out of turn');
        }
        if (str_starts_with(self::text($serverFinal), 'e=')) {
            throw ScramFailure::because('the server refused: ' . substr($serverFinal, 2));
        }
        $signature = self::base64(self::attribute($serverFinal, 'v')) ?? throw ScramFailure::because('the server\'s signature is not base 64');
        if (!hash_equals($this->expected, $signature)) {
            throw ScramFailure::because('the server did not prove it knows the user');
        }
        $this->verified = true;
    }

    /** Whether the server's signature was right. */
    public function verified(): bool
    {
        return $this->verified;
    }

    /** The GS2 header its binding names. */
    private function header(): string
    {
        if ($this->endPoint !== null) {
            return self::GS2_END_POINT;
        }

        return $this->unoffered ? self::GS2_UNOFFERED : self::GS2_NONE;
    }

    /**
     * `$message`, which must be UTF-8.
     *
     * @throws TurborelException SQLSTATE 28000 if it is not
     */
    private static function text(string $message): string
    {
        return preg_match('//u', $message) === 1 ? $message : throw ScramFailure::because('a message is not UTF-8');
    }

    /** The octets `$text` encodes in base 64; none unless it is their canonical encoding, padded, its spare bits zero [RFC 4648 §3.5]. */
    private static function base64(string $text): ?string
    {
        $octets = base64_decode($text, true);

        return $octets !== false && base64_encode($octets) === $text ? $octets : null;
    }

    /**
     * The value of the attribute `$name` that `$field` must be.
     *
     * @throws TurborelException SQLSTATE 28000 if it is missing or another
     */
    private static function attribute(?string $field, string $name): string
    {
        if ($field === null) {
            throw ScramFailure::because("the attribute {$name} is missing");
        }
        if (!str_starts_with($field, "{$name}=")) {
            throw ScramFailure::because("the attribute {$name} was expected, not {$field}");
        }

        return substr($field, 2);
    }
}

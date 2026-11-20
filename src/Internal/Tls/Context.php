<?php

declare(strict_types=1);

namespace Turborel\Internal\Tls;

use Turborel\Internal\ConnectionFailure;
use Turborel\Internal\Quietly;
use Turborel\Session\Tls;
use Turborel\Session\Verification;
use Turborel\TurborelException;

/**
 * A TLS configuration made PHP's stream context options for one
 * connection to `host`: the authorities trusted, the identity presented,
 * the server's certificate checked as the configuration says and kept,
 * for SCRAM-SHA-256-PLUS's binding. Trusting a file's authorities and the
 * platform's both takes one file of them all, which PHP's openssl reads;
 * it is written for the handshake and removed after it.
 *
 * @internal
 */
final class Context
{
    /** A certificate in PEM, its base 64 between its armour. */
    private const string PEM_BODY = '/-----BEGIN CERTIFICATE-----(.+?)-----END CERTIFICATE-----/s';

    /** The prefix of the file of both sets of authorities. */
    private const string BOTH_PREFIX = 'turborel-roots-';

    /** The file of both sets of authorities, while one is written. */
    private ?string $both = null;

    /** The context of `$tls` for a connection to `$host`. */
    public function __construct(
        private readonly Tls $tls,
        private readonly string $host,
    ) {}

    /**
     * The `ssl` options of PHP's stream context.
     *
     * @return array<string, bool|string>
     *
     * @throws TurborelException SQLSTATE 08006 if the file of both sets of authorities cannot be written
     */
    public function options(): array
    {
        $verification = $this->tls->verification;
        $options = [
            'verify_peer' => $verification !== Verification::None,
            'verify_peer_name' => $verification === Verification::Full,
            'peer_name' => $this->tls->serverName ?? $this->host,
            'allow_self_signed' => false,
            'capture_peer_cert' => true,
            'SNI_enabled' => true,
            'disable_compression' => true,
        ];
        $options += $this->authorities($this->tls->authorities);
        if ($this->tls->identity !== null) {
            $options += ['local_cert' => $this->tls->identity->chain, 'local_pk' => $this->tls->identity->key];
        }

        return $options;
    }

    /** Removes the file of both sets of authorities, if one was written. */
    public function release(): void
    {
        if ($this->both !== null) {
            $both = $this->both;
            Quietly::call(static fn(): bool => unlink($both));
            $this->both = null;
        }
    }

    /**
     * The DER of the certificate the server of `$resource` presented, if PHP kept one.
     *
     * @param resource $resource
     */
    public static function peerCertificate(mixed $resource): ?string
    {
        $options = stream_context_get_params($resource)['options'];
        $ssl = $options['ssl'] ?? null;
        $certificate = \is_array($ssl) ? ($ssl['peer_certificate'] ?? null) : null;
        if (!$certificate instanceof \OpenSSLCertificate || !openssl_x509_export($certificate, $pem) || !\is_string($pem)) {
            return null;
        }
        $der = preg_match(self::PEM_BODY, $pem, $body) === 1 ? base64_decode(preg_replace('/\s+/', '', $body[1]) ?? '', true) : false;

        return \is_string($der) ? $der : null;
    }

    /**
     * The options that name `$authorities`: a file, a directory, or a file of both.
     *
     * @return array<string, string>
     *
     * @throws TurborelException SQLSTATE 08006 if the file of both cannot be written
     */
    private function authorities(Authorities $authorities): array
    {
        $options = $authorities->platformDirectory === null ? [] : ['capath' => $authorities->platformDirectory];
        if ($authorities->file === null) {
            return $authorities->platformFile === null ? $options : $options + ['cafile' => $authorities->platformFile];
        }
        if ($authorities->platformFile === null) {
            return $options + ['cafile' => $authorities->path ?? ''];
        }
        $platform = Quietly::call(static fn(): string|false => file_get_contents($authorities->platformFile ?? ''))[0];

        return $options + ['cafile' => $this->writeBoth($authorities->file . "\n" . (\is_string($platform) ? $platform : ''))];
    }

    /**
     * Writes `$pem`, both sets of authorities, to a file of its own, and gives its path.
     *
     * @throws TurborelException SQLSTATE 08006 if it cannot be written
     */
    private function writeBoth(string $pem): string
    {
        [$path] = Quietly::call(static fn(): string|false => tempnam(sys_get_temp_dir(), self::BOTH_PREFIX));
        if (!\is_string($path) || Quietly::call(static fn(): int|false => file_put_contents($path, $pem))[0] === false) {
            throw ConnectionFailure::because('cannot write the certificate authorities trusted for TLS');
        }
        $this->both = $path;

        return $path;
    }
}

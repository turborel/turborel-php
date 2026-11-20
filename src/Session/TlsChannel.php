<?php

declare(strict_types=1);

namespace Turborel\Session;

use Turborel\Internal\Tls\Context;

/**
 * What a TLS connection gives SCRAM-SHA-256-PLUS to bind its exchange to
 * [CLIENT Connecting]: the certificate the server presented, in DER, from
 * which `tls-server-end-point` is computed. PHP's streams export no keying
 * material, so `tls-exporter` is never offered (RULES.md D1).
 */
final readonly class TlsChannel
{
    /** The channel of the server's certificate `$certificate`, in DER, if it presented one. */
    public function __construct(
        /** The server's certificate, in DER. */
        public ?string $certificate,
    ) {}

    /**
     * The channel of the TLS stream `$stream`, a program opened with its
     * context's `ssl` option `capture_peer_cert` set, so the server's
     * certificate is kept [CLIENT Connecting].
     *
     * @param resource $stream
     */
    public static function of(mixed $stream): self
    {
        return new self(Context::peerCertificate($stream));
    }
}

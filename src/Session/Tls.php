<?php

declare(strict_types=1);

namespace Turborel\Session;

use Turborel\Internal\ConnectionFailure;
use Turborel\Internal\Tls\Authorities;
use Turborel\TurborelException;

/**
 * The TLS a connection begins, by PHP's streams with the openssl
 * extension [CLIENT Connecting]: the certificate authorities it trusts,
 * those of a PEM file, the platform's, or both, the platform's those of
 * the files SSL_CERT_FILE and SSL_CERT_DIR name if either is set; the
 * certificate chain and key it presents, if any, for the mechanism
 * `certificate`; and how it checks the server's certificate, signed by an
 * authority trusted and of the server's name unless told otherwise.
 */
final readonly class Tls
{
    /** The TLS of its parts; made by `trusting`, `presenting` or `roots`. */
    private function __construct(
        /** The authorities trusted. */
        public Authorities $authorities,
        /** The PEM files of the certificate chain and the key presented, if any. */
        public ?Identity $identity = null,
        /** How the server's certificate is checked. */
        public Verification $verification = Verification::Full,
        /** The name the server's certificate is checked against; the host's if none. */
        public ?string $serverName = null,
    ) {}

    /**
     * TLS trusting the authorities of the PEM file `$roots` alone.
     *
     * @throws TurborelException SQLSTATE 08006 if the file cannot be read or holds no certificate
     */
    public static function trusting(string $roots): self
    {
        return new self(Authorities::of($roots, false));
    }

    /**
     * TLS trusting the authorities of `$roots`, presenting the certificate
     * chain of the PEM file `$chain` and the key of `$key`.
     *
     * @throws TurborelException SQLSTATE 08006 if a file cannot be read
     */
    public static function presenting(string $roots, string $chain, string $key): self
    {
        return new self(Authorities::of($roots, false), Identity::of($chain, $key));
    }

    /**
     * TLS trusting the authorities of the PEM file `$file`, if any, and the
     * platform's if `$platform`.
     *
     * @throws TurborelException SQLSTATE 08006 if no authority is trusted, or a file cannot be read
     */
    public static function roots(?string $file, bool $platform): self
    {
        if ($file === null && !$platform) {
            throw ConnectionFailure::because('no certificate authority is trusted: name a file of them, or the platform\'s');
        }

        return new self(Authorities::of($file, $platform));
    }

    /** TLS that checks no certificate: it keeps a connection from being overheard, not from a server in the middle. */
    public static function unverified(): self
    {
        return new self(Authorities::none(), null, Verification::None);
    }

    /**
     * It, presenting the certificate chain of `$chain` and the key of `$key`.
     *
     * @throws TurborelException SQLSTATE 08006 if a file cannot be read
     */
    public function presentingAlso(string $chain, string $key): self
    {
        return new self($this->authorities, Identity::of($chain, $key), $this->verification, $this->serverName);
    }

    /** It, checking the server's certificate against `$name` rather than the host's. */
    public function forServerName(string $name): self
    {
        return new self($this->authorities, $this->identity, $this->verification, $name);
    }

    /**
     * It, checking the server's certificate as `$verification` says.
     *
     * @throws TurborelException SQLSTATE 08006 if it checks the certificate, and trusts no authority
     */
    public function verifying(Verification $verification): self
    {
        if ($verification !== Verification::None && !$this->authorities->any()) {
            throw ConnectionFailure::because('no certificate authority is trusted: name a file of them, or the platform\'s');
        }

        return new self($this->authorities, $this->identity, $verification, $this->serverName);
    }
}

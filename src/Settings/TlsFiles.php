<?php

declare(strict_types=1);

namespace Turborel\Settings;

/**
 * The files a connection's TLS is made of, as settings name them
 * [CLIENT TLS by mode].
 */
final readonly class TlsFiles
{
    /** The files of their parts. */
    public function __construct(
        /** A PEM file of the authorities trusted. */
        public ?string $roots = null,
        /** Whether the platform's authorities are trusted too. */
        public bool $systemRoots = false,
        /** A PEM file of the client's certificate chain, to present. */
        public ?string $certificate = null,
        /** A PEM file of its private key. */
        public ?string $key = null,
        /** The name the server's certificate is checked against, if not the host's. */
        public ?string $serverName = null,
    ) {}
}

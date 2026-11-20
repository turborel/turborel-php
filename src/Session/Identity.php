<?php

declare(strict_types=1);

namespace Turborel\Session;

use Turborel\Internal\ConnectionFailure;
use Turborel\TurborelException;

/**
 * The certificate chain and key a TLS client presents, each a PEM file,
 * for the mechanism `certificate` [CLIENT Connecting].
 */
final readonly class Identity
{
    /** The chain of `$chain` and the key of `$key`. */
    private function __construct(
        /** The PEM file of the certificate chain, the client's own first. */
        public string $chain,
        /** The PEM file of the private key. */
        public string $key,
    ) {}

    /**
     * The chain of the PEM file `$chain` and the key of the PEM file `$key`.
     *
     * @throws TurborelException SQLSTATE 08006 if a file cannot be read
     */
    public static function of(string $chain, string $key): self
    {
        foreach ([$chain, $key] as $path) {
            if (!is_file($path) || !is_readable($path)) {
                throw ConnectionFailure::because("cannot read {$path}");
            }
        }

        return new self($chain, $key);
    }
}

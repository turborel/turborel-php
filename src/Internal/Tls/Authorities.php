<?php

declare(strict_types=1);

namespace Turborel\Internal\Tls;

use Turborel\Internal\ConnectionFailure;
use Turborel\Internal\Quietly;
use Turborel\TurborelException;

/**
 * The certificate authorities a TLS client trusts: those of a PEM file,
 * the platform's, or both [CLIENT Connecting]. The platform's are
 * OpenSSL's: the file and directory SSL_CERT_FILE and SSL_CERT_DIR name,
 * if set, else those PHP's OpenSSL was built with.
 *
 * @internal
 */
final readonly class Authorities
{
    /** A certificate in a PEM file. */
    private const string PEM = '/-----BEGIN CERTIFICATE-----.+?-----END CERTIFICATE-----/s';

    /** The authorities of their parts. */
    private function __construct(
        /** The PEM text of the file's authorities, if a file is trusted. */
        public ?string $file,
        /** The path of that file. */
        public ?string $path,
        /** The platform's authority file, if the platform's are trusted and it has one. */
        public ?string $platformFile,
        /** The platform's authority directory, if the platform's are trusted and it has one. */
        public ?string $platformDirectory,
    ) {}

    /** No authority: of TLS that checks no certificate. */
    public static function none(): self
    {
        return new self(null, null, null, null);
    }

    /**
     * The authorities of the PEM file `$path`, if any, and the platform's if `$platform`.
     *
     * @throws TurborelException SQLSTATE 08006 if the file cannot be read or holds no certificate, or the platform has none
     */
    public static function of(?string $path, bool $platform): self
    {
        $file = $path === null ? null : self::read($path);
        if (!$platform) {
            return new self($file, $path, null, null);
        }
        $locations = openssl_get_cert_locations();
        $platformFile = self::existing(self::variable('SSL_CERT_FILE') ?? ($locations['default_cert_file'] ?? null), is_file(...));
        $platformDirectory = self::existing(self::variable('SSL_CERT_DIR') ?? ($locations['default_cert_dir'] ?? null), is_dir(...));
        if ($platformFile === null && $platformDirectory === null) {
            throw ConnectionFailure::because('cannot trust the platform\'s certificate authorities: it holds none');
        }

        return new self($file, $path, $platformFile, $platformDirectory);
    }

    /**
     * The PEM text of `$path`, which must hold a certificate.
     *
     * @throws TurborelException SQLSTATE 08006 if it cannot be read or holds none
     */
    private static function read(string $path): string
    {
        [$text] = Quietly::call(static fn(): string|false => is_file($path) ? file_get_contents($path) : false);
        if (!\is_string($text)) {
            throw ConnectionFailure::because("cannot read {$path}");
        }
        if (preg_match(self::PEM, $text) !== 1) {
            throw ConnectionFailure::because("{$path} holds no certificate");
        }

        return $text;
    }

    /** The environment's variable `$name`, if it is set and not empty. */
    private static function variable(string $name): ?string
    {
        $value = getenv($name);

        return \is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * `$path` if it is a string `$exists` holds of.
     *
     * @param \Closure(string): bool $exists
     */
    private static function existing(mixed $path, \Closure $exists): ?string
    {
        return \is_string($path) && $path !== '' && $exists($path) ? $path : null;
    }
}

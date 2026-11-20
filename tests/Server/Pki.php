<?php

declare(strict_types=1);

namespace Turborel\Tests\Server;

/**
 * The live tests' certificates, made as the tests begin so no secret is
 * ever committed: an authority, a server certificate of `localhost` and
 * 127.0.0.1 it issued, and a client certificate of the user ANN, each key
 * on P-256, each signature ECDSA with SHA-256.
 */
final class Pki
{
    /** The OpenSSL configuration of the three certificates' extensions. */
    private const string CONFIG = <<<'CNF'
        [req]
        distinguished_name = dn
        [dn]
        [authority]
        basicConstraints = critical,CA:TRUE
        keyUsage = critical,keyCertSign,cRLSign
        subjectKeyIdentifier = hash
        [server]
        basicConstraints = critical,CA:FALSE
        keyUsage = critical,digitalSignature,keyEncipherment
        extendedKeyUsage = serverAuth
        subjectAltName = DNS:localhost,IP:127.0.0.1
        [client]
        basicConstraints = critical,CA:FALSE
        keyUsage = critical,digitalSignature
        extendedKeyUsage = clientAuth
        CNF;

    /** The days each certificate is valid. */
    private const int DAYS = 2;

    /** The paths of the authority's certificate, the server's certificate and key, and the client's. */
    public function __construct(
        /** The authority's certificate. */
        public readonly string $authority,
        /** The server's certificate. */
        public readonly string $serverCertificate,
        /** The server's key. */
        public readonly string $serverKey,
        /** The client's certificate, of ANN. */
        public readonly string $clientCertificate,
        /** The client's key. */
        public readonly string $clientKey,
    ) {}

    /** Makes the certificates in the directory `$directory`. */
    public static function make(string $directory): self
    {
        $config = "{$directory}/openssl.cnf";
        file_put_contents($config, self::CONFIG);
        $authorityKey = self::key();
        $authority = self::sign(['commonName' => 'turborel-php test authority'], $authorityKey, null, $authorityKey, [$config, 'authority', 1]);
        $serverKey = self::key();
        $server = self::sign(['commonName' => 'localhost'], $serverKey, $authority, $authorityKey, [$config, 'server', 2]);
        $clientKey = self::key();
        $client = self::sign(['commonName' => 'ann'], $clientKey, $authority, $authorityKey, [$config, 'client', 3]);
        $pki = new self("{$directory}/ca.pem", "{$directory}/server.pem", "{$directory}/server.key", "{$directory}/ann.pem", "{$directory}/ann.key");
        foreach ([[$pki->authority, $authority], [$pki->serverCertificate, $server], [$pki->clientCertificate, $client]] as [$path, $certificate]) {
            openssl_x509_export_to_file($certificate, $path);
        }
        foreach ([[$pki->serverKey, $serverKey], [$pki->clientKey, $clientKey]] as [$path, $key]) {
            openssl_pkey_export_to_file($key, $path);
        }

        return $pki;
    }

    /** A new key on P-256. */
    private static function key(): \OpenSSLAsymmetricKey
    {
        $key = openssl_pkey_new(['private_key_type' => \OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        if (!$key instanceof \OpenSSLAsymmetricKey) {
            throw new \RuntimeException('no key could be made');
        }

        return $key;
    }

    /**
     * The certificate of `$subject` and `$key`, issued by the certificate `$issuer` and its key `$issuerKey`, or by itself.
     *
     * @param array<string, string>                                  $subject
     * @param array{string, string, int}                             $extensions the configuration file, its section, and the serial number
     */
    private static function sign(array $subject, \OpenSSLAsymmetricKey $key, ?\OpenSSLCertificate $issuer, \OpenSSLAsymmetricKey $issuerKey, array $extensions): \OpenSSLCertificate
    {
        $options = ['config' => $extensions[0], 'x509_extensions' => $extensions[1], 'digest_alg' => 'sha256'];
        $request = openssl_csr_new($subject, $key, $options);
        if (!$request instanceof \OpenSSLCertificateSigningRequest) {
            throw new \RuntimeException('no certificate request could be made');
        }
        $certificate = openssl_csr_sign($request, $issuer, $issuerKey, self::DAYS, $options, $extensions[2]);
        if (!$certificate instanceof \OpenSSLCertificate) {
            throw new \RuntimeException('no certificate could be signed');
        }

        return $certificate;
    }
}

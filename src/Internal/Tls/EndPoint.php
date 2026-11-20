<?php

declare(strict_types=1);

namespace Turborel\Internal\Tls;

/**
 * The channel binding `tls-server-end-point` [RFC 5929 §4], as turborel's
 * `binding.rs` computes it: the hash of the server's certificate, by the
 * hash its signature algorithm names. A signature of MD5 or SHA-1 is bound
 * by SHA-256; of SHA-256, SHA-384 or SHA-512, by that; RSASSA-PSS by its
 * one hash, where its mask's is the same [RFC 4055 §3.1]; a signature of
 * no hash, as Ed25519's, of two, or of an algorithm not known here, has no
 * binding, as RFC 5929 §4.1 defines none.
 *
 * @internal
 */
final class EndPoint
{
    /** The signature algorithms known, by their identifiers' DER bodies in hexadecimal, and the hash each binds by. */
    private const array SIGNATURES = [
        '2a864886f70d010104' => 'sha256', // md5WithRSAEncryption
        '2a864886f70d010105' => 'sha256', // sha1WithRSAEncryption
        '2a864886f70d01010b' => 'sha256', // sha256WithRSAEncryption
        '2a864886f70d01010c' => 'sha384', // sha384WithRSAEncryption
        '2a864886f70d01010d' => 'sha512', // sha512WithRSAEncryption
        '2a8648ce3d0401' => 'sha256', // ecdsa-with-SHA1
        '2a8648ce3d040302' => 'sha256', // ecdsa-with-SHA256
        '2a8648ce3d040303' => 'sha384', // ecdsa-with-SHA384
        '2a8648ce3d040304' => 'sha512', // ecdsa-with-SHA512
    ];

    /** The hashes RSASSA-PSS may name, by their identifiers' DER bodies, and the hash each binds by. */
    private const array HASHES = [
        '2b0e03021a' => 'sha256', // id-sha1
        '608648016503040201' => 'sha256', // id-sha256
        '608648016503040202' => 'sha384', // id-sha384
        '608648016503040203' => 'sha512', // id-sha512
    ];

    /** RSASSA-PSS's identifier [RFC 4055 §3.1]. */
    private const string RSA_PSS = '2a864886f70d01010a';

    /** MGF1's identifier, the mask generation function RSASSA-PSS names. */
    private const string MGF1 = '2a864886f70d010108';

    /** SHA-1's identifier, RSASSA-PSS's default hash and mask hash. */
    private const string SHA1 = '2b0e03021a';

    /** The context tag of RSASSA-PSS's hash algorithm, [0]. */
    private const int PSS_HASH = 0xA0;

    /** The context tag of its mask generation algorithm, [1]. */
    private const int PSS_MASK = 0xA1;

    /** The binding data of the certificate whose DER is `$certificate`; none if its algorithm defines none. */
    public static function of(string $certificate): ?string
    {
        $body = Der::expect($certificate, Der::SEQUENCE);
        $tbs = $body === null ? null : Der::expect($body[0], Der::SEQUENCE);
        $identifier = $tbs === null ? null : self::identifier($tbs[1]);
        if ($identifier === null) {
            return null;
        }
        [$algorithm, $parameters] = [bin2hex($identifier[0]), $identifier[1]];
        $hash = $algorithm === self::RSA_PSS ? self::pss($parameters) : (self::SIGNATURES[$algorithm] ?? null);

        return $hash === null ? null : hash($hash, $certificate, true);
    }

    /**
     * An AlgorithmIdentifier's algorithm and parameters.
     *
     * @return array{string, string}|null
     */
    private static function identifier(string $der): ?array
    {
        $body = Der::expect($der, Der::SEQUENCE);

        return $body === null ? null : Der::expect($body[0], Der::OID);
    }

    /** The hash RSASSA-PSS of `$parameters` binds by: its one hash, where its mask hash is the same. */
    private static function pss(string $parameters): ?string
    {
        $sequence = Der::expect($parameters, Der::SEQUENCE);
        if ($sequence === null) {
            return null;
        }
        [$fields, $hash, $mask] = [$sequence[0], self::SHA1, self::SHA1];
        $explicit = Der::expect($fields, self::PSS_HASH);
        if ($explicit !== null) {
            $hash = bin2hex(self::identifier($explicit[0])[0] ?? '');
            $fields = $explicit[1];
        }
        $masked = Der::expect($fields, self::PSS_MASK);
        if ($masked !== null) {
            $function = self::identifier($masked[0]);
            $mask = $function !== null && bin2hex($function[0]) === self::MGF1 ? bin2hex(self::identifier($function[1])[0] ?? '') : '';
        }

        return $hash === $mask ? (self::HASHES[$hash] ?? null) : null;
    }
}

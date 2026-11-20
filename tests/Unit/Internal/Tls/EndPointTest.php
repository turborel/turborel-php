<?php

declare(strict_types=1);

namespace Turborel\Tests\Unit\Internal\Tls;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Turborel\Internal\Tls\Der;
use Turborel\Internal\Tls\EndPoint;
use Turborel\Tests\Support\CodecTestCase;

/**
 * `tls-server-end-point`, the hash of the server's certificate by the hash
 * its signature algorithm names, as turborel's `binding.rs` tests it.
 */
#[CoversClass(EndPoint::class)]
#[CoversClass(Der::class)]
final class EndPointTest extends CodecTestCase
{
    /** md5WithRSAEncryption. */
    private const string MD5_RSA = '2a864886f70d010104';

    /** sha1WithRSAEncryption. */
    private const string SHA1_RSA = '2a864886f70d010105';

    /** MGF1. */
    private const string MGF1 = '2a864886f70d010108';

    /** RSASSA-PSS. */
    private const string RSA_PSS = '2a864886f70d01010a';

    /** id-sha1. */
    private const string SHA1 = '2b0e03021a';

    /** id-sha256. */
    private const string SHA256 = '608648016503040201';

    /** id-sha384. */
    private const string SHA384 = '608648016503040202';

    /** id-sha512. */
    private const string SHA512 = '608648016503040203';

    /**
     * Verifies: [PROTOCOL Mechanisms] [RFC 5929 §4.1]: the signature's hash
     * chooses the binding's: MD5 and SHA-1 bind by SHA-256, SHA-256,
     * SHA-384 and SHA-512 by themselves, of RSA and ECDSA alike.
     */
    #[Test]
    #[DataProvider('signatures')]
    public function theSignatureHashChoosesTheBindingHash(string $oid, string $hash): void
    {
        $der = self::certificate(self::algorithm($oid, "\x05\x00"));
        self::assertSame(hash($hash, $der, true), EndPoint::of($der));
    }

    /**
     * Signature algorithms, each its identifier and the hash it binds by.
     *
     * @return iterable<string, array{string, string}>
     */
    public static function signatures(): iterable
    {
        yield 'MD5 RSA' => [self::MD5_RSA, 'sha256'];
        yield 'SHA-1 RSA' => [self::SHA1_RSA, 'sha256'];
        yield 'SHA-1 ECDSA' => ['2a8648ce3d0401', 'sha256'];
        yield 'SHA-256 RSA' => ['2a864886f70d01010b', 'sha256'];
        yield 'SHA-256 ECDSA' => ['2a8648ce3d040302', 'sha256'];
        yield 'SHA-384 RSA' => ['2a864886f70d01010c', 'sha384'];
        yield 'SHA-384 ECDSA' => ['2a8648ce3d040303', 'sha384'];
        yield 'SHA-512 RSA' => ['2a864886f70d01010d', 'sha512'];
        yield 'SHA-512 ECDSA' => ['2a8648ce3d040304', 'sha512'];
    }

    /**
     * Verifies: [PROTOCOL Mechanisms] [RFC 4055 §3.1]: RSASSA-PSS binds by
     * its one hash, SHA-1 by default, and by none where its mask's hash is
     * another, its mask not MGF1, its hash not known, or it has no
     * parameters.
     */
    #[Test]
    public function rsassaPssIsBoundByItsOneHash(): void
    {
        $same = self::certificate(self::pss(self::SHA384, self::SHA384));
        self::assertSame(hash('sha384', $same, true), EndPoint::of($same));
        $defaults = self::certificate(self::pss(null, null));
        self::assertSame(hash('sha256', $defaults, true), EndPoint::of($defaults));
        $sha1 = self::certificate(self::pss(self::SHA1, null));
        self::assertSame(hash('sha256', $sha1, true), EndPoint::of($sha1));
        self::assertNull(EndPoint::of(self::certificate(self::pss(self::SHA256, null))));
        self::assertNull(EndPoint::of(self::certificate(self::pss(self::SHA512, self::SHA256))));
        self::assertNull(EndPoint::of(self::certificate(self::pss(self::MGF1, self::MGF1))));
        $otherMask = self::tlv(0xA1, self::algorithm(self::SHA1_RSA, self::algorithm(self::SHA256, '')));
        $masked = self::algorithm(self::RSA_PSS, self::tlv(0x30, self::tlv(0xA0, self::algorithm(self::SHA256, '')) . $otherMask));
        self::assertNull(EndPoint::of(self::certificate($masked)));
        self::assertNull(EndPoint::of(self::certificate(self::algorithm(self::RSA_PSS, ''))));
    }

    /**
     * Verifies: [PROTOCOL Mechanisms] [RFC 5929 §4.1]: a signature of no
     * hash, as Ed25519's, has no binding, and neither has a certificate
     * cut short at any byte, or of no algorithm.
     */
    #[Test]
    public function noBindingWithoutOneKnownHash(): void
    {
        self::assertNull(EndPoint::of(self::certificate(self::algorithm('2b6570', ''))));
        $whole = self::certificate(self::algorithm('2a8648ce3d040302', ''));
        for ($cut = 0; $cut < \strlen($whole); $cut++) {
            self::assertNull(EndPoint::of(substr($whole, 0, $cut)));
        }
        self::assertNull(EndPoint::of(self::certificate(self::tlv(0x30, "\x05\x00"))));
    }

    /**
     * Verifies: [PROTOCOL Mechanisms] [X.690 §8.1]: DER's elements are read
     * by their tags and lengths, short and long forms; a length's long form
     * of no bytes or more than four, or an element cut short, is none.
     */
    #[Test]
    public function derElementsAreReadByTagAndLength(): void
    {
        self::assertSame([Der::OID, "\x07", "\x09"], Der::element("\x06\x01\x07\x09"));
        self::assertSame(["\x01\x02", "\x03"], Der::expect("\x30\x81\x02\x01\x02\x03", Der::SEQUENCE));
        self::assertNull(Der::expect("\x30\x81\x02\x01\x02\x03", Der::OID));
        foreach (['', "\x30", "\x30\x02\x01", "\x30\x80", "\x30\x85\x00\x00\x00\x00\x01", "\x30\x82\x01"] as $short) {
            self::assertNull(Der::element($short));
        }
    }

    /** An element of `$tag` and `$body`, its length in the short form. */
    private static function tlv(int $tag, string $body): string
    {
        return \chr($tag) . \chr(\strlen($body)) . $body;
    }

    /** An AlgorithmIdentifier of the identifier `$oid`, in hexadecimal, and `$parameters`. */
    private static function algorithm(string $oid, string $parameters): string
    {
        return self::tlv(0x30, self::tlv(Der::OID, (string) hex2bin($oid)) . $parameters);
    }

    /** A certificate of the signature algorithm `$signature`. */
    private static function certificate(string $signature): string
    {
        return self::tlv(0x30, self::tlv(0x30, "\x02\x01\x07") . $signature . self::tlv(0x03, "\x00"));
    }

    /** RSASSA-PSS of the hash `$hash` and the mask hash `$mask`, each if given. */
    private static function pss(?string $hash, ?string $mask): string
    {
        $hashed = $hash === null ? '' : self::tlv(0xA0, self::algorithm($hash, "\x05\x00"));
        $masked = $mask === null ? '' : self::tlv(0xA1, self::algorithm(self::MGF1, self::algorithm($mask, "\x05\x00")));

        return self::algorithm(self::RSA_PSS, self::tlv(0x30, $hashed . $masked));
    }
}

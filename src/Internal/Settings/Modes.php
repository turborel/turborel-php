<?php

declare(strict_types=1);

namespace Turborel\Internal\Settings;

use Turborel\Internal\ConnectionFailure;
use Turborel\Session\Tls;
use Turborel\Session\Verification;
use Turborel\Settings\TlsFiles;
use Turborel\Settings\TlsMode;
use Turborel\TurborelException;

/**
 * The client's TLS for each mode, as turborel-client's `tls/modes.rs`
 * makes it [CLIENT TLS by mode]: `prefer` and `require` check no
 * certificate; `verify-ca` checks it against the authorities trusted, not
 * its name; `verify-full` both. Each presents the client's certificate if
 * one is named.
 *
 * @internal
 */
final class Modes
{
    /**
     * The TLS of `$mode`, trusting and presenting what `$files` name; none of `disable`.
     *
     * @throws TurborelException SQLSTATE 08006 if a file cannot be read, a verifying mode trusts no authority, or a certificate has no key
     */
    public static function tls(TlsMode $mode, TlsFiles $files): ?Tls
    {
        $tls = match ($mode) {
            TlsMode::Disable => null,
            TlsMode::Prefer, TlsMode::Require => Tls::unverified(),
            TlsMode::VerifyCa => Tls::roots($files->roots, $files->systemRoots)->verifying(Verification::Authority),
            TlsMode::VerifyFull => Tls::roots($files->roots, $files->systemRoots),
        };
        if ($tls === null || $files->certificate === null) {
            return $tls;
        }
        if ($files->key === null) {
            throw ConnectionFailure::because('a client certificate is presented with its key, and none is named');
        }

        return $tls->presentingAlso($files->certificate, $files->key);
    }
}

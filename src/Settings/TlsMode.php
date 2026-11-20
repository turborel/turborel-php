<?php

declare(strict_types=1);

namespace Turborel\Settings;

/**
 * How a connection over TCP uses TLS, as turborel-client's `TlsMode`
 * [CLIENT TLS by mode]. A connection over a Unix socket or a named pipe,
 * which only its machine reaches, uses none whatever this says.
 */
enum TlsMode: string
{
    /** Never: plain TCP. */
    case Disable = 'disable';

    /** TLS if the server takes it, else plain TCP; the server's certificate not checked. */
    case Prefer = 'prefer';

    /** TLS or no connection; the server's certificate not checked. */
    case Require = 'require';

    /** TLS, the server's certificate signed by an authority trusted, its name not checked. */
    case VerifyCa = 'verify-ca';

    /** TLS, the server's certificate signed by an authority trusted and of the server's name. */
    case VerifyFull = 'verify-full';

    /** Whether the server's certificate is checked against the authorities trusted. */
    public function verifies(): bool
    {
        return $this === self::VerifyCa || $this === self::VerifyFull;
    }
}

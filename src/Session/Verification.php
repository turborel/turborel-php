<?php

declare(strict_types=1);

namespace Turborel\Session;

/**
 * How a TLS connection checks the server's certificate [CLIENT TLS by mode].
 */
enum Verification: string
{
    /** Signed by an authority trusted, and of the server's name: `verify-full`. */
    case Full = 'verify-full';

    /** Signed by an authority trusted: `verify-ca`. */
    case Authority = 'verify-ca';

    /** Not checked: `require` and `prefer`, which keep a connection from being overheard alone. */
    case None = 'require';
}

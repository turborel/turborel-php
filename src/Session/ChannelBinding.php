<?php

declare(strict_types=1);

namespace Turborel\Session;

/**
 * Whether SCRAM binds its exchange to the TLS connection carrying it
 * [CLIENT TLS by mode].
 */
enum ChannelBinding: string
{
    /** Binds nothing. */
    case Disable = 'disable';

    /** Binds where the connection has a binding. */
    case Prefer = 'prefer';

    /** Refuses an exchange that binds nothing. */
    case Require = 'require';
}

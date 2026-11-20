<?php

declare(strict_types=1);

namespace Turborel;

/**
 * turborel-php, the PHP driver for turborel.
 *
 * The driver speaks turborel's native protocol, version 1, in plain PHP,
 * with no C extension and no dependency at run time beyond ext-openssl
 * (RULES.md D1, D15). Its standard interface is a PDO-style API of its own
 * (RULES.md D2). Every failure it raises is a {@see TurborelException},
 * carrying the SQLSTATE and SQLCODE of what went wrong. The values it
 * reads and sends are PHP's own, or classes of `Turborel\Value`, and the
 * types of columns and parameters are classes of `Turborel\Type`.
 *
 * The driver is in alpha, version 0.0.0: anything may change from one
 * build to the next (VERSIONING.md).
 */
final class Turborel
{
    /** The turborel release the driver is made for, which is its own version (VERSIONING.md), as composer.json's. */
    public const string VERSION = '0.0.0';

    /** The version of turborel's native protocol the driver speaks: Hello's first field. */
    public const int PROTOCOL_VERSION = 1;
}

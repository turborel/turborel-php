<?php

declare(strict_types=1);

namespace Turborel\Settings;

/**
 * What a server must be for a connection to it to be kept, as
 * turborel-client's `TargetRole` [CLIENT Several servers].
 */
enum TargetRole: string
{
    /** Any server. */
    case Any = 'any';

    /** One that takes changes: a server alone, or its group's primary. */
    case ReadWrite = 'read-write';

    /** One that takes none: a replica following its group's log. */
    case ReadOnly = 'read-only';
}

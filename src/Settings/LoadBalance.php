<?php

declare(strict_types=1);

namespace Turborel\Settings;

/**
 * Whether the servers are tried in the order given, as turborel-client's
 * `LoadBalance` [CLIENT Several servers].
 */
enum LoadBalance: string
{
    /** In order. */
    case Disable = 'disable';

    /** In an order drawn at random for each connection, so connections spread over them. */
    case Random = 'random';
}

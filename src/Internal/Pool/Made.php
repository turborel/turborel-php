<?php

declare(strict_types=1);

namespace Turborel\Internal\Pool;

use Turborel\Pool\Source;
use Turborel\Session\Connection;
use Turborel\Settings\Settings;

/**
 * The source of connections settings, or a closure, make.
 *
 * @internal
 */
final readonly class Made implements Source
{
    /**
     * The source of `$maker`.
     *
     * @param Settings|(\Closure(): Connection) $maker
     */
    public function __construct(private Settings|\Closure $maker) {}

    /**
     * {@inheritDoc}
     */
    public function connect(): Connection
    {
        return $this->maker instanceof Settings ? $this->maker->connect() : ($this->maker)();
    }
}

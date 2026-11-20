<?php

declare(strict_types=1);

namespace Turborel\Internal\Codec\Reply;

/**
 * Suspended 'S': the rows asked for were sent and the cursor has more [PROTOCOL Suspended 'S'].
 *
 * @internal
 */
final readonly class Suspended implements Reply {}

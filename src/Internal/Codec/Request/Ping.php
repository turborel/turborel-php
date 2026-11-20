<?php

declare(strict_types=1);

namespace Turborel\Internal\Codec\Request;

use Turborel\Internal\Codec\Frames;
use Turborel\Internal\Codec\Kind;

/**
 * Ping 'G', with `ping`: answered by Pong [PROTOCOL Ping 'G'].
 *
 * @internal
 */
final readonly class Ping implements Request
{
    /**
     * Its frame.
     */
    public function encode(): string
    {
        return Frames::frame(Kind::PING, '');
    }
}

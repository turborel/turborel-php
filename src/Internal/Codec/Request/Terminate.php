<?php

declare(strict_types=1);

namespace Turborel\Internal\Codec\Request;

use Turborel\Internal\Codec\Frames;
use Turborel\Internal\Codec\Kind;

/**
 * Terminate 'X': the session ended, and the connection closed [PROTOCOL Terminate 'X'].
 *
 * @internal
 */
final readonly class Terminate implements Request
{
    /**
     * Its frame.
     */
    public function encode(): string
    {
        return Frames::frame(Kind::TERMINATE, '');
    }
}

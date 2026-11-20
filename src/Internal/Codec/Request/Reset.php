<?php

declare(strict_types=1);

namespace Turborel\Internal\Codec\Request;

use Turborel\Internal\Codec\Frames;
use Turborel\Internal\Codec\Kind;

/**
 * Reset 'W', with `reset`: the session put back as it was at login [PROTOCOL Reset 'W'].
 *
 * @internal
 */
final readonly class Reset implements Request
{
    /**
     * Its frame.
     */
    public function encode(): string
    {
        return Frames::frame(Kind::RESET, '');
    }
}

<?php

declare(strict_types=1);

namespace Turborel\Internal\Codec\Request;

use Turborel\TurborelException;

/**
 * A message the client sends, framed [PROTOCOL Requests].
 *
 * @internal
 */
interface Request
{
    /**
     * The request's frame: its header, then its body.
     *
     * @throws TurborelException SQLSTATE 22003 if a field is out of its range, 22021 if a string is not UTF-8
     */
    public function encode(): string;
}

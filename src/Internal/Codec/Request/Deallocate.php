<?php

declare(strict_types=1);

namespace Turborel\Internal\Codec\Request;

use Turborel\Internal\Codec\Frames;
use Turborel\Internal\Codec\Kind;
use Turborel\Internal\Codec\Writer;
use Turborel\TurborelException;

/**
 * Deallocate 'D': a prepared statement destroyed [PROTOCOL Deallocate 'D'].
 *
 * @internal
 */
final readonly class Deallocate implements Request
{
    /**
     * The request of its fields.
     */
    public function __construct(
        /** The statement's number. */
        public int $statement,
    ) {}

    /**
     * Its frame.
     *
     * @throws TurborelException SQLSTATE 22003 if the number is not a u32
     */
    public function encode(): string
    {
        return Frames::frame(Kind::DEALLOCATE, (new Writer())->u32($this->statement)->bytesWritten());
    }
}

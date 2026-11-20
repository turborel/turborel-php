<?php

declare(strict_types=1);

namespace Turborel\Internal\Codec\Request;

use Turborel\Internal\Codec\Frames;
use Turborel\Internal\Codec\Kind;
use Turborel\Internal\Codec\Writer;
use Turborel\TurborelException;

/**
 * CopyEnd 'N': the copy ended, or abandoned [PROTOCOL CopyEnd 'N'].
 *
 * @internal
 */
final readonly class CopyEnd implements Request
{
    /**
     * The request of its fields.
     */
    public function __construct(
        /** Whether every row is undone. */
        public bool $abandon = false,
    ) {}

    /**
     * Its frame.
     *
     * @throws TurborelException never, its one field being a flag
     */
    public function encode(): string
    {
        return Frames::frame(Kind::COPY_END, (new Writer())->flag($this->abandon)->bytesWritten());
    }
}

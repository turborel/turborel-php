<?php

declare(strict_types=1);

namespace Turborel\Internal\Codec\Request;

use Turborel\Internal\Codec\Frames;
use Turborel\Internal\Codec\Kind;
use Turborel\Internal\Codec\Writer;
use Turborel\TurborelException;

/**
 * Prepare 'P': a statement prepared under the client's number [PROTOCOL Prepare 'P'].
 *
 * @internal
 */
final readonly class Prepare implements Request
{
    /**
     * The request of its fields.
     */
    public function __construct(
        /** The client's number for it, a u32. */
        public int $statement,
        /** The statement. */
        public string $sql,
    ) {}

    /**
     * Its frame.
     *
     * @throws TurborelException SQLSTATE 22003 if a field is out of its range, 22021 if a string is not UTF-8
     */
    public function encode(): string
    {
        return Frames::frame(Kind::PREPARE, (new Writer())->u32($this->statement)->string($this->sql)->bytesWritten());
    }
}

<?php

declare(strict_types=1);

namespace Turborel\Internal\Codec\Request;

use Turborel\Internal\Codec\Frames;
use Turborel\Internal\Codec\Kind;
use Turborel\Internal\Codec\Writer;
use Turborel\TurborelException;

/**
 * Close 'C': an open cursor closed, and its tail, tag 3 cursor [PROTOCOL Close 'C'].
 *
 * @internal
 */
final readonly class Close implements Request
{
    /**
     * The request of its fields.
     */
    public function __construct(
        /** The statement's number. */
        public int $statement,
        /** The cursor's number, with `cursors`; 0 otherwise. */
        public int $cursor = 0,
    ) {}

    /**
     * Its frame.
     *
     * @throws TurborelException SQLSTATE 22003 if a field is out of its range, 22021 if a string is not UTF-8
     */
    public function encode(): string
    {
        $writer = (new Writer())->u32($this->statement);
        (new Extras([], $this->cursor))->writeCursor($writer);

        return Frames::frame(Kind::CLOSE, $writer->bytesWritten());
    }
}

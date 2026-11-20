<?php

declare(strict_types=1);

namespace Turborel\Internal\Codec\Request;

use Turborel\Internal\Codec\Frames;
use Turborel\Internal\Codec\Kind;
use Turborel\Internal\Codec\Writer;
use Turborel\TurborelException;

/**
 * Fetch 'F': more rows of an open cursor, at most `rows`, and its tail, tag 3 cursor [PROTOCOL Fetch 'F'].
 *
 * @internal
 */
final readonly class Fetch implements Request
{
    /**
     * The request of its fields.
     */
    public function __construct(
        /** The statement's number. */
        public int $statement,
        /** The most rows sent; 0 for all. */
        public int $rows = 0,
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
        $writer = (new Writer())->u32($this->statement)->u32($this->rows);
        (new Extras([], $this->cursor))->writeCursor($writer);

        return Frames::frame(Kind::FETCH, $writer->bytesWritten());
    }
}

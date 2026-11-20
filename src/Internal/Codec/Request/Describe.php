<?php

declare(strict_types=1);

namespace Turborel\Internal\Codec\Request;

use Turborel\Internal\Codec\Frames;
use Turborel\Internal\Codec\Kind;
use Turborel\Internal\Codec\Writer;
use Turborel\TurborelException;

/**
 * Describe 'Y', with `describe`: a prepared statement described, 0, with cursor 0, or an open cursor, 1 [PROTOCOL Describe 'Y'].
 *
 * @internal
 */
final readonly class Describe implements Request
{
    /** Describe's `what` of a statement. */
    private const int STATEMENT = 0;

    /** Describe's `what` of a cursor. */
    private const int CURSOR = 1;

    /**
     * The request of its fields.
     */
    public function __construct(
        /** The statement's number. */
        public int $statement,
        /** The cursor's number, to describe a cursor; none to describe the statement. */
        public ?int $cursor = null,
    ) {}

    /**
     * Its frame.
     *
     * @throws TurborelException SQLSTATE 22003 if a number is not a u32
     */
    public function encode(): string
    {
        $writer = (new Writer())->u8($this->cursor === null ? self::STATEMENT : self::CURSOR)->u32($this->statement)->u32($this->cursor ?? 0);

        return Frames::frame(Kind::DESCRIBE, $writer->bytesWritten());
    }
}

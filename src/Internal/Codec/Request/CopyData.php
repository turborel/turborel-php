<?php

declare(strict_types=1);

namespace Turborel\Internal\Codec\Request;

use Turborel\Internal\Codec\Fields;
use Turborel\Internal\Codec\Frames;
use Turborel\Internal\Codec\Kind;
use Turborel\Internal\Codec\Writer;
use Turborel\TurborelException;

/**
 * CopyData 'J': rows of the copy, as many as the client likes [PROTOCOL CopyData 'J'].
 *
 * @internal
 */
final readonly class CopyData implements Request
{
    /**
     * The request of its fields.
     *
     * @param list<list<mixed>> $rows
     */
    public function __construct(
        /** The rows, each a list of values. */
        public array $rows,
    ) {}

    /**
     * Its frame.
     *
     * @throws TurborelException SQLSTATE 07006 if a value has no value of the protocol, 22003 if a field is out of its range, 22021 if a string is not UTF-8
     */
    public function encode(): string
    {
        return Frames::frame(Kind::COPY_DATA, Fields::writeRows(new Writer(), $this->rows)->bytesWritten());
    }
}

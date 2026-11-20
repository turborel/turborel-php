<?php

declare(strict_types=1);

namespace Turborel\Internal\Codec\Request;

use Turborel\Internal\Codec\Frames;
use Turborel\Internal\Codec\Kind;
use Turborel\Internal\Codec\Writer;
use Turborel\TurborelException;

/**
 * CopyIn 'I', with `copy`: a copy of rows into a table begun, of the columns named or every column, and whether the transaction ends with it [PROTOCOL CopyIn 'I'].
 *
 * @internal
 */
final readonly class CopyIn implements Request
{
    /**
     * The request of its fields.
     *
     * @param list<string> $columns
     */
    public function __construct(
        /** The table, as SQL names it. */
        public string $table,
        /** Its columns the rows give values of; none for every column, in order. */
        public array $columns = [],
        /** Whether the transaction ends with the copy. */
        public bool $ends = false,
    ) {}

    /**
     * Its frame.
     *
     * @throws TurborelException SQLSTATE 22003 if a field is out of its range, 22021 if a string is not UTF-8
     */
    public function encode(): string
    {
        $writer = (new Writer())->string($this->table)->strings($this->columns)->flag($this->ends);

        return Frames::frame(Kind::COPY_IN, $writer->bytesWritten());
    }
}

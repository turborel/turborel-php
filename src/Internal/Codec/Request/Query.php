<?php

declare(strict_types=1);

namespace Turborel\Internal\Codec\Request;

use Turborel\Internal\Codec\Frames;
use Turborel\Internal\Codec\Kind;
use Turborel\Internal\Codec\Tail;
use Turborel\Internal\Codec\Writer;
use Turborel\TurborelException;

/**
 * Query 'Q': a direct SQL statement, whether the transaction ends with it, and its tail, tag 1 attributes and tag 2 multi [PROTOCOL Query 'Q'].
 *
 * @internal
 */
final readonly class Query implements Request
{
    /**
     * The request of its fields.
     *
     * @param list<array{string, string}> $attributes
     */
    public function __construct(
        /** The statement, or with `multi` several. */
        public string $sql,
        /** Whether the transaction ends with the statement. */
        public bool $ends = false,
        /** The request's attributes, with `query-attributes`. */
        public array $attributes = [],
        /** Whether the text is several statements, with `multi-statement`. */
        public bool $multi = false,
    ) {}

    /**
     * Its frame.
     *
     * @throws TurborelException SQLSTATE 22003 if a field is out of its range, 22021 if a string is not UTF-8
     */
    public function encode(): string
    {
        $writer = (new Writer())->string($this->sql)->flag($this->ends);
        Tail::writeAttributes($writer, $this->attributes);
        if ($this->multi) {
            $writer->u8(Tail::MULTI);
        }

        return Frames::frame(Kind::QUERY, $writer->bytesWritten());
    }
}

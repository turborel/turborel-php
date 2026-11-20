<?php

declare(strict_types=1);

namespace Turborel\Internal\Codec\Request;

use Turborel\Internal\Codec\Frames;
use Turborel\Internal\Codec\Kind;
use Turborel\Internal\Codec\Tail;
use Turborel\Internal\Codec\ValueWriter;
use Turborel\Internal\Codec\Writer;
use Turborel\TurborelException;

/**
 * Execute 'E': a prepared statement executed with a value for each parameter, at most `rows` rows sent now, whether the transaction ends with it, and its tail, tag 1 attributes and tag 3 cursor [PROTOCOL Execute 'E'].
 *
 * @internal
 */
final readonly class Execute implements Request
{
    /**
     * The request of its fields.
     *
     * @param list<mixed> $values
     */
    public function __construct(
        /** The statement's number. */
        public int $statement,
        /** A value for each parameter, as the value codec takes them. */
        public array $values = [],
        /** The most rows sent now; 0 for all. */
        public int $rows = 0,
        /** Whether the transaction ends with the statement. */
        public bool $ends = false,
        /** Its attributes and its cursor's number, its tail. */
        public Extras $extras = new Extras(),
    ) {}

    /**
     * Its frame.
     *
     * @throws TurborelException SQLSTATE 07006 if a value has no value of the protocol, 22003 if a field is out of its range, 22021 if a string is not UTF-8
     */
    public function encode(): string
    {
        $writer = ValueWriter::list((new Writer())->u32($this->statement), $this->values)->u32($this->rows)->flag($this->ends);
        Tail::writeAttributes($writer, $this->extras->attributes);
        $this->extras->writeCursor($writer);

        return Frames::frame(Kind::EXECUTE, $writer->bytesWritten());
    }
}

<?php

declare(strict_types=1);

namespace Turborel\Internal\Codec\Request;

use Turborel\Internal\Codec\Tail;
use Turborel\Internal\Codec\Writer;
use Turborel\TurborelException;

/**
 * What an Execute's tail may carry beyond its first form: its attributes,
 * tag 1, with `query-attributes`, and its cursor's number, tag 3, with
 * `cursors`, written only where not 0 [PROTOCOL The tail].
 *
 * @internal
 */
final readonly class Extras
{
    /**
     * The attributes `$attributes` and the cursor `$cursor`.
     *
     * @param list<array{string, string}> $attributes
     */
    public function __construct(
        /** @var list<array{string, string}> $attributes each a name and a value */
        public array $attributes = [],
        /** The cursor's number; 0, the default cursor, is not written. */
        public int $cursor = 0,
    ) {}

    /**
     * Writes tag 3 and the cursor's number, unless it is 0.
     *
     * @throws TurborelException SQLSTATE 22003 if it is not a u32
     */
    public function writeCursor(Writer $writer): void
    {
        if ($this->cursor !== 0) {
            $writer->u8(Tail::CURSOR)->u32($this->cursor);
        }
    }
}

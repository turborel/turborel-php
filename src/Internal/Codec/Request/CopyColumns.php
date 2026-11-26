<?php

declare(strict_types=1);

namespace Turborel\Internal\Codec\Request;

use Turborel\Internal\Codec\Frames;
use Turborel\Internal\Codec\Kind;
use Turborel\Internal\Codec\Writer;
use Turborel\TurborelException;

/**
 * CopyColumns 'O', with `copy` and `columns`: rows of a copy by column,
 * as text, in place of CopyData [PROTOCOL CopyColumns 'O']. Each column
 * has a null bit for each row, row 0 the low bit of the first byte; an
 * end for each row's cell in its text, a null cell's the end before it;
 * and its cells' UTF-8 one after another. It has no reply.
 *
 * @internal
 */
final readonly class CopyColumns implements Request
{
    /**
     * The request of `$rows`, each a text or null for each of `$columns` columns.
     *
     * @param list<list<?string>> $rows
     */
    public function __construct(
        /** @var list<list<?string>> $rows the rows, each a cell for each column: its text, or none of a null */
        public array $rows,
        /** The copy's columns, a cell of each row for each. */
        public int $columns,
    ) {}

    /**
     * Its frame.
     *
     * @throws TurborelException SQLSTATE 22003 if a count or an end is past a u32
     */
    public function encode(): string
    {
        $writer = (new Writer())->u32(\count($this->rows))->u32($this->columns);
        for ($column = 0; $column < $this->columns; $column++) {
            [$nulls, $ends, $text] = [str_repeat("\0", intdiv(\count($this->rows) + 7, 8)), [], ''];
            foreach ($this->rows as $at => $row) {
                $cell = $row[$column] ?? null;
                if ($cell === null) {
                    $nulls[$at >> 3] = \chr(\ord($nulls[$at >> 3]) | (1 << ($at & 7)));
                } else {
                    $text .= $cell;
                }
                $ends[] = \strlen($text);
            }
            $writer->bytes($nulls)->u32(\count($ends));
            foreach ($ends as $end) {
                $writer->u32($end);
            }
            $writer->bytes($text);
        }

        return Frames::frame(Kind::COPY_COLUMNS, $writer->bytesWritten());
    }
}

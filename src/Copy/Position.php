<?php

declare(strict_types=1);

namespace Turborel\Copy;

/**
 * Where in a source a record began [CLIENT Importing]: its number, the
 * first 1, a header not counted, and the line it began on, the first 1,
 * of a source made of lines.
 */
final readonly class Position
{
    /** The position of record `$record`, begun on line `$line` if known. */
    public function __construct(
        /** The record's number among the source's records of data. */
        public int $record,
        /** The line it began on, of a source made of lines. */
        public ?int $line = null,
    ) {}

    /** As `record 2, line 4`. */
    public function __toString(): string
    {
        return "record {$this->record}" . ($this->line === null ? '' : ", line {$this->line}");
    }
}

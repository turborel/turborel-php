<?php

declare(strict_types=1);

namespace Turborel\Copy;

use Turborel\Internal\Copy\Splitting;
use Turborel\Internal\Quietly;

/**
 * Delimited text as a source, as turborel-client's `Delimited` reads it
 * [CLIENT Importing]: CSV as RFC 4180 §2 defines it, or tab-separated
 * text, a record a line, read a line at a time from a stream, a record at
 * most the dialect's longest, so a file of any size is read in bounded
 * memory, and a quote left open is refused at the bound.
 *
 * A record ends at CR LF or LF, the last one's optional; a header may come
 * first; a CSV field may be enclosed in double quotes, and must be to hold
 * the delimiter, a quote or a line break, a quote in it written twice. A
 * field written as the dialect's text for null, not in quotes, is null:
 * by default an empty one, so `,,` is a null and `,"",` the empty string.
 * Text is UTF-8; a byte order mark beginning the input is passed over.
 */
final class Delimited implements Source
{
    /** The UTF-8 byte order mark. */
    private const string BOM = "\u{FEFF}";

    /** The bytes of the record being read. */
    private string $bytes = '';

    /** How many lines have been read. */
    private int $lines = 0;

    /** How many records of data have been read. */
    private int $records = 0;

    /** Whether a header is still to be read. */
    private bool $headerPending;

    /** Whether anything has been read: a byte order mark begins only the first record. */
    private bool $started = false;

    /** Whether the input has ended, or cannot be read on. */
    private bool $done = false;

    /**
     * Delimited text read from `$input`, written as `$dialect` says.
     *
     * @param resource $input
     */
    public function __construct(private readonly mixed $input, private readonly Dialect $dialect)
    {
        $this->headerPending = $dialect->header;
    }

    /**
     * {@inheritDoc}
     *
     * @return list<Heading>|null
     *
     * @throws SourceFailure if the header cannot be read, or there is none
     */
    public function headings(): ?array
    {
        if (!$this->headerPending) {
            return null;
        }
        $this->headerPending = false;
        $fields = $this->fields(0);
        if ($fields === null) {
            throw new SourceFailure(new Position(0, 1), 'there is no header');
        }

        return array_map(static fn(array $field): Heading => new Heading($field[0]), $fields[0]);
    }

    /**
     * {@inheritDoc}
     *
     * @throws SourceFailure why the record cannot be read, and where
     */
    public function record(): ?Record
    {
        $this->headings();
        if ($this->done) {
            return null;
        }
        $number = $this->records + 1;
        $read = $this->fields($number);
        if ($read === null) {
            return null;
        }
        $this->records = $number;
        $values = array_map(fn(array $field): ?string => !$field[1] && $field[0] === $this->dialect->null ? null : $field[0], $read[0]);

        return new Record($values, new Position($number, $read[1]));
    }

    /**
     * The next record's fields, each its text and whether it was quoted, and the line it began on; none at the end.
     *
     * @return array{list<array{string, bool}>, int}|null
     *
     * @throws SourceFailure why it cannot be read, as record `$record`
     */
    private function fields(int $record): ?array
    {
        $line = $this->lines + 1;
        try {
            $fields = $this->raw() ? Splitting::split($this->bytes, $this->dialect) : null;
        } catch (\UnexpectedValueException $wrong) {
            $this->done = true;

            throw new SourceFailure(new Position($record, $line), $wrong->getMessage());
        }
        $this->done = $fields === null;

        return $fields === null ? null : [$fields, $line];
    }

    /**
     * Reads the next record's bytes, its line break taken off: false at the end of the input.
     *
     * @throws \UnexpectedValueException what is wrong
     */
    private function raw(): bool
    {
        $this->bytes = '';
        $quoted = false;
        while (true) {
            $room = $this->dialect->maxRecord - \strlen($this->bytes) + 1;
            [$line, $warning] = Quietly::call(fn(): string|false => $room > 0 ? fgets($this->input, $room + 1) : '');
            if ($line === false && $warning !== null) {
                throw new \UnexpectedValueException("cannot read: {$warning}");
            }
            $line = $line === false ? '' : $line;
            $this->bytes .= $line;
            if (\strlen($this->bytes) > $this->dialect->maxRecord) {
                throw new \UnexpectedValueException("the record is longer than {$this->dialect->maxRecord} bytes");
            }
            if ($line === '') {
                if ($quoted) {
                    throw new \UnexpectedValueException('a quoted field is not closed');
                }

                return $this->bytes !== '';
            }
            $quoted = $this->dialect->quoted && Splitting::inQuotes($this->bytes, $this->dialect->delimiter);
            ++$this->lines;
            // A line break in a quoted field does not end the record.
            if (!$quoted) {
                $this->ended();

                return true;
            }
        }
    }

    /** Takes the record's line break off its bytes, and a byte order mark off the first's. */
    private function ended(): void
    {
        if (str_ends_with($this->bytes, "\n")) {
            $this->bytes = substr($this->bytes, 0, -1);
            if (str_ends_with($this->bytes, "\r")) {
                $this->bytes = substr($this->bytes, 0, -1);
            }
        }
        if (!$this->started && str_starts_with($this->bytes, self::BOM)) {
            $this->bytes = substr($this->bytes, \strlen(self::BOM));
        }
        $this->started = true;
    }
}

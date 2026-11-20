<?php

declare(strict_types=1);

namespace Turborel\Copy;

/**
 * Where rows to import come from, as turborel-client's `Source` [CLIENT Importing].
 *
 * `headings` is asked once, before the first record; then `record` until
 * it gives none, and none again if asked after. A source holds no more
 * than a record at a time, however long it is, and refuses a record past
 * a bound of its own rather than read the rest of its input looking for
 * its end. Delimited reads CSV and tab-separated text.
 */
interface Source
{
    /**
     * Its fields' names and types, as of a header row; none if it does not know them, its fields then the table's columns in order.
     *
     * @return list<Heading>|null
     *
     * @throws SourceFailure why it cannot be read, and where
     */
    public function headings(): ?array;

    /**
     * The next record; none at the end.
     *
     * @throws SourceFailure why it cannot be read, and where: a source cannot go on after
     */
    public function record(): ?Record;
}

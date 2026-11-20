<?php

declare(strict_types=1);

namespace Turborel\Copy;

/**
 * How an export writes a query's rows [CLIENT Copying and exporting].
 */
enum ExportFormat
{
    /** CSV (RFC 4180), with a header of the columns' names. */
    case Csv;

    /** The protocol's own frames: a Description, Rows, a Complete, which NativeRows reads back. */
    case Native;
}

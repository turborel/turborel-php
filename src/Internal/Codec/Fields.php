<?php

declare(strict_types=1);

namespace Turborel\Internal\Codec;

use Turborel\TurborelException;
use Turborel\Type\Column;
use Turborel\Type\Parameter;

/**
 * The composite fields messages are made of: rows, columns and
 * parameters [PROTOCOL Composite fields]. Each list is its count, then
 * each item, grown as items are read, never reserved for the count.
 *
 * @internal
 */
final class Fields
{
    /**
     * A column: named flag, its name if named, data type, not null flag.
     *
     * @throws TurborelException SQLSTATE 08006 if it is malformed
     */
    public static function column(Reader $reader): Column
    {
        $name = $reader->flag() ? $reader->string() : null;

        return new Column($name, TypeCodec::read($reader), $reader->flag());
    }

    /**
     * A list of columns.
     *
     * @return list<Column>
     *
     * @throws TurborelException SQLSTATE 08006 if one is malformed
     */
    public static function columns(Reader $reader): array
    {
        $columns = [];
        for ($count = $reader->u32(); $count > 0; $count--) {
            $columns[] = self::column($reader);
        }

        return $columns;
    }

    /**
     * A list of parameters, each a varying flag and a data type.
     *
     * @return list<Parameter>
     *
     * @throws TurborelException SQLSTATE 08006 if one is malformed
     */
    public static function parameters(Reader $reader): array
    {
        $parameters = [];
        for ($count = $reader->u32(); $count > 0; $count--) {
            $parameters[] = new Parameter($reader->flag(), TypeCodec::read($reader));
        }

        return $parameters;
    }

    /**
     * A list of rows, each a list of values.
     *
     * @return list<list<mixed>>
     *
     * @throws TurborelException SQLSTATE 08006 if one is malformed
     */
    public static function rows(Reader $reader): array
    {
        $rows = [];
        for ($count = $reader->u32(); $count > 0; $count--) {
            $rows[] = ValueCodec::list($reader);
        }

        return $rows;
    }

    /**
     * Writes a list of rows, each a list of values.
     *
     * @param list<list<mixed>> $rows
     *
     * @throws TurborelException as ValueWriter does of a value
     */
    public static function writeRows(Writer $writer, array $rows): Writer
    {
        $writer->u32(\count($rows));
        foreach ($rows as $row) {
            ValueWriter::list($writer, $row);
        }

        return $writer;
    }
}

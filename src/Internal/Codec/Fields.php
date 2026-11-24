<?php

declare(strict_types=1);

namespace Turborel\Internal\Codec;

use Turborel\TurborelException;
use Turborel\Type\Called;
use Turborel\Type\Column;
use Turborel\Type\Declared;
use Turborel\Type\Parameter;
use Turborel\Type\ParameterMode;

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
     * `$columns`, each with its declared type where the tail of a Description tells it, tag 19: a count, then of each
     * column its type's name and its type as SQL writes it [PROTOCOL Declared types].
     *
     * @param list<Column> $columns
     *
     * @return list<Column>
     *
     * @throws TurborelException SQLSTATE 08006 if the tail is malformed, or its types are not one for each column
     */
    public static function declared(Reader $reader, array $columns): array
    {
        $tail = new Tail();
        while ($tail->next($reader, [Tail::DECLARED]) !== null) {
            $columns = self::withDeclared($reader, $columns);
        }

        return $columns;
    }

    /**
     * The tail of a Prepared: `$columns`, each with its declared type where tag 19 tells it, and, where tag 20 tells it,
     * what a CALL tells of its procedure and each of its `?` marks [PROTOCOL Prepared 'P'].
     *
     * @param list<Column> $columns
     *
     * @return array{list<Column>, ?Called}
     *
     * @throws TurborelException SQLSTATE 08006 if the tail is malformed, a mode is of no code, or a position 0
     */
    public static function preparedTail(Reader $reader, array $columns): array
    {
        [$tail, $called] = [new Tail(), null];
        while (($tag = $tail->next($reader, [Tail::DECLARED, Tail::CALLED])) !== null) {
            if ($tag === Tail::DECLARED) {
                $columns = self::withDeclared($reader, $columns);

                continue;
            }
            [$schema, $name, $parameters] = [$reader->string(), $reader->string(), []];
            for ($count = $reader->u32(); $count > 0; $count--) {
                $parameters[] = self::called($reader);
            }
            $called = new Called($schema, $name, $parameters);
        }

        return [$columns, $called];
    }

    /**
     * One `?` of a CALL as tag 20 writes it: its mode, its parameter's ordinal position, from 1, and its type.
     *
     * @throws TurborelException SQLSTATE 08006 if its mode is of no code, its position 0, or it is malformed
     */
    private static function called(Reader $reader): Parameter
    {
        $code = $reader->u8();
        $mode = ParameterMode::tryFrom($code) ?? throw $reader->malformed("{$code} is no parameter mode's code");
        $ordinal = $reader->u32();
        if ($ordinal === 0) {
            throw $reader->malformed("a parameter's ordinal position is from 1");
        }

        return new Parameter($reader->flag(), TypeCodec::read($reader), $mode, $ordinal);
    }

    /**
     * `$columns`, each with the declared type tag 19 gives it.
     *
     * @param list<Column> $columns
     *
     * @return list<Column>
     *
     * @throws TurborelException SQLSTATE 08006 if its types are not one for each column, or are malformed
     */
    private static function withDeclared(Reader $reader, array $columns): array
    {
        $declared = [];
        for ($count = $reader->u32(); $count > 0; $count--) {
            $declared[] = new Declared($reader->string(), $reader->string());
        }
        if (\count($declared) !== \count($columns)) {
            throw $reader->malformed('its declared types are not one for each column');
        }

        return array_map(static fn(Column $c, Declared $d): Column => $c->withDeclared($d), $columns, $declared);
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

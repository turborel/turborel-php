<?php

declare(strict_types=1);

namespace Turborel\Internal\Copy;

use Turborel\Copy\ExportFormat;
use Turborel\Internal\Codec\Fields;
use Turborel\Internal\Codec\Frames;
use Turborel\Internal\Codec\Kind;
use Turborel\Internal\Codec\TypeWriter;
use Turborel\Internal\Codec\Writer;
use Turborel\Internal\ConnectionFailure;
use Turborel\Internal\Quietly;
use Turborel\Statements\Sink;
use Turborel\TurborelException;
use Turborel\Type\Column;

/**
 * A query's rows written to a stream as they come, as turborel-client's
 * `export.rs` writes them: as CSV (RFC 4180 §2), a header of the
 * columns' names, then a record for each row, a field quoted where it
 * holds a comma, a quote, a line break or spaces at either end, a quote in
 * it written twice, a null an empty field and an empty string `""`, each
 * record ended by CR LF; or as the protocol's own frames. The first write
 * that fails ends the export.
 *
 * @internal
 */
final class Exporter implements Sink
{
    /** Why the first write failed, if one did. */
    public ?string $failed = null;

    /**
     * The exporter to `$out`, as `$format` says.
     *
     * @param resource $out
     */
    public function __construct(private readonly mixed $out, private readonly ExportFormat $format) {}

    /** `$field` as CSV writes it: quoted where it must be. */
    public static function field(string $field): string
    {
        $quoted = $field === '' || strpbrk($field, ",\"\n\r") !== false || preg_match('/^\s|\s$/u', $field) === 1;

        return $quoted ? '"' . str_replace('"', '""', $field) . '"' : $field;
    }

    /**
     * `$value` as a CSV field: a null empty and unquoted.
     *
     * @throws TurborelException never
     */
    public static function value(mixed $value): string
    {
        return $value === null ? '' : self::field(Text::of($value));
    }

    /**
     * Writes the columns' names, or a Description.
     *
     * @throws TurborelException never: a type is written as it was read
     */
    public function columns(array $columns): void
    {
        if ($this->format === ExportFormat::Csv) {
            $this->write(self::record(array_map(static fn(Column $column): string => self::field($column->name ?? ''), $columns)));

            return;
        }
        $writer = (new Writer())->u32(\count($columns));
        foreach ($columns as $column) {
            $writer->flag($column->name !== null);
            if ($column->name !== null) {
                $writer->string($column->name);
            }
            TypeWriter::write($writer, $column->type)->flag($column->notNull);
        }
        $this->write(Frames::frame(Kind::DESCRIPTION, $writer->bytesWritten()));
    }

    /**
     * Writes a batch of rows, as records or a Rows frame.
     *
     * @throws TurborelException never: a value is written as it was read
     */
    public function rows(array $rows): void
    {
        if ($this->format === ExportFormat::Csv) {
            $this->write(implode('', array_map(static fn(array $row): string => self::record(array_map(self::value(...), $row)), $rows)));

            return;
        }
        $this->write(Frames::frame(Kind::ROWS, Fields::writeRows(new Writer(), $rows)->bytesWritten()));
    }

    /**
     * Ends the export: a Complete of native rows; then the stream flushed.
     *
     * @throws TurborelException SQLSTATE 08006 if a write failed: the export's failure, not the connection's
     */
    public function end(): void
    {
        if ($this->format === ExportFormat::Native) {
            $this->write(Frames::frame(Kind::COMPLETE, (new Writer())->u32(0)->string('00000')->flag(false)->bytesWritten()));
        }
        if ($this->failed === null) {
            [$flushed, $warning] = Quietly::call(fn(): bool => fflush($this->out));
            $this->failed = $flushed ? null : ($warning ?? 'the stream cannot be flushed');
        }
        if ($this->failed !== null) {
            throw ConnectionFailure::because("cannot write the export: {$this->failed}");
        }
    }

    /**
     * A CSV record of `$fields`, ended by CR LF.
     *
     * @param list<string> $fields
     */
    private static function record(array $fields): string
    {
        return implode(',', $fields) . "\r\n";
    }

    /** Writes `$bytes`, unless a write failed before. */
    private function write(string $bytes): void
    {
        for ($at = 0; $this->failed === null && $at < \strlen($bytes);) {
            [$written, $warning] = Quietly::call(fn(): int|false => fwrite($this->out, substr($bytes, $at)));
            if ($written === false || $written === 0) {
                $this->failed = $warning ?? 'the stream takes no more';
            } else {
                $at += $written;
            }
        }
    }
}

<?php

declare(strict_types=1);

namespace Turborel\Copy;

use Turborel\Internal\Misuse;
use Turborel\TurborelException;

/**
 * How delimited text is written, as turborel-client's `Dialect`
 * [CLIENT Importing]: its delimiter, whether fields may be enclosed in
 * quotes, whether a header names the fields, the text of a null, and the
 * longest record.
 */
final readonly class Dialect
{
    /** The longest record read by default, in bytes: the protocol's longest body, as a longer record could not be sent. */
    public const int DEFAULT_MAX_RECORD = 16 * 1024 * 1024;

    /** The dialect of its parts. */
    private function __construct(
        /** The delimiter, one ASCII character. */
        public string $delimiter,
        /** Whether fields may be enclosed in quotes, as CSV's are. */
        public bool $quoted,
        /** Whether the first record names the fields. */
        public bool $header = false,
        /** What a field not enclosed in quotes is written as to be null. */
        public string $null = '',
        /** The longest record, in bytes, its line break counted. */
        public int $maxRecord = self::DEFAULT_MAX_RECORD,
    ) {}

    /** CSV (RFC 4180 §2): fields split at commas, enclosed in quotes as they need; no header; an empty field null. */
    public static function csv(): self
    {
        return new self(',', true);
    }

    /** Tab-separated text: fields split at tabs, never quoted; no header; an empty field null. */
    public static function tsv(): self
    {
        return new self("\t", false);
    }

    /**
     * The dialect, its fields split at `$delimiter`.
     *
     * @throws TurborelException SQLSTATE TB910 unless it is one ASCII character other than a quote, CR or LF
     */
    public function withDelimiter(string $delimiter): self
    {
        if (\strlen($delimiter) !== 1 || \ord($delimiter) > 0x7F || str_contains("\"\r\n", $delimiter)) {
            // Shown as Rust's Debug shows a char: quoted by single quotes, its escapes written out.
            $shown = strtr($delimiter, ['\\' => '\\\\', "'" => "\\'", "\t" => '\\t', "\r" => '\\r', "\n" => '\\n', "\0" => '\\0']);

            throw Misuse::of("a delimiter is one ASCII character, not a quote or a line break: '{$shown}'");
        }

        return new self($delimiter, $this->quoted, $this->header, $this->null, $this->maxRecord);
    }

    /** The dialect, its first record naming the fields (RFC 4180 §2 rule 3). */
    public function withHeader(): self
    {
        return new self($this->delimiter, $this->quoted, true, $this->null, $this->maxRecord);
    }

    /** The dialect, a field written as `$null`, not in quotes, null. */
    public function withNull(string $null): self
    {
        return new self($this->delimiter, $this->quoted, $this->header, $null, $this->maxRecord);
    }

    /** The dialect, a record at most `$bytes` long, its line break counted. */
    public function withMaxRecord(int $bytes): self
    {
        return new self($this->delimiter, $this->quoted, $this->header, $this->null, $bytes);
    }
}

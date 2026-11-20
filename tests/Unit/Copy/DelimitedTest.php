<?php

declare(strict_types=1);

namespace Turborel\Tests\Unit\Copy;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\CoversNamespace;
use PHPUnit\Framework\Attributes\Test;
use Turborel\Copy\Delimited;
use Turborel\Copy\Dialect;
use Turborel\Copy\Position;
use Turborel\Copy\SourceFailure;
use Turborel\Internal\Copy\Splitting;
use Turborel\Internal\Misuse;
use Turborel\Internal\Quietly;
use Turborel\Tests\Support\CodecTestCase;
use Turborel\TurborelException;

/**
 * Delimited text read as a source: turborel-client's delimited tests,
 * case for case.
 */
#[CoversNamespace('Turborel\Copy')]
#[CoversClass(Splitting::class)]
#[CoversClass(Quietly::class)]
#[CoversClass(Misuse::class)]
#[CoversClass(TurborelException::class)]
final class DelimitedTest extends CodecTestCase
{
    /**
     * Verifies: [CLIENT Importing]: CSV's records end at CR LF or LF, the
     * last's optional; fields split at commas, spaces kept; a field in
     * quotes holds commas, line breaks and quotes written twice; an empty
     * field is null, one of empty quotes the empty string; an empty line is
     * a record of one null field (RFC 4180 §2 rules 1, 2, 4 to 7).
     *
     * @throws TurborelException never, the text being well formed
     */
    #[Test]
    public function csvRecordsAndFields(): void
    {
        $csv = Dialect::csv();
        self::assertSame([['a', 'b'], ['c', 'd']], self::fields("a,b\r\nc,d\n", $csv));
        self::assertSame([['a', 'b'], ['c', 'd']], self::fields("a,b\nc,d", $csv), 'the last line break is optional');
        self::assertSame([[' a ', ' b ']], self::fields(' a , b ', $csv), 'spaces are part of a field');
        self::assertSame([['a,b', 'say "hi"']], self::fields('"a,b","say ""hi"""', $csv));
        self::assertSame([["one\r\ntwo\nthree", 'x']], self::fields("\"one\r\ntwo\nthree\",x\n", $csv), 'line breaks in quotes are kept');
        self::assertSame([[null, '', null]], self::fields(',"",', $csv));
        self::assertSame([['a'], [null], ['b']], self::fields("a\n\nb\n", $csv));
        self::assertSame([['"']], self::fields('""""', $csv));
        self::assertSame([], self::fields('', $csv));
        self::assertSame([['é', "\u{1F600}"]], self::fields("é,\u{1F600}", $csv));
    }

    /**
     * Verifies: [CLIENT Importing]: the text for null is null only not in
     * quotes; an empty field is then the empty string. A byte order mark
     * beginning the input is passed over, and nowhere else.
     *
     * @throws TurborelException never, the text being well formed
     */
    #[Test]
    public function csvNullTextAndByteOrderMark(): void
    {
        self::assertSame([[null, 'NULL', '', 'null']], self::fields('NULL,"NULL",,null', Dialect::csv()->withNull('NULL')));
        self::assertSame([['a', 'b'], ["\u{FEFF}c"]], self::fields("\u{FEFF}a,b\n\u{FEFF}c", Dialect::csv()));
    }

    /**
     * Verifies: [CLIENT Importing]: a header names the fields, read once,
     * whether or not asked for; records are numbered from the first after
     * it, and each says the line it began on, past the line breaks of
     * quoted fields; a header missing is refused.
     *
     * @throws TurborelException never from the text well formed
     */
    #[Test]
    public function csvHeaderAndPositions(): void
    {
        $text = "ID,\"FULL NAME\"\n1,\"Ada\nLovelace\"\n2,Grace\n";
        $source = new Delimited(self::input($text), Dialect::csv()->withHeader());
        $headings = $source->headings() ?? [];
        self::assertSame(['ID', 'FULL NAME'], array_map(static fn($h): string => $h->name, $headings));
        self::assertSame([null, null], array_map(static fn($h): mixed => $h->type, $headings));
        self::assertNull($source->headings(), 'read once');
        $positions = [];
        while (($record = $source->record()) !== null) {
            $positions[] = $record->position;
        }
        self::assertEquals([new Position(1, 2), new Position(2, 4)], $positions);
        self::assertNull($source->record(), 'none again after the end');
        self::assertSame([['1', "Ada\nLovelace"], ['2', 'Grace']], self::fields($text, Dialect::csv()->withHeader()));
        try {
            (new Delimited(self::input(''), Dialect::csv()->withHeader()))->headings();
            self::fail('no header');
        } catch (SourceFailure $missing) {
            self::assertEquals(new Position(0, 1), $missing->position);
            self::assertSame('record 0, line 1: there is no header', $missing->getMessage());
        }
        self::assertNull((new Delimited(self::input('a'), Dialect::csv()))->headings(), 'no header unless told');
    }

    /**
     * Verifies: [CLIENT Importing]: a quote in a field not quoted, anything
     * but the delimiter after a closing quote, a quote left open at the end
     * of the input, and a field not UTF-8 are refused, saying the record,
     * the line it began on, and the field; the source then gives nothing
     * more (RFC 4180 §2 rules 5, 7).
     *
     * @throws TurborelException never from the records before the error
     */
    #[Test]
    public function csvErrorsSayWhere(): void
    {
        $quote = self::error("a\nb,c\"d\n", Dialect::csv());
        self::assertEquals(new Position(2, 2), $quote->position);
        self::assertSame('field 2: a quote in a field not enclosed in quotes', $quote->reason);
        self::assertSame("field 1: a quoted field's closing quote is followed by more", self::error('"a"b,c', Dialect::csv())->reason);
        $open = self::error("a\nb\n\"c\nd\ne\n", Dialect::csv());
        self::assertEquals(new Position(3, 3), $open->position, 'where the record began');
        self::assertSame('a quoted field is not closed', $open->reason);
        self::assertSame('a quoted field is not closed', self::error('"c', Dialect::csv())->reason);
        self::assertSame('record 1, line 1: field 2 is not UTF-8', self::error("ok,\xFF\xFE", Dialect::csv())->getMessage());
        self::assertSame('field 2 is not UTF-8', self::error("ok\t\xFF", Dialect::tsv())->reason);
        $source = new Delimited(self::input("\"a\"b\nc\n"), Dialect::csv());
        self::assertFailure(SourceFailure::SQLSTATE, SourceFailure::SQLCODE, static fn() => $source->record());
        self::assertNull($source->record(), 'nothing more after an error');
    }

    /**
     * Verifies: [CLIENT Importing]: a record longer than the bound is
     * refused once the bound is read, so an endless input, or a quote never
     * closed, is read in bounded memory; a record of the bound is read. The
     * default bound is the protocol's longest body.
     *
     * @throws TurborelException never from the records within the bound
     */
    #[Test]
    public function recordsAreBounded(): void
    {
        self::assertSame('the record is longer than 1024 bytes', self::error(str_repeat('a', 5000), Dialect::csv()->withMaxRecord(1024))->reason);
        self::assertSame('the record is longer than 4096 bytes', self::error(str_repeat('"', 10_000) . "\n\n", Dialect::csv()->withMaxRecord(4096))->reason);
        self::assertSame([['abcd']], self::fields("abcd\n", Dialect::csv()->withMaxRecord(5)), 'its line break counted');
        self::assertSame('the record is longer than 5 bytes', self::error("abcde\n", Dialect::csv()->withMaxRecord(5))->reason);
    }

    /**
     * Verifies: [CLIENT Importing]: tab-separated text splits at tabs, with
     * no quoting, its records at line breaks; an empty field is null.
     *
     * @throws TurborelException never, the text being well formed
     */
    #[Test]
    public function tabSeparatedRecords(): void
    {
        self::assertSame([['a', 'b'], ['"c', '"d"']], self::fields("a\tb\r\n\"c\t\"d\"\n", Dialect::tsv()));
        self::assertSame([['a', null, 'b,c']], self::fields("a\t\tb,c", Dialect::tsv()));
        self::assertSame([['a', null]], self::fields("a\t\\N", Dialect::tsv()->withNull('\N')));
    }

    /**
     * Verifies: [CLIENT Importing]: a delimiter is one ASCII character, not
     * a quote, CR or LF, else refused (SQLSTATE TB910); fields then split
     * at it.
     *
     * @throws TurborelException never from the delimiters taken
     */
    #[Test]
    public function delimiters(): void
    {
        self::assertSame([['a', 'b;c', 'd,e']], self::fields('a;"b;c";d,e', Dialect::csv()->withDelimiter(';')));
        self::assertSame([['a', "b\tc"]], self::fields("a|b\tc", Dialect::tsv()->withDelimiter('|')));
        foreach (['"', "\n", "\r", 'é', ''] as $wrong) {
            self::assertFailure('TB910', -910, static fn() => Dialect::csv()->withDelimiter($wrong));
        }
    }

    /**
     * The fields of each record of `$text`, written as `$dialect` says.
     *
     * @return list<list<string|null>>
     *
     * @throws TurborelException never, the tests' text being well formed
     */
    private static function fields(string $text, Dialect $dialect): array
    {
        $source = new Delimited(self::input($text), $dialect);
        $all = [];
        while (($record = $source->record()) !== null) {
            $all[] = array_map(static fn(mixed $v): ?string => \is_string($v) ? $v : null, $record->values);
        }

        return $all;
    }

    /** The failure reading `$text` as `$dialect` says gives. */
    private static function error(string $text, Dialect $dialect): SourceFailure
    {
        $source = new Delimited(self::input($text), $dialect);
        try {
            while ($source->record() !== null) {
                // Read on to the error.
            }
        } catch (SourceFailure $failure) {
            return $failure;
        }
        self::fail('no error');
    }

    /**
     * A stream holding `$text`.
     *
     * @return resource
     */
    private static function input(string $text): mixed
    {
        $stream = fopen('php://memory', 'w+b');
        self::assertIsResource($stream);
        fwrite($stream, $text);
        rewind($stream);

        return $stream;
    }
}

<?php

declare(strict_types=1);

namespace Turborel\Tests\Unit\Copy;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\CoversNamespace;
use PHPUnit\Framework\Attributes\Test;
use Turborel\Copy\ImportFailure;
use Turborel\Copy\Position;
use Turborel\Copy\Rejected;
use Turborel\Internal\Copy\Assigning;
use Turborel\Internal\Copy\Names;
use Turborel\Internal\Sql\Lexer;
use Turborel\Tests\Support\CodecTestCase;
use Turborel\TurborelException;
use Turborel\Type\CharacterType;
use Turborel\Type\ExactType;
use Turborel\Type\LobType;
use Turborel\Value\CharUnits;
use Turborel\Value\Lob;
use Turborel\Value\LobKind;

/**
 * An import's parts with no server: the names it writes in SQL, the
 * strings it assigns before it sends them, and how it tells a failure.
 */
#[CoversClass(Names::class)]
#[CoversClass(Lexer::class)]
#[CoversClass(Assigning::class)]
#[CoversNamespace('Turborel\Copy')]
#[CoversNamespace('Turborel\Internal\Codec')]
#[CoversClass(TurborelException::class)]
#[CoversNamespace('Turborel\Type')]
#[CoversNamespace('Turborel\Value')]
final class ImportPartsTest extends CodecTestCase
{
    /**
     * Verifies: [CLIENT Importing]: a table name of up to three
     * identifiers, catalog, schema and table, is written again as
     * delimited identifiers, a regular one in upper case; anything else,
     * a reserved word among it, is none; a heading names the column it is
     * the identifier of, a key word too, else as written.
     */
    #[Test]
    public function namesAreWrittenAsDelimitedIdentifiers(): void
    {
        self::assertSame(['"STAFF"', '"ANN"."STAFF"', '"CAT"."ANN"."Mixed ""q"""'], [Names::table('staff'), Names::table('ann . staff'), Names::table('cat.ann."Mixed ""q"""')]);
        self::assertSame(\function_exists('mb_strtoupper') ? '"ÉTÉ"' : '"éTé"', Names::table('été'), "Unicode's upper case where mbstring is loaded");
        foreach (['', 'a.', '.a', 'a..b', 'a.b.c.d', 'select', 'a b', '"open', '1a', str_repeat('a', 129)] as $wrong) {
            self::assertNull(Names::table($wrong), $wrong);
        }
        self::assertNotNull(Names::table(str_repeat('a_', 128)), 'an underscore is not counted');
        self::assertSame(['ID', 'FULL NAME', 'SELECT', 'Mixed', 'a b'], [Names::column('id'), Names::column('"FULL NAME"'), Names::column('select'),
            Names::column('"Mixed"'), Names::column('a b')]);
    }

    /**
     * Verifies: [CLIENT Importing]: a string for a character string column
     * is assigned as INSERT assigns it: CHARACTER padded, VARYING kept, one
     * too long refused (SQLSTATE 22001) unless what is past its length is
     * spaces, counted in characters or octets as the type counts; a
     * character large object's string given as a large object held whole;
     * any other value as it is.
     *
     * @throws TurborelException never from the strings that fit
     */
    #[Test]
    public function stringsAreAssignedAsInsertAssignsThem(): void
    {
        $varying = new CharacterType(true, 3, CharUnits::Characters);
        self::assertSame(['ab ', 'abc', 'éé', 'abc', 7], [Assigning::assigned('ab', new CharacterType(false, 3, CharUnits::Characters)),
            Assigning::assigned('abc   ', $varying), Assigning::assigned('éé', $varying), Assigning::assigned('abc', new ExactType(9, 0)), Assigning::assigned(7, $varying)]);
        self::assertSame('é', Assigning::assigned('é ', new CharacterType(true, 2, CharUnits::Octets)));
        self::assertFailure('22001', -301, static fn() => Assigning::assigned('abcd', $varying));
        try {
            Assigning::assigned('éé', new CharacterType(false, 3, CharUnits::Octets));
            self::fail('too long');
        } catch (TurborelException $failure) {
            self::assertSame(['a value of 4 octets, not spaces beyond 3, does not fit CHARACTER(3 OCTETS)', 'ISO/IEC 9075:1992 §13.8 GR4b iii, §9.2 GR3b ii',
                'CHARACTER(3 OCTETS)'], [$failure->getMessage(), $failure->getRule(), $failure->getDiagnostics()['dataType'] ?? null]);
        }
        $clob = new LobType(LobKind::Character, 3, CharUnits::Characters);
        self::assertEquals([new Lob(LobKind::Character, 'abc'), new Lob(LobKind::Character, 'ab')], [Assigning::assigned('abc  ', $clob), Assigning::assigned('ab', $clob)]);
        try {
            Assigning::assigned('abcd', $clob);
            self::fail('too long');
        } catch (TurborelException $failure) {
            self::assertSame(['a large object longer than CHARACTER LARGE OBJECT(3), not padding beyond it, does not fit it',
                'ISO/IEC 9075:1992 §13.8 GR4b iii; ISO/IEC 9075-2:2023 §9.2 GR3e ii'], [$failure->getMessage(), $failure->getRule()]);
        }
        self::assertSame('ab', Assigning::assigned('ab', new LobType(LobKind::Binary, 3, CharUnits::Octets)));
    }

    /**
     * Verifies: [CLIENT Importing]: an import's failure says where its
     * record began, why, the input's in its words or SQL's as a failure is
     * written, the rows committed and rolled back, and takes its cause's
     * SQLSTATE; a row skipped says so.
     */
    #[Test]
    public function aFailureSaysWhereWhyAndWhatWasKept(): void
    {
        $sql = new TurborelException('no', '23000', -201);
        $stopped = new ImportFailure(new Position(3, 4), $sql, false, 1, 2);
        self::assertSame(
            ['record 3, line 4: SQLCODE -201 (SQLSTATE 23000): no; 1 row committed, 2 rolled back', '23000', -201, $sql],
            [$stopped->getMessage(), $stopped->getSqlState(), $stopped->getSqlCode(), $stopped->getPrevious()],
        );
        $input = new ImportFailure(null, ImportFailure::inputCause('3 fields, for 2 columns'), true, 0, 0);
        self::assertSame(['3 fields, for 2 columns; 0 rows committed, 0 rolled back', '22000', -301], [$input->getMessage(), $input->getSqlState(), $input->getSqlCode()]);
        self::assertSame('record 1: 3 fields, for 2 columns; skipped', (string) new Rejected(new Position(1), ImportFailure::inputCause('3 fields, for 2 columns'), true));
    }
}

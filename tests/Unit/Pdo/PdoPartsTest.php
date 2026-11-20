<?php

declare(strict_types=1);

namespace Turborel\Tests\Unit\Pdo;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\CoversNamespace;
use PHPUnit\Framework\Attributes\Test;
use Turborel\Internal\Pdo\Binding;
use Turborel\Internal\Pdo\ColumnMeta;
use Turborel\Internal\Pdo\Fetching;
use Turborel\Pdo\Pdo;
use Turborel\Tests\Support\CodecTestCase;
use Turborel\TurborelException;
use Turborel\Type\ApproximateType;
use Turborel\Type\BinaryType;
use Turborel\Type\BooleanType;
use Turborel\Type\Column;
use Turborel\Type\DatetimeType;
use Turborel\Type\DecFloatType;
use Turborel\Type\JsonType;
use Turborel\Type\LobType;
use Turborel\Value\CharUnits;
use Turborel\Value\LobKind;
use Turborel\Value\Moment;

/**
 * The PDO-style API's parts with no server: a column's meta, values bound
 * as their types say, and the fetch modes.
 */
#[CoversNamespace('Turborel\Internal')]
#[CoversNamespace('Turborel\Type')]
#[CoversClass(TurborelException::class)]
final class PdoPartsTest extends CodecTestCase
{
    /**
     * Verifies: [DESIGN] D2: getColumnMeta names each type as SQL writes
     * its kind, reads a truth value as PARAM_BOOL and a large object as
     * PARAM_LOB, and gives a length and a precision where a type has one.
     */
    #[Test]
    public function aColumnsMetaNamesItsType(): void
    {
        $columns = [new Column('B', new BinaryType(true, 8), false), new Column('F', new ApproximateType(53), false), new Column('D', new DecFloatType(34), false),
            new Column('T', new BooleanType(), false), new Column('S', new DatetimeType(Moment::Timestamp, true, 6), false),
            new Column('L', new LobType(LobKind::Binary, 9, CharUnits::Octets), false), new Column(null, new JsonType(), true), new Column('C', new LobType(LobKind::Character, 9, CharUnits::Characters), false),
            new Column('X', new BinaryType(false, 4), false)];
        $meta = array_map(static fn(Column $c): array => [ColumnMeta::of($c)['native_type'], ColumnMeta::of($c)['pdo_type'], ColumnMeta::of($c)['len'], ColumnMeta::of($c)['precision']], $columns);
        self::assertSame([['BINARY VARYING', Pdo::PARAM_STR, 8, 0], ['DOUBLE PRECISION', Pdo::PARAM_STR, -1, 0], ['DECFLOAT', Pdo::PARAM_STR, -1, 0],
            ['BOOLEAN', Pdo::PARAM_BOOL, -1, 0], ['TIMESTAMP WITH TIME ZONE', Pdo::PARAM_STR, -1, 6], ['BINARY LARGE OBJECT', Pdo::PARAM_LOB, 9, 0],
            ['JSON', Pdo::PARAM_STR, -1, 0], ['CHARACTER LARGE OBJECT', Pdo::PARAM_LOB, 9, 0], ['BINARY', Pdo::PARAM_STR, 4, 0]], $meta);
        self::assertSame(['', ['not_null']], [ColumnMeta::of($columns[6])['name'], ColumnMeta::of($columns[6])['flags']]);
    }

    /**
     * Verifies: [DESIGN] D2: bound values are taken as their types say: null;
     * an integer of an integer's digits or a truth value; a truth value; a
     * string of a scalar; a large object of a stream's contents; else as
     * they are; a position not bound is refused (SQLSTATE TB910).
     *
     * @throws TurborelException never from the positions bound
     */
    #[Test]
    public function boundValuesAreTakenAsTheirTypesSay(): void
    {
        $stream = fopen('php://memory', 'w+b');
        self::assertIsResource($stream);
        fwrite($stream, 'lob');
        rewind($stream);
        $bound = [[null, Pdo::PARAM_STR], ['5', Pdo::PARAM_NULL], ['-12', Pdo::PARAM_INT], [true, Pdo::PARAM_INT], ['x', Pdo::PARAM_INT], [0, Pdo::PARAM_BOOL],
            [7, Pdo::PARAM_STR], [1.5, Pdo::PARAM_STR], [$stream, Pdo::PARAM_LOB], ['99999999999999999999', Pdo::PARAM_INT]];
        $values = Binding::values(array_combine(range(1, \count($bound)), $bound), []);
        self::assertSame([null, null, -12, 1, 'x', false, '7', '1.5', 'lob', '99999999999999999999'], $values);
        self::assertFailure('TB910', -910, static fn(): array => Binding::values([2 => [1, Pdo::PARAM_INT]], []));
        self::assertFailure('TB910', -910, static fn(): int => Binding::position(0));
    }

    /**
     * Verifies: [DESIGN] D2: a row is read in each mode; FETCH_COLUMN of a
     * column not there, FETCH_CLASS of no class, and a mode not taken are
     * refused (SQLSTATE TB910).
     *
     * @throws TurborelException never from the modes taken
     */
    #[Test]
    public function aRowIsReadInEachMode(): void
    {
        $columns = [new Column('A', new BooleanType(), false), new Column('B', new BooleanType(), false)];
        self::assertSame([['A' => 1, 'B' => 2], [1, 2], ['A' => 1, 'B' => 2, 0 => 1, 1 => 2], 2], [Fetching::row([1, 2], $columns, Pdo::FETCH_ASSOC, 0, []),
            Fetching::row([1, 2], $columns, Pdo::FETCH_NUM, 0, []), Fetching::row([1, 2], $columns, Pdo::FETCH_BOTH, 0, []), Fetching::row([1, 2], $columns, Pdo::FETCH_COLUMN, 1, [])]);
        self::assertFailure('TB910', -910, static fn(): mixed => Fetching::row([1], $columns, Pdo::FETCH_COLUMN, 5, []));
        self::assertFailure('TB910', -910, static fn(): mixed => Fetching::row([1], $columns, Pdo::FETCH_CLASS, 'No\\Such\\Class', []));
        self::assertFailure('TB910', -910, static fn(): int => Fetching::mode(1));
    }
}

<?php

declare(strict_types=1);

namespace Turborel\Internal\Codec;

use Turborel\TurborelException;

/**
 * A statement's completion, a Complete's body: SQLCODE 0 or 100, its
 * SQLSTATE, whether a transaction is active, and its tail, tags 4, 5, 6,
 * 16, 17 and 18, each where it says something [PROTOCOL Complete 'K'].
 *
 * @internal
 */
final readonly class Completion
{
    /** The tags a Complete's tail takes. */
    private const array TAKEN = [Tail::POSITION, Tail::MORE, Tail::ROWS, Tail::GENERATED, Tail::CONDITIONS, Tail::TIME];

    /**
     * The completion of its parts.
     *
     * @param array{list<string>, list<Failure>} $raised
     */
    public function __construct(
        /** Its SQLCODE: 0, or 100 of no data. */
        public int $sqlcode,
        /** Its SQLSTATE. */
        public string $sqlstate,
        /** Whether a transaction is active after it. */
        public bool $active,
        /** Tags 4, 5, 6 and 18: the commit's position, whether more replies follow, the rows counted, the time. */
        public Counts $counts = new Counts(),
        /** @var array{list<string>, list<Failure>} $raised tags 16 and 17: the identity values generated, and the conditions raised */
        public array $raised = [[], []],
    ) {}

    /**
     * The completion `$reader` reads next, its tail to the body's end.
     *
     * @throws TurborelException SQLSTATE 08006 if it is malformed
     */
    public static function read(Reader $reader): self
    {
        [$sqlcode, $sqlstate, $active] = [Wide::i32($reader->u32()), $reader->string(), $reader->flag()];
        [$position, $more, $rows, $generated, $conditions, $time] = [null, false, null, [], [], null];
        $tail = new Tail();
        while (($tag = $tail->next($reader, self::TAKEN)) !== null) {
            match ($tag) {
                Tail::POSITION => $position = $reader->u64(),
                Tail::MORE => $more = true,
                Tail::ROWS => $rows = $reader->u64(),
                Tail::GENERATED => $generated = $reader->strings(),
                Tail::CONDITIONS => $conditions = self::conditions($reader),
                default => $time = new Timing($reader->u64(), $reader->u64(), $reader->u64()),
            };
        }

        return new self($sqlcode, $sqlstate, $active, new Counts($position, $more, $rows, $time), [$generated, $conditions]);
    }

    /**
     * The conditions of tag 17: a list, each a failure as bytes, its own tail ending where its length does.
     *
     * @return list<Failure>
     *
     * @throws TurborelException SQLSTATE 08006 if one is malformed
     */
    private static function conditions(Reader $reader): array
    {
        $conditions = [];
        for ($count = $reader->u32(); $count > 0; $count--) {
            $inner = new Reader($reader->bytes(), 'condition');
            $conditions[] = Failure::read($inner);
            $inner->finish();
        }

        return $conditions;
    }
}

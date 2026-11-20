<?php

declare(strict_types=1);

namespace Turborel\Internal\Codec;

use Turborel\TurborelException;

/**
 * A failure, as an Error carries it and a Complete's conditions do: its
 * SQLCODE, SQLSTATE and message, the rule it cites, its position, whether
 * a transaction is active after it, and its diagnostics, tail 7 to 15
 * [PROTOCOL Composite fields].
 *
 * @internal
 */
final readonly class Failure
{
    /** The failure of its parts. */
    public function __construct(
        /** Its SQLCODE, an i32. */
        public int $sqlcode,
        /** Its SQLSTATE, five characters. */
        public string $sqlstate,
        /** Its message, for people. */
        public string $message,
        /** The rule it cites, and its position in characters into the statement, each if given. */
        public Cited $cited = new Cited(),
        /** Whether a transaction is active after it, and what else it says. */
        public FailureState $state = new FailureState(),
    ) {}

    /**
     * The failure `$reader` reads next, its diagnostics to the body's end.
     *
     * @throws TurborelException SQLSTATE 08006 if it is malformed
     */
    public static function read(Reader $reader): self
    {
        $sqlcode = Wide::i32($reader->u32());
        [$sqlstate, $message] = [$reader->string(), $reader->string()];
        $rule = $reader->flag() ? $reader->string() : null;
        $position = $reader->flag() ? $reader->u64() : null;
        $active = $reader->flag();

        return new self($sqlcode, $sqlstate, $message, new Cited($rule, $position), new FailureState($active, Diagnostics::read($reader)));
    }
}

<?php

declare(strict_types=1);

namespace Turborel\Statements;

use Turborel\Internal\Codec\Completion;
use Turborel\TurborelException;

/**
 * A statement's completion [CLIENT Running statements]: its SQLCODE, 0 or
 * 100 of no data, its SQLSTATE, whether a transaction is active after it,
 * and what else the server told of it, each where granted: the rows it
 * counted, the log position of the commit it made, the identity values it
 * generated, its conditions, and the server's time for it
 * [PROTOCOL Complete 'K'].
 */
final readonly class Completed
{
    /** The completion a Complete carried. */
    private function __construct(private Completion $completion) {}

    /**
     * The completion a Complete carried.
     *
     * @internal
     */
    public static function of(Completion $completion): self
    {
        return new self($completion);
    }

    /** Its SQLCODE: 0, or 100 of no data. */
    public function sqlcode(): int
    {
        return $this->completion->sqlcode;
    }

    /** Its SQLSTATE: 00000, a warning's of class 01, or 02000 of no data. */
    public function sqlstate(): string
    {
        return $this->completion->sqlstate;
    }

    /** Whether a transaction is active after it. */
    public function active(): bool
    {
        return $this->completion->active;
    }

    /** Whether it is a warning's, SQLSTATE class 01. */
    public function isWarning(): bool
    {
        return str_starts_with($this->completion->sqlstate, '01');
    }

    /** The rows it inserted, updated, deleted or merged, or a query returned, with `row-counts`. */
    public function rows(): int|string|null
    {
        return $this->completion->counts->rows;
    }

    /** The log position of the commit it made, with `commit-position`. */
    public function position(): int|string|null
    {
        return $this->completion->counts->position;
    }

    /** Whether another statement's replies follow, of a text of several. */
    public function more(): bool
    {
        return $this->completion->counts->more;
    }

    /** The server's time for it, in microseconds, with `row-counts`. */
    public function time(): ?Timing
    {
        $time = $this->completion->counts->time;

        return $time === null ? null : new Timing($time->total, $time->queued, $time->locks);
    }

    /**
     * Each identity value an INSERT generated, as SQL writes it, in the order of its rows, with `generated-values`.
     *
     * @return list<string>
     */
    public function generated(): array
    {
        return $this->completion->raised[0];
    }

    /**
     * Each condition it raised, with `conditions`.
     *
     * @return list<TurborelException>
     */
    public function conditions(): array
    {
        return array_map(TurborelException::fromServer(...), $this->completion->raised[1]);
    }
}

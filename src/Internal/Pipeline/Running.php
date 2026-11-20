<?php

declare(strict_types=1);

namespace Turborel\Internal\Pipeline;

use Turborel\Internal\Codec\Request\Query;
use Turborel\Internal\Session\Wire;
use Turborel\Internal\Statements\Preparing;
use Turborel\Internal\Statements\Querying;
use Turborel\Internal\Statements\Results;
use Turborel\Pipeline\Piped;
use Turborel\Pipeline\Pipeline;
use Turborel\Statements\Completed;
use Turborel\Statements\Outcome;
use Turborel\TurborelException;

/**
 * A pipeline run, as turborel-client's `pipeline.rs` runs it
 * [CLIENT Pipelines]: its requests written a window at a time, each
 * window's replies read before the next is written, so neither side waits
 * on the other to read. Under OnError::Skip a savepoint is set before each
 * request but the first, the requests after a failure undone to the one
 * set after it, or never sent; the batch is one transaction, committed as
 * it ends under autocommit where it began none.
 *
 * @internal
 */
final readonly class Running
{
    /** The run over `$wire`, each request its own transaction if `$autocommit`, but of a skipping batch. */
    public function __construct(private Wire $wire, private bool $autocommit) {}

    /**
     * Runs `$queued`, stopping at the first failure if `$skip`: one outcome each, in order.
     *
     * @param list<Queued> $queued
     *
     * @return list<Piped>
     *
     * @throws TurborelException SQLSTATE 08006 once the connection is lost; under skip, the failure of undoing what failed
     */
    public function run(array $queued, bool $skip): array
    {
        $began = $this->wire->active;
        $sent = $skip ? self::guarded($queued) : $queued;
        $outcomes = [];
        foreach (self::windows($sent, $this->autocommit && !$skip, $this->wire->told->attributes()) as $window) {
            if ($skip && array_filter($outcomes, static fn(Piped $o): bool => $o->failure !== null) !== []) {
                array_push($outcomes, ...array_map(static fn(): Piped => Piped::skipped(), $window));

                continue;
            }
            array_push($outcomes, ...$this->window($window));
        }
        if ($skip) {
            $outcomes = $this->settle($sent, $outcomes, $began);
        }
        $shown = [];
        foreach ($sent as $at => $q) {
            if ($q->kind !== Queued::SAVEPOINT) {
                $shown[] = $outcomes[$at] ?? Piped::skipped();
            }
        }

        return $shown;
    }

    /**
     * The requests of `$queued`, encoded, the transaction ended with each if `$ends`, grouped into windows of at most
     * Pipeline::WINDOW bytes, a request longer than that alone in its own.
     *
     * @param list<Queued>                $queued
     * @param list<array{string, string}> $attributes
     *
     * @return list<list<array{Queued, string}>>
     *
     * @throws TurborelException SQLSTATE 22000 or a class 22 SQLSTATE of a value the protocol cannot carry
     */
    public static function windows(array $queued, bool $ends, array $attributes): array
    {
        [$windows, $current, $filled] = [[], [], 0];
        foreach ($queued as $q) {
            $bytes = $q->request($ends, $q->kind === Queued::SAVEPOINT ? [] : $attributes)->encode();
            if ($current !== [] && $filled + \strlen($bytes) > Pipeline::WINDOW) {
                [$windows[], $current, $filled] = [$current, [], 0];
            }
            $current[] = [$q, $bytes];
            $filled += \strlen($bytes);
        }

        return $current === [] ? $windows : [...$windows, $current];
    }

    /**
     * `$queued`, a savepoint of its own before each request but the first.
     *
     * @param list<Queued> $queued
     *
     * @return list<Queued>
     */
    private static function guarded(array $queued): array
    {
        $sent = [];
        foreach ($queued as $n => $q) {
            if ($n > 0) {
                $sent[] = Queued::savepoint($n);
            }
            $sent[] = $q;
        }

        return $sent;
    }

    /**
     * Writes `$window` at once and reads its outcomes.
     *
     * @param list<array{Queued, string}> $window
     *
     * @return list<Piped>
     *
     * @throws TurborelException SQLSTATE 08006 once the connection is lost
     */
    private function window(array $window): array
    {
        $this->wire->sendBytes(implode('', array_column($window, 1)));
        $outcomes = [];
        foreach ($window as [$q]) {
            try {
                $outcomes[] = Piped::done($this->reply($q));
            } catch (TurborelException $failure) {
                if ($this->wire->lost !== null) {
                    throw $failure;
                }
                $outcomes[] = Piped::failed($failure);
            }
        }

        return $outcomes;
    }

    /**
     * The reply to `$q`, sent.
     *
     * @throws TurborelException its failure, or SQLSTATE 08006
     */
    private function reply(Queued $q): Outcome
    {
        return match ($q->kind) {
            Queued::EXECUTE => $q->statement === null ? throw $this->wire->lose('an Execute was queued with no statement')
                : (new Preparing($this->wire, $this->autocommit))->executed($q->statement),
            Queued::CLOSE, Queued::DEALLOCATE => new Outcome([], [], Completed::of((new Results($this->wire))->completion())),
            default => (new Querying($this->wire, $this->autocommit))->outcome(),
        };
    }

    /**
     * Ends a skipping batch of `$sent`: the requests after the first that failed undone to the savepoint set after it, and
     * marked skipped; the first savepoint released; under autocommit of a batch that began no transaction, its work committed.
     *
     * @param list<Queued> $sent
     * @param list<Piped>  $outcomes
     *
     * @return list<Piped>
     *
     * @throws TurborelException the failure of undoing, releasing or committing
     */
    private function settle(array $sent, array $outcomes, bool $began): array
    {
        $statements = [];
        foreach ($outcomes as $at => $outcome) {
            if ($outcome->failure === null) {
                continue;
            }
            $after = $sent[$at + 1] ?? null;
            if ($after !== null && $after->kind === Queued::SAVEPOINT && ($outcomes[$at + 1] ?? null)?->outcome !== null) {
                $statements[] = 'ROLLBACK TO SAVEPOINT ' . Queued::name($after->number);
            }
            for ($i = $at + 1; $i < \count($outcomes); $i++) {
                $outcomes[$i] = Piped::skipped();
            }

            break;
        }
        $first = $sent[1] ?? null;
        if ($first !== null && $first->kind === Queued::SAVEPOINT && ($outcomes[1] ?? null)?->outcome !== null) {
            $statements[] = 'RELEASE SAVEPOINT ' . Queued::name($first->number);
        }
        if (!$began && $this->autocommit) {
            $statements[] = 'COMMIT';
        }
        foreach ($statements as $sql) {
            $this->wire->send(new Query($sql));
            (new Querying($this->wire, false))->outcome();
        }

        return $outcomes;
    }
}

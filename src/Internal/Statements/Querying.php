<?php

declare(strict_types=1);

namespace Turborel\Internal\Statements;

use Turborel\Internal\Codec\Reply\Complete;
use Turborel\Internal\Codec\Reply\Description;
use Turborel\Internal\Codec\Request\Query;
use Turborel\Internal\Session\Control;
use Turborel\Internal\Session\Wire;
use Turborel\Session\Capability;
use Turborel\Statements\Completed;
use Turborel\Statements\Outcome;
use Turborel\Statements\Ran;
use Turborel\Statements\Sink;
use Turborel\Statements\Streamed;
use Turborel\TurborelException;

/**
 * Direct SQL statements run, as turborel-client's `query`, `query_into`
 * and `query_many` run them [PROTOCOL Query 'Q']: a query answered by a
 * Description, its rows in Rows replies and a Complete; another
 * statement by a Complete; a failure by an Error
 * [CLIENT Running statements].
 *
 * @internal
 */
final readonly class Querying
{
    /** The results read over the wire. */
    private Results $results;

    /** Queries over `$wire`, each statement its own transaction if `$autocommit`. */
    public function __construct(private Wire $wire, private bool $autocommit)
    {
        $this->results = new Results($wire);
    }

    /**
     * Runs `$sql`, its outcome.
     *
     * @throws TurborelException the statement's failure, or SQLSTATE 08006 if the connection is lost
     */
    public function query(string $sql): Outcome
    {
        $this->send($sql, false);

        return $this->outcome();
    }

    /**
     * Runs `$sql`, a query's columns and rows given to `$sink` as they come.
     *
     * @throws TurborelException the statement's failure, after some rows went to `$sink` too, or SQLSTATE 08006
     */
    public function into(string $sql, Sink $sink): Streamed
    {
        $this->send($sql, false);
        $reply = $this->wire->receive();
        if ($reply instanceof Complete) {
            return new Streamed(0, Completed::of($reply->completion));
        }
        if (!$reply instanceof Description) {
            throw $this->results->unexpected($reply, 'the server sent no result');
        }
        $sink->columns($reply->columns);
        $count = 0;
        $this->results->rows(static function (array $rows) use ($sink, &$count): void {
            $count += \count($rows);
            $sink->rows($rows);
        });

        return new Streamed($count, null);
    }

    /**
     * Runs `$sql`, several statements, as one request, each in order until one fails, with `multi-statement` [CLIENT Controlling the session].
     *
     * @throws TurborelException SQLSTATE 08006 if `multi-statement` was not granted, or the connection is lost
     */
    public function many(string $sql): Ran
    {
        (new Control($this->wire))->needs(Capability::MULTI_STATEMENT);
        $this->send($sql, true);
        $outcomes = [];
        while (true) {
            try {
                $outcome = $this->outcome();
            } catch (TurborelException $failure) {
                if ($this->wire->lost !== null) {
                    throw $failure;
                }

                return new Ran($outcomes, $failure);
            }
            $outcomes[] = $outcome;
            if ($outcome->completed === null || !$outcome->completed->more()) {
                return new Ran($outcomes, null);
            }
        }
    }

    /**
     * Sends `$sql`, as several statements if `$multi`, with the attributes granted.
     *
     * @throws TurborelException SQLSTATE 08006 if the connection is lost
     */
    private function send(string $sql, bool $multi): void
    {
        $this->wire->send(new Query($sql, $this->autocommit, $this->wire->told->attributes(), $multi));
    }

    /**
     * One statement's outcome: a query's columns, rows and last Complete, or another's Complete.
     *
     * @throws TurborelException the statement's failure, or SQLSTATE 08006 if another reply comes
     */
    private function outcome(): Outcome
    {
        $reply = $this->wire->receive();
        if ($reply instanceof Complete) {
            return new Outcome([], [], Completed::of($reply->completion));
        }
        if (!$reply instanceof Description) {
            throw $this->results->unexpected($reply, 'the server sent no result');
        }
        [$rows, , $completion] = $this->results->rows();

        return new Outcome($reply->columns, $rows, $completion === null ? null : Completed::of($completion));
    }
}

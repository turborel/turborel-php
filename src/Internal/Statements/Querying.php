<?php

declare(strict_types=1);

namespace Turborel\Internal\Statements;

use Turborel\Internal\Codec\Reply\Complete;
use Turborel\Internal\Codec\Reply\Description;
use Turborel\Internal\Codec\Reply\Error;
use Turborel\Internal\Codec\Reply\Rows;
use Turborel\Internal\Codec\Request\Query;
use Turborel\Internal\Session\Wire;
use Turborel\Statements\Completed;
use Turborel\Statements\Outcome;
use Turborel\TurborelException;

/**
 * A direct SQL statement run, as turborel-client's `query` runs one
 * [PROTOCOL Query 'Q']: a query answered by a Description, its rows in
 * Rows replies and a Complete; another statement by a Complete; a failure
 * by an Error [CLIENT Running statements].
 *
 * @internal
 */
final readonly class Querying
{
    /** Queries over `$wire`, each statement its own transaction if `$autocommit`. */
    public function __construct(private Wire $wire, private bool $autocommit) {}

    /**
     * Runs `$sql`, its outcome.
     *
     * @throws TurborelException the statement's failure, or SQLSTATE 08006 if the connection is lost
     */
    public function query(string $sql): Outcome
    {
        $attributes = $this->wire->told->attributes;
        $this->wire->send(new Query($sql, $this->autocommit, $attributes));
        $reply = $this->wire->receive();
        if ($reply instanceof Complete) {
            return new Outcome([], [], Completed::of($reply->completion));
        }
        if ($reply instanceof Error) {
            throw $this->wire->failed(TurborelException::fromServer($reply->failure));
        }
        if (!$reply instanceof Description) {
            throw $this->wire->lose('the server sent no result');
        }
        $rows = [];
        while (true) {
            $next = $this->wire->receive();
            if ($next instanceof Rows) {
                array_push($rows, ...$next->rows);
            } elseif ($next instanceof Complete) {
                return new Outcome($reply->columns, $rows, Completed::of($next->completion));
            } elseif ($next instanceof Error) {
                throw $this->wire->failed(TurborelException::fromServer($next->failure));
            } else {
                throw $this->wire->lose('the server broke off its rows');
            }
        }
    }
}

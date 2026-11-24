<?php

declare(strict_types=1);

namespace Turborel\Internal\Statements;

use Turborel\Internal\Codec\Reply\Description;
use Turborel\Internal\Codec\Request\Close;
use Turborel\Internal\Codec\Request\Deallocate;
use Turborel\Internal\Codec\Request\Describe;
use Turborel\Internal\Codec\Request\Execute;
use Turborel\Internal\Codec\Request\Extras;
use Turborel\Internal\Codec\Request\Fetch;
use Turborel\Internal\Codec\Request\Prepare;
use Turborel\Internal\Codec\Wide;
use Turborel\Internal\Session\Control;
use Turborel\Internal\Session\Wire;
use Turborel\Session\Capability;
use Turborel\Statements\Batch;
use Turborel\Statements\Completed;
use Turborel\Statements\Outcome;
use Turborel\Statements\Statement;
use Turborel\TurborelException;
use Turborel\Type\Column;

/**
 * Prepared statements and their cursors, as turborel-client's
 * `statements.rs` runs them [CLIENT Prepared statements]: prepared once,
 * executed, or a query's cursor opened and read a batch at a time. A
 * cursor other than 0 needs `cursors`, a description again `describe`
 * [CLIENT Controlling the session].
 *
 * @internal
 */
final readonly class Preparing
{
    /** The results read over the wire. */
    private Results $results;

    /** What asks for capabilities. */
    private Control $control;

    /** Prepared statements over `$wire`, each its own transaction if `$autocommit`. */
    public function __construct(private Wire $wire, private bool $autocommit)
    {
        $this->results = new Results($wire);
        $this->control = new Control($wire);
    }

    /**
     * Prepares `$sql`, its dynamic parameters written `?`, under the connection's next number [PROTOCOL Prepare 'P'].
     *
     * @throws TurborelException the server's, as SQLSTATE 42000 of a statement not preparable, or SQLSTATE 08006
     */
    public function prepare(string $sql): Statement
    {
        $number = $this->wire->nextStatement;
        $this->wire->nextStatement = ($number + 1) & Wide::U32_MAX;
        $this->wire->send(new Prepare($number, $sql));

        return $this->results->prepared();
    }

    /**
     * Executes `$statement` with `$values`: a query's rows, all of them, or another statement's completion [PROTOCOL Execute 'E'].
     *
     * @param list<mixed> $values
     *
     * @throws TurborelException the server's, as SQLSTATE 07001 of values not one for each parameter, or SQLSTATE 08006
     */
    public function execute(Statement $statement, array $values): Outcome
    {
        $this->wire->send(new Execute($statement->number, $values, 0, $this->autocommit, new Extras($this->wire->told->attributes())));

        return $this->executed($statement);
    }

    /**
     * The reply to an Execute of `$statement` sent: a query's rows, all of them, a CALL's output row, or another's completion.
     *
     * @throws TurborelException the statement's failure, or SQLSTATE 08006 if another reply comes
     */
    public function executed(Statement $statement): Outcome
    {
        if ($statement->columns === []) {
            return new Outcome([], [], Completed::of($this->results->completion()));
        }
        [$rows, , $completion] = $this->results->rows();

        return new Outcome($statement->columns, $rows, $completion === null ? null : Completed::of($completion));
    }

    /**
     * Opens the cursor `$cursor` of `$statement`, a query, with `$values`: at most `$rows` rows, all if 0. Under
     * autocommit, granted `fetch-ends`, the transaction ends as the cursor does [CLIENT What the server tells].
     *
     * @param list<mixed> $values
     *
     * @throws TurborelException the server's, as SQLSTATE 24000 if the cursor is open, or SQLSTATE 08006
     */
    public function open(Statement $statement, array $values, int $rows, int $cursor): Batch
    {
        $this->numbered($cursor);
        $ends = $this->autocommit && $this->wire->told->has(Capability::FETCH_ENDS);
        $this->wire->send(new Execute($statement->number, $values, $rows, $ends, new Extras($this->wire->told->attributes(), $cursor)));

        return $this->batch();
    }

    /**
     * At most `$rows` more rows of the cursor `$cursor` of `$statement`, all if 0 [PROTOCOL Fetch 'F'].
     *
     * @throws TurborelException the server's, as SQLSTATE 24000 if the cursor is not open, or SQLSTATE 08006
     */
    public function fetch(Statement $statement, int $rows, int $cursor): Batch
    {
        $this->numbered($cursor);
        $this->wire->send(new Fetch($statement->number, $rows, $cursor));

        return $this->batch();
    }

    /**
     * Closes the cursor `$cursor` of `$statement` [PROTOCOL Close 'C'].
     *
     * @throws TurborelException the server's, as SQLSTATE 24000 if it is not open, or SQLSTATE 08006
     */
    public function close(Statement $statement, int $cursor): void
    {
        $this->numbered($cursor);
        $this->wire->send(new Close($statement->number, $cursor));
        $this->results->completion();
    }

    /**
     * `$statement` described again, as it is now, with `describe` [PROTOCOL Describe 'Y'].
     *
     * @throws TurborelException the server's, as SQLSTATE 26000 if it is no more, or SQLSTATE 08006
     */
    public function describe(Statement $statement): Statement
    {
        $this->control->needs(Capability::DESCRIBE);
        $this->wire->send(new Describe($statement->number));

        return $this->results->prepared();
    }

    /**
     * The columns of the open cursor `$cursor` of `$statement`, with `describe` [PROTOCOL Describe 'Y'].
     *
     * @return list<Column>
     *
     * @throws TurborelException the server's, as SQLSTATE 24000 if it is not open, or SQLSTATE 08006
     */
    public function describeCursor(Statement $statement, int $cursor): array
    {
        $this->control->needs(Capability::DESCRIBE);
        $this->wire->send(new Describe($statement->number, $cursor));
        // Its Description is its last reply, as no rows follow it.
        $reply = $this->wire->receive(true);
        if ($reply instanceof Description) {
            return $reply->columns;
        }

        throw $this->results->unexpected($reply, 'the server sent no Description');
    }

    /**
     * Destroys `$statement` [PROTOCOL Deallocate 'D'].
     *
     * @throws TurborelException the server's, as SQLSTATE 24000 if its cursor is open, or SQLSTATE 08006
     */
    public function deallocate(Statement $statement): void
    {
        $this->wire->send(new Deallocate($statement->number));
        $this->results->completion();
    }

    /**
     * That `$cursor` is 0, or `cursors` was granted; a cursor or a count of rows not a u32 is refused as the request is encoded.
     *
     * @throws TurborelException SQLSTATE 08006 if `cursors` was not granted
     */
    private function numbered(int $cursor): void
    {
        $this->control->needs(Capability::CURSORS, $cursor === 0);
    }

    /**
     * The batch of rows the server sends, and whether the cursor has more.
     *
     * @throws TurborelException the statement's failure, or SQLSTATE 08006 if the rows are broken off
     */
    private function batch(): Batch
    {
        [$rows, $more] = $this->results->rows();

        return new Batch($rows, $more);
    }
}

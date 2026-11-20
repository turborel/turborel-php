<?php

declare(strict_types=1);

namespace Turborel\Pipeline;

use Turborel\Internal\Pipeline\Queued;
use Turborel\Internal\Pipeline\Running;
use Turborel\Internal\Session\Wire;
use Turborel\Statements\Statement;
use Turborel\TurborelException;

/**
 * Requests queued on a connection and written at once, as
 * turborel-client's `Pipeline` [CLIENT Pipelines]: a batch costs one
 * round trip, not one a request. The server answers each request alone
 * and in turn, so a failure is its request's own, and the requests after
 * it run, unless OnError::Skip stops the batch at its first.
 */
final class Pipeline
{
    /** The most bytes of requests written before their replies are read, as turborel-client's PIPELINE_WINDOW: 64 KiB. */
    public const int WINDOW = 64 * 1024;

    /** @var list<Queued> the requests queued */
    private array $queued = [];

    /** What the batch does after a request fails. */
    private OnError $onError = OnError::Continue;

    /**
     * A pipeline of requests over `$wire`, each its own transaction if `$autocommit`.
     *
     * @internal
     */
    public function __construct(private readonly Wire $wire, private readonly bool $autocommit) {}

    /** Queues `$sql`, run as the connection's `query` runs it. */
    public function query(string $sql): self
    {
        $this->queued[] = Queued::query($sql);

        return $this;
    }

    /**
     * Queues an execution of `$statement` with `$values`, as the connection's `execute` runs it: all of a query's rows.
     *
     * @param list<mixed> $values
     */
    public function execute(Statement $statement, array $values = []): self
    {
        $this->queued[] = Queued::execute($statement, $values);

        return $this;
    }

    /**
     * Queues the closing of statement `$number`'s cursor, then its destruction: a pool's reset of what a borrower left.
     *
     * @internal
     */
    public function release(int $number): self
    {
        array_push($this->queued, Queued::close($number), Queued::deallocate($number));

        return $this;
    }

    /** What the batch does after a request fails. */
    public function onError(OnError $onError): self
    {
        $this->onError = $onError;

        return $this;
    }

    /** How many requests are queued. */
    public function count(): int
    {
        return \count($this->queued);
    }

    /** Whether none is queued. */
    public function isEmpty(): bool
    {
        return $this->queued === [];
    }

    /**
     * Writes the requests, a window of 64 KiB at a time, its replies read before the next, and gives one outcome for each, in
     * the order they were queued.
     *
     * @return list<Piped>
     *
     * @throws TurborelException SQLSTATE 08006 once the connection is lost; under OnError::Skip, the failure of undoing the requests after a failed one
     */
    public function run(): array
    {
        return (new Running($this->wire, $this->autocommit))->run($this->queued, $this->onError === OnError::Skip);
    }
}

<?php

declare(strict_types=1);

namespace Turborel\Internal\Statements;

use Turborel\Internal\Codec\Completion;
use Turborel\Internal\Codec\Reply\Complete;
use Turborel\Internal\Codec\Reply\Error;
use Turborel\Internal\Codec\Reply\Prepared;
use Turborel\Internal\Codec\Reply\Reply;
use Turborel\Internal\Codec\Reply\Rows;
use Turborel\Internal\Codec\Reply\Suspended;
use Turborel\Internal\Session\Wire;
use Turborel\Statements\Statement;
use Turborel\TurborelException;

/**
 * The replies a statement's request takes, as turborel-client's
 * `completion`, `prepared` and `rows` read them: an Error is the
 * statement's failure, the connection kept unless it ends it; any reply
 * out of turn loses the connection [PROTOCOL Conversations].
 *
 * @internal
 */
final readonly class Results
{
    /** The results read over `$wire`. */
    public function __construct(private Wire $wire) {}

    /**
     * The Complete of a request sent.
     *
     * @throws TurborelException the statement's failure, or SQLSTATE 08006 if another reply comes
     */
    public function completion(): Completion
    {
        $reply = $this->wire->receive();
        if ($reply instanceof Complete) {
            return $reply->completion;
        }

        throw $this->unexpected($reply, 'the server sent no completion');
    }

    /**
     * The statement the server's Prepared describes.
     *
     * @throws TurborelException the statement's failure, or SQLSTATE 08006 if another reply comes
     */
    public function prepared(): Statement
    {
        $reply = $this->wire->receive();
        if ($reply instanceof Prepared) {
            return new Statement($reply->statement, $reply->parameters, $reply->columns, $reply->called);
        }

        throw $this->unexpected($reply, 'the server sent no Prepared');
    }

    /**
     * The rows the server sends until it says whether the cursor has more,
     * each batch given to `$batch` if one is, else kept: the rows kept, and
     * whether it has more [PROTOCOL Execute 'E'] [PROTOCOL Fetch 'F']; a
     * Query's rows, not `$suspendable`, end with Complete alone.
     *
     * @param (\Closure(list<list<mixed>>): void)|null $batch
     *
     * @return array{list<list<mixed>>, bool, ?Completion}
     *
     * @throws TurborelException the statement's failure, or SQLSTATE 08006 if the rows are broken off
     */
    public function rows(?\Closure $batch = null, bool $suspendable = true): array
    {
        $rows = [];
        while (true) {
            $reply = $this->wire->receive();
            if ($reply instanceof Rows) {
                if ($batch !== null) {
                    $batch($reply->rows);
                } else {
                    array_push($rows, ...$reply->rows);
                }
            } elseif ($reply instanceof Complete) {
                return [$rows, false, $reply->completion];
            } elseif ($reply instanceof Suspended && $suspendable) {
                return [$rows, true, null];
            } else {
                throw $this->unexpected($reply, 'the server broke off its rows');
            }
        }
    }

    /** The failure of `$reply`, an Error, kept as the connection's if it ends it; else the connection lost, saying `$why`. */
    public function unexpected(Reply $reply, string $why): TurborelException
    {
        if ($reply instanceof Error) {
            return $this->wire->failed(TurborelException::fromServer($reply->failure));
        }

        return $this->wire->lose($why);
    }
}

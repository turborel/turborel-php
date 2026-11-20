<?php

declare(strict_types=1);

namespace Turborel\Session;

use Turborel\Internal\Codec\Reply\Challenge;
use Turborel\Internal\Codec\Reply\Error;
use Turborel\Internal\Codec\Reply\Ready;
use Turborel\Internal\Codec\Reply\Reply;
use Turborel\Internal\Codec\Request\Answer;
use Turborel\Internal\Codec\Request\Hello;
use Turborel\Internal\Codec\Request\Request;
use Turborel\Internal\ConnectionFailure;
use Turborel\Internal\Session\Control;
use Turborel\Internal\Session\Ending;
use Turborel\Internal\Session\Wire;
use Turborel\Internal\Statements\Preparing;
use Turborel\Internal\Statements\Querying;
use Turborel\Internal\Transport\Opener;
use Turborel\Internal\Transport\Stream;
use Turborel\Statements\Batch;
use Turborel\Statements\Completed;
use Turborel\Statements\Outcome;
use Turborel\Statements\Ran;
use Turborel\Statements\Sink;
use Turborel\Statements\Statement;
use Turborel\Statements\Streamed;
use Turborel\TurborelException;
use Turborel\Type\Column;

/**
 * A connection to a server, and its session, as turborel-client's
 * `Connection` [CLIENT Connecting]: made to a target over TCP, a Unix
 * socket or a named pipe, or TLS, its user proved by a mechanism, the
 * capabilities its offer asks for granted as the server has them; then
 * one request at a time, each wait bounded as its timeouts say
 * [CLIENT Timeouts]. A connection lost takes no more requests.
 */
final class Connection
{
    /** The connection's frames. */
    private Wire $wire;

    /** The session's authorization identifier, as Ready told it. */
    private string $authorization = '';

    /** The server's product, as Ready told it. */
    private string $server = '';

    /** The key that cancels the session's statements, as Ready gave it. */
    private string $key = '';

    /** Whether each statement is its own transaction. */
    private bool $autocommit = false;

    /** The connection of `$wire` to `$target`, if it was made to one, waiting as `$timeouts` say, if it bounds its stream. */
    private function __construct(Wire $wire, private readonly ?Target $target, private ?Timeouts $timeouts)
    {
        $this->wire = $wire;
    }

    /**
     * Connects to `$target` as `$user`, proved by `$mechanism`, asking what
     * `$offer` asks for, waiting as `$timeouts` say. A mechanism that sends
     * a secret is refused over plain TCP before anything is sent
     * (SQLSTATE 28000) [CLIENT Connecting].
     *
     * @throws TurborelException SQLSTATE 28000 of a secret over plain TCP or credentials refused, 08006 if the server cannot be reached or the login fails
     */
    public static function connect(Target $target, string $user, Mechanism $mechanism, ?Timeouts $timeouts = null, ?Offer $offer = null): self
    {
        if ($target->isPlainTcp() && $mechanism->secret()) {
            throw ConnectionFailure::refused("{$mechanism->name()} is sent only over TLS, a Unix socket or a named pipe, never over plain TCP");
        }
        $timeouts ??= new Timeouts();
        [$stream, $channel] = Opener::open($target, $timeouts->connect);
        if ($channel !== null) {
            $mechanism->bind($channel);
        }
        $connection = new self(new Wire($stream), $target, $timeouts);
        $connection->opened($user, $mechanism, $offer ?? new Offer());
        $stream->bound($timeouts->read);

        return $connection;
    }

    /**
     * Logs in over `$stream`, a stream the program opened, connected to a
     * server, as `$user`, proved by `$mechanism`, asking what `$offer` asks
     * [CLIENT Connecting]. Its maker answers for its privacy and bounds its
     * waits; of a TLS stream, bind SCRAM first by `$mechanism->bind(TlsChannel::of($stream))`.
     * It cannot be reached again, so has no canceller.
     *
     * @param resource $stream
     *
     * @throws TurborelException credentials refused, or SQLSTATE 08006 if the login fails
     */
    public static function open(mixed $stream, string $user, Mechanism $mechanism, ?Offer $offer = null): self
    {
        $connection = new self(new Wire(new Stream($stream)), null, null);
        $connection->opened($user, $mechanism, $offer ?? new Offer());

        return $connection;
    }

    /** The session's authorization identifier, as Ready told it: the user it logged in as. */
    public function authorization(): string
    {
        return $this->authorization;
    }

    /** The server's product, as Ready told it: `turborel 0.0.0 (alpha)`. */
    public function server(): string
    {
        return $this->server;
    }

    /** Where it was made to; none of a stream the program opened. */
    public function target(): ?Target
    {
        return $this->target;
    }

    /**
     * What the server granted of what the connection asked for, in the spec's order [CLIENT What a connection asks for].
     *
     * @return list<string>
     */
    public function granted(): array
    {
        return $this->wire->told->granted;
    }

    /** Whether the server granted the capability `$capability`. */
    public function has(string $capability): bool
    {
        return $this->wire->told->has($capability);
    }

    /** The value of the session's setting `$name` as the server last told it, with no round trip; none if it told none of the name [CLIENT What the server tells]. */
    public function setting(string $name): ?string
    {
        return $this->wire->told->settings[$name] ?? null;
    }

    /**
     * Every setting the server has told, by name.
     *
     * @return array<string, string>
     */
    public function settings(): array
    {
        return $this->wire->told->settings;
    }

    /** Whether the session's transaction is active, as the server said after the last statement. */
    public function inTransaction(): bool
    {
        return $this->wire->active;
    }

    /** Whether each statement is its own transaction, committed as it succeeds within its request. */
    public function autocommit(): bool
    {
        return $this->autocommit;
    }

    /** Makes each statement its own transaction, if `$on`, or not [CLIENT Running statements]. */
    public function setAutocommit(bool $on): void
    {
        $this->autocommit = $on;
    }

    /** The read timeout, in seconds; none for no limit, or of a stream the program opened and bounds. */
    public function readTimeout(): ?float
    {
        return $this->timeouts?->read;
    }

    /**
     * Bounds each read and write from now on to `$within` seconds, none for no limit [CLIENT Timeouts].
     *
     * @throws TurborelException SQLSTATE 08006 if the connection cannot be bounded, 22003 if the limit is not positive
     */
    public function setReadTimeout(?float $within): void
    {
        if ($this->timeouts === null) {
            throw ConnectionFailure::because('a stream given to open is bounded by its maker');
        }
        $timeouts = new Timeouts($this->timeouts->connect, $within);
        $this->wire->stream->bound($within);
        $this->timeouts = $timeouts;
    }

    /** Takes replies of at most `$bytes` bytes of body from now on; a longer one loses the connection [CLIENT Timeouts]. */
    public function setMaxBody(int $bytes): void
    {
        $this->wire->maxBody = $bytes;
    }

    /** Whether the connection is lost: it takes no more requests. */
    public function isLost(): bool
    {
        return $this->wire->lost !== null;
    }

    /** What cancels the statement this connection runs, from elsewhere, over a connection of its own; none of a stream the program opened [CLIENT Running statements]. */
    public function canceller(): ?Canceller
    {
        return $this->target === null ? null : new Canceller($this->target, $this->key);
    }

    /**
     * Runs the statement `$sql`, giving a query's columns and rows, or another statement's completion [CLIENT Running statements].
     *
     * @throws TurborelException the statement's failure, or SQLSTATE 08006 if the connection is lost
     */
    public function query(string $sql): Outcome
    {
        return (new Querying($this->wire, $this->autocommit))->query($sql);
    }

    /**
     * Runs `$sql` as `query` does, a query's columns and rows given to `$sink` as they come, so no more than a batch is held.
     *
     * @throws TurborelException the statement's failure, after some rows went to `$sink` too, or SQLSTATE 08006
     */
    public function queryInto(string $sql, Sink $sink): Streamed
    {
        return (new Querying($this->wire, $this->autocommit))->into($sql, $sink);
    }

    /**
     * Runs `$sql`, several statements, as one request: each in order, each its own transaction under autocommit, until one fails [CLIENT Controlling the session].
     *
     * @throws TurborelException SQLSTATE 08006 if `multi-statement` was not granted, or the connection is lost
     */
    public function queryMany(string $sql): Ran
    {
        return (new Querying($this->wire, $this->autocommit))->many($sql);
    }

    /**
     * Prepares `$sql` once, its dynamic parameters written `?` [CLIENT Prepared statements].
     *
     * @throws TurborelException the server's, as SQLSTATE 37000 of a statement not preparable, or SQLSTATE 08006
     */
    public function prepare(string $sql): Statement
    {
        return $this->preparing()->prepare($sql);
    }

    /**
     * Executes `$statement` with `$values`, one for each `?`: a query's rows, all of them, or another statement's completion.
     *
     * @param list<mixed> $values
     *
     * @throws TurborelException the statement's failure, as SQLSTATE 07001 of values not one for each `?`, or SQLSTATE 08006
     */
    public function execute(Statement $statement, array $values = []): Outcome
    {
        return $this->preparing()->execute($statement, $values);
    }

    /**
     * Opens the cursor `$cursor` of `$statement`, a query, with `$values`, and gives its first `$rows` rows, all if 0.
     * Under autocommit, granted `fetch-ends`, the transaction ends as the cursor does; a cursor other than 0 needs `cursors`.
     *
     * @param list<mixed> $values
     *
     * @throws TurborelException the server's, as SQLSTATE 24000 if the cursor is open, or SQLSTATE 08006
     */
    public function openCursor(Statement $statement, array $values = [], int $rows = 0, int $cursor = 0): Batch
    {
        return $this->preparing()->open($statement, $values, $rows, $cursor);
    }

    /**
     * The next `$rows` rows of the cursor `$cursor` of `$statement`, all if 0.
     *
     * @throws TurborelException the server's, as SQLSTATE 24000 if the cursor is not open, or SQLSTATE 08006
     */
    public function fetch(Statement $statement, int $rows = 0, int $cursor = 0): Batch
    {
        return $this->preparing()->fetch($statement, $rows, $cursor);
    }

    /**
     * Closes the cursor `$cursor` of `$statement`.
     *
     * @throws TurborelException the server's, as SQLSTATE 24000 if it is not open, or SQLSTATE 08006
     */
    public function close(Statement $statement, int $cursor = 0): void
    {
        $this->preparing()->close($statement, $cursor);
    }

    /**
     * `$statement` described again by the server, as it is now, with `describe` [CLIENT Controlling the session].
     *
     * @throws TurborelException the server's, as SQLSTATE 26000 if it is no more, or SQLSTATE 08006
     */
    public function describe(Statement $statement): Statement
    {
        return $this->preparing()->describe($statement);
    }

    /**
     * The columns of the open cursor `$cursor` of `$statement`, with `describe`.
     *
     * @return list<Column>
     *
     * @throws TurborelException the server's, as SQLSTATE 24000 if it is not open, or SQLSTATE 08006
     */
    public function describeCursor(Statement $statement, int $cursor = 0): array
    {
        return $this->preparing()->describeCursor($statement, $cursor);
    }

    /**
     * Destroys `$statement`, of no use after.
     *
     * @throws TurborelException the server's, as SQLSTATE 24000 if its cursor is open, or SQLSTATE 08006
     */
    public function deallocate(Statement $statement): void
    {
        $this->preparing()->deallocate($statement);
    }

    /**
     * Hands each notice the server sends from now on to `$handler`, as it comes; none, they are kept [CLIENT What the server tells].
     *
     * @param (\Closure(Notice): void)|null $handler
     */
    public function setNoticeHandler(?\Closure $handler): void
    {
        $this->wire->told->handler = $handler;
    }

    /**
     * The notices kept, the last 64 that came with no handler set, taken.
     *
     * @return list<Notice>
     */
    public function takeNotices(): array
    {
        [$notices, $this->wire->told->notices] = [$this->wire->told->notices, []];

        return $notices;
    }

    /** The last statement's completion: its rows, generated values, server time and conditions, each as granted. */
    public function completed(): ?Completed
    {
        return $this->wire->told->completed === null ? null : Completed::of($this->wire->told->completed);
    }

    /**
     * The conditions the last statement raised, with `conditions`, taken, so each is told once.
     *
     * @return list<TurborelException>
     */
    public function takeConditions(): array
    {
        $conditions = $this->completed()?->conditions() ?? [];
        $this->wire->told->completed = $this->wire->told->completed?->withoutConditions();

        return $conditions;
    }

    /** The log position of the last commit the server told of, with `commit-position`; an int, or a string past PHP_INT_MAX. */
    public function commitPosition(): int|string|null
    {
        return $this->wire->told->position;
    }

    /** The SQLSTATE of the warning the last statement completed with, of class 01, if it did. */
    public function warning(): ?string
    {
        return $this->wire->warning;
    }

    /**
     * Sets attributes every later Query and Execute carries, by name, read in SQL by QUERY_ATTRIBUTE(name), until set again.
     *
     * @param array<array-key, string> $attributes each value by its name, a name of digits alone being an int key in PHP
     *
     * @throws TurborelException SQLSTATE 08006 if `query-attributes` was not granted, for attributes not none
     */
    public function setQueryAttributes(array $attributes): void
    {
        (new Control($this->wire))->needs(Capability::QUERY_ATTRIBUTES, $attributes === []);
        $pairs = [];
        foreach ($attributes as $name => $value) {
            $pairs[] = [(string) $name, $value];
        }
        $this->wire->told->attributes = $pairs;
    }

    /**
     * Asks whether the server and the connection are alive, with no statement run, the server answering at once [CLIENT Controlling the session].
     *
     * @throws TurborelException SQLSTATE 08006 if `ping` was not granted, or the connection is lost
     */
    public function ping(): void
    {
        (new Control($this->wire))->ping();
    }

    /**
     * Puts the session back as it was at login: its transaction rolled
     * back, its prepared statements, cursors, temporary tables and settings
     * gone, its user and cancel key kept [CLIENT Controlling the session].
     *
     * @throws TurborelException SQLSTATE 08006 if `reset` was not granted, or the connection is lost
     */
    public function reset(): void
    {
        (new Control($this->wire))->reset();
    }

    /**
     * Authenticates the connection again as `$user`, proved by
     * `$mechanism`, whose new session it then is, under a new cancel key
     * [CLIENT Controlling the session]. Refused, the connection fails.
     *
     * @throws TurborelException the refusal, or SQLSTATE 08006 if `change-user` was not granted
     */
    public function changeUser(string $user, Mechanism $mechanism): void
    {
        $ready = (new Control($this->wire))->changeUser($user, $mechanism);
        [$this->authorization, $this->server, $this->key] = [$ready->authorization, $ready->server, $ready->key];
    }

    /**
     * Ends the session, its transaction as SESSION.COMMIT_AT_END says, and
     * waits for the server to close, at most the read timeout, else 60
     * seconds; a commit that fails then gives its failure [CLIENT Connecting].
     *
     * @throws TurborelException the failing commit's failure, or SQLSTATE 08006 if the server answers otherwise
     */
    public function terminate(): void
    {
        Ending::terminate($this->wire, $this->timeouts === null ? Timeouts::TERMINATE_WITHIN : ($this->timeouts->read ?? Timeouts::TERMINATE_WITHIN));
    }

    /** The prepared statements of the session. */
    private function preparing(): Preparing
    {
        return new Preparing($this->wire, $this->autocommit);
    }

    /**
     * Sends `$request` as it is.
     *
     * @throws TurborelException SQLSTATE 08006 if the connection is lost
     *
     * @internal
     */
    public function send(Request $request): void
    {
        $this->wire->send($request);
    }

    /**
     * The next reply but a Notice or a Status.
     *
     * @throws TurborelException SQLSTATE 08006 if the connection is lost
     *
     * @internal
     */
    public function receive(): Reply
    {
        return $this->wire->receive();
    }

    /**
     * The connection's frames, for the layers over it.
     *
     * @internal
     */
    public function wire(): Wire
    {
        return $this->wire;
    }

    /**
     * Logs in, closing the stream if the login fails.
     *
     * @throws TurborelException the server's refusal, or SQLSTATE 08006 if it sends no Ready
     */
    private function opened(string $user, Mechanism $mechanism, Offer $offer): void
    {
        try {
            $this->logIn($user, $mechanism, $offer);
        } catch (TurborelException $refused) {
            $this->wire->stream->close();

            throw $refused;
        }
    }

    /**
     * Logs in as `$user`, by `$mechanism`, asking for what `$offer` asks:
     * Hello, then each Challenge answered, until Ready or an Error
     * [PROTOCOL Opening a connection].
     *
     * @throws TurborelException the server's refusal, or SQLSTATE 08006 if it sends no Ready
     */
    private function logIn(string $user, Mechanism $mechanism, Offer $offer): void
    {
        $initial = $mechanism->initial();
        $this->wire->send(new Hello($user, $mechanism->name(), $initial, $offer->capabilities, $offer->attributes));
        while (true) {
            $reply = $this->wire->receive();
            if ($reply instanceof Challenge) {
                $this->wire->send(new Answer($mechanism->answer($reply->challenge)));
            } elseif ($reply instanceof Ready) {
                $mechanism->ready();
                $this->ready($reply, $offer->compressFrom);

                return;
            } elseif ($reply instanceof Error) {
                $mechanism->refused();

                throw TurborelException::fromServer($reply->failure);
            } else {
                throw $this->wire->lose('the server sent no Ready');
            }
        }
    }

    /** Takes what Ready told: the authorization, the server, the key, the capabilities granted; compression begins after it. */
    private function ready(Ready $ready, int $compressFrom): void
    {
        [$this->authorization, $this->server, $this->key] = [$ready->authorization, $ready->server, $ready->key];
        $this->wire->told->granted = $ready->capabilities;
        $this->wire->compressFrom = $this->has(Capability::COMPRESSION) ? $compressFrom : null;
    }

    /**
     * Its state as var_dump() shows it: where it was made to, as whom, whether lost; never its cancel key (RULES.md R-SAFE-4).
     *
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return ['target' => (string) $this->target, 'authorization' => $this->authorization, 'lost' => $this->isLost()];
    }
}

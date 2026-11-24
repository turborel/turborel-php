<?php

declare(strict_types=1);

namespace Turborel\Internal\Session;

use Turborel\Debug\Event;
use Turborel\Internal\Codec\Completion;
use Turborel\Internal\Codec\FrameHeader;
use Turborel\Internal\Codec\Frames;
use Turborel\Internal\Codec\Replies;
use Turborel\Internal\Codec\Reply\Complete;
use Turborel\Internal\Codec\Reply\Description;
use Turborel\Internal\Codec\Reply\Error;
use Turborel\Internal\Codec\Reply\EventSent;
use Turborel\Internal\Codec\Reply\LobCreated;
use Turborel\Internal\Codec\Reply\LobData;
use Turborel\Internal\Codec\Reply\Notice;
use Turborel\Internal\Codec\Reply\Ready;
use Turborel\Internal\Codec\Reply\Reply;
use Turborel\Internal\Codec\Reply\Rows;
use Turborel\Internal\Codec\Reply\Status;
use Turborel\Internal\Codec\Reply\Suspended;
use Turborel\Internal\Codec\Request\CopyData;
use Turborel\Internal\Codec\Request\CopyEnd;
use Turborel\Internal\Codec\Request\Ping;
use Turborel\Internal\Codec\Request\Request;
use Turborel\Internal\ConnectionFailure;
use Turborel\Internal\Misuse;
use Turborel\Internal\Transport\Stream;
use Turborel\Session\Notice as PublicNotice;
use Turborel\TurborelException;

/**
 * A connection's frames, as turborel-client's `frames.rs` sends and reads
 * them: each request encoded and written, compressed once compression is
 * granted; each reply read within the longest body taken, inflated if it
 * came compressed, and decoded; a Notice or a Status before a statement's
 * reply taken on the way. A failure of the connection is kept, and given
 * again to every request after it [CLIENT Running statements].
 *
 * @internal
 */
final class Wire
{
    /** The longest body taken, unless the program says otherwise [CLIENT Timeouts]. */
    public int $maxBody = FrameHeader::MAX_BODY;

    /** The threshold past which a frame is sent compressed, once compression is granted; none until then. */
    public ?int $compressFrom = null;

    /** The failure that lost the connection, if one did. */
    public ?TurborelException $lost = null;

    /** Whether the session's transaction is active, as the server last said. */
    public bool $active = false;

    /** The SQLSTATE of the warning the last statement completed with, if it did. */
    public ?string $warning = null;

    /** What the server has told beside the replies. */
    public Told $told;

    /** Whether a copy is under way, when only its rows, its end and Ping are sent. */
    public bool $copying = false;

    /** The number the next statement prepared takes, from 0, wrapping past a u32. */
    public int $nextStatement = 0;

    /** The wire over `$stream`. */
    public function __construct(
        /** The connection's stream. */
        public readonly Stream $stream,
    ) {
        $this->told = new Told();
    }

    /**
     * Sends `$request`, compressed where granted; while a copy is under way, only its rows, its end and Ping.
     *
     * @throws TurborelException SQLSTATE 08006 if the connection is lost, or is lost as it is sent; TB910 if a copy is under way
     */
    public function send(Request $request): void
    {
        if ($this->copying && !$request instanceof CopyData && !$request instanceof CopyEnd && !$request instanceof Ping) {
            throw Misuse::of('a copy is under way: only its rows, its end and Ping are sent until it ends');
        }
        $this->sendBytes($request->encode());
    }

    /**
     * Sends `$frames`, whole frames already encoded, compressed where granted.
     *
     * @throws TurborelException SQLSTATE 08006 if the connection is lost, or is lost as they are sent
     */
    public function sendBytes(string $frames): void
    {
        if ($this->lost !== null) {
            throw $this->lost;
        }
        try {
            $this->stream->write($this->compressFrom === null ? $frames : Frames::compress($frames, $this->compressFrom));
        } catch (TurborelException $lost) {
            throw $this->lostBy($lost);
        }
    }

    /**
     * The next reply but a Notice or a Status, which are taken on the way. A reply holding a value the driver cannot take
     * fails with that value's error once the rest of its request's replies are read past, unless the request is answered by
     * one reply alone (`$one`), and the connection goes on [PROTOCOL Values and fields].
     *
     * @throws TurborelException SQLSTATE 08006 if the connection is lost, or a frame is malformed; TB916 or 0A000 of a value
     *                           the driver cannot take
     */
    public function receive(bool $one = false): Reply
    {
        while (true) {
            $reply = $this->took($this->next($one));
            if ($reply !== null) {
                return $reply;
            }
        }
    }

    /** Keeps `$failure`, as the connection's if it ends the connection, and gives it back. */
    public function failed(TurborelException $failure): TurborelException
    {
        if ($failure->endsConnection()) {
            $this->lost = $failure;
        }

        return $failure;
    }

    /** Loses the connection, saying `$why`, and gives the failure. */
    public function lose(string $why): TurborelException
    {
        return $this->lostBy(ConnectionFailure::because($why));
    }

    /** Loses the connection by `$failure`, and gives it back. */
    public function lostBy(TurborelException $failure): TurborelException
    {
        $this->lost = $failure;
        $this->stream->close();

        return $failure;
    }

    /**
     * The next frame decoded. One read whole that does not decode is malformed, which loses the connection, or holds a value
     * the driver cannot take, which fails its request alone, read past with the rest of its replies unless `$one`, as
     * turborel-client's `next_reply` does [PROTOCOL Values and fields].
     *
     * @throws TurborelException SQLSTATE 08006 if the connection is lost, or the frame is malformed; the value's failure
     */
    private function next(bool $one): Reply
    {
        [$kind, $body] = $this->frame();
        try {
            return Replies::decode($kind, $body);
        } catch (TurborelException $failure) {
            $this->skipped($kind, $body, $failure);
            if (!$one) {
                $this->drained($kind, $body);
            }

            throw $failure;
        }
    }

    /**
     * The next frame's kind and body, inflated if it came compressed.
     *
     * @return array{int, string}
     *
     * @throws TurborelException SQLSTATE 08006 if the connection is lost, or no frame whole comes, or its header or compression is malformed
     */
    private function frame(): array
    {
        if ($this->lost !== null) {
            throw $this->lost;
        }
        try {
            $header = FrameHeader::decode($this->stream->read(FrameHeader::SIZE), $this->maxBody);

            return Frames::unwrap($header->kind, $this->stream->read($header->length), $this->compressFrom !== null, $this->maxBody);
        } catch (TurborelException $failure) {
            throw $this->lostBy($failure);
        }
    }

    /** `$reply`, unless it is a Notice or a Status, which is taken; a statement's reply, its end noted. */
    private function took(Reply $reply): ?Reply
    {
        if ($reply instanceof Notice) {
            $this->told->notice(new PublicNotice($reply->sqlstate, $reply->message, $reply->diagnostics === null ? [] : $reply->diagnostics->fields));
        } elseif ($reply instanceof Status) {
            $this->told->settings[$reply->name] = $reply->value;
        } elseif ($reply instanceof EventSent) {
            $this->told->events[] = $reply->event;
        } else {
            return $this->noted($reply);
        }

        return null;
    }

    /**
     * The next frame, of an event sent unasked between requests, as turborel-client's `debug_wait` reads it, if one begins
     * to arrive within `$within` seconds, without limit if none: the event; none of a Notice or a Status, taken, or of nothing.
     *
     * @throws TurborelException the server's Error; SQLSTATE 08006 of a reply to no request, or if the connection is lost
     */
    public function unasked(?float $within): ?Event
    {
        if ($within !== null && !$this->stream->waiting($within)) {
            return null;
        }
        $reply = $this->next(true);
        if ($reply instanceof EventSent) {
            return $reply->event;
        }
        if ($reply instanceof Error) {
            throw $this->failed(TurborelException::fromServer($reply->failure));
        }
        if (!$reply instanceof Notice && !$reply instanceof Status) {
            throw $this->lose('the server answered no request');
        }
        $this->took($reply);

        return null;
    }

    /**
     * Takes a frame of `$kind` and `$body` that did not decode, for `$failure`: the connection lost if it ends it, as a
     * malformed frame's does; else, of a Complete, what its head says noted.
     *
     * @throws TurborelException `$failure`, if it ends the connection
     */
    private function skipped(int $kind, string $body, TurborelException $failure): void
    {
        if ($failure->endsConnection()) {
            throw $this->lostBy($failure);
        }
        $head = Completion::head($kind, $body);
        if ($head !== null) {
            $this->noted(new Complete($head));
        }
    }

    /**
     * Reads past the rest of a request's replies once one, of `$kind` and `$body`, held a value the driver cannot take: up to
     * the one that ends them, each taken as `receive` takes it, or skipped as that one was.
     *
     * @throws TurborelException SQLSTATE 08006 if the connection is lost before the last
     */
    private function drained(int $kind, string $body): void
    {
        while (!Replies::endsRequest($kind, $body)) {
            [$kind, $body] = $this->frame();
            try {
                $this->took(Replies::decode($kind, $body));
            } catch (TurborelException $failure) {
                $this->skipped($kind, $body, $failure);
            }
        }
    }

    /** `$reply`, what it says of the session noted: whether a transaction is active, the last completion and its warning. */
    private function noted(Reply $reply): Reply
    {
        if ($reply instanceof Complete) {
            $this->active = $reply->completion->active;
            $this->warning = str_starts_with($reply->completion->sqlstate, '01') ? $reply->completion->sqlstate : null;
            $this->told->position = $reply->completion->counts->position ?? $this->told->position;
            $this->told->completed = $reply->completion;
        } elseif ($reply instanceof Error) {
            [$this->active, $this->warning] = [$reply->failure->state->active, null];
        } elseif ($reply instanceof Description) {
            [$this->active, $this->warning] = [true, null];
        } elseif ($reply instanceof Rows || $reply instanceof Suspended
            || $reply instanceof LobData || $reply instanceof LobCreated) {
            // A locator is valid within a transaction alone, and a result's rows are read within one.
            $this->active = true;
        } elseif ($reply instanceof Ready) {
            // Ready ends a login or a ChangeUser, which renews the session as Reset does, its transaction rolled back.
            [$this->active, $this->warning] = [false, null];
        }

        return $reply;
    }
}

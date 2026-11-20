<?php

declare(strict_types=1);

namespace Turborel\Internal\Session;

use Turborel\Internal\Codec\FrameHeader;
use Turborel\Internal\Codec\Frames;
use Turborel\Internal\Codec\Replies;
use Turborel\Internal\Codec\Reply\Complete;
use Turborel\Internal\Codec\Reply\Description;
use Turborel\Internal\Codec\Reply\Error;
use Turborel\Internal\Codec\Reply\LobCreated;
use Turborel\Internal\Codec\Reply\LobData;
use Turborel\Internal\Codec\Reply\Notice;
use Turborel\Internal\Codec\Reply\Reply;
use Turborel\Internal\Codec\Reply\Rows;
use Turborel\Internal\Codec\Reply\Status;
use Turborel\Internal\Codec\Reply\Suspended;
use Turborel\Internal\Codec\Request\Request;
use Turborel\Internal\ConnectionFailure;
use Turborel\Internal\Transport\Stream;
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

    /** What the server has told beside the replies. */
    public Told $told;

    /** The wire over `$stream`. */
    public function __construct(
        /** The connection's stream. */
        public readonly Stream $stream,
    ) {
        $this->told = new Told();
    }

    /**
     * Sends `$request`, compressed where granted.
     *
     * @throws TurborelException SQLSTATE 08006 if the connection is lost, or is lost as it is sent
     */
    public function send(Request $request): void
    {
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
     * The next reply but a Notice or a Status, which are taken on the way.
     *
     * @throws TurborelException SQLSTATE 08006 if the connection is lost, or the reply cannot be read
     */
    public function receive(): Reply
    {
        while (true) {
            $reply = $this->next();
            if ($reply instanceof Notice) {
                $this->told->notice($reply);
            } elseif ($reply instanceof Status) {
                $this->told->settings[$reply->name] = $reply->value;
            } else {
                return $this->noted($reply);
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
     * The next frame read and decoded, inflated if it came compressed.
     *
     * @throws TurborelException SQLSTATE 08006 if the connection is lost, or the frame cannot be read
     */
    private function next(): Reply
    {
        if ($this->lost !== null) {
            throw $this->lost;
        }
        try {
            $header = FrameHeader::decode($this->stream->read(FrameHeader::SIZE), $this->maxBody);
            $body = $this->stream->read($header->length);
            [$kind, $body] = Frames::unwrap($header->kind, $body, $this->compressFrom !== null, $this->maxBody);

            return Replies::decode($kind, $body);
        } catch (TurborelException $failure) {
            throw $this->lostBy($failure);
        }
    }

    /** `$reply`, what it says of the session noted: whether a transaction is active, the last completion. */
    private function noted(Reply $reply): Reply
    {
        if ($reply instanceof Complete) {
            $this->active = $reply->completion->active;
            $this->told->position = $reply->completion->counts->position ?? $this->told->position;
            $this->told->completed = $reply->completion;
        } elseif ($reply instanceof Error) {
            $this->active = $reply->failure->state->active;
        } elseif ($reply instanceof Description || $reply instanceof Rows || $reply instanceof Suspended
            || $reply instanceof LobData || $reply instanceof LobCreated) {
            // A locator is valid within a transaction alone, and a result's rows are read within one.
            $this->active = true;
        }

        return $reply;
    }
}

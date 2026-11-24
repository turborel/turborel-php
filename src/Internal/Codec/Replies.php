<?php

declare(strict_types=1);

namespace Turborel\Internal\Codec;

use Turborel\Debug\Breakpoint;
use Turborel\Internal\Codec\Reply\Challenge;
use Turborel\Internal\Codec\Reply\Complete;
use Turborel\Internal\Codec\Reply\Debugged;
use Turborel\Internal\Codec\Reply\Description;
use Turborel\Internal\Codec\Reply\Error;
use Turborel\Internal\Codec\Reply\EventSent;
use Turborel\Internal\Codec\Reply\LobCreated;
use Turborel\Internal\Codec\Reply\LobData;
use Turborel\Internal\Codec\Reply\Notice;
use Turborel\Internal\Codec\Reply\Pong;
use Turborel\Internal\Codec\Reply\Prepared;
use Turborel\Internal\Codec\Reply\Ready;
use Turborel\Internal\Codec\Reply\Reply;
use Turborel\Internal\Codec\Reply\Rows;
use Turborel\Internal\Codec\Reply\Status;
use Turborel\Internal\Codec\Reply\Suspended;
use Turborel\TurborelException;

/**
 * The replies a server sends, each read from its kind and body as the spec
 * lays it out [PROTOCOL Replies]. A kind no reply has, a body cut short,
 * or bytes left after its last field fail the connection [PROTOCOL Frames].
 *
 * @internal
 */
final class Replies
{
    /** A Lob reply's data [PROTOCOL Lob 'L']. */
    private const int LOB_DATA = 0;

    /** A Lob reply's locator. */
    private const int LOB_LOCATOR = 1;

    /**
     * The reply of kind `$kind` whose body is `$body`.
     *
     * @throws TurborelException SQLSTATE 08006 if no reply is of that kind, or the body is malformed
     */
    public static function decode(int $kind, string $body): Reply
    {
        $reader = new Reader($body, 'reply');
        $reply = match ($kind) {
            Kind::CHALLENGE => new Challenge($reader->bytes()),
            Kind::READY => new Ready($reader->string(), $reader->key(), $reader->strings(), $reader->string()),
            Kind::PREPARED => self::prepared($reader),
            Kind::DESCRIPTION => new Description(Fields::declared($reader, Fields::columns($reader))),
            Kind::ROWS => new Rows(Fields::rows($reader)),
            Kind::SUSPENDED => new Suspended(),
            Kind::COMPLETE => new Complete(Completion::read($reader)),
            Kind::ERROR => new Error(Failure::read($reader)),
            Kind::LOB => self::lob($reader),
            Kind::NOTICE => new Notice($reader->string(), $reader->string(), Diagnostics::read($reader)),
            Kind::STATUS => new Status($reader->string(), $reader->string()),
            Kind::PONG => new Pong(),
            Kind::DEBUGGED => self::debugged($reader),
            Kind::EVENT => new EventSent(DebugCodec::event($reader)),
            default => throw $reader->malformed("no reply is of kind {$kind}"),
        };
        $reader->finish();

        return $reply;
    }

    /**
     * A Debugged: what it gives, then a connection's ID, a breakpoint, the breakpoints, or a paused flag and the pause.
     *
     * @throws TurborelException SQLSTATE 08006 if it is of no kind, or malformed
     */
    private static function debugged(Reader $reader): Debugged
    {
        $what = $reader->u8();
        $value = match ($what) {
            Debugged::CONNECTION => $reader->u64(),
            Debugged::BREAKPOINT => DebugCodec::breakpoint($reader),
            Debugged::BREAKPOINTS => self::breakpoints($reader),
            Debugged::STACK => $reader->flag() ? DebugCodec::pause($reader) : null,
            default => throw $reader->malformed('no Debug reply is of this kind'),
        };

        return new Debugged($what, $value);
    }

    /**
     * A count of breakpoints, then each.
     *
     * @return list<Breakpoint>
     *
     * @throws TurborelException SQLSTATE 08006 if one is malformed
     */
    private static function breakpoints(Reader $reader): array
    {
        $all = [];
        for ($count = $reader->u32(); $count > 0; $count--) {
            $all[] = DebugCodec::breakpoint($reader);
        }

        return $all;
    }

    /**
     * A Prepared: its statement's number, its input parameters, its columns, then its tail.
     *
     * @throws TurborelException SQLSTATE 08006 if it is malformed
     */
    private static function prepared(Reader $reader): Prepared
    {
        [$statement, $parameters] = [$reader->u32(), Fields::parameters($reader)];
        [$columns, $called] = Fields::preparedTail($reader, Fields::columns($reader));

        return new Prepared($statement, $parameters, $columns, $called);
    }

    /**
     * Whether a reply of `$kind` and `$body`, decoded or not, is the last its request is answered with, by the order of a
     * statement's replies, as turborel's `ends_request` says: a Description, Rows, a Notice, a Status or an Event has more after it; a
     * Complete has if its tail says more, read as `Completion::head` reads it; any other reply ends its request's.
     */
    public static function endsRequest(int $kind, string $body): bool
    {
        if (\in_array($kind, [Kind::DESCRIPTION, Kind::ROWS, Kind::NOTICE, Kind::STATUS, Kind::EVENT], true)) {
            return false;
        }

        return $kind !== Kind::COMPLETE || Completion::head($kind, $body)?->counts->more !== true;
    }

    /**
     * A Lob reply: 0 and its data, or 1 and its locator.
     *
     * @throws TurborelException SQLSTATE 08006 if it is neither
     */
    private static function lob(Reader $reader): Reply
    {
        return match ($reader->u8()) {
            self::LOB_DATA => new LobData($reader->bytes()),
            self::LOB_LOCATOR => new LobCreated($reader->u32()),
            default => throw $reader->malformed('no Lob reply is of this kind'),
        };
    }
}

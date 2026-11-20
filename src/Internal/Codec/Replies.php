<?php

declare(strict_types=1);

namespace Turborel\Internal\Codec;

use Turborel\Internal\Codec\Reply\Challenge;
use Turborel\Internal\Codec\Reply\Complete;
use Turborel\Internal\Codec\Reply\Description;
use Turborel\Internal\Codec\Reply\Error;
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
            Kind::PREPARED => new Prepared($reader->u32(), Fields::parameters($reader), Fields::columns($reader)),
            Kind::DESCRIPTION => new Description(Fields::columns($reader)),
            Kind::ROWS => new Rows(Fields::rows($reader)),
            Kind::SUSPENDED => new Suspended(),
            Kind::COMPLETE => new Complete(Completion::read($reader)),
            Kind::ERROR => new Error(Failure::read($reader)),
            Kind::LOB => self::lob($reader),
            Kind::NOTICE => new Notice($reader->string(), $reader->string(), Diagnostics::read($reader)),
            Kind::STATUS => new Status($reader->string(), $reader->string()),
            Kind::PONG => new Pong(),
            default => throw $reader->malformed("no reply is of kind {$kind}"),
        };
        $reader->finish();

        return $reply;
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

<?php

declare(strict_types=1);

namespace Turborel\Internal\Session;

use Turborel\Internal\Codec\FrameHeader;
use Turborel\Internal\Codec\Replies;
use Turborel\Internal\Codec\Reply\Error;
use Turborel\Internal\Codec\Reply\Notice;
use Turborel\Internal\Codec\Reply\Pong;
use Turborel\Internal\Codec\Reply\Status;
use Turborel\Internal\Codec\Request\Terminate;
use Turborel\Internal\ConnectionFailure;
use Turborel\TurborelException;

/**
 * Terminate, as turborel-client sends it [PROTOCOL Terminate 'X']: the
 * session ends, its transaction as SESSION.COMMIT_AT_END says, and the
 * server closes; a commit that fails sends its Error before it closes,
 * which is the failure Terminate gives [CLIENT Connecting].
 *
 * @internal
 */
final class Ending
{
    /**
     * Ends the session over `$wire`, waiting at most `$within` seconds for the server to close.
     *
     * @throws TurborelException the failing commit's failure, or SQLSTATE 08006 if the server answers otherwise
     */
    public static function terminate(Wire $wire, float $within): void
    {
        $wire->send(new Terminate());
        try {
            $wire->stream->bound($within);
            $rest = $wire->stream->rest($wire->maxBody + FrameHeader::SIZE);
        } finally {
            $wire->lostBy(ConnectionFailure::because('the session was terminated'));
        }
        if (\strlen($rest) < FrameHeader::SIZE) {
            return;
        }
        $header = FrameHeader::decode(substr($rest, 0, FrameHeader::SIZE), $wire->maxBody);
        $reply = Replies::decode($header->kind, substr($rest, FrameHeader::SIZE, $header->length));
        if ($reply instanceof Error) {
            throw TurborelException::fromServer($reply->failure);
        }
        if (!$reply instanceof Notice && !$reply instanceof Status && !$reply instanceof Pong) {
            throw ConnectionFailure::because('the server answered Terminate');
        }
    }
}

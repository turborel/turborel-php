<?php

declare(strict_types=1);

namespace Turborel\Tests\Live\Session;

use Turborel\Internal\Codec\Failure;
use Turborel\Internal\Codec\FrameHeader;
use Turborel\Internal\Codec\Frames;
use Turborel\Internal\Codec\Replies;
use Turborel\Internal\Codec\Reply\Error;
use Turborel\Internal\Transport\Opener;
use Turborel\Session\Target;
use Turborel\TurborelException;

/**
 * A connection's bytes written as a test makes them, for what no request
 * class sends, and the server's Error read back.
 */
final class Raw
{
    /**
     * Sends the frame of kind `$kind` and body `$body` to `$target`, and gives the server's Error.
     *
     * @throws TurborelException if the server sends no Error
     */
    public static function exchange(Target $target, string $kind, string $body): Failure
    {
        [$stream] = Opener::open($target, 5.0);
        try {
            $stream->write(Frames::frame(\ord($kind), $body));
            $header = FrameHeader::decode($stream->read(FrameHeader::SIZE));
            $reply = Replies::decode($header->kind, $stream->read($header->length));
        } finally {
            $stream->close();
        }
        if (!$reply instanceof Error) {
            throw new TurborelException('the server sent no Error', 'HY000', 0);
        }

        return $reply->failure;
    }
}

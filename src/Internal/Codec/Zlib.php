<?php

declare(strict_types=1);

namespace Turborel\Internal\Codec;

use Turborel\Internal\ConnectionFailure;
use Turborel\Internal\Quietly;
use Turborel\TurborelException;

/**
 * zlib streams (RFC 1950) by PHP's zlib extension, which the driver
 * suggests and does not require: without it the driver never asks for
 * `compression` (RULES.md D15). Inflating stops at a limit, given input a
 * step at a time, so a stream that would inflate past it is refused
 * before it is held [PROTOCOL Compressed frames].
 *
 * @internal
 */
final class Zlib
{
    /** The extension that gives zlib. */
    private const string EXTENSION = 'zlib';

    /** The input given the inflater at a time: deflate expands an octet at most 1032-fold, so a step holds at most about 4 MiB more. */
    private const int STEP = 4096;

    /**
     * zlib, checked to be there.
     *
     * @throws TurborelException SQLSTATE 08006 if the extension is not loaded
     */
    public function __construct()
    {
        if (!\extension_loaded(self::EXTENSION)) {
            throw ConnectionFailure::because('a compressed frame needs PHP\'s zlib extension');
        }
    }

    /**
     * `$data` as a zlib stream.
     *
     * @throws TurborelException SQLSTATE 08006 if zlib fails
     */
    public function deflate(string $data): string
    {
        $stream = $this->quietly(static fn(): string|false => zlib_encode($data, \ZLIB_ENCODING_DEFLATE));

        return \is_string($stream) ? $stream : throw ConnectionFailure::because('zlib could not compress a frame');
    }

    /**
     * The data of the zlib stream `$stream`, all of it, at most `$limit` bytes.
     *
     * @throws TurborelException SQLSTATE 08006 if it is no zlib stream, has bytes after its end, or inflates past `$limit`
     */
    public function inflate(string $stream, int $limit): string
    {
        $context = $this->quietly(static fn(): \InflateContext|false => inflate_init(\ZLIB_ENCODING_DEFLATE));
        if (!$context instanceof \InflateContext) {
            throw ConnectionFailure::because('zlib could not begin to inflate');
        }
        $data = '';
        for ($at = 0; $at < \strlen($stream); $at += self::STEP) {
            $last = $at + self::STEP >= \strlen($stream);
            $chunk = substr($stream, $at, self::STEP);
            $more = $this->quietly(static fn(): string|false => inflate_add($context, $chunk, $last ? \ZLIB_FINISH : \ZLIB_SYNC_FLUSH));
            if (!\is_string($more) || \strlen($data) + \strlen($more) > $limit) {
                throw ConnectionFailure::because('a compressed frame is no zlib stream, or inflates past the length it declares');
            }
            $data .= $more;
        }
        if (inflate_get_status($context) !== \ZLIB_STREAM_END || inflate_get_read_len($context) !== \strlen($stream)) {
            throw ConnectionFailure::because('a compressed frame\'s zlib stream is cut short, or bytes follow its end');
        }

        return $data;
    }

    /**
     * What `$call` gives, false if PHP warned in it, the warning taken rather than printed (RULES.md R-ERR-3).
     *
     * @template T
     *
     * @param \Closure(): T $call
     *
     * @return T|false
     */
    private function quietly(\Closure $call): mixed
    {
        [$result, $warned] = Quietly::call($call);

        return $warned === null ? $result : false;
    }
}

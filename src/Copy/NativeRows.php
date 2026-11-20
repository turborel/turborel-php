<?php

declare(strict_types=1);

namespace Turborel\Copy;

use Turborel\Internal\Codec\FrameHeader;
use Turborel\Internal\Codec\Replies;
use Turborel\Internal\Codec\Reply\Complete;
use Turborel\Internal\Codec\Reply\Description;
use Turborel\Internal\Codec\Reply\Reply;
use Turborel\Internal\Codec\Reply\Rows;
use Turborel\Internal\ConnectionFailure;
use Turborel\TurborelException;
use Turborel\Type\Column;

/**
 * Rows an export wrote in the protocol's own frames, read back with every
 * value as it was, as turborel-client's `read_native` reads them
 * [CLIENT Copying and exporting].
 */
final readonly class NativeRows
{
    /**
     * The columns and rows read.
     *
     * @param list<Column>      $columns
     * @param list<list<mixed>> $rows
     */
    public function __construct(
        /** @var list<Column> $columns the query's columns */
        public array $columns,
        /** @var list<list<mixed>> $rows its rows */
        public array $rows,
    ) {}

    /**
     * The native rows `$input` holds: a Description, Rows, then a Complete.
     *
     * @param resource $input
     *
     * @throws TurborelException SQLSTATE 08006 for input that is not native rows, or is cut short
     */
    public static function read(mixed $input): self
    {
        $first = self::next($input);
        if (!$first instanceof Description) {
            throw self::notNative('no Description first');
        }
        $rows = [];
        while (true) {
            $reply = self::next($input);
            if ($reply instanceof Rows) {
                array_push($rows, ...$reply->rows);
            } elseif ($reply instanceof Complete) {
                return new self($first->columns, $rows);
            } else {
                throw self::notNative('a frame other than Rows before the Complete');
            }
        }
    }

    /**
     * The next frame of `$input`, decoded.
     *
     * @param resource $input
     *
     * @throws TurborelException SQLSTATE 08006 if it cannot be read
     */
    private static function next(mixed $input): Reply
    {
        $header = FrameHeader::decode(self::exactly($input, FrameHeader::SIZE));

        return Replies::decode($header->kind, self::exactly($input, $header->length));
    }

    /**
     * Exactly `$count` bytes of `$input`.
     *
     * @param resource $input
     *
     * @throws TurborelException SQLSTATE 08006 if fewer come
     */
    private static function exactly(mixed $input, int $count): string
    {
        $bytes = '';
        while (\strlen($bytes) < $count) {
            $chunk = fread($input, max(1, $count - \strlen($bytes)));
            if ($chunk === false || $chunk === '') {
                // As Rust's read_exact says of input that ends too soon.
                throw self::notNative('failed to fill whole buffer');
            }
            $bytes .= $chunk;
        }

        return $bytes;
    }

    /** The failure of input that is not native rows, `$why`. */
    private static function notNative(string $why): TurborelException
    {
        return ConnectionFailure::because("not native rows: {$why}");
    }
}

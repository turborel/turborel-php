<?php

declare(strict_types=1);

namespace Turborel\Internal\Codec\Request;

use Turborel\Internal\Codec\Frames;
use Turborel\Internal\Codec\Kind;
use Turborel\Internal\Codec\Writer;
use Turborel\TurborelException;
use Turborel\Value\LobKind;

/**
 * Lob 'L', the request: work on a large object by its locator, an op then
 * its fields [PROTOCOL Lob 'L']. Each op is made by its named constructor.
 *
 * @internal
 */
final readonly class Lob implements Request
{
    /** Read: locator, from, count, characters flag. */
    private const int READ = 0;

    /** Create: kind. */
    private const int CREATE = 1;

    /** Append: locator, bytes. */
    private const int APPEND = 2;

    /** Free: locator. */
    private const int FREE = 3;

    /** Hold: locator. */
    private const int HOLD = 4;

    /** The request of the op `$op` whose fields after it are `$fields`, already written. */
    private function __construct(
        /** The op's number. */
        public int $op,
        /** The op's fields, written. */
        public string $fields,
    ) {}

    /**
     * Read at most `$count` octets, or characters if `$characters`, from `$from`, counting from 0.
     *
     * @throws TurborelException SQLSTATE 22003 if a field is out of its range
     */
    public static function read(int $locator, int|string $from, int|string $count, bool $characters = false): self
    {
        return new self(self::READ, (new Writer())->u32($locator)->u64($from)->u64($count)->flag($characters)->bytesWritten());
    }

    /**
     * Create an empty large object of `$kind`.
     *
     * @throws TurborelException never, the kind being a byte
     */
    public static function create(LobKind $kind): self
    {
        return new self(self::CREATE, (new Writer())->u8($kind->value)->bytesWritten());
    }

    /**
     * Append `$octets` to the large object `$locator` that Create made.
     *
     * @throws TurborelException SQLSTATE 22003 if a field is out of its range
     */
    public static function append(int $locator, string $octets): self
    {
        return new self(self::APPEND, (new Writer())->u32($locator)->bytes($octets)->bytesWritten());
    }

    /**
     * End the locator `$locator`.
     *
     * @throws TurborelException SQLSTATE 22003 if it is not a u32
     */
    public static function free(int $locator): self
    {
        return new self(self::FREE, (new Writer())->u32($locator)->bytesWritten());
    }

    /**
     * Keep the locator `$locator` valid past its transaction's commit.
     *
     * @throws TurborelException SQLSTATE 22003 if it is not a u32
     */
    public static function hold(int $locator): self
    {
        return new self(self::HOLD, (new Writer())->u32($locator)->bytesWritten());
    }

    /**
     * Its frame.
     *
     * @throws TurborelException never, its fields having been written
     */
    public function encode(): string
    {
        return Frames::frame(Kind::LOB, \chr($this->op) . $this->fields);
    }
}

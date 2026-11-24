<?php

declare(strict_types=1);

namespace Turborel\Internal\Codec\Request;

use Turborel\Debug\Action;
use Turborel\Debug\RoutineKind;
use Turborel\Internal\Codec\Frames;
use Turborel\Internal\Codec\Kind;
use Turborel\Internal\Codec\Writer;
use Turborel\TurborelException;

/**
 * Debug 'V', with `debugger` *(extension)*: what a debugger's connection
 * asks of the session it debugs [PROTOCOL Debug 'V'].
 *
 * @internal
 */
final readonly class Debug implements Request
{
    /** Identify: this connection's ID. */
    public const int IDENTIFY = 0;

    /** Attach to the session of a connection's ID. */
    public const int ATTACH = 1;

    /** Detach. */
    public const int DETACH = 2;

    /** Set a breakpoint. */
    public const int BREAK = 3;

    /** Clear a breakpoint. */
    public const int CLEAR = 4;

    /** The breakpoints set. */
    public const int BREAKPOINTS = 5;

    /** Resume the paused target. */
    public const int RESUME = 6;

    /** Interrupt the running target. */
    public const int INTERRUPT = 7;

    /** The target's pause. */
    public const int STACK = 8;

    /** The request of `$what`, its fields after it, `$fields`. */
    private function __construct(
        /** What it asks. */
        public int $what,
        /** Its fields, encoded. */
        public string $fields = '',
    ) {}

    /** A request of `$what` alone: identify, detach, breakpoints, interrupt or stack. */
    public static function of(int $what): self
    {
        return new self($what);
    }

    /**
     * Attach to the session of the connection `$id`, a u64.
     *
     * @throws TurborelException SQLSTATE 22003 if the ID is no u64
     */
    public static function attach(int|string $id): self
    {
        return new self(self::ATTACH, (new Writer())->u64($id)->bytesWritten());
    }

    /**
     * A breakpoint at line `$line` of the `$kind` routine `$schema`.`$name`, where `$condition` is true if given.
     *
     * @throws TurborelException SQLSTATE 22003 if the line is no u32, 22021 if a string is not UTF-8
     */
    public static function break(RoutineKind $kind, string $schema, string $name, int $line, ?string $condition): self
    {
        $writer = (new Writer())->u8($kind->value)->string($schema)->string($name)->u32($line)->flag($condition !== null);
        if ($condition !== null) {
            $writer->string($condition);
        }

        return new self(self::BREAK, $writer->bytesWritten());
    }

    /**
     * Clear the breakpoint `$number`.
     *
     * @throws TurborelException SQLSTATE 22003 if it is no u32
     */
    public static function clear(int $number): self
    {
        return new self(self::CLEAR, (new Writer())->u32($number)->bytesWritten());
    }

    /** Resume the paused target as `$how` says. */
    public static function resume(Action $how): self
    {
        return new self(self::RESUME, \chr($how->value));
    }

    /** Its frame. */
    public function encode(): string
    {
        return Frames::frame(Kind::DEBUG, \chr($this->what) . $this->fields);
    }
}

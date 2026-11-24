<?php

declare(strict_types=1);

namespace Turborel\Internal\Codec;

use Turborel\Debug\Breakpoint;
use Turborel\Debug\Cursor;
use Turborel\Debug\Event;
use Turborel\Debug\EventKind;
use Turborel\Debug\Frame;
use Turborel\Debug\Named;
use Turborel\Debug\Origin;
use Turborel\Debug\Paused;
use Turborel\Debug\Place;
use Turborel\Debug\RoutineKind;
use Turborel\Debug\Scope;
use Turborel\TurborelException;

/**
 * The debugger's fields as turborel's `debug.rs` reads them
 * [PROTOCOL Debugged 'V'] [PROTOCOL Event 'W'] *(extension)*: a
 * breakpoint, a pause and its frames, and an event.
 *
 * @internal
 */
final class DebugCodec
{
    /** A pause's reason: a breakpoint, its number after. */
    private const int AT_BREAKPOINT = 0;

    /** A pause's reason: the end of a step. */
    private const int STEPPED = 1;

    /**
     * A breakpoint: its number, its routine's schema and name, its line, and its condition if it has one.
     *
     * @throws TurborelException SQLSTATE 08006 if it is malformed
     */
    public static function breakpoint(Reader $reader): Breakpoint
    {
        return new Breakpoint($reader->u32(), $reader->string(), $reader->string(), $reader->u32(), self::given($reader));
    }

    /**
     * An event: paused, with its pause; timed out; cancelled; or ended.
     *
     * @throws TurborelException SQLSTATE 08006 if it is of no kind, or malformed
     */
    public static function event(Reader $reader): Event
    {
        $kind = EventKind::tryFrom($reader->u8()) ?? throw $reader->malformed("no debugger's event is of this kind");

        return new Event($kind, $kind === EventKind::Paused ? self::pause($reader) : null);
    }

    /**
     * A pause: its reason, a breakpoint's number or a step, then its frames, the outermost first.
     *
     * @throws TurborelException SQLSTATE 08006 if it is for no reason, or malformed
     */
    public static function pause(Reader $reader): Paused
    {
        $breakpoint = match ($reader->u8()) {
            self::AT_BREAKPOINT => $reader->u32(),
            self::STEPPED => null,
            default => throw $reader->malformed('no pause is for this reason'),
        };
        $frames = [];
        for ($count = $reader->u32(); $count > 0; $count--) {
            $frames[] = self::frame($reader);
        }

        return new Paused($breakpoint, $frames);
    }

    /**
     * A routine's kind.
     *
     * @throws TurborelException SQLSTATE 08006 if it is none
     */
    public static function kind(Reader $reader): RoutineKind
    {
        return RoutineKind::tryFrom($reader->u8()) ?? throw $reader->malformed('not a kind of routine');
    }

    /**
     * A frame: its origin if it has one, whether it is visible, its place and its line and column if told, its parameters,
     * its variables and its cursors.
     *
     * @throws TurborelException SQLSTATE 08006 if it is malformed
     */
    private static function frame(Reader $reader): Frame
    {
        $origin = $reader->flag() ? new Origin(self::kind($reader), $reader->string(), $reader->string(), $reader->string(), $reader->string()) : null;
        $visible = $reader->flag();
        $at = $reader->flag() ? $reader->u64() : null;
        [$line, $column] = $reader->flag() ? [$reader->u32(), $reader->u32()] : [null, null];
        [$parameters, $variables, $cursors] = [self::named($reader), self::named($reader), []];
        for ($count = $reader->u32(); $count > 0; $count--) {
            $cursors[] = new Cursor($reader->string(), $reader->flag(), $reader->string());
        }

        return new Frame($origin, $visible, new Place($at, $line, $column), new Scope($parameters, $variables, $cursors));
    }

    /**
     * A list of parameters or variables: a count, then each its name, its type as SQL writes it, and its value.
     *
     * @return list<Named>
     *
     * @throws TurborelException SQLSTATE 08006 if one is malformed
     */
    private static function named(Reader $reader): array
    {
        $named = [];
        for ($count = $reader->u32(); $count > 0; $count--) {
            $named[] = new Named($reader->string(), $reader->string(), ValueCodec::read($reader));
        }

        return $named;
    }

    /**
     * A string if its flag says one is given.
     *
     * @throws TurborelException SQLSTATE 08006 if it is malformed
     */
    private static function given(Reader $reader): ?string
    {
        return $reader->flag() ? $reader->string() : null;
    }
}

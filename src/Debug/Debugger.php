<?php

declare(strict_types=1);

namespace Turborel\Debug;

use Turborel\Internal\Codec\Reply\Debugged;
use Turborel\Internal\Codec\Reply\Error;
use Turborel\Internal\Codec\Request\Debug;
use Turborel\Internal\Session\Control;
use Turborel\Internal\Session\Wire;
use Turborel\Internal\Statements\Results;
use Turborel\Session\Capability;
use Turborel\TurborelException;

/**
 * A connection's routine debugger, with `debugger` granted *(extension)*,
 * as turborel-client's `debug_*` methods [CLIENT Debugging routines]: it
 * attaches to the session of another connection, by that one's ID, sets
 * breakpoints in the routines and triggers its user may debug, and drives
 * that session's routines as they pause, step by step, reading their call
 * stack. What it hears of its target, an Event, comes unasked, and is kept
 * until `wait` reads it.
 *
 * Two connections: the target runs its statements as ever, often in
 * another process; the debugger's connection waits for its events.
 */
final class Debugger
{
    /**
     * The debugger of the connection of `$wire`.
     *
     * @internal
     */
    public function __construct(private readonly Wire $wire) {}

    /**
     * This connection's ID, as INFORMATION_SCHEMA.CONNECTIONS shows it: what a debugger attaches to its session by, a u64.
     *
     * @throws TurborelException SQLSTATE 08006 if `debugger` was not granted, or the connection is lost
     */
    public function id(): int|string
    {
        $id = $this->debugged(Debug::of(Debug::IDENTIFY), Debugged::CONNECTION);

        return \is_int($id) || \is_string($id) ? $id : throw $this->wire->lose("the server sent no connection's ID");
    }

    /**
     * This connection attached as the debugger of the session of connection `$id`: one of its own user's, or any if its
     * user is the database's owner or holds MONITOR.
     *
     * @throws TurborelException SQLSTATE 08003 if no connection of that ID is served whose session this one may debug; TBD01 if
     *                           this connection is attached already, or the session is this one's or has a debugger already
     */
    public function attach(int|string $id): void
    {
        $this->done(Debug::attach($id));
    }

    /**
     * This connection detached: its breakpoints gone, its target, if paused, going on, the events kept dropped.
     *
     * @throws TurborelException SQLSTATE TBD01 if it is attached to none
     */
    public function detach(): void
    {
        $this->wire->told->events = [];
        $this->done(Debug::of(Debug::DETACH));
    }

    /**
     * A breakpoint at line `$line`, from 1, of the text of the `$kind` routine `$schema`.`$name`, its specific name or, if
     * it is the one routine of that kind so named, its name; or of the trigger so named. An empty schema is the session's
     * default. The target pauses at each statement that begins on that line, where `$condition`, a `<search condition>` over
     * the frame's parameters and variables, is true if given.
     *
     * @throws TurborelException SQLCODE -103 if there is no such routine or line, -104 (42000) if the user holds no DEBUG on
     *                           it; SQLSTATE TBD01 if this connection is attached to none
     */
    public function break(RoutineKind $kind, string $schema, string $name, int $line, ?string $condition = null): Breakpoint
    {
        $set = $this->debugged(Debug::break($kind, $schema, $name, $line, $condition), Debugged::BREAKPOINT);

        return $set instanceof Breakpoint ? $set : throw $this->wire->lose('the server sent no breakpoint');
    }

    /**
     * The breakpoint `$number` cleared.
     *
     * @throws TurborelException SQLSTATE TBD01 if there is none of that number, or this connection is attached to none
     */
    public function clear(int $number): void
    {
        $this->done(Debug::clear($number));
    }

    /**
     * The breakpoints set, in the order they were set.
     *
     * @return list<Breakpoint>
     *
     * @throws TurborelException SQLSTATE TBD01 if this connection is attached to none
     */
    public function breakpoints(): array
    {
        $all = $this->debugged(Debug::of(Debug::BREAKPOINTS), Debugged::BREAKPOINTS);

        return \is_array($all) ? $all : throw $this->wire->lose('the server sent no breakpoints');
    }

    /**
     * The paused target told to go on as `$how` says: to the next statement, into a routine it invokes (`Into`) or not
     * (`Over`), to the next of the frame it returns to (`Out`), or to a breakpoint (`Continue`).
     *
     * @throws TurborelException SQLSTATE TBD01 if the target is not paused, or this connection is attached to none
     */
    public function resume(Action $how): void
    {
        $this->done(Debug::resume($how));
    }

    /**
     * The running target told to pause at the next statement it runs of a routine the debugger may debug.
     *
     * @throws TurborelException SQLSTATE TBD01 if this connection is attached to none
     */
    public function interrupt(): void
    {
        $this->done(Debug::of(Debug::INTERRUPT));
    }

    /**
     * The target's pause, with its call stack, the outermost frame first; none if it runs.
     *
     * @throws TurborelException SQLSTATE TBD01 if this connection is attached to none
     */
    public function stack(): ?Paused
    {
        $paused = $this->debugged(Debug::of(Debug::STACK), Debugged::STACK);

        return $paused === null || $paused instanceof Paused ? $paused : throw $this->wire->lose('the server sent no stack');
    }

    /**
     * The next event of the session this connection debugs not yet read: one kept, else the next the server sends, waited
     * for at most `$within` seconds, without limit if none; none if none came.
     *
     * @throws TurborelException SQLSTATE 08006 if the connection is lost, or a reply comes where an event was waited for
     */
    public function wait(?float $within = null): ?Event
    {
        $kept = array_shift($this->wire->told->events);

        return $kept ?? $this->wire->unasked($within);
    }

    /**
     * What Debugged gives back to `$request`, of `$what`.
     *
     * @return int|string|Breakpoint|list<Breakpoint>|Paused|null
     *
     * @throws TurborelException the server's refusal; SQLSTATE 08006 if `debugger` was not granted, or another reply comes
     */
    private function debugged(Debug $request, int $what): int|string|Breakpoint|array|Paused|null
    {
        (new Control($this->wire))->needs(Capability::DEBUGGER);
        $this->wire->send($request);
        $reply = $this->wire->receive();
        if ($reply instanceof Debugged && $reply->what === $what) {
            return $reply->value;
        }
        if ($reply instanceof Error) {
            throw $this->wire->failed(TurborelException::fromServer($reply->failure));
        }

        throw $this->wire->lose('the server sent no Debug reply');
    }

    /**
     * `$request` sent, its completion read.
     *
     * @throws TurborelException the server's refusal; SQLSTATE 08006 if `debugger` was not granted, or another reply comes
     */
    private function done(Debug $request): void
    {
        (new Control($this->wire))->needs(Capability::DEBUGGER);
        $this->wire->send($request);
        (new Results($this->wire))->completion();
    }
}

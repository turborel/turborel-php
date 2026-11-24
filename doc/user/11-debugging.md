# Debugging Routines

[Contents](README.md)

*(extension)* A procedure, a function or a trigger written in SQL can be
run under a debugger: another connection to the same server, which
attaches to the session that runs the routine, sets breakpoints at
lines of the routine's text, and, each time that session pauses, reads
its call stack, each frame's parameters, variables and cursors, and
tells it how to go on. [Debugging Routines](../client/31-debugging.md)
in the API Reference lists every method, and Turborel's own
[Debugging Routines](https://github.com/turborel/turborel/blob/main/doc/user/11-debugging.md)
tells who may debug what.

## Two connections

The session to debug is that of a connection whose ID its program tells
the debugger, as `$connection->debugger()->id()` gives it. The debugger
is another connection of the same user, or of the database's owner or a
user granted MONITOR, often in another program or process, as the
session it debugs waits on its CALL while it is paused.

The debugger attaches, sets a breakpoint, and waits for the session to
pause there, here at most a second. A routine is named by its specific
name, or by its name where one routine of its kind has it, and an empty
schema is the session's default.

```php
use Turborel\Debug\Action;
use Turborel\Debug\EventKind;
use Turborel\Debug\RoutineKind;

$connection->query('CREATE PROCEDURE COUNT_UP(IN N INTEGER) CONTAINS SQL BEGIN DECLARE I INTEGER DEFAULT 0; SET I = N + 1; END');
$connection->query('COMMIT');
$debugger = $db->debugger();
$debugger->attach($connection->debugger()->id());
$debugger->break(RoutineKind::Procedure, '', 'COUNT_UP', 1);
$event = $debugger->wait(1.0);
if ($event?->kind === EventKind::Paused) {
    $top = $event->paused->frames[count($event->paused->frames) - 1];
    echo $top->origin?->name, ' line ', $top->place->line, PHP_EOL;
    $debugger->resume(Action::Over);
} else {
    echo 'no pause within a second', PHP_EOL;
}
$debugger->detach();
```

```text
no pause within a second
```

Had the other session called COUNT_UP, `wait` would have given the
pause: its breakpoint's number, and its frames, the outermost first,
each with its routine, its line and column, and its parameters and
variables by name, type and value. `resume` lets it go on: `Action::Into`
to its next statement in whatever frame, `Over` to the next of this
frame, `Out` to the next of the frame it returns to, or `Continue` to a
breakpoint; `interrupt` pauses a running session at its next statement.

## When a pause ends by itself

A paused session waits for its debugger at most
DEBUGGER.PAUSE_TIMEOUT_MS, and then goes on by itself; `wait` then gives
`EventKind::TimedOut`. A cancel of its statement gives `Cancelled`, and
the session's end gives `Ended` and detaches the debugger.

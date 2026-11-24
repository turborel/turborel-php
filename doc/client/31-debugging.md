# Debugging Routines

[Contents](README.md)

## NAME

`Turborel\Debug\Debugger`: a connection's routine debugger, which drives
the routines another connection's session runs *(extension)*.

## SYNOPSIS

```php
final class Debugger
{
    public function id(): int|string;
    public function attach(int|string $id): void;
    public function detach(): void;
    public function break(RoutineKind $kind, string $schema, string $name, int $line, ?string $condition = null): Breakpoint;
    public function clear(int $number): void;
    public function breakpoints(): array;
    public function wait(?float $within = null): ?Event;
    public function stack(): ?Paused;
    public function resume(Action $how): void;
    public function interrupt(): void;
}

enum RoutineKind: int { case Procedure = 0; case Function = 1; case Trigger = 2; }
enum Action: int { case Into = 0; case Over = 1; case Out = 2; case Continue = 3; }
enum EventKind: int { case Paused = 0; case TimedOut = 1; case Cancelled = 2; case Ended = 3; }

final readonly class Breakpoint { public int $number; public string $schema; public string $name; public int $line; public ?string $condition; }
final readonly class Event { public EventKind $kind; public ?Paused $paused; }
final readonly class Paused { public ?int $breakpoint; public array $frames; public function stepped(): bool; }
final readonly class Frame { public ?Origin $origin; public bool $visible; public Place $place; public Scope $scope; }
final readonly class Origin { public RoutineKind $kind; public string $schema; public string $specificName; public string $shownSchema; public string $name; }
final readonly class Place { public int|string|null $at; public ?int $line; public ?int $column; }
final readonly class Scope { public array $parameters; public array $variables; public array $cursors; }
final readonly class Named { public string $name; public string $type; public mixed $value; }
final readonly class Cursor { public string $name; public bool $open; public string $query; }
```

## DESCRIPTION

Granted `debugger`, which a connection asks for,
`Connection::debugger()` gives the connection's debugger; the standard
interface's `Turborel\Pdo\Connection::debugger()`, a driver's extension
of it, gives its low-level connection's. Two connections take part: the
target runs its statements as ever, and the debugger, attached to the
target's session, drives the routines it runs.

`id` gives the connection's ID, as INFORMATION_SCHEMA.CONNECTIONS shows
it, by which another connection's debugger `attach`es to its session:
one of its own user's, or any if its user is the database's owner or
holds MONITOR. `detach` ends the debugging: the breakpoints go, and the
target, if paused, goes on.

`break` sets a breakpoint at a line, from 1, of the text of a procedure,
a function or a trigger, named by its specific name, or its name if one
routine of that kind has it; an empty schema is the session's default.
The breakpoint names its routine by its specific name, as RAISE_PAY_1.
Given a condition, a `<search condition>` over the frame's parameters
and variables, it pauses only where that is true. `clear` clears a
breakpoint by its number, and `breakpoints` lists them, in the order
they were set.

`wait` gives the next event of the target: a pause, with its call
stack, the outermost frame first; a timeout, once a pause lasted past
DEBUGGER.PAUSE_TIMEOUT_MS; a cancel of its statement; or its end, which
detaches the debugger. It waits at most `$within` seconds, without limit
if none, and gives none if no event came; a wait a signal interrupts
goes on for the time left. An event that comes among a
request's replies is kept for the next `wait`. `stack` gives the pause
again, none of a target running. `resume` lets a paused target go on: a
step `Into`, `Over` or `Out`, or `Continue` to a breakpoint;
`interrupt` pauses a running one at its next statement.

A frame names its routine by its specific name and by its name, none of
a statement run directly; one the debugger may not see into is not
`visible`, and shows its origin alone. Its place is in characters from
the beginning of the routine's text, and by line and column from 1. Its
scope holds its parameters, a trigger's its transition variables, and
its variables, each with its type as SQL writes it and its value, and
its cursors.

## ERRORS

| SQLSTATE | SQLCODE | When |
|---|---|---|
| 08003 | -919 | An attach to an ID no connection served has whose session this one may debug; the session goes on |
| 42000 | -104 | A breakpoint in a routine the user holds no DEBUG on; the session goes on |
| TBD01 | -910 | A request the target's state does not allow: a resume of a target not paused, a second attach, any request but `id` and `attach` of a debugger attached to none; the session goes on |
| 08006 | -919 | `debugger` not granted, before anything is sent; the connection lost |

## EXAMPLES

On `$connection`, a `Connection`, and `$db`, a `Turborel\Pdo\Connection`
of the same user:

```php
use Turborel\Debug\RoutineKind;

$connection->query('CREATE PROCEDURE RAISE_PAY(IN PCT INTEGER) CONTAINS SQL BEGIN DECLARE N INTEGER DEFAULT 0; SET N = PCT; END');
$connection->query('COMMIT');
$debugger = $db->debugger();
$debugger->attach($connection->debugger()->id());
$set = $debugger->break(RoutineKind::Procedure, '', 'RAISE_PAY', 1, 'PCT > 5');
echo "Breakpoint {$set->number} at {$set->schema}.{$set->name} line {$set->line} if {$set->condition}", PHP_EOL;
echo var_export($debugger->stack(), true), PHP_EOL;
$debugger->detach();
```

```text
Breakpoint 1 at ANN.RAISE_PAY_1 line 1 if PCT > 5
NULL
```

## STANDARD

[PROTOCOL Debug 'V'], [PROTOCOL Debugged 'V'], [PROTOCOL Event 'W'],
[PROTOCOL Capabilities], [CLIENT Debugging routines].

## SEE ALSO

[Connections](21-connections.md), [Debugging Routines](../user/11-debugging.md).

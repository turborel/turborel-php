<?php

declare(strict_types=1);

namespace Turborel\Tests\Live\Debug;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\CoversNamespace;
use PHPUnit\Framework\Attributes\Test;
use Turborel\Debug\Action;
use Turborel\Debug\EventKind;
use Turborel\Debug\Frame;
use Turborel\Debug\Named;
use Turborel\Debug\Paused;
use Turborel\Debug\RoutineKind;
use Turborel\Internal\Codec\Request\Query;
use Turborel\Internal\Statements\Querying;
use Turborel\Session\Connection;
use Turborel\Session\Scram;
use Turborel\Tests\Server\LiveServer;
use Turborel\Tests\Support\LiveTestCase;
use Turborel\TurborelException;
use Turborel\Value\Exact;

/**
 * The routine debugger *(extension)*, two connections of one process: the
 * target's CALL sent and its reply read only once the debugger lets it go
 * on, as a program of two threads would.
 */
#[CoversNamespace('Turborel\Debug')]
#[CoversNamespace('Turborel\Session')]
#[CoversNamespace('Turborel\Internal')]
#[CoversNamespace('Turborel\Statements')]
#[CoversNamespace('Turborel\Type')]
#[CoversNamespace('Turborel\Value')]
#[CoversClass(TurborelException::class)]
final class DebuggerTest extends LiveTestCase
{
    /**
     * Verifies: [CLIENT Debugging routines] [PROTOCOL Debug 'V'] [PROTOCOL Debugged 'V'] [PROTOCOL Event 'W']:
     * a debugger attaches to another connection's session by its ID, sets a
     * breakpoint, conditioned too, and lists and clears them; the target's
     * CALL pauses there, the event giving its frame's procedure, line,
     * parameters and variables; a step over pauses at the next line; the
     * pause is read again; continue lets the CALL complete; detached, the
     * debugger has no breakpoints.
     *
     * @throws TurborelException never from the debugging
     */
    #[Test]
    public function aDebuggerBreaksStepsAndResumesAnotherSession(): void
    {
        $target = self::owner();
        $target->query('CREATE TABLE D_STAFF (ID INTEGER PRIMARY KEY, SALARY INTEGER)');
        $target->query('INSERT INTO D_STAFF VALUES (1, 50000), (2, 60000)');
        $target->query("CREATE PROCEDURE D_RAISE(IN PCT INTEGER) MODIFIES SQL DATA\nBEGIN\n  DECLARE N INTEGER DEFAULT 0;\n  UPDATE D_STAFF SET SALARY = SALARY * (100 + PCT) / 100;\n  SET N = (SELECT COUNT(*) FROM D_STAFF);\nEND");
        $target->query('COMMIT');
        $debugger = self::owner()->debugger();
        $debugger->attach($target->debugger()->id());
        $set = $debugger->break(RoutineKind::Procedure, '', 'D_RAISE', 4);
        $other = $debugger->break(RoutineKind::Procedure, '', 'D_RAISE', 5, 'PCT > 50');
        self::assertSame([[$set->number, 4, null], [$other->number, 5, 'PCT > 50']], array_map(static fn($b): array => [$b->number, $b->line, $b->condition], $debugger->breakpoints()));
        $debugger->clear($other->number);
        self::assertCount(1, $debugger->breakpoints());
        $target->wire()->send(new Query('CALL D_RAISE(10)'));
        $paused = self::paused($debugger->wait(10.0)?->paused);
        $top = self::top($paused);
        self::assertSame([$set->number, 'D_RAISE', 4, [['PCT', 'INTEGER', '10']], [['N', 'INTEGER', '0']]], [$paused->breakpoint, $top->origin?->name, $top->place->line, self::shown($top->scope->parameters), self::shown($top->scope->variables)]);
        $debugger->resume(Action::Over);
        $stepped = self::paused($debugger->wait(10.0)?->paused);
        self::assertSame([true, 5], [$stepped->stepped(), self::top($stepped)->place->line]);
        self::assertSame(5, self::top(self::paused($debugger->stack()))->place->line);
        $debugger->resume(Action::Continue);
        self::assertSame(0, (new Querying($target->wire(), false))->outcome()->completed?->sqlcode());
        self::assertNull($debugger->stack());
        $debugger->detach();
        $target->terminate();
    }

    /**
     * Verifies: [PROTOCOL Debug 'V'] [CLIENT Debugging routines]: refusals
     * keep the session: an ID no connection served has 08003; a breakpoint
     * in a routine the user holds no DEBUG on 42000, SQLCODE -104; a resume
     * of a target not paused, or any request of a debugger attached to
     * none, TBD01, SQLCODE -910; a target's end is the debugger's event.
     *
     * @throws TurborelException never from the requests that succeed
     */
    #[Test]
    public function aDebuggersRefusalsKeepItsSession(): void
    {
        $owner = self::owner();
        $owner->query('CREATE PROCEDURE D_OWN(IN A INTEGER) CONTAINS SQL BEGIN SET A = A + 1; END');
        $owner->query('COMMIT');
        $debugger = self::owner()->debugger();
        self::assertFailure('08003', -919, static fn() => $debugger->attach(999_999));
        self::assertFailure('TBD01', -910, static fn() => $debugger->resume(Action::Continue));
        $bob = Connection::connect(self::socket(), 'bob', new Scram(LiveServer::BOB_PASSWORD));
        $bobs = Connection::connect(self::socket(), 'bob', new Scram(LiveServer::BOB_PASSWORD))->debugger();
        $bobs->attach($bob->debugger()->id());
        self::assertFailure('42000', -104, static fn() => $bobs->break(RoutineKind::Procedure, 'ANN', 'D_OWN', 1));
        self::assertFailure('TBD01', -910, static fn() => $bobs->resume(Action::Into));
        $bobs->interrupt();
        $bob->terminate();
        self::assertSame(EventKind::Ended, $bobs->wait(10.0)?->kind);
        self::assertSame('ANN', $owner->query('VALUES CURRENT_USER')->rows[0][0] ?? null);
        $owner->terminate();
    }

    /** The pause `$paused`, which must be one. */
    private static function paused(?Paused $paused): Paused
    {
        self::assertNotNull($paused);

        return $paused;
    }

    /** The innermost frame of `$paused`. */
    private static function top(Paused $paused): Frame
    {
        $top = $paused->frames[\count($paused->frames) - 1] ?? null;
        self::assertNotNull($top);

        return $top;
    }

    /**
     * Each of `$named`, its name, type and value's units.
     *
     * @param list<Named> $named
     *
     * @return list<array{string, string, string}>
     */
    private static function shown(array $named): array
    {
        return array_map(static fn(Named $n): array => [$n->name, $n->type, $n->value instanceof Exact ? $n->value->units : var_export($n->value, true)], $named);
    }
}

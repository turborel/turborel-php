<?php

declare(strict_types=1);

namespace Turborel\Tests\Live\Session;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\CoversNamespace;
use PHPUnit\Framework\Attributes\Test;
use Turborel\Debug\Action;
use Turborel\Debug\Debugger;
use Turborel\Debug\RoutineKind;
use Turborel\Tests\Support\LiveTestCase;
use Turborel\TurborelException;

/**
 * Waits a signal interrupts, as a program with pcntl's handlers meets
 * them: each made again, as turborel-client's are, never a lost
 * connection. Skipped where PHP has no pcntl.
 */
#[CoversNamespace('Turborel\Internal')]
#[CoversNamespace('Turborel\Session')]
#[CoversNamespace('Turborel\Debug')]
#[CoversNamespace('Turborel\Statements')]
#[CoversNamespace('Turborel\Type')]
#[CoversNamespace('Turborel\Value')]
#[CoversClass(TurborelException::class)]
final class SignalsTest extends LiveTestCase
{
    /** How many signals the handler took. */
    private static int $taken = 0;

    /**
     * Verifies: [CLIENT Debugging routines] [CLIENT Timeouts] [CLIENT Connecting]:
     * with a handler that restarts no system call, a debugger's wait a
     * signal interrupts waits on for the time left; a query whose reply a
     * signal interrupts, its routine paused by another process's debugger
     * that resumes it later, completes; Terminate with a signal pending
     * ends the session; each connection is kept until then.
     *
     * @throws TurborelException never, each wait being made again
     */
    #[Test]
    public function aSignalDuringAWaitIsWaitedThrough(): void
    {
        if (!\function_exists('pcntl_async_signals') || !\function_exists('pcntl_fork') || !\function_exists('posix_kill')) {
            self::markTestSkipped('PHP has no pcntl or posix here');
        }
        pcntl_async_signals(true);
        pcntl_signal(\SIGALRM, static function (): void {
            ++self::$taken;
        }, false);
        try {
            $target = self::owner();
            $target->query('CREATE PROCEDURE S_WAIT(IN A INTEGER) CONTAINS SQL BEGIN DECLARE B INTEGER DEFAULT 0; SET B = A + 1; END');
            $target->query('COMMIT');
            $debugger = self::owner()->debugger();
            $debugger->attach($target->debugger()->id());
            pcntl_alarm(1);
            $began = hrtime(true);
            self::assertNull($debugger->wait(2.0));
            self::assertGreaterThanOrEqual(1.9, (hrtime(true) - $began) / 1e9, 'the time left waited after the signal');
            $debugger->break(RoutineKind::Procedure, '', 'S_WAIT', 1);
            self::resumedLater($debugger);
            pcntl_alarm(1);
            self::assertSame(0, $target->query('CALL S_WAIT(1)')->completed?->sqlcode());
            posix_kill(posix_getpid(), \SIGALRM);
            $target->terminate();
            self::assertGreaterThanOrEqual(3, self::$taken);
        } finally {
            pcntl_alarm(0);
            pcntl_signal(\SIGALRM, \SIG_DFL);
            pcntl_async_signals(false);
        }
    }

    /**
     * A child process that, two seconds on, takes the pause `$debugger` waits for and lets it go on, then ends at once,
     * running none of the parent's shutdown functions.
     *
     * @throws TurborelException never in the parent
     */
    private static function resumedLater(Debugger $debugger): void
    {
        $child = pcntl_fork();
        if ($child === 0) {
            try {
                sleep(2);
                $debugger->wait(10.0);
                $debugger->resume(Action::Continue);
            } finally {
                posix_kill(posix_getpid(), \SIGKILL);
            }
        }
        self::assertGreaterThan(0, $child);
        register_shutdown_function(static fn() => pcntl_waitpid($child, $status, \WNOHANG));
    }
}

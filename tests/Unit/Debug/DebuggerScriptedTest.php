<?php

declare(strict_types=1);

namespace Turborel\Tests\Unit\Debug;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\CoversNamespace;
use PHPUnit\Framework\Attributes\Test;
use Turborel\Debug\Debugger;
use Turborel\Debug\EventKind;
use Turborel\Internal\Codec\Frames;
use Turborel\Internal\Codec\Kind;
use Turborel\Internal\Codec\Replies;
use Turborel\Internal\Statements\Querying;
use Turborel\Session\Capability;
use Turborel\Tests\Support\CodecTestCase;
use Turborel\TurborelException;

/**
 * A debugger's replies and events over a socket pair whose other end the
 * test writes as the server *(extension)*.
 */
#[CoversNamespace('Turborel\Debug')]
#[CoversNamespace('Turborel\Internal')]
#[CoversNamespace('Turborel\Statements')]
#[CoversNamespace('Turborel\Session')]
#[CoversClass(TurborelException::class)]
final class DebuggerScriptedTest extends CodecTestCase
{
    /**
     * Verifies: [PROTOCOL Event 'W'] [CLIENT Debugging routines]: an event
     * read among a request's replies never ends them, and is kept for the
     * next wait; a wait that sees nothing within its time gives none, and
     * one that reads a Notice takes it and gives none; an Error is the
     * wait's failure; another reply loses the connection.
     *
     * @throws TurborelException never from the replies in turn
     */
    #[Test]
    public function anEventIsKeptForTheNextWait(): void
    {
        [$wire, $server] = self::pair();
        $wire->told->granted = [Capability::DEBUGGER];
        $ended = Frames::frame(Kind::EVENT, "\x03");
        fwrite($server, $ended . Frames::frame(Kind::COMPLETE, self::writer()->u32(0)->string('00000')->flag(false)->bytesWritten()));
        (new Querying($wire, false))->query('X');
        $debugger = new Debugger($wire);
        self::assertSame(EventKind::Ended, $debugger->wait(0.0)?->kind);
        self::assertNull($debugger->wait(0.05));
        fwrite($server, Frames::frame(Kind::NOTICE, self::writer()->string('01000')->string('n')->bytesWritten()));
        self::assertNull($debugger->wait(1.0));
        self::assertCount(1, $wire->told->notices);
        fwrite($server, Frames::frame(Kind::ERROR, self::writer()->u32(-910 & 0xFFFF_FFFF)->string('TBD01')->string('no')->flag(false)->flag(false)->flag(false)->bytesWritten()));
        self::assertFailure('TBD01', -910, static fn() => $debugger->wait(1.0));
        fwrite($server, Frames::frame(Kind::PONG, ''));
        self::assertConnectionFails(static fn() => $debugger->wait(1.0));
    }

    /**
     * Verifies: [PROTOCOL Debugged 'V'] [PROTOCOL Debug 'V']: a Debug
     * request answered by Debugged of another kind, or by another reply,
     * loses the connection; a Debugged or a pause of no kind, or an event
     * of none, fails the connection; without `debugger` granted, nothing is
     * sent (SQLSTATE 08006).
     *
     * @throws TurborelException never from writing the server's end
     */
    #[Test]
    public function aDebuggersRepliesOutOfTurnLoseTheConnection(): void
    {
        [$wire, $server] = self::pair();
        self::assertConnectionFails(static fn() => (new Debugger($wire))->id());
        self::assertNull($wire->lost);
        $wire->told->granted = [Capability::DEBUGGER];
        fwrite($server, Frames::frame(Kind::DEBUGGED, "\x03\x00"));
        self::assertConnectionFails(static fn() => (new Debugger($wire))->id());
        self::assertNotNull($wire->lost);
        foreach (["\x04", "\x03\x01\x02"] as $body) {
            self::assertConnectionFails(static fn() => Replies::decode(Kind::DEBUGGED, $body));
        }
        self::assertConnectionFails(static fn() => Replies::decode(Kind::EVENT, "\x04"));
    }
}

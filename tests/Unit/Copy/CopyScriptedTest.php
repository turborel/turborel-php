<?php

declare(strict_types=1);

namespace Turborel\Tests\Unit\Copy;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\CoversNamespace;
use PHPUnit\Framework\Attributes\Test;
use Turborel\Copy\Copying;
use Turborel\Copy\NativeRows;
use Turborel\Internal\Codec\Frames;
use Turborel\Internal\Codec\Kind;
use Turborel\Internal\Misuse;
use Turborel\Internal\Session\Wire;
use Turborel\Internal\Transport\Stream;
use Turborel\Session\Capability;
use Turborel\Tests\Support\CodecTestCase;
use Turborel\Tests\Support\Scripted;
use Turborel\TurborelException;

/**
 * A copy over a scripted stream of the server's replies,
 * and native rows read from bytes the test writes.
 */
#[CoversNamespace('Turborel\Copy')]
#[CoversNamespace('Turborel\Internal')]
#[CoversNamespace('Turborel\Statements')]
#[CoversNamespace('Turborel\Type')]
#[CoversClass(TurborelException::class)]
final class CopyScriptedTest extends CodecTestCase
{
    /**
     * Verifies: [PROTOCOL CopyData 'J'] [CLIENT Copying and exporting]: rows
     * are sent once about 256 KiB of them wait, the rest at the end; the
     * Complete's count of rows is the copy's; a copy let go of before it
     * ends is abandoned, and one whose connection is lost is not; one
     * ended takes nothing more (SQLSTATE TB910).
     *
     * @throws TurborelException never from writing the server's end
     */
    #[Test]
    public function rowsAreSentAChunkAtATime(): void
    {
        $complete = Frames::frame(Kind::COMPLETE, self::writer()->u32(0)->string('00000')->flag(false)->u8(6)->u64(300)->bytesWritten());
        $wire = new Wire(new Stream(Scripted::open('chunks', $complete)));
        $wire->told->granted = [Capability::COPY];
        $copying = Copying::begin($wire, 'T', [], false);
        $row = [str_repeat('x', 1000)];
        for ($n = 0; $n < 300; $n++) {
            $copying->send($row);
        }
        $before = \strlen(Scripted::sent('chunks'));
        self::assertGreaterThan(Copying::CHUNK_BYTES, $before, 'a chunk sent before the end');
        self::assertSame(300, $copying->finish());
        self::assertGreaterThan($before, \strlen(Scripted::sent('chunks')), 'the rest sent at the end');
        foreach ([static fn() => $copying->send($row), static fn() => $copying->finish(), static fn() => $copying->abandon()] as $after) {
            self::assertFailure(Misuse::SQLSTATE, Misuse::SQLCODE, $after);
        }
        $wire = new Wire(new Stream(Scripted::open('dropped', Frames::frame(Kind::COMPLETE, self::writer()->u32(0)->string('00000')->flag(false)->bytesWritten()))));
        $wire->told->granted = [Capability::COPY];
        (static function () use ($wire): void {
            Copying::begin($wire, 'T', [], false)->send([1]);
        })();
        self::assertFalse($wire->copying, 'abandoned');
        $lost = new Wire(new Stream(Scripted::open('lost', '')));
        $lost->told->granted = [Capability::COPY];
        $copy = Copying::begin($lost, 'T', [], false);
        $lost->lose('gone');
        unset($copy);
        self::assertNotNull($lost->lost);
    }

    /**
     * Verifies: [CLIENT Copying and exporting]: input that is not native
     * rows, one that begins with another frame than a Description, or holds
     * another before the Complete, is refused (SQLSTATE 08006).
     *
     * @throws TurborelException never from writing the input
     */
    #[Test]
    public function inputThatIsNotNativeRowsIsRefused(): void
    {
        $description = Frames::frame(Kind::DESCRIPTION, self::writer()->u32(0)->bytesWritten());
        foreach ([Frames::frame(Kind::PONG, ''), $description . Frames::frame(Kind::PONG, '')] as $bytes) {
            $input = fopen('php://memory', 'w+b');
            self::assertIsResource($input);
            fwrite($input, $bytes);
            rewind($input);
            self::assertConnectionFails(static fn(): NativeRows => NativeRows::read($input));
        }
    }
}

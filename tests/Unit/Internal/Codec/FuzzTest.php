<?php

declare(strict_types=1);

namespace Turborel\Tests\Unit\Internal\Codec;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\CoversNamespace;
use PHPUnit\Framework\Attributes\Test;
use Random\Engine\Mt19937;
use Random\Randomizer;
use Turborel\Internal\Codec\Kind;
use Turborel\Internal\Codec\Replies;
use Turborel\Internal\Codec\TypeWriter;
use Turborel\Internal\Codec\ValueWriter;
use Turborel\Internal\ConnectionFailure;
use Turborel\Internal\ValueFailure;
use Turborel\Tests\Support\CodecTestCase;
use Turborel\TurborelException;
use Turborel\Type\ArrayType;
use Turborel\Type\BooleanType;
use Turborel\Value\ArrayValue;
use Turborel\Value\Exact;
use Turborel\Value\Json;

/**
 * The reply decoder fed bytes no server sends: random bodies, and well
 * formed ones with a byte changed or cut, from a fixed seed so every run
 * makes the same cases (RULES.md R-TEST-5).
 */
#[CoversNamespace('Turborel\Internal\Codec')]
#[CoversNamespace('Turborel\Type')]
#[CoversNamespace('Turborel\Value')]
#[CoversClass(ConnectionFailure::class)]
#[CoversClass(ValueFailure::class)]
#[CoversClass(TurborelException::class)]
final class FuzzTest extends CodecTestCase
{
    /** The generator's seed, fixed. */
    private const int SEED = 7422;

    /** The cases of each kind. */
    private const int CASES = 300;

    /** Every reply's kind, and a request's that no reply has. */
    private const array KINDS = [Kind::CHALLENGE, Kind::READY, Kind::PREPARED, Kind::DESCRIPTION, Kind::ROWS, Kind::SUSPENDED,
        Kind::COMPLETE, Kind::ERROR, Kind::LOB, Kind::NOTICE, Kind::STATUS, Kind::PONG, Kind::QUERY];

    /**
     * Verifies: [PROTOCOL Errors and notices] [PROTOCOL Frames]: whatever a
     * reply's body holds, the decoder gives a reply or fails the connection
     * (SQLCODE -919, SQLSTATE 08006), never another failure, a PHP error
     * or a warning.
     */
    #[Test]
    public function randomBodiesGiveAReplyOrFailTheConnection(): void
    {
        $random = new Randomizer(new Mt19937(self::SEED));
        foreach (self::KINDS as $kind) {
            $this->decodeOrFail($kind, '');
            for ($case = 0; $case < self::CASES; $case++) {
                $this->decodeOrFail($kind, $random->getBytes($random->getInt(1, 64)));
            }
        }
    }

    /**
     * Verifies: [PROTOCOL Errors and notices] [PROTOCOL Values]: well formed
     * Rows, Prepared and Complete replies with one byte changed, or cut
     * short at any byte, are read or fail the connection, never anything
     * else.
     *
     * @throws TurborelException never from writing the well formed bodies
     */
    #[Test]
    public function changedAndCutBodiesGiveAReplyOrFailTheConnection(): void
    {
        $random = new Randomizer(new Mt19937(self::SEED));
        $row = [null, 'é', new Exact('-12', 1), 1.5, true, new ArrayValue([1, new ArrayValue([2])]), Json::fromText('{"a":[1,2.5,"x"]}')];
        $bodies = [
            Kind::ROWS => ValueWriter::list(self::writer()->u32(1), $row)->bytesWritten(),
            Kind::PREPARED => TypeWriter::write(self::writer()->u32(1)->u32(1)->flag(false), new ArrayType(3, new BooleanType()))->u32(0)->bytesWritten(),
            Kind::COMPLETE => self::writer()->u32(0)->string('00000')->flag(false)->u8(6)->u64(1)->u8(18)->u64(1)->u64(2)->u64(3)->bytesWritten(),
        ];
        foreach ($bodies as $kind => $body) {
            for ($cut = 0; $cut < \strlen($body); $cut++) {
                $this->decodeOrFail($kind, substr($body, 0, $cut));
            }
            for ($case = 0; $case < self::CASES; $case++) {
                $changed = $body;
                $changed[$random->getInt(0, \strlen($body) - 1)] = $random->getBytes(1);
                $this->decodeOrFail($kind, $changed);
            }
        }
    }

    /** Decodes `$body` as a reply of `$kind`, asserting it is read or fails the connection, PHP's warnings made failures. */
    private function decodeOrFail(int $kind, string $body): void
    {
        set_error_handler(static function (int $level, string $message): never {
            self::fail("PHP raised {$message}");
        });
        try {
            Replies::decode($kind, $body);
            $this->addToAssertionCount(1);
        } catch (TurborelException $failure) {
            self::assertSame([ConnectionFailure::SQLSTATE, ConnectionFailure::SQLCODE], [$failure->getSqlState(), $failure->getSqlCode()], $failure->getMessage());
        } finally {
            restore_error_handler();
        }
    }
}

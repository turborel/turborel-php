<?php

declare(strict_types=1);

namespace Turborel\Tests\Support;

use PHPUnit\Framework\TestCase;
use Turborel\Internal\Codec\Reader;
use Turborel\Internal\Codec\Writer;
use Turborel\Internal\ConnectionFailure;
use Turborel\TurborelException;

/**
 * What the codec's tests share (RULES.md R-TEST-7): bytes written as the
 * spec prints them, and the failures a reader must give.
 */
abstract class CodecTestCase extends TestCase
{
    /** Bytes of the hexadecimal pairs `$pairs`, as the spec prints them, `48 24 00 00 00`. */
    final protected static function hex(string $pairs): string
    {
        $bytes = hex2bin(str_replace([' ', "\n"], '', $pairs));
        self::assertIsString($bytes, "'{$pairs}' are hexadecimal pairs");

        return $bytes;
    }

    /** A reader of `$bytes`, as a reply's body. */
    final protected static function reader(string $bytes): Reader
    {
        return new Reader($bytes, 'reply');
    }

    /** A writer, empty. */
    final protected static function writer(): Writer
    {
        return new Writer();
    }

    /**
     * Asserts that `$attempt` fails with the failure that loses the
     * connection: SQLSTATE 08006, SQLCODE -919.
     *
     * @param \Closure(): mixed $attempt what must fail
     */
    final protected static function assertConnectionFails(\Closure $attempt): void
    {
        self::assertFailure(ConnectionFailure::SQLSTATE, ConnectionFailure::SQLCODE, $attempt);
    }

    /**
     * Asserts that `$attempt` fails as the driver's error of SQLSTATE `$sqlState` and SQLCODE `$sqlCode`.
     *
     * @param \Closure(): mixed $attempt what must fail
     */
    final protected static function assertFailure(string $sqlState, int $sqlCode, \Closure $attempt): void
    {
        try {
            $attempt();
        } catch (TurborelException $failure) {
            self::assertSame([$sqlState, $sqlCode], [$failure->getSqlState(), $failure->getSqlCode()], $failure->getMessage());

            return;
        }
        self::fail("expected SQLSTATE {$sqlState}");
    }
}

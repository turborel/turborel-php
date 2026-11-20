<?php

declare(strict_types=1);

namespace Turborel\Tests\Unit\Pipeline;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\CoversNamespace;
use PHPUnit\Framework\Attributes\Test;
use Turborel\Internal\Agent\Parser;
use Turborel\Internal\Agent\Statement;
use Turborel\Internal\Pipeline\Queued;
use Turborel\Internal\Pipeline\Running;
use Turborel\Pipeline\Pipeline;
use Turborel\Tests\Support\CodecTestCase;
use Turborel\TurborelException;

/**
 * A pipeline's windows, and the SQL-agent's connection statements read,
 * with no server.
 */
#[CoversNamespace('Turborel\Internal')]
#[CoversNamespace('Turborel\Pipeline')]
#[CoversClass(TurborelException::class)]
final class WindowsTest extends CodecTestCase
{
    /**
     * Verifies: [CLIENT Pipelines]: requests are grouped into windows of at
     * most 64 KiB, in order, a request longer than a window alone in one,
     * every request in one window once; none queued, no window.
     *
     * @throws TurborelException never, the requests being well formed
     */
    #[Test]
    public function requestsAreWrittenAWindowAtATime(): void
    {
        $queued = [Queued::query('a'), Queued::query('VALUES 1'), Queued::query(str_repeat('x', intdiv(Pipeline::WINDOW, 2))), Queued::query(str_repeat('y', Pipeline::WINDOW)), Queued::query('z')];
        self::assertSame([3, 1, 1], array_map(\count(...), Running::windows($queued, false, [])));
        $all = implode('', array_merge(...array_map(static fn(array $w): array => array_column($w, 1), Running::windows($queued, true, []))));
        self::assertSame(implode('', array_map(static fn(Queued $q): string => $q->request(true, [])->encode(), $queued)), $all, 'every request, each once');
        self::assertSame([], Running::windows([], false, []));
    }

    /**
     * Verifies: [CLIENT The SQL-agent]: CONNECT, SET CONNECTION and
     * DISCONNECT are read as ISO/IEC 9075:1992 §15 writes them, a literal's
     * continuations joined, a semicolon after taken; any other statement is
     * none; one that does not parse fails (SQLSTATE 2A000).
     *
     * @throws TurborelException never from the statements well formed
     */
    #[Test]
    public function connectionStatementsAreRead(): void
    {
        $connect = Parser::statement("connect to 'db' 'host' as 'one' user 'ann';");
        self::assertSame([Statement::CONNECT, 'dbhost', 'one', 'ann'], [$connect?->kind, $connect?->values['server']->text ?? null,
            $connect?->values['name']->text ?? null, $connect?->values['user']->text ?? null]);
        self::assertSame(Statement::CONNECT_DEFAULT, Parser::statement('CONNECT TO DEFAULT')?->kind);
        self::assertSame(['DEFAULT', 'two'], [Parser::statement('SET CONNECTION DEFAULT')?->object, Parser::statement("SET CONNECTION 'two'")?->values['name']->text ?? null]);
        foreach (['ALL', 'CURRENT', 'DEFAULT'] as $object) {
            self::assertSame($object, Parser::statement("/* end */ DISCONNECT {$object} -- all\n")?->object);
        }
        self::assertFalse(Parser::statement('DISCONNECT ?')?->values['name']->literal ?? true);
        foreach (['SELECT 1', 'SET SCHEMA ANN', '', 'VALUES 1'] as $other) {
            self::assertNull(Parser::statement($other));
        }
        foreach (['CONNECT', "CONNECT TO 'a' AS", "DISCONNECT 'a' 'b' x", 'SET CONNECTION', "CONNECT TO 'a' ~"] as $wrong) {
            self::assertFailure('2A000', -102, static fn(): ?Statement => Parser::statement($wrong));
        }
    }
}

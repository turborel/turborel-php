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
     * DISCONNECT are read as ISO/IEC 9075:1992 §15 writes them, as turborel
     * reads them: a literal's continuations joined across a newline, a
     * national literal's text taken, a semicolon after taken; any other
     * statement is none; text that is no tokens fails with SQLCODE -101,
     * one that does not parse with -102 (SQLSTATE 42000), and a variable
     * specification or a character set not SQL_TEXT as valid SQL not
     * implemented (SQLSTATE 0A000).
     *
     * @throws TurborelException never from the statements well formed
     */
    #[Test]
    public function connectionStatementsAreRead(): void
    {
        $connect = Parser::statement("connect to N'db'\n 'host' as 'one' user 'ann';");
        self::assertSame([Statement::CONNECT, 'dbhost', 'one', 'ann'], [$connect?->kind, $connect?->values['server']->text ?? null,
            $connect?->values['name']->text ?? null, $connect?->values['user']->text ?? null]);
        self::assertSame(Statement::CONNECT_DEFAULT, Parser::statement('CONNECT TO DEFAULT')?->kind);
        self::assertSame(['DEFAULT', 'two'], [Parser::statement('SET CONNECTION DEFAULT')?->object, Parser::statement("SET CONNECTION 'two'")?->values['name']->text ?? null]);
        foreach (['ALL', 'CURRENT', 'DEFAULT'] as $object) {
            self::assertSame($object, Parser::statement("/* end */ DISCONNECT {$object} -- all\n")?->object);
        }
        self::assertFalse(Parser::statement('DISCONNECT 5')?->values['name']->literal ?? true);
        foreach (['SELECT 1', 'SET SCHEMA ANN', '', 'VALUES 1'] as $other) {
            self::assertNull(Parser::statement($other));
        }
        foreach (['CONNECT', "CONNECT TO 'a' AS", "DISCONNECT 'a' 'b' x", 'SET CONNECTION', "CONNECT TO 'a' ~", 'DISCONNECT ?', "CONNECT TO 'a' 'b'"] as $wrong) {
            self::assertFailure('42000', -102, static fn(): ?Statement => Parser::statement($wrong));
        }
        foreach (["CONNECT\tTO 'x'", 'DISCONNECT ALL -- bye', "SELECT 'abc"] as $unlexed) {
            self::assertFailure('42000', -101, static fn(): ?Statement => Parser::statement($unlexed));
        }
        foreach (['DISCONNECT :x', "CONNECT TO _LATIN1'abc'"] as $unimplemented) {
            self::assertFailure('0A000', -900, static fn(): ?Statement => Parser::statement($unimplemented));
        }
        self::assertSame(['-1', false], [Parser::statement('CONNECT TO -1')?->values['server']->text ?? null, Parser::statement('CONNECT TO -1')?->values['server']->literal ?? null]);
        $server = static fn(string $sql): array => [Parser::statement($sql)?->values['server']->text ?? null, Parser::statement($sql)?->values['server']->literal ?? null];
        self::assertSame(['DATE literal', false], $server("CONNECT TO DATE '2020-01-01' AS 'c'"));
        self::assertSame(['INTERVAL literal', false], $server("CONNECT TO INTERVAL -'1' DAY(3) USER 'u'"));
        self::assertSame(['a', true], $server("CONNECT TO _SQL_TEXT'a'"));
        self::assertSame(["\x0A", false], $server("CONNECT TO X'0A'"));
        self::assertSame(['TRUE', false], $server('CONNECT TO TRUE'));
        $found = ["CONNECT TO 'a' X'0A'" => "X'0A'", "CONNECT TO 'a' _X'b'" => '_X', "CONNECT TO 'a' ; ;" => '";"', "CONNECT TO 'a' b" => 'B', 'CONNECT TO' => 'end of statement'];
        foreach ($found as $sql => $shown) {
            try {
                Parser::statement($sql);
                self::fail($sql);
            } catch (TurborelException $failure) {
                self::assertStringEndsWith("found {$shown}", $failure->getMessage(), $sql);
            }
        }
    }
}

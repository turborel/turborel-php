<?php

declare(strict_types=1);

namespace Turborel\Tests\Unit\Sql;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\CoversNamespace;
use PHPUnit\Framework\Attributes\Test;
use Turborel\Internal\Copy\Names;
use Turborel\Internal\Sql\Lexer;
use Turborel\Tests\Support\CodecTestCase;
use Turborel\TurborelException;

/**
 * SQL text split into tokens as turborel's lexer splits it, with no server.
 */
#[CoversNamespace('Turborel\Internal')]
#[CoversClass(TurborelException::class)]
final class LexerTest extends CodecTestCase
{
    /**
     * Verifies: [CLIENT The SQL-agent]: the separators are dropped, spaces,
     * newlines and comments, nested bracketed ones too; each token keeps
     * its kind, its value and where it began and ended, in characters.
     *
     * @throws TurborelException never, the text being tokens
     */
    #[Test]
    public function tokensAreReadAsTurborelReadsThem(): void
    {
        $kinds = static fn(string $sql): array => array_map(static fn(array $t): array => [$t[0], $t[1]], Lexer::lex($sql));
        self::assertSame([[Lexer::KEYWORD, 'SELECT'], [Lexer::IDENTIFIER, 'ÉTÉ_1'], [Lexer::DELIMITER, '.'], [Lexer::IDENTIFIER, 'a"b']], $kinds("/* a /* nested */ one */select été_1.\"a\"\"b\" -- end\r\n"));
        self::assertSame([[Lexer::EXACT, '1000'], [Lexer::EXACT, '.5'], [Lexer::APPROXIMATE, '1.5e-3'], [Lexer::EXACT, '2'], [Lexer::IDENTIFIER, 'E'], [Lexer::LOB_LENGTH, '10K']], $kinds('1_000 .5 1.5e-3 2 e 10k'));
        self::assertSame([[Lexer::STRING, "it's"], [Lexer::STRING, 'ab'], [Lexer::STRING, 'a'], [Lexer::STRING, 'b'], [Lexer::BINARY, "\x0a\xff"]], $kinds("'it''s' N'a'\n  'b' 'a' 'b' X'0A f F'"));
        self::assertSame([[Lexer::INTRODUCER, 'INFORMATION_SCHEMA.SQL_TEXT'], [Lexer::STRING, 'x'], [Lexer::IDENTIFIER, '$NODE_ID'], [Lexer::DELIMITER, '$'], [Lexer::IDENTIFIER, 'OTHER']], $kinds("_information_schema.sql_text'x' \$node_id \$other"));
        self::assertSame(['[', ']', '<->', '<=', '||', '!', '?'], array_column(Lexer::lex('??( ??) <-> <= || ! ?'), 1));
        self::assertSame([[Lexer::KEYWORD, 'MATCH'], [Lexer::DELIMITER, '('], [Lexer::DELIMITER, ')'], [Lexer::DELIMITER, '-'], [Lexer::DELIMITER, '->'], [Lexer::DELIMITER, '(']], $kinds('MATCH ()-->('));
        self::assertSame([[Lexer::DELIMITER, '('], [Lexer::DELIMITER, ')']], $kinds("()--(\n"), 'no relationship before MATCH');
        self::assertSame([[Lexer::IDENTIFIER, 'A', 2, 3]], Lexer::lex('  a'));
        self::assertSame([[Lexer::IDENTIFIER, str_repeat('A', 128) . str_repeat('_', 9), 0, 137]], Lexer::lex(str_repeat('a', 128) . str_repeat('_', 9)), 'an underscore is not counted');
    }

    /**
     * Verifies: [CLIENT The SQL-agent]: text that is no tokens is refused
     * with SQLCODE -101, an empty delimited identifier with -102 and an
     * identifier of more than 128 characters with -103, each SQLSTATE
     * 42000, its message as turborel's.
     */
    #[Test]
    public function textThatIsNoTokensIsRefused(): void
    {
        $lexical = ["'open", "'a\nb'", "\"a\nb\"", '"open', '/* open', '-- no newline', 'a != b', "a\tb", '1a', '@', "X'0'", "X'zz'"];
        foreach ($lexical as $text) {
            self::assertFailure('42000', -101, static fn(): array => Lexer::lex($text));
        }
        self::assertFailure('42000', -102, static fn(): array => Lexer::lex('""'));
        self::assertFailure('42000', -103, static fn(): array => Lexer::lex(str_repeat('a', 129)));
        self::assertFailure('42000', -103, static fn(): array => Lexer::lex('"' . str_repeat('a', 129) . '"'));
        $messages = ["a\tb" => "\"a\" must be followed by a <delimiter token> or a <separator>, not '\\t'", '@' => "character '@' cannot start a <token>"];
        foreach ($messages as $text => $message) {
            try {
                Lexer::lex($text);
                self::fail('refused');
            } catch (TurborelException $failure) {
                self::assertSame($message, $failure->getMessage());
            }
        }
    }

    /**
     * Verifies: [CLIENT Importing]: a table name and a column's heading are
     * read by turborel's lexer, a pseudo-column in any case and a regular
     * identifier of any letters included; text that is no identifiers is
     * none of them.
     */
    #[Test]
    public function namesAreReadByTheLexer(): void
    {
        self::assertSame('"S"."été"', Names::table('s . "été"'));
        self::assertSame('$FROM_ID', Names::column('$from_id'));
        self::assertSame('ΔX', Names::identifier('δx'));
        self::assertNull(Names::table('a..b'));
        self::assertNull(Names::table("'a'"));
        self::assertNull(Names::identifier('a b'));
        self::assertSame('a b', Names::column('a b'));
        self::assertSame('a!=b', Names::column('a!=b'));
    }
}

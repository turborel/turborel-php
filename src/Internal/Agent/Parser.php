<?php

declare(strict_types=1);

namespace Turborel\Internal\Agent;

use Turborel\Internal\Codec\Cited;
use Turborel\Internal\Codec\Diagnostics;
use Turborel\Internal\Codec\Failure;
use Turborel\Internal\Codec\FailureState;
use Turborel\Internal\Sql\Lexer;
use Turborel\TurborelException;

/**
 * The connection statements a client runs itself, read as turborel's
 * `connection_statement` reads them (ISO/IEC 9075:1992 §15.1 to §15.3):
 * the text split into tokens first, any text that is no tokens refused;
 * then a statement whose first words are CONNECT, DISCONNECT or SET
 * CONNECTION parsed, each name a `<simple value specification>`, a
 * literal; any other statement is none.
 *
 * @internal
 */
final class Parser
{
    /** The character set a literal's introducer may name, SQL_TEXT. */
    private const string SQL_TEXT = 'SQL_TEXT';

    /** The next token's place. */
    private int $at = 0;

    /** A refusal of valid SQL not implemented, given once the statement is read whole, as turborel's refuse_later gives it. */
    private ?TurborelException $refused = null;

    /**
     * The parser of `$tokens`.
     *
     * @param list<array{string, string, int, int}> $tokens
     */
    private function __construct(private readonly array $tokens, private readonly int $length) {}

    /**
     * The connection statement `$sql` is; none if it is another.
     *
     * @throws TurborelException SQLSTATE 42000 if it is no tokens, or a connection statement that does not parse; 0A000 of valid SQL not implemented
     */
    public static function statement(string $sql): ?Statement
    {
        $tokens = Lexer::lex($sql);
        $parser = new self($tokens, (int) preg_match_all('/./su', $sql));
        $first = $tokens[0] ?? null;
        $second = $tokens[1] ?? null;
        $connect = $first !== null && $first[0] === Lexer::KEYWORD && \in_array($first[1], ['CONNECT', 'DISCONNECT'], true);
        $set = $first !== null && $first[0] === Lexer::KEYWORD && $first[1] === 'SET' && $second !== null && $second[0] === Lexer::KEYWORD && $second[1] === 'CONNECTION';
        if (!$connect && !$set) {
            return null;
        }
        $statement = match ($first[1] ?? '') {
            'CONNECT' => $parser->connect(),
            'DISCONNECT' => $parser->disconnect(),
            default => $parser->set(),
        };
        $parser->end();

        return $statement;
    }

    /**
     * CONNECT TO `<SQL-server name>` [AS `<connection name>`] [USER `<user name>`], or TO DEFAULT.
     *
     * @throws TurborelException SQLSTATE 42000 if it does not parse
     */
    private function connect(): Statement
    {
        $this->expect('CONNECT');
        $this->expect('TO');
        if ($this->accept('DEFAULT')) {
            return new Statement(Statement::CONNECT_DEFAULT);
        }
        $values = ['server' => $this->value()];
        if ($this->accept('AS')) {
            $values['name'] = $this->value();
        }
        if ($this->accept('USER')) {
            $values['user'] = $this->value();
        }

        return new Statement(Statement::CONNECT, $values);
    }

    /**
     * SET CONNECTION `<connection object>`: DEFAULT or a name.
     *
     * @throws TurborelException SQLSTATE 42000 if it does not parse
     */
    private function set(): Statement
    {
        $this->expect('SET');
        $this->expect('CONNECTION');
        if ($this->accept('DEFAULT')) {
            return new Statement(Statement::SET, [], 'DEFAULT');
        }

        return new Statement(Statement::SET, ['name' => $this->value()]);
    }

    /**
     * DISCONNECT `<disconnect object>`: DEFAULT, ALL, CURRENT or a name.
     *
     * @throws TurborelException SQLSTATE 42000 if it does not parse
     */
    private function disconnect(): Statement
    {
        $this->expect('DISCONNECT');
        foreach (['DEFAULT', 'ALL', 'CURRENT'] as $object) {
            if ($this->accept($object)) {
                return new Statement(Statement::DISCONNECT, [], $object);
            }
        }

        return new Statement(Statement::DISCONNECT, ['name' => $this->value()]);
    }

    /**
     * A `<simple value specification>`: a literal, a character string literal's text kept; a variable specification is refused.
     *
     * @throws TurborelException SQLSTATE 0A000 of a variable specification; 42000 if no literal is next
     */
    private function value(): Value
    {
        if ($this->is(Lexer::DELIMITER, ':')) {
            throw self::failure(-900, '0A000', 'DESIGN', '<variable specification> (embedded SQL) is valid SQL but is not implemented (RULES.md D18)', $this->here());
        }
        [$kind, $text] = $this->peek();
        if (\in_array($text, ['DATE', 'TIME', 'TIMESTAMP'], true) && $kind === Lexer::KEYWORD && ($this->tokens[$this->at + 1][0] ?? '') === Lexer::STRING) {
            $this->at += 2;

            return new Value("{$text} literal", false);
        }
        if ([$kind, $text] === [Lexer::KEYWORD, 'INTERVAL']) {
            return $this->interval();
        }
        if ($kind === Lexer::INTRODUCER && ($this->tokens[$this->at + 1][0] ?? '') === Lexer::STRING) {
            $this->introducer($text);
            [$kind, $text] = $this->peek();
        }
        $sign = $this->tokens[$this->at] ?? null;
        $next = $this->tokens[$this->at + 1] ?? null;
        $signed = $sign !== null && $sign[0] === Lexer::DELIMITER && \in_array($sign[1], ['+', '-'], true);
        if ($signed && $next !== null && \in_array($next[0], [Lexer::EXACT, Lexer::APPROXIMATE], true) && $next[2] === $sign[3]) {
            [$kind, $text] = [$next[0], $sign[1] . $next[1]];
            ++$this->at;
        }
        $literal = \in_array($kind, [Lexer::STRING, Lexer::BINARY, Lexer::EXACT, Lexer::APPROXIMATE], true)
            || ($kind === Lexer::KEYWORD && \in_array($text, ['TRUE', 'FALSE', 'UNKNOWN'], true));
        if (!$literal) {
            throw $this->syntax('expected a <literal>');
        }
        ++$this->at;

        return new Value($text, $kind === Lexer::STRING);
    }

    /** An interval literal, INTERVAL [sign] 'string' and its qualifier's words, read as a value that is no character string. */
    private function interval(): Value
    {
        ++$this->at;
        if ($this->is(Lexer::DELIMITER, '+') || $this->is(Lexer::DELIMITER, '-')) {
            ++$this->at;
        }
        while (($token = $this->tokens[$this->at] ?? null) !== null && ($token[0] !== Lexer::DELIMITER || \in_array($token[1], ['(', ')', ','], true))
            && !\in_array($token[1], ['AS', 'USER'], true)) {
            ++$this->at;
        }

        return new Value('INTERVAL literal', false);
    }

    /** An introducer before a literal: of a character set but SQL_TEXT, its refusal kept for the statement's end. */
    private function introducer(string $name): void
    {
        $parts = explode('.', $name);
        $sqlText = end($parts) === self::SQL_TEXT && (\count($parts) === 1 || ($parts[\count($parts) - 2] ?? '') === 'INFORMATION_SCHEMA');
        if (!$sqlText) {
            $this->refused ??= self::failure(-900, '0A000', 'DESIGN', "the character set {$name} is valid SQL but is not implemented (RULES.md D18)", $this->here());
        }
        ++$this->at;
    }

    /**
     * The statement's end, after one semicolon if written; then a refusal of valid SQL not implemented read in it, if any.
     *
     * @throws TurborelException SQLSTATE 42000 if more follows; 0A000 of valid SQL not implemented
     */
    private function end(): void
    {
        if ($this->is(Lexer::DELIMITER, ';')) {
            ++$this->at;
        }
        if (isset($this->tokens[$this->at])) {
            throw $this->syntax('expected end of statement');
        }
        if ($this->refused !== null) {
            throw $this->refused;
        }
    }

    /** Whether the key word `$word` is next, taken if it is. */
    private function accept(string $word): bool
    {
        if ($this->is(Lexer::KEYWORD, $word)) {
            ++$this->at;

            return true;
        }

        return false;
    }

    /**
     * That the key word `$word` is next, taken.
     *
     * @throws TurborelException SQLSTATE 42000 if another is
     */
    private function expect(string $word): void
    {
        if (!$this->accept($word)) {
            throw $this->syntax("expected {$word}");
        }
    }

    /** Whether the next token is of `$kind` and `$text`. */
    private function is(string $kind, string $text): bool
    {
        return [$this->peek()[0], $this->peek()[1]] === [$kind, $text];
    }

    /**
     * The next token's kind and text; of the end, none and empty.
     *
     * @return array{string, string}
     */
    private function peek(): array
    {
        $token = $this->tokens[$this->at] ?? null;

        return $token === null ? ['', ''] : [$token[0], $token[1]];
    }

    /** Where the next token begins, in characters; past the last, the text's length. */
    private function here(): int
    {
        return $this->tokens[$this->at][2] ?? $this->length;
    }

    /** A syntax error, `$message`, found the next token, as turborel's parser describes it: SQLCODE -102. */
    private function syntax(string $message): TurborelException
    {
        [$kind, $text] = $this->peek();
        $found = match ($kind) {
            '' => 'end of statement',
            Lexer::STRING => "'{$text}'",
            Lexer::BINARY => "X'" . strtoupper(bin2hex($text)) . "'",
            Lexer::INTRODUCER => "_{$text}",
            Lexer::DELIMITER => "\"{$text}\"",
            default => $text,
        };

        return self::failure(-102, '42000', null, "{$message}, found {$found}", $this->here());
    }

    /** The failure of SQLCODE `$code` and SQLSTATE `$state`, citing `$rule`, `$message`, at character `$at`. */
    private static function failure(int $code, string $state, ?string $rule, string $message, int $at): TurborelException
    {
        return TurborelException::fromServer(new Failure($code, $state, $message, new Cited($rule, $at), new FailureState(false, new Diagnostics(['severity' => Diagnostics::ERROR]))));
    }
}

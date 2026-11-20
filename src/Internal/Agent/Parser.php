<?php

declare(strict_types=1);

namespace Turborel\Internal\Agent;

use Turborel\TurborelException;

/**
 * The connection statements a client runs itself read from SQL text, as
 * turborel's `connection_statement` reads them (ISO/IEC 9075:1992 §15.1
 * to §15.3): a statement whose first words are CONNECT, DISCONNECT, or
 * SET CONNECTION; any other is none.
 *
 * @internal
 */
final class Parser
{
    /** A token: spaces and comments before it, then a word, a string, a delimited identifier, a number, a parameter, or a mark. */
    private const string TOKEN = <<<'RE'
        /\G(?:\s|--[^\n]*(?:\n|$)|\/\*.*?\*\/)*(?:(?<word>\p{L}[\p{L}\p{Nd}_]*)|'(?<string>(?:[^']|'')*)'|"(?<quoted>(?:[^"]|"")+)"|(?<number>\d+(?:\.\d*)?|\.\d+)|(?<parameter>\?|:\p{L}[\p{L}\p{Nd}_]*)|(?<mark>(?!--|\/\*)\S))/Asu
        RE;

    /** @var list<array{string, string}> the tokens, each its kind and text */
    private array $tokens;

    /** The next token's place. */
    private int $at = 0;

    /**
     * The parser of `$sql`'s tokens.
     *
     * @throws TurborelException SQLSTATE 2A000 if it cannot be split into tokens
     */
    private function __construct(string $sql)
    {
        $this->tokens = self::tokens($sql);
    }

    /**
     * The connection statement `$sql` is; none if it is another.
     *
     * @throws TurborelException SQLSTATE 2A000 if it is one but does not parse
     */
    public static function statement(string $sql): ?Statement
    {
        $parser = new self($sql);
        $first = $parser->tokens[0][1] ?? '';
        $second = $parser->tokens[1][1] ?? '';
        $kinds = [$parser->tokens[0][0] ?? '', $parser->tokens[1][0] ?? ''];
        if ($kinds[0] !== 'word' || !(\in_array($first, ['CONNECT', 'DISCONNECT'], true) || ($first === 'SET' && $kinds[1] === 'word' && $second === 'CONNECTION'))) {
            return null;
        }
        $statement = match ($first) {
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
     * @throws TurborelException SQLSTATE 2A000 if it does not parse
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
     * @throws TurborelException SQLSTATE 2A000 if it does not parse
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
     * @throws TurborelException SQLSTATE 2A000 if it does not parse
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
     * A `<simple value specification>`: a character string literal, its continuations joined; or a number or a parameter.
     *
     * @throws TurborelException SQLSTATE 2A000 if none is next
     */
    private function value(): Value
    {
        [$kind, $text] = $this->tokens[$this->at] ?? ['', ''];
        if ($kind === 'string') {
            ++$this->at;
            while (($next = $this->tokens[$this->at] ?? null) !== null && $next[0] === 'string') {
                $text .= $next[1];
                ++$this->at;
            }

            return new Value($text, true);
        }
        if ($kind === 'number' || $kind === 'parameter') {
            ++$this->at;

            return new Value($text, false);
        }

        throw self::syntax('a <simple value specification> is expected, found ' . ($text === '' ? 'the end' : $text));
    }

    /**
     * That the statement ends here, a semicolon past it taken.
     *
     * @throws TurborelException SQLSTATE 2A000 if more follows
     */
    private function end(): void
    {
        if (($this->tokens[$this->at] ?? null) === ['mark', ';']) {
            ++$this->at;
        }
        if (isset($this->tokens[$this->at])) {
            throw self::syntax("the statement ends before {$this->tokens[$this->at][1]}");
        }
    }

    /** Whether the key word `$word` is next, taken if it is. */
    private function accept(string $word): bool
    {
        if (($this->tokens[$this->at] ?? null) === ['word', $word]) {
            ++$this->at;

            return true;
        }

        return false;
    }

    /**
     * That the key word `$word` is next, taken.
     *
     * @throws TurborelException SQLSTATE 2A000 if another is
     */
    private function expect(string $word): void
    {
        if (!$this->accept($word)) {
            throw self::syntax("expected {$word}, found " . ($this->tokens[$this->at][1] ?? 'the end'));
        }
    }

    /**
     * The tokens of `$sql`, each its kind and text: a word in upper case, a string's or a delimited identifier's body.
     *
     * @return list<array{string, string}>
     *
     * @throws TurborelException SQLSTATE 2A000 if a part of it is no token
     */
    private static function tokens(string $sql): array
    {
        $tokens = [];
        $at = 0;
        while (preg_match(self::TOKEN, $sql, $match, \PREG_UNMATCHED_AS_NULL, $at) === 1) {
            $at += \strlen($match[0]);
            foreach (['word', 'string', 'quoted', 'number', 'parameter', 'mark'] as $kind) {
                $text = $match[$kind] ?? null;
                if ($text !== null) {
                    $tokens[] = [$kind, match ($kind) {
                        'word' => strtoupper($text),
                        'string' => str_replace("''", "'", $text),
                        'quoted' => str_replace('""', '"', $text),
                        default => $text,
                    }];

                    break;
                }
            }
        }
        if (trim((string) preg_replace('/--[^\n]*|\/\*.*?\*\//s', '', substr($sql, $at))) !== '') {
            throw self::syntax('the statement holds no token at ' . substr($sql, $at, 10));
        }

        return $tokens;
    }

    /** A syntax error, `$message`, as turborel's parser gives one: SQLSTATE 2A000, SQLCODE -102. */
    private static function syntax(string $message): TurborelException
    {
        return new TurborelException($message, '2A000', -102);
    }
}

<?php

declare(strict_types=1);

namespace Turborel\Tools;

/**
 * A function, method, closure or arrow function found in a file's tokens:
 * where it begins, its parameters and the lines of code of its body.
 */
final readonly class Functions
{
    /** The tokens that neither are code nor make a line one of code. */
    private const array NOT_CODE = [\T_WHITESPACE, \T_COMMENT, \T_DOC_COMMENT];

    /** The tokens that open a brace a `}` closes. */
    private const array OPENING = ['{', \T_CURLY_OPEN, \T_DOLLAR_OPEN_CURLY_BRACES];

    /** The tokens that begin a function. */
    private const array FUNCTION = [\T_FUNCTION, \T_FN];

    /**
     * A function found.
     */
    private function __construct(
        /** The line its declaration begins on. */
        public int $line,
        /** Its parameters. */
        public int $parameters,
        /** The lines of its body that hold code, its braces not counted. */
        public int $codeLines,
    ) {}

    /**
     * Every function in `$tokens`, in order.
     *
     * @param list<\PhpToken> $tokens
     *
     * @return list<self>
     */
    public static function in(array $tokens): array
    {
        $found = [];
        foreach ($tokens as $at => $token) {
            if ($token->is(self::FUNCTION)) {
                $found[] = self::at($tokens, $at, $token->line);
            }
        }

        return $found;
    }

    /**
     * The function whose keyword is `$tokens[$at]`, on line `$line`.
     *
     * @param list<\PhpToken> $tokens
     */
    private static function at(array $tokens, int $at, int $line): self
    {
        $open = self::next($tokens, $at, ['(']);
        $close = self::matching($tokens, $open);
        $parameters = 0;
        foreach (\array_slice($tokens, $open, $close - $open) as $token) {
            $parameters += $token->is(\T_VARIABLE) ? 1 : 0;
        }
        $body = self::next($tokens, $close, ['{', ';', \T_DOUBLE_ARROW]);
        $lines = ($tokens[$body] ?? null)?->text === '{' ? self::codeLines($tokens, $body) : 0;

        return new self($line, $parameters, $lines);
    }

    /**
     * The lines holding code between the brace at `$tokens[$open]` and the
     * one closing it.
     *
     * @param list<\PhpToken> $tokens
     */
    private static function codeLines(array $tokens, int $open): int
    {
        $close = self::matching($tokens, $open);
        $lines = [];
        foreach (\array_slice($tokens, $open + 1, $close - $open - 1) as $token) {
            if ($token->is(self::NOT_CODE)) {
                continue;
            }
            $last = $token->line + substr_count($token->text, "\n");
            for ($line = $token->line; $line <= $last; $line++) {
                $lines[$line] = true;
            }
        }

        return \count($lines);
    }

    /**
     * Where the first of `$kinds` comes after `$tokens[$from]`, or past the
     * end if none does.
     *
     * @param list<\PhpToken>   $tokens
     * @param list<int|string>  $kinds
     */
    private static function next(array $tokens, int $from, array $kinds): int
    {
        $at = $from + 1;
        while (isset($tokens[$at]) && !$tokens[$at]->is($kinds)) {
            $at++;
        }

        return $at;
    }

    /**
     * Where the bracket opened at `$tokens[$open]` closes, or past the end
     * if it does not.
     *
     * @param list<\PhpToken> $tokens
     */
    private static function matching(array $tokens, int $open): int
    {
        $depth = 0;
        $at = $open;
        for (; isset($tokens[$at]); $at++) {
            $token = $tokens[$at];
            $depth += $token->is(['(', '[', ...self::OPENING]) ? 1 : 0;
            $depth -= $token->is([')', ']', '}']) ? 1 : 0;
            if ($depth === 0) {
                return $at;
            }
        }

        return $at;
    }
}

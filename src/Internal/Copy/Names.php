<?php

declare(strict_types=1);

namespace Turborel\Internal\Copy;

use Turborel\Internal\Sql\Lexer;
use Turborel\TurborelException;

/**
 * The names an import writes in SQL, as turborel-client's `insert.rs`
 * reads them: a `<table name>` of up to three `<identifier>`s, catalog,
 * schema and table, each written again as a `<delimited identifier>`, so
 * nothing else is taken for one; a heading read as the column it names.
 * An `<identifier>` is read by turborel's lexer: a `<regular
 * identifier>`, in upper case, that is no `<reserved word>`, a pseudo-column
 * of a node or edge table, or a `<delimited identifier>` (ISO/IEC 9075:1992
 * §5.2, §5.4).
 *
 * @internal
 */
final class Names
{
    /** The most parts of a `<table name>`: catalog, schema and table. */
    private const int MAX_PARTS = 3;

    /** `$name` as a `<delimited identifier>`, a quote in it written twice. */
    public static function delimited(string $name): string
    {
        return '"' . str_replace('"', '""', $name) . '"';
    }

    /** `$text`, a `<table name>`, as SQL text of delimited identifiers; none if it is not one. */
    public static function table(string $text): ?string
    {
        $tokens = self::tokens($text);
        if ($tokens === null || \count($tokens) % 2 === 0 || \count($tokens) > self::MAX_PARTS * 2 - 1) {
            return null;
        }
        $parts = [];
        foreach ($tokens as $at => [$kind, $value]) {
            $wanted = $at % 2 === 0 ? [$kind === Lexer::IDENTIFIER] : [[$kind, $value] === [Lexer::DELIMITER, '.']];
            if (!$wanted[0]) {
                return null;
            }
            if ($at % 2 === 0) {
                $parts[] = self::delimited($value);
            }
        }

        return implode('.', $parts);
    }

    /** `$text` as an `<identifier>`, if it is one alone: a regular one in upper case, or a delimited one's body. */
    public static function identifier(string $text): ?string
    {
        $tokens = self::tokens($text);

        return $tokens !== null && \count($tokens) === 1 && $tokens[0][0] === Lexer::IDENTIFIER ? $tokens[0][1] : null;
    }

    /** The column `$heading` names: the `<identifier>` it is, if it is one, a key word too, in upper case; else as written. */
    public static function column(string $heading): string
    {
        $tokens = self::tokens($heading);
        if ($tokens !== null && \count($tokens) === 1 && \in_array($tokens[0][0], [Lexer::IDENTIFIER, Lexer::KEYWORD], true)) {
            return $tokens[0][1];
        }

        return $heading;
    }

    /**
     * The tokens of `$text`, each its kind and text, as turborel's lexer splits them; none if it is no tokens.
     *
     * @return list<array{string, string}>|null
     */
    private static function tokens(string $text): ?array
    {
        try {
            return array_map(static fn(array $t): array => [$t[0], $t[1]], Lexer::lex($text));
        } catch (TurborelException) {
            return null;
        }
    }
}

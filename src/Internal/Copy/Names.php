<?php

declare(strict_types=1);

namespace Turborel\Internal\Copy;

/**
 * The names an import writes in SQL, as turborel-client's `insert.rs`
 * reads them: a `<table name>` of up to three `<identifier>`s, catalog,
 * schema and table, each written again as a `<delimited identifier>`, so
 * nothing else is taken for one; a heading read as the column it names.
 * An `<identifier>` is a `<regular identifier>`, in upper case, that is
 * no `<reserved word>`, or a `<delimited identifier>` (ISO/IEC 9075:1992
 * §5.2, §5.4).
 *
 * @internal
 */
final class Names
{
    /** The most parts of a `<table name>`: catalog, schema and table. */
    private const int MAX_PARTS = 3;

    /** The longest `<identifier>`, in characters, as turborel's MAX_IDENTIFIER_LENGTH. */
    private const int MAX_LENGTH = 128;

    /** A token of a name: a regular identifier, a delimited one, or a period, with the spaces before it. */
    private const string TOKEN = '/\G\s*(?:(\p{L}[\p{L}\p{Nd}_]*)|"((?:[^"\r\n]|"")+)"|(\.))/Au';

    /** The `<reserved word>`s, as turborel's lexer has them (KEY_WORDS). */
    private const array KEY_WORDS = [
        'ABSOLUTE', 'ACTION', 'ADD', 'ALL', 'ALLOCATE', 'ALTER', 'AND', 'ANY', 'ARE', 'AS', 'ASC',
        'ASSERTION', 'AT', 'AUTHORIZATION', 'AVG', 'BEGIN', 'BETWEEN', 'BIT', 'BIT_LENGTH', 'BOTH', 'BY',
        'CASCADE', 'CASCADED', 'CASE', 'CAST', 'CATALOG', 'CHAR', 'CHARACTER', 'CHAR_LENGTH',
        'CHARACTER_LENGTH', 'CHECK', 'CLOSE', 'COALESCE', 'COLLATE', 'COLLATION', 'COLUMN', 'COMMIT',
        'CONNECT', 'CONNECTION', 'CONSTRAINT', 'CONSTRAINTS', 'CONTINUE', 'CONVERT', 'CORRESPONDING', 'COUNT',
        'CREATE', 'CROSS', 'CURRENT', 'CURRENT_DATE', 'CURRENT_TIME', 'CURRENT_TIMESTAMP', 'CURRENT_USER',
        'CURSOR', 'DATE', 'DAY', 'DEALLOCATE', 'DEC', 'DECIMAL', 'DECLARE', 'DEFAULT', 'DEFERRABLE',
        'DEFERRED', 'DELETE', 'DESC', 'DESCRIBE', 'DESCRIPTOR', 'DIAGNOSTICS', 'DISCONNECT', 'DISTINCT',
        'DOMAIN', 'DOUBLE', 'DROP', 'ELSE', 'END', 'ESCAPE', 'EXCEPT', 'EXCEPTION', 'EXEC', 'EXECUTE',
        'EXISTS', 'EXTERNAL', 'EXTRACT', 'FALSE', 'FETCH', 'FIRST', 'FLOAT', 'FOR', 'FOREIGN', 'FOUND',
        'FROM', 'FULL', 'GET', 'GLOBAL', 'GO', 'GOTO', 'GRANT', 'GROUP', 'HAVING', 'HOUR', 'IDENTITY',
        'IMMEDIATE', 'IN', 'INDICATOR', 'INITIALLY', 'INNER', 'INPUT', 'INSENSITIVE', 'INSERT', 'INT',
        'INTEGER', 'INTERSECT', 'INTERVAL', 'INTO', 'IS', 'ISOLATION', 'JOIN', 'KEY', 'LANGUAGE', 'LAST',
        'LEADING', 'LEFT', 'LEVEL', 'LIKE', 'LOCAL', 'LOWER', 'MATCH', 'MAX', 'MIN', 'MINUTE', 'MODULE',
        'MONTH', 'NAMES', 'NATIONAL', 'NATURAL', 'NCHAR', 'NEXT', 'NO', 'NOT', 'NULL', 'NULLIF', 'NUMERIC',
        'OCTET_LENGTH', 'OF', 'ON', 'ONLY', 'OPEN', 'OPTION', 'OR', 'ORDER', 'OUTER', 'OUTPUT', 'OVERLAPS',
        'PAD', 'PARTIAL', 'POSITION', 'PRECISION', 'PREPARE', 'PRESERVE', 'PRIMARY', 'PRIOR', 'PRIVILEGES',
        'PROCEDURE', 'PUBLIC', 'READ', 'REAL', 'REFERENCES', 'RELATIVE', 'RESTRICT', 'REVOKE', 'RIGHT',
        'ROLLBACK', 'ROWS', 'SCHEMA', 'SCROLL', 'SECOND', 'SECTION', 'SELECT', 'SESSION', 'SESSION_USER',
        'SET', 'SIZE', 'SMALLINT', 'SOME', 'SPACE', 'SQL', 'SQLCODE', 'SQLERROR', 'SQLSTATE', 'SUBSTRING',
        'SUM', 'SYSTEM_USER', 'TABLE', 'TEMPORARY', 'THEN', 'TIME', 'TIMESTAMP', 'TIMEZONE_HOUR',
        'TIMEZONE_MINUTE', 'TO', 'TRAILING', 'TRANSACTION', 'TRANSLATE', 'TRANSLATION', 'TRIM', 'TRUE',
        'UNION', 'UNIQUE', 'UNKNOWN', 'UPDATE', 'UPPER', 'USAGE', 'USER', 'USING', 'VALUE', 'VALUES',
        'VARCHAR', 'VARYING', 'VIEW', 'WHEN', 'WHENEVER', 'WHERE', 'WITH', 'WORK', 'WRITE', 'YEAR', 'ZONE',
    ];

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
            if (($at % 2 === 0) !== ($kind === 'identifier')) {
                return null;
            }
            if ($kind === 'identifier') {
                $parts[] = self::delimited($value);
            }
        }

        return implode('.', $parts);
    }

    /** `$text` as an `<identifier>`, if it is one alone: a regular one in upper case, or a delimited one's body. */
    public static function identifier(string $text): ?string
    {
        $tokens = self::tokens($text);

        return $tokens !== null && \count($tokens) === 1 && $tokens[0][0] === 'identifier' ? $tokens[0][1] : null;
    }

    /** The column `$heading` names: the `<identifier>` it is, if it is one, a key word too, in upper case; else as written. */
    public static function column(string $heading): string
    {
        $tokens = self::tokens($heading);
        if ($tokens !== null && \count($tokens) === 1 && $tokens[0][0] !== 'period') {
            return $tokens[0][1];
        }

        return $heading;
    }

    /**
     * The tokens of `$text`: each an identifier, a key word or a period, and its text; none if it holds anything else.
     *
     * @return list<array{string, string}>|null
     */
    private static function tokens(string $text): ?array
    {
        $tokens = [];
        $at = 0;
        while (preg_match(self::TOKEN, $text, $match, 0, $at) === 1) {
            $at += \strlen($match[0]);
            $token = self::token($match);
            if ($token === null) {
                return null;
            }
            $tokens[] = $token;
        }

        return trim(substr($text, $at)) === '' ? $tokens : null;
    }

    /**
     * The token `$match` matched; none if it is a regular identifier longer than the longest.
     *
     * @param array<int|string, string> $match
     *
     * @return array{string, string}|null
     */
    private static function token(array $match): ?array
    {
        if (($match[3] ?? '') === '.') {
            return ['period', '.'];
        }
        if (($match[2] ?? '') !== '') {
            return ['identifier', str_replace('""', '"', $match[2])];
        }
        // Upper case is Unicode's where the mbstring extension is loaded, else ASCII's.
        $word = \function_exists('mb_strtoupper') ? mb_strtoupper($match[1] ?? '') : strtoupper($match[1] ?? '');
        if (\in_array($word, self::KEY_WORDS, true)) {
            return ['keyword', $word];
        }
        $length = (int) preg_match_all('/[^_]/u', $word);

        return $length <= self::MAX_LENGTH ? ['identifier', $word] : null;
    }
}

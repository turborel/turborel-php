<?php

declare(strict_types=1);

namespace Turborel\Internal\Sql;

use Turborel\Internal\Codec\Cited;
use Turborel\Internal\Codec\Diagnostics;
use Turborel\Internal\Codec\Failure;
use Turborel\Internal\Codec\FailureState;
use Turborel\TurborelException;

/**
 * SQL text split into tokens, as turborel's `lexer.rs` splits it: the
 * separators between them dropped, spaces, newlines (LF or CR LF) and
 * comments, `--` to a newline or bracketed, nested; each token a key word, an
 * identifier, a number, a character string literal (its parts joined
 * across separators that hold a newline), a binary string literal, an
 * introducer, a large object length, or a delimiter. Text that is no
 * tokens is refused with SQLCODE -101, as turborel refuses it.
 *
 * @internal
 */
final class Lexer
{
    /** A key word. */
    public const string KEYWORD = 'keyword';

    /** An identifier: a regular one in upper case, or a delimited one's body. */
    public const string IDENTIFIER = 'identifier';

    /** An exact number's text. */
    public const string EXACT = 'exact';

    /** An approximate number's text. */
    public const string APPROXIMATE = 'approximate';

    /** A character string literal's value. */
    public const string STRING = 'string';

    /** A binary string literal's octets. */
    public const string BINARY = 'binary';

    /** An introducer's character set name. */
    public const string INTRODUCER = 'introducer';

    /** A large object length, digits and a multiplier. */
    public const string LOB_LENGTH = 'lob_length';

    /** A delimiter. */
    public const string DELIMITER = 'delimiter';

    /** The most characters of an identifier, as turborel's MAX_IDENTIFIER_LENGTH. */
    public const int MAX_IDENTIFIER_LENGTH = 128;

    /** The pseudo-columns of node and edge tables, each read as one identifier. */
    private const array PSEUDO_COLUMNS = ['$NODE_ID', '$EDGE_ID', '$FROM_ID', '$TO_ID'];

    /** The trigraphs, each the bracket it stands for. */
    private const array TRIGRAPHS = ['??(' => '[', '??)' => ']'];

    /** Every delimiter but a character string literal, longest first. */
    private const array DELIMITERS = ['<->', '<=>', '<#>', '<+>', '<~>', '<%>', '||', '<>', '>=', '<=', '{-', '-}', '->', '::', ',', '(', ')', '<', '>', '.', ':', '=',
        '*', '+', '-', '/', '?', ';', '[', ']', '|', '{', '}', '^', '$', '%', '&', '~', '!'];

    /** The reserved words, as turborel's lexer has them (KEY_WORDS). */
    public const array KEY_WORDS = [
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

    /** @var list<string> the text's characters */
    private array $c;

    /** The next character's place. */
    private int $at = 0;

    /** Whether MATCH was read, so `--` may be one of a graph pattern's relationships. */
    private bool $matched = false;

    /** The lexer of `$text`. */
    private function __construct(string $text)
    {
        $chars = preg_split('//u', $text, -1, \PREG_SPLIT_NO_EMPTY);
        $this->c = $chars === false ? str_split($text) : $chars;
    }

    /**
     * The tokens of `$text`, each its kind, its text or value, and where it began and ended, in characters.
     *
     * @return list<array{string, string, int, int}>
     *
     * @throws TurborelException SQLSTATE 2A000, SQLCODE -101, of text that is no tokens; -102 of an empty delimited identifier; -103 of an identifier too long
     */
    public static function lex(string $text): array
    {
        $lexer = new self($text);
        $tokens = [];
        while (($first = $lexer->peek(0)) !== null) {
            if ($lexer->separator()) {
                continue;
            }
            $start = $lexer->at;
            [$kind, $value] = $lexer->token($first);
            $lexer->followed($kind, $start);
            $lexer->matched = $lexer->matched || [$kind, $value] === [self::KEYWORD, 'MATCH'];
            $tokens[] = [$kind, $value, $start, $lexer->at];
        }

        return $tokens;
    }

    /** The character `$k` places on, if any. */
    private function peek(int $k): ?string
    {
        return $this->c[$this->at + $k] ?? null;
    }

    /** The length of the newline here, LF or CR LF; none if there is none. */
    private function newline(): ?int
    {
        return match (true) {
            $this->peek(0) === "\n" => 1,
            $this->peek(0) === "\r" && $this->peek(1) === "\n" => 2,
            default => null,
        };
    }

    /**
     * Skips one part of a separator, and says whether there was one.
     *
     * @throws TurborelException SQLCODE -101 of a comment that does not end
     */
    private function separator(): bool
    {
        if ($this->peek(0) === ' ') {
            ++$this->at;

            return true;
        }
        if (($n = $this->newline()) !== null) {
            $this->at += $n;

            return true;
        }
        if ($this->peek(0) !== '-' || $this->peek(1) !== '-' || $this->relationship()) {
            return $this->bracketed();
        }
        $start = $this->at;
        while ($this->peek(0) !== null && $this->newline() === null) {
            ++$this->at;
        }
        $n = $this->newline() ?? throw self::failure(-101, 'X3.135-1986 §5.3', 'a <comment> must end with a <newline>', $start);
        $this->at += $n;

        return true;
    }

    /** Whether the `--` next is one of a graph pattern's relationships after MATCH, not a comment. */
    private function relationship(): bool
    {
        $before = fn(int $k): ?string => $this->at - $k >= 0 ? ($this->c[$this->at - $k] ?? null) : null;
        $opened = $before(1) === ')' || ($before(1) === '<' && $before(2) === ')');
        $closed = $this->peek(2) === '(' || ($this->peek(2) === '>' && $this->peek(3) === '(');

        return $this->matched && $opened && $closed;
    }

    /**
     * Skips a bracketed comment, nested ones in it, if one is next, and says whether there was one.
     *
     * @throws TurborelException SQLCODE -101 if it does not end
     */
    private function bracketed(): bool
    {
        if ($this->peek(0) !== '/' || $this->peek(1) !== '*') {
            return false;
        }
        [$start, $depth] = [$this->at, 0];
        while (true) {
            $pair = [$this->peek(0), $this->peek(1)];
            if ($pair[0] === null) {
                throw self::failure(-101, 'ISO/IEC 9075-2:2023 §5.2', 'a <bracketed comment> must end with */', $start);
            }
            if ($pair !== ['/', '*'] && $pair !== ['*', '/']) {
                ++$this->at;

                continue;
            }
            $depth += $pair === ['/', '*'] ? 1 : -1;
            $this->at += 2;
            if ($depth <= 0) {
                return true;
            }
        }
    }

    /**
     * The token that begins with `$first`: its kind and text.
     *
     * @return array{string, string}
     *
     * @throws TurborelException SQLCODE -101 of a character that begins none, or a literal or identifier that does not end
     */
    private function token(string $first): array
    {
        $next = $this->peek(1);

        return match (true) {
            ($first === 'N' || $first === 'n') && $next === "'" => [self::STRING, $this->national()],
            ($first === 'X' || $first === 'x') && $next === "'" => [self::BINARY, $this->binary()],
            $first === '_' && $next !== null && preg_match('/^[A-Za-z]$/', $next) === 1 => [self::INTRODUCER, $this->introducer()],
            ($pseudo = $this->pseudo()) !== null => [self::IDENTIFIER, $pseudo],
            self::starts($first) => $this->regular(),
            ctype_digit($first) || ($first === '.' && $next !== null && ctype_digit($next)) => $this->number(),
            $first === "'" => [self::STRING, $this->quotedParts()],
            $first === '"' => [self::IDENTIFIER, $this->delimited()],
            $first === '!' && $next === '=' => throw self::failure(-101, 'X3.135-1986 §5.3 F', '"!=" is not a <token>; not equals is written "<>"', $this->at),
            default => [self::DELIMITER, $this->delimiter() ?? throw self::failure(-101, 'X3.135-1986 §5.3 F', 'character ' . self::debug($first, "'") . ' cannot start a <token>', $this->at)],
        };
    }

    /** Whether `$c` begins an identifier: a letter, as Unicode's Alphabetic property has it. */
    private static function starts(string $c): bool
    {
        return preg_match('/^\p{Alphabetic}$/u', $c) === 1;
    }

    /** Whether `$c` continues an identifier: a letter, an ASCII digit, or an underscore. */
    private static function continues(string $c): bool
    {
        return self::starts($c) || ctype_digit($c) || $c === '_';
    }

    /**
     * A national character string literal: N, then a character string literal's quoted parts.
     *
     * @throws TurborelException SQLCODE -101 if it does not end
     */
    private function national(): string
    {
        ++$this->at;

        return $this->quotedParts();
    }

    /**
     * A binary string literal's octets: X, then quoted parts of pairs of hexits and spaces.
     *
     * @throws TurborelException SQLCODE -101 of anything else, or an odd number of hexits
     */
    private function binary(): string
    {
        $start = $this->at;
        ++$this->at;
        $hexits = str_replace(' ', '', $this->quotedParts());
        if (preg_match('/^(?:[0-9A-Fa-f]{2})*$/', $hexits) !== 1) {
            throw self::failure(-101, 'ISO/IEC 9075-2:2023 §5.3', 'a <binary string literal> holds pairs of <hexit>s and spaces, and nothing else', $start);
        }

        return (string) hex2bin($hexits);
    }

    /** An introducer's character set name, upper case: `_`, then simple Latin letters, digits and underscores, a schema's before a period. */
    private function introducer(): string
    {
        ++$this->at;
        $start = $this->at;
        $part = static fn(?string $c): bool => $c !== null && preg_match('/^[A-Za-z0-9_]$/', $c) === 1;
        while ($part($this->peek(0))) {
            ++$this->at;
        }
        while ($this->peek(0) === '.' && $this->peek(1) !== null && preg_match('/^[A-Za-z]$/', $this->peek(1)) === 1) {
            ++$this->at;
            while ($part($this->peek(0))) {
                ++$this->at;
            }
        }

        return strtoupper($this->text($start));
    }

    /** A pseudo-column's name, `$` then its name in any case, upper case, if one is next. */
    private function pseudo(): ?string
    {
        if ($this->peek(0) !== '$') {
            return null;
        }
        $rest = '';
        for ($k = 1; ($c = $this->peek($k)) !== null && self::continues($c); $k++) {
            $rest .= $c;
        }
        $word = '$' . self::upper($rest);
        if (!\in_array($word, self::PSEUDO_COLUMNS, true)) {
            return null;
        }
        $this->at += \strlen($word);

        return $word;
    }

    /**
     * A regular identifier, upper case, or a key word.
     *
     * @return array{string, string}
     *
     * @throws TurborelException SQLCODE -103 if it is longer than the longest
     */
    private function regular(): array
    {
        $start = $this->at;
        ++$this->at;
        while (($c = $this->peek(0)) !== null && self::continues($c)) {
            ++$this->at;
        }
        $word = self::upper($this->text($start));
        if (\in_array($word, self::KEY_WORDS, true)) {
            return [self::KEYWORD, $word];
        }
        $this->length($start, \count(array_filter(\array_slice($this->c, $start, $this->at - $start), static fn(string $c): bool => $c !== '_')));

        return [self::IDENTIFIER, $word];
    }

    /**
     * A delimited identifier's body, a doubled quote one.
     *
     * @throws TurborelException SQLCODE -101 if it does not end or holds a newline; -102 if empty; -103 if too long
     */
    private function delimited(): string
    {
        [$start, $body, $parts] = [$this->at, '', 0];
        ++$this->at;
        while (true) {
            $c = $this->peek(0) ?? throw self::failure(-101, 'ISO/IEC 9075:1992 §5.2', 'unterminated <delimited identifier>', $start);
            if ($this->newline() !== null) {
                throw self::failure(-101, 'ISO/IEC 9075:1992 §5.2 SR3', 'a <newline> cannot appear inside a <delimited identifier>', $this->at);
            }
            ++$this->at;
            if ($c === '"') {
                if ($this->peek(0) !== '"') {
                    break;
                }
                ++$this->at;
            }
            $body .= $c;
            ++$parts;
        }
        if ($body === '') {
            throw self::failure(-102, 'ISO/IEC 9075:1992 §5.2', 'a <delimited identifier body> has at least one part', $start);
        }
        $this->length($start, $parts);

        return $body;
    }

    /**
     * That an identifier of `$length` characters, at `$start`, is at most the longest.
     *
     * @throws TurborelException SQLCODE -103 if it is longer
     */
    private function length(int $start, int $length): void
    {
        if ($length > self::MAX_IDENTIFIER_LENGTH) {
            throw self::failure(-103, 'ISO/IEC 9075:1992 §5.2 SR8, SR9', '<identifier> ' . $this->text($start) . ' is longer than ' . self::MAX_IDENTIFIER_LENGTH . ' characters', $start);
        }
    }

    /**
     * A number: an exact one, an approximate one, or a large object length.
     *
     * @return array{string, string}
     */
    private function number(): array
    {
        $start = $this->at;
        $this->digits();
        $m = $this->peek(0);
        if ($m !== null && str_contains('KMGTPkmgtp', $m) && !(($n = $this->peek(1)) !== null && preg_match('/^[\p{L}\p{N}_]$/u', $n) === 1) && ctype_digit($this->text($start))) {
            $digits = $this->text($start);
            ++$this->at;

            return [self::LOB_LENGTH, $digits . strtoupper($m)];
        }
        if ($this->peek(0) === '.') {
            ++$this->at;
            $this->digits();
        }
        if ($this->peek(0) === 'E' || $this->peek(0) === 'e') {
            $before = ($this->peek(1) === '+' || $this->peek(1) === '-') ? 2 : 1;
            if (($d = $this->peek($before)) !== null && ctype_digit($d)) {
                $this->at += $before;
                $this->digits();

                return [self::APPROXIMATE, str_replace('_', '', $this->text($start))];
            }
        }

        return [self::EXACT, str_replace('_', '', $this->text($start))];
    }

    /** Skips digits, an underscore taken only between two. */
    private function digits(): void
    {
        while (true) {
            $from = $this->at;
            while (($c = $this->peek(0)) !== null && ctype_digit($c)) {
                ++$this->at;
            }
            $next = $this->peek(1);
            if (!($this->at > $from && $this->peek(0) === '_' && $next !== null && ctype_digit($next))) {
                return;
            }
            ++$this->at;
        }
    }

    /**
     * The characters of a literal's quoted parts, one after another where each separator between them holds a newline.
     *
     * @throws TurborelException SQLCODE -101 of a part that does not end, or holds a newline
     */
    private function quotedParts(): string
    {
        $value = '';
        while (true) {
            $value .= $this->quotedPart();
            $after = $this->at;
            while ($this->separator()) {
                // Each part of the separator skipped.
            }
            $newline = \in_array("\n", \array_slice($this->c, $after, $this->at - $after), true);
            if (!($newline && $this->peek(0) === "'")) {
                $this->at = $after;

                return $value;
            }
        }
    }

    /**
     * One quoted part, its characters, a doubled quote one.
     *
     * @throws TurborelException SQLCODE -101 if it does not end, or holds a newline
     */
    private function quotedPart(): string
    {
        [$start, $value] = [$this->at, ''];
        ++$this->at;
        while (true) {
            $c = $this->peek(0) ?? throw self::failure(-101, 'X3.135-1986 §5.2 F', 'unterminated <character string literal>', $start);
            if ($this->newline() !== null) {
                throw self::failure(-101, 'X3.135-1986 §5.1 SR1', 'a <newline> cannot appear inside a <character string literal>', $this->at);
            }
            if ($c === "'") {
                if ($this->peek(1) !== "'") {
                    ++$this->at;

                    return $value;
                }
                [$value, $this->at] = [$value . "'", $this->at + 2];

                continue;
            }
            [$value, $this->at] = [$value . $c, $this->at + 1];
        }
    }

    /** The delimiter that begins here, taken; a trigraph the bracket it stands for. */
    private function delimiter(): ?string
    {
        foreach ([...array_keys(self::TRIGRAPHS), ...self::DELIMITERS] as $d) {
            if ($this->begins($d)) {
                $this->at += \strlen($d);

                return self::TRIGRAPHS[$d] ?? $d;
            }
        }

        return null;
    }

    /** Whether the text here begins with `$d`. */
    private function begins(string $d): bool
    {
        $chars = preg_split('//u', $d, -1, \PREG_SPLIT_NO_EMPTY);
        foreach ($chars === false ? [] : $chars as $k => $c) {
            if ($this->peek($k) !== $c) {
                return false;
            }
        }

        return true;
    }

    /**
     * That a token that is no delimiter is followed by a delimiter, a separator, or the end.
     *
     * @throws TurborelException SQLCODE -101 if it is not
     */
    private function followed(string $kind, int $start): void
    {
        $nondelimiter = ($this->c[$start] ?? '') !== '"' && \in_array($kind, [self::KEYWORD, self::IDENTIFIER, self::EXACT, self::APPROXIMATE, self::LOB_LENGTH], true);
        $next = $this->peek(0);
        if (!$nondelimiter || $next === null || $next === ' ' || $next === "'" || $next === '"' || $this->newline() !== null || $this->boundary()) {
            return;
        }
        $shown = self::debug($this->text($start), '"') . ' must be followed by a <delimiter token> or a <separator>, not ' . self::debug($next, "'");

        throw self::failure(-101, 'X3.135-1986 §5.3 SR2', $shown, $this->at);
    }

    /** Whether a delimiter begins here. */
    private function boundary(): bool
    {
        foreach ([...array_keys(self::TRIGRAPHS), ...self::DELIMITERS] as $d) {
            if ($this->begins($d)) {
                return true;
            }
        }

        return false;
    }

    /** The characters from `$start` to the next one. */
    private function text(int $start): string
    {
        return implode('', \array_slice($this->c, $start, $this->at - $start));
    }

    /** `$text` in upper case: Unicode's where the mbstring extension is loaded, else ASCII's. */
    public static function upper(string $text): string
    {
        return \function_exists('mb_strtoupper') ? mb_strtoupper($text) : strtoupper($text);
    }

    /** `$text` as Rust's Debug writes a string, quoted by `$quote`, or a character, by a single quote: its escapes written out. */
    private static function debug(string $text, string $quote): string
    {
        $escaped = strtr($text, ['\\' => '\\\\', "\t" => '\\t', "\n" => '\\n', "\r" => '\\r', "\0" => '\\0', $quote => '\\' . $quote]);

        return $quote . $escaped . $quote;
    }

    /** The failure of SQLCODE `$code`, citing `$rule`, `$message`, at character `$at`: SQLSTATE 2A000 of SQL text. */
    private static function failure(int $code, string $rule, string $message, int $at): TurborelException
    {
        return TurborelException::fromServer(new Failure($code, '2A000', $message, new Cited($rule, $at), new FailureState(false, new Diagnostics(['severity' => Diagnostics::ERROR]))));
    }
}

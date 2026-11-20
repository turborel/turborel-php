<?php

declare(strict_types=1);

namespace Turborel\Internal\Settings;

use Turborel\Internal\Quietly;
use Turborel\Settings\Options;
use Turborel\TurborelException;

/**
 * The files connection settings come from, as turborel-client's
 * `files.rs` reads them: a service file of named sections of settings
 * [CLIENT The service file], and a password file of passwords by server
 * and user [CLIENT The password file]. A password file is read only if it
 * is a regular file, or a link to one: its owner answers for keeping it
 * private.
 *
 * @internal
 */
final class Files
{
    /** What begins a comment line, in either file. */
    private const string COMMENT = '#';

    /** What matches any value in a password file. */
    private const string ANY = '*';

    /** What escapes the character after it in a password file. */
    private const string ESCAPE = '\\';

    /** What separates a password file's fields. */
    private const string SEPARATOR = ':';

    /** The fields of a password file's line. */
    private const int FIELDS = 4;

    /**
     * The settings of the section `$name` of the service file `$file`.
     *
     * @throws TurborelException SQLSTATE 08001 if it cannot be read, a line is malformed, or it has no section `$name`
     */
    public static function service(string $file, string $name): Options
    {
        [$text, $warning] = Quietly::call(static fn(): string|false => is_dir($file) ? false : file_get_contents($file));
        if (!\is_string($text)) {
            throw Invalid::because("cannot read {$file}: " . ($warning ?? 'it is no file'));
        }

        return self::section($text, $name, $file);
    }

    /**
     * The settings of the section `$name` of the service file text `$text`, read from `$file`.
     *
     * @throws TurborelException SQLSTATE 08001 if a line is malformed or sets no setting, or there is no section `$name`
     */
    public static function section(string $text, string $name, string $file): Options
    {
        [$found, $current] = [null, null];
        foreach (self::lines($text) as $at => $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, self::COMMENT)) {
                continue;
            }
            $n = $at + 1;
            if (str_starts_with($line, '[')) {
                $current = str_ends_with($line, ']') ? trim(substr($line, 1, -1)) : throw self::at($file, $n, 'a section\'s name ends with ]');
                continue;
            }
            $section = $current ?? throw self::at($file, $n, 'a setting comes before any section');
            $options = self::setting($line, $file, $n);
            if ($section === $name) {
                $found = $options->over($found ?? new Options());
            }
        }

        return $found ?? throw Invalid::because("{$file} has no service {$name}");
    }

    /**
     * The password the password file text `$text` gives `$user` at `$host` and `$port`: its first line matching all three.
     */
    public static function password(string $text, string $host, string $port, string $user): ?string
    {
        foreach (self::lines($text) as $line) {
            if (str_starts_with(ltrim($line), self::COMMENT)) {
                continue;
            }
            $fields = self::fields($line);
            if (\count($fields) === self::FIELDS && self::matches($fields[0], $host) && self::matches($fields[1], $port) && self::matches($fields[2], $user)) {
                return $fields[3][0];
            }
        }

        return null;
    }

    /** The text of the file at `$path`, if it can be read and is a regular file, a link followed to one; none otherwise. */
    public static function readRegular(string $path): ?string
    {
        [$text] = Quietly::call(static fn(): string|false => is_file($path) ? file_get_contents($path) : false);

        return \is_string($text) ? $text : null;
    }

    /**
     * The setting of the line `$line`, `<key> = <value>`, line `$n` of `$file`.
     *
     * @throws TurborelException SQLSTATE 08001 if it is malformed, names no setting, or sets the service or its file
     */
    private static function setting(string $line, string $file, int $n): Options
    {
        if (!str_contains($line, '=')) {
            throw self::at($file, $n, 'a setting is <key> = <value>');
        }
        [$key, $value] = explode('=', $line, 2) + ['', ''];
        $key = trim($key);
        if ($key === Options::SERVICE || $key === Options::SERVICE_FILE) {
            throw self::at($file, $n, "a service cannot set {$key}");
        }
        try {
            return (new Options())->set($key, trim($value));
        } catch (TurborelException $wrong) {
            throw self::at($file, $n, $wrong->getMessage());
        }
    }

    /**
     * The fields of the password file's line `$line`, unescaped, each with whether it was written with no escape: the first
     * three split at each colon not escaped, the fourth the rest.
     *
     * @return list<array{string, bool}>
     */
    private static function fields(string $line): array
    {
        [$fields, $field, $escaped, $plain] = [[], '', false, true];
        foreach (self::characters($line) as $c) {
            if ($escaped) {
                [$field, $escaped, $plain] = [$field . $c, false, false];
            } elseif ($c === self::ESCAPE) {
                $escaped = true;
            } elseif ($c === self::SEPARATOR && \count($fields) < self::FIELDS - 1) {
                [$fields[], $field, $plain] = [[$field, $plain], '', true];
            } else {
                $field .= $c;
            }
        }
        $fields[] = [$field, $plain];

        return $fields;
    }

    /**
     * Whether `$field` matches `$wanted`: a `*` written plain matches anything.
     *
     * @param array{string, bool} $field
     */
    private static function matches(array $field, string $wanted): bool
    {
        return ($field[1] && $field[0] === self::ANY) || $field[0] === $wanted;
    }

    /**
     * The lines of `$text`.
     *
     * @return list<string>
     */
    private static function lines(string $text): array
    {
        $lines = preg_split('/\r?\n/', $text);

        return $lines === false ? [] : $lines;
    }

    /**
     * The characters of `$line`, UTF-8.
     *
     * @return list<string>
     */
    private static function characters(string $line): array
    {
        $characters = preg_split('//u', $line, -1, \PREG_SPLIT_NO_EMPTY);

        return $characters === false ? str_split($line) : $characters;
    }

    /** The failure of line `$n` of `$file`, `$why`. */
    private static function at(string $file, int $n, string $why): TurborelException
    {
        return Invalid::because("{$file} line {$n}: {$why}");
    }
}

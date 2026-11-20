<?php

declare(strict_types=1);

namespace Turborel\Tools;

/**
 * Short functions and files (RULES.md R-SIZE): a file of at most 600
 * lines, a function of at most 50 lines of code and 5 parameters.
 */
final class SizeCheck
{
    /** The most lines a file has (R-SIZE-2). */
    public const int MAX_FILE_LINES = 600;

    /** The most lines of code a function's body has (R-SIZE-1). */
    public const int MAX_FUNCTION_LINES = 50;

    /** The most parameters a function takes (R-SIZE-3). */
    public const int MAX_PARAMETERS = 5;

    /**
     * What breaks R-SIZE in `$files`.
     *
     * @param list<string> $files
     *
     * @return list<string>
     */
    public static function check(array $files): array
    {
        $findings = [];
        foreach ($files as $file) {
            $text = Sources::read($file);
            $lines = substr_count($text, "\n");
            if ($lines > self::MAX_FILE_LINES) {
                $findings[] = "{$file}: {$lines} lines, more than " . self::MAX_FILE_LINES . ' (R-SIZE-2)';
            }
            foreach (Functions::in(array_values(\PhpToken::tokenize($text))) as $function) {
                $findings = [...$findings, ...self::function($file, $function)];
            }
        }

        return $findings;
    }

    /**
     * What breaks R-SIZE in one function of `$file`.
     *
     * @return list<string>
     */
    private static function function(string $file, Functions $function): array
    {
        $findings = [];
        if ($function->codeLines > self::MAX_FUNCTION_LINES) {
            $findings[] = "{$file}:{$function->line}: {$function->codeLines} lines of code, more than "
                . self::MAX_FUNCTION_LINES . ' (R-SIZE-1)';
        }
        if ($function->parameters > self::MAX_PARAMETERS) {
            $findings[] = "{$file}:{$function->line}: {$function->parameters} parameters, more than "
                . self::MAX_PARAMETERS . ' (R-SIZE-3)';
        }

        return $findings;
    }
}

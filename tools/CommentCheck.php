<?php

declare(strict_types=1);

namespace Turborel\Tools;

/**
 * Comments that say why, not what is left to do (RULES.md R-DOC-5): no
 * comment marks work left undone, and none cites the open-work file.
 */
final class CommentCheck
{
    /** A comment marking work left undone. */
    private const string LEFT_UNDONE = '/\b(TODO|FIXME|XXX)\b/';

    /** The open-work file, which code never cites. */
    private const string OPEN_WORK = 'TODO.md';

    /** The tokens that are comments. */
    private const array COMMENTS = [\T_COMMENT, \T_DOC_COMMENT];

    /**
     * What breaks R-DOC-5 in `$files`.
     *
     * @param list<string> $files
     *
     * @return list<string>
     */
    public static function check(array $files): array
    {
        $findings = [];
        foreach ($files as $file) {
            foreach (\PhpToken::tokenize(Sources::read($file)) as $token) {
                if (!$token->is(self::COMMENTS)) {
                    continue;
                }
                if (str_contains($token->text, self::OPEN_WORK)) {
                    $findings[] = "{$file}:{$token->line}: code never cites " . self::OPEN_WORK . ' (R-DOC-5)';
                } elseif (preg_match(self::LEFT_UNDONE, $token->text) === 1) {
                    $findings[] = "{$file}:{$token->line}: open work goes in " . self::OPEN_WORK . ', not a comment (R-DOC-5)';
                }
            }
        }

        return $findings;
    }
}

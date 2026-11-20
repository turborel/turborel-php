<?php

declare(strict_types=1);

namespace Turborel\Tools;

/**
 * The failures of a JUnit report, as GitHub's workflow commands that
 * annotate a run, so a failure on a runner whose log needs a token to
 * read is told on the run's page (RULES.md D4).
 */
final class Annotations
{
    /** The workflow command of an error, its title and its message. */
    private const string ERROR = '::error title=%s::%s';

    /**
     * Each failure and error of the JUnit report at `$report`, as a workflow command.
     *
     * @return list<string>
     */
    public static function of(string $report): array
    {
        $xml = is_file($report) ? simplexml_load_file($report) : false;
        if ($xml === false) {
            return [];
        }
        $lines = [];
        $cases = $xml->xpath('//testcase[failure or error]');
        foreach ($cases ?? [] as $case) {
            $problem = $case->failure ?? $case->error;
            $message = str_replace(["%", "\r", "\n"], ['%25', '%0D', '%0A'], (string) $problem);
            $lines[] = \sprintf(self::ERROR, (string) $case['name'], $message);
        }

        return $lines;
    }
}

<?php

declare(strict_types=1);

namespace Turborel\Tools;

/**
 * The coverage floor (RULES.md R-COV): the share of the library's lines
 * the tests run, from PHPUnit's Clover report, is at least the floor.
 */
final class CoverageFloor
{
    /** The least share of lines covered, in percent; it only rises (R-COV-2). */
    public const float FLOOR = 98.0;

    /** A whole, in percent. */
    private const float WHOLE = 100.0;

    /**
     * What breaks R-COV in the Clover report at `$report`.
     *
     * @return list<string>
     */
    public static function check(string $report): array
    {
        $xml = is_file($report) ? simplexml_load_file($report) : false;
        $metrics = $xml === false ? null : ($xml->project->metrics ?? null);
        if ($metrics === null) {
            return ["{$report}: no Clover coverage report; run `composer coverage` (R-COV-1)"];
        }
        $lines = (int) $metrics['statements'];
        $covered = (int) $metrics['coveredstatements'];
        $share = $lines === 0 ? 0.0 : self::WHOLE * $covered / $lines;
        fwrite(STDOUT, \sprintf('coverage: %d of %d lines, %.2f%%, floor %.2f%%', $covered, $lines, $share, self::FLOOR) . PHP_EOL);
        if ($share < self::FLOOR) {
            return [\sprintf('coverage: %.2f%% of lines, below the floor of %.2f%% (R-COV-2)', $share, self::FLOOR)];
        }

        return [];
    }
}

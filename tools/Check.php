<?php

declare(strict_types=1);

namespace Turborel\Tools;

/**
 * The project's own checks, run by `composer check` and `composer coverage`
 * (RULES.md D4): sizes, comments, documentation, test citations, the
 * documents' links, the manual's layout and the coverage floor.
 */
final class Check
{
    /** The command that tells a JUnit report's failures as GitHub's workflow commands. */
    private const string ANNOTATE = 'annotate';

    /** The command that checks the coverage report against the floor. */
    private const string COVERAGE = 'coverage';

    /** The directories of the library, its tests and its tools, from the root. */
    private const array SOURCES = ['src', 'tests', 'tools'];

    /** The library's directory, from the root. */
    private const string LIBRARY = 'src';

    /** The tests' directory, from the root. */
    private const string TESTS = 'tests';

    /** The exit status of checks passed. */
    private const int PASSED = 0;

    /** The exit status of a check failed. */
    private const int FAILED = 1;

    /**
     * Runs the checks `$arguments` name over the project at `$root`,
     * prints what each finds, and gives the exit status.
     *
     * @param list<string> $arguments none for the source checks, `coverage` and a Clover report's path, or `annotate` and a JUnit report's
     */
    public static function main(string $root, array $arguments): int
    {
        if (($arguments[0] ?? null) === self::ANNOTATE) {
            foreach (Annotations::of($arguments[1] ?? '') as $line) {
                fwrite(STDOUT, $line . PHP_EOL);
            }

            return self::PASSED;
        }
        $findings = ($arguments[0] ?? null) === self::COVERAGE
            ? CoverageFloor::check($arguments[1] ?? '')
            : self::sources($root);
        foreach ($findings as $finding) {
            fwrite(STDERR, $finding . PHP_EOL);
        }
        if ($findings !== []) {
            return self::FAILED;
        }
        fwrite(STDOUT, 'check: passed' . PHP_EOL);

        return self::PASSED;
    }

    /**
     * What the source checks find in the project at `$root`.
     *
     * @return list<string>
     */
    private static function sources(string $root): array
    {
        $all = Sources::under($root, self::SOURCES);
        $rules = Rules::read($root);

        return [
            ...SizeCheck::check($all),
            ...CommentCheck::check($all),
            ...DocCheck::check(Sources::under($root, [self::LIBRARY])),
            ...CitationCheck::check(Sources::under($root, [self::TESTS]), $rules),
            ...LinkCheck::check($root),
            ...ManualCheck::check($root),
        ];
    }
}

<?php

declare(strict_types=1);

namespace Turborel\Tools;

/**
 * Every test cites what it verifies (RULES.md R-TRACE-1): its doc comment
 * has a paragraph `Verifies:` that begins with a citation, and every
 * citation in it names a heading of the manual or a rule that exists.
 */
final class CitationCheck
{
    /** The tests' directory in a path. */
    private const string TESTS = '/tests/';

    /** The tests' namespace, which `tests/` holds by PSR-4. */
    private const string NAMESPACE = 'Turborel\\Tests\\';

    /** A test class's file. */
    private const string TEST_FILE = 'Test.php';

    /** A test method's name, without PHPUnit's attribute. */
    private const string TEST_PREFIX = 'test';

    /** PHPUnit's attribute of a test method. */
    private const string TEST_ATTRIBUTE = 'PHPUnit\\Framework\\Attributes\\Test';

    /** The paragraph of a doc comment that cites, to a blank line or a tag. */
    private const string VERIFIES = '/Verifies:\s*(.*?)(?:\n\s*\n|\n\s*@|$)/s';

    /** A citation: a heading of the protocol or the client, a decision, or a tool's rule. */
    private const string CITATION = '/\[(PROTOCOL|CLIENT) ([^\]]+)\]|\[DESIGN\] (D\d+)|\[TOOL\] (R-[A-Z]+-\d+)/';

    /** A paragraph's first citation, which it must begin with. */
    private const string LEADS = '/^\[(PROTOCOL |CLIENT |DESIGN\] D\d|TOOL\] R-)/';

    /**
     * What breaks R-TRACE-1 in the tests' `$files`, given the IDs `$rules` defines.
     *
     * @param list<string> $files
     *
     * @return list<string>
     */
    public static function check(array $files, Rules $rules): array
    {
        $findings = [];
        foreach ($files as $file) {
            if (!str_ends_with($file, self::TEST_FILE)) {
                continue;
            }
            $name = ClassFile::name($file, self::TESTS, self::NAMESPACE);
            if ($name === null) {
                $findings[] = "{$file}: declares no test class of its path (R-TRACE-1)";

                continue;
            }
            foreach (self::tests(new \ReflectionClass($name)) as $test) {
                $findings = [...$findings, ...self::cited($file, $test, $rules)];
            }
        }

        return $findings;
    }

    /**
     * The test methods of `$class`.
     *
     * @param \ReflectionClass<object> $class
     *
     * @return list<\ReflectionMethod>
     */
    private static function tests(\ReflectionClass $class): array
    {
        $tests = [];
        foreach ($class->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            $named = str_starts_with($method->getName(), self::TEST_PREFIX);
            if ($named || $method->getAttributes(self::TEST_ATTRIBUTE) !== []) {
                $tests[] = $method;
            }
        }

        return $tests;
    }

    /**
     * What is wrong with the citations of `$test`, in `$file`.
     *
     * @return list<string>
     */
    private static function cited(string $file, \ReflectionMethod $test, Rules $rules): array
    {
        $where = "{$file}: {$test->getName()}";
        $doc = self::text((string) $test->getDocComment());
        if (preg_match(self::VERIFIES, $doc, $paragraph) !== 1 || preg_match(self::LEADS, $paragraph[1]) !== 1) {
            return ["{$where} has no paragraph `Verifies:` beginning with a citation (R-TRACE-1)"];
        }
        // A citation may wrap across the comment's lines.
        $flat = (string) preg_replace('/\s+/', ' ', $paragraph[1]);
        preg_match_all(self::CITATION, $flat, $citations, \PREG_SET_ORDER | \PREG_UNMATCHED_AS_NULL);
        $findings = [];
        foreach ($citations as [$whole, $kind, $heading, $decision, $requirement]) {
            $headings = match ($kind) {
                'PROTOCOL' => Headings::PROTOCOL,
                'CLIENT' => Headings::CLIENT,
                default => null,
            };
            $exists = $headings === null
                ? $rules->defines($decision ?? $requirement ?? '')
                : \in_array($heading, $headings, true);
            if (!$exists) {
                $findings[] = "{$where} cites {$whole}, which does not exist (R-TRACE-2)";
            }
        }

        return $findings;
    }

    /** A doc comment's text, its delimiters and leading stars gone and each line's spaces made one. */
    private static function text(string $doc): string
    {
        $lines = [];
        foreach (explode("\n", $doc) as $line) {
            $lines[] = trim(trim($line), '/*');
        }

        return implode("\n", array_map(static fn(string $line): string => trim($line), $lines));
    }
}

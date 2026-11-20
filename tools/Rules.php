<?php

declare(strict_types=1);

namespace Turborel\Tools;

/**
 * The decisions and requirements RULES.md defines, so a test may cite only
 * one that exists (RULES.md R-TRACE-1).
 */
final readonly class Rules
{
    /** A decision's heading in RULES.md, as `#### D3. ...`. */
    private const string DECISION = '/^#### (D\d+)\./m';

    /** A requirement's row in RULES.md, as `| R-ERR-1 | ...`. */
    private const string REQUIREMENT = '/^\| (R-[A-Z]+-\d+) \|/m';

    /**
     * The IDs RULES.md defines.
     *
     * @param list<string> $ids
     */
    private function __construct(private array $ids) {}

    /** The decisions and requirements of the RULES.md at `$root`. */
    public static function read(string $root): self
    {
        $text = Sources::read($root . '/RULES.md');
        preg_match_all(self::DECISION, $text, $decisions);
        preg_match_all(self::REQUIREMENT, $text, $requirements);

        return new self([...$decisions[1], ...$requirements[1]]);
    }

    /** Whether RULES.md defines `$id`, a decision `D3` or a requirement `R-ERR-1`. */
    public function defines(string $id): bool
    {
        return \in_array($id, $this->ids, true);
    }
}

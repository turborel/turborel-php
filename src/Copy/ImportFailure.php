<?php

declare(strict_types=1);

namespace Turborel\Copy;

use Turborel\TurborelException;

/**
 * Why an import stopped, and what of it was kept, as turborel-client's
 * `ImportError` [CLIENT Importing]: where the record that stopped it began,
 * if one did; why, the input's or SQL's; how many rows were committed
 * before, and how many of the batch under way were rolled back. Its
 * SQLSTATE and SQLCODE are its SQL cause's, or 22000 and -301 of the
 * input's (RULES.md D8).
 */
final class ImportFailure extends TurborelException
{
    /** The failure of an import stopped at `$position`, for `$cause`, after `$committed` rows, `$rolledBack` of its batch undone. */
    public function __construct(
        /** Where the record that stopped it began, if one did. */
        public readonly ?Position $position,
        /** Why it stopped: SQL's failure, or the input's, of SQLSTATE 22000. */
        public readonly TurborelException $cause,
        /** Whether the cause is the input's: the source's, or a record that does not fit the columns. */
        public readonly bool $input,
        /** How many rows were committed before. */
        public readonly int|string $committed,
        /** How many of the batch under way were rolled back. */
        public readonly int $rolledBack,
    ) {
        $rows = $committed . ' row' . ((string) $committed === '1' ? '' : 's');
        $where = $position === null ? '' : "{$position}: ";
        parent::__construct("{$where}" . self::cause($cause, $input) . "; {$rows} committed, {$rolledBack} rolled back", $cause->getSqlState(), $cause->getSqlCode(), $cause);
    }

    /** `$cause` in words: the input's message, or SQL's as turborel-client writes a failure, its rule after it. */
    public static function cause(TurborelException $cause, bool $input): string
    {
        if ($input) {
            return $cause->getMessage();
        }
        $rule = $cause->getRule() === null ? '' : " [{$cause->getRule()}]";

        return "SQLCODE {$cause->getSqlCode()} (SQLSTATE {$cause->getSqlState()}): {$cause->getMessage()}{$rule}";
    }

    /** The input's failure `$message`, of SQLSTATE 22000 and SQLCODE -301. */
    public static function inputCause(string $message): TurborelException
    {
        return new TurborelException($message, SourceFailure::SQLSTATE, SourceFailure::SQLCODE);
    }
}

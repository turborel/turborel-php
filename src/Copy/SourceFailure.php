<?php

declare(strict_types=1);

namespace Turborel\Copy;

use Turborel\TurborelException;

/**
 * Why a source cannot give its next record: what is wrong, and where
 * [CLIENT Importing]. turborel-client's SourceError has no SQLSTATE; the
 * driver's one error type gives it SQLSTATE 22000 and SQLCODE -301, a data
 * exception, as an import's input failure (RULES.md D8).
 */
final class SourceFailure extends TurborelException
{
    /** SQLSTATE 22000, data exception, of what a source or an import's input holds wrong. */
    public const string SQLSTATE = '22000';

    /** SQLCODE -301, a data exception, as turborel's Code::Data. */
    public const int SQLCODE = -301;

    /** The failure of the record begun at `$position`, `$reason` saying what is wrong. */
    public function __construct(
        /** Where the record began. */
        public readonly Position $position,
        /** What is wrong, in plain words. */
        public readonly string $reason,
    ) {
        parent::__construct("{$position}: {$reason}", self::SQLSTATE, self::SQLCODE);
    }
}

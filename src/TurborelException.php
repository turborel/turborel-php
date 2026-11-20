<?php

declare(strict_types=1);

namespace Turborel;

/**
 * The driver's one error type: every failure it raises is one of these,
 * whether the server reported it or the driver found it (RULES.md R-ERR-1).
 *
 * Each carries the SQLSTATE and the SQLCODE of the failure, as the server
 * gives them, so a program tells failures apart by their codes rather than
 * by their messages, which are for people.
 */
class TurborelException extends \RuntimeException
{
    /**
     * Makes the failure of `$message`, SQLSTATE `$sqlState` and SQLCODE
     * `$sqlCode`, caused by `$previous` if anything was.
     */
    public function __construct(
        string $message,
        private readonly string $sqlState,
        private readonly int $sqlCode,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    /** The failure's SQLSTATE: five characters, its class the first two. */
    public function getSqlState(): string
    {
        return $this->sqlState;
    }

    /** The failure's SQLCODE: negative for an error. */
    public function getSqlCode(): int
    {
        return $this->sqlCode;
    }
}

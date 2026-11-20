<?php

declare(strict_types=1);

namespace Turborel;

use Turborel\Internal\Codec\Cited;
use Turborel\Internal\Codec\Failure;

/**
 * The driver's one error type: every failure it raises is one of these,
 * whether the server reported it or the driver found it (RULES.md R-ERR-1).
 *
 * Each carries the SQLSTATE and the SQLCODE of the failure, as the server
 * gives them, so a program tells failures apart by their codes rather than
 * by their messages, which are for people. A failure the server reported
 * carries the rest of what it said too: the rule it cites, its position in
 * the statement, whether a transaction is active after it, and its
 * diagnostics, where `diagnostics` was granted (R-ERR-2).
 */
class TurborelException extends \RuntimeException
{
    /** SQLSTATE 08003, connection does not exist: of another connection, which leaves this one. */
    private const string NO_CONNECTION = '08003';

    /** The class of a connection exception. */
    private const string CONNECTION_CLASS = '08';

    /** The class of credentials refused. */
    private const string CREDENTIALS_CLASS = '28';

    /** SQLCODE -919, a connection's failure: of credentials refused, as the connection is made. */
    private const int CONNECTING = -919;

    /** What the server said of the failure beyond its codes and message, if the server reported it. */
    private ?Failure $failure = null;

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

    /**
     * The failure a server reported, `$failure`, as the driver's error.
     *
     * @internal
     */
    public static function fromServer(Failure $failure): self
    {
        $exception = new self($failure->message, $failure->sqlstate, $failure->sqlcode);
        $exception->failure = $failure;

        return $exception;
    }

    /**
     * The same failure, its position in its statement left out: of a statement the driver made, not the program.
     *
     * @internal
     */
    public function withoutPosition(): self
    {
        if ($this->failure === null) {
            return $this;
        }
        $f = $this->failure;

        return self::fromServer(new Failure($f->sqlcode, $f->sqlstate, $f->message, new Cited($f->cited->rule), $f->state));
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

    /** The rule of a standard the failure cites, as `X3.135-1986 §5.7 SR3b`, if it cites one. */
    public function getRule(): ?string
    {
        return $this->failure?->cited->rule;
    }

    /** Where in its statement the failure is, in characters, if the server placed it: an int, or a u64's digits. */
    public function getPosition(): int|string|null
    {
        return $this->failure?->cited->position;
    }

    /** Whether the session's transaction is active after the failure, if the server reported it; none if the driver found it. */
    public function isTransactionActive(): ?bool
    {
        return $this->failure?->state->active;
    }

    /**
     * What the failure says beyond its codes and message, with `diagnostics`
     * granted: severity, detail, hint, context, schema, table, column,
     * dataType and constraint, each that is known.
     *
     * @return array<string, string>
     */
    public function getDiagnostics(): array
    {
        return $this->failure?->state->diagnostics->fields ?? [];
    }

    /**
     * Whether the connection it came on is lost: a connection exception,
     * SQLSTATE class 08 but 08003, which names another connection, or
     * credentials refused as the connection is made, class 28 of SQLCODE
     * -919 [PROTOCOL Error 'E'].
     */
    public function endsConnection(): bool
    {
        $class = substr($this->sqlState, 0, 2);
        $connection = $class === self::CONNECTION_CLASS && $this->sqlState !== self::NO_CONNECTION;

        return $connection || ($class === self::CREDENTIALS_CLASS && $this->sqlCode === self::CONNECTING);
    }
}

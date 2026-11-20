<?php

declare(strict_types=1);

namespace Turborel\Session;

/**
 * A condition a statement raised, or a notice of the server's own, as
 * the server sent it [CLIENT What the server tells]: its SQLSTATE, its
 * message, and, granted `diagnostics`, its severity and what it is about.
 */
final readonly class Notice
{
    /**
     * The notice of its parts.
     *
     * @param array<string, string> $diagnostics
     */
    public function __construct(
        /** Its SQLSTATE, as 01000 of a warning. */
        public string $sqlState,
        /** Its message. */
        public string $message,
        /** @var array<string, string> $diagnostics severity, detail, hint, context, schema, table, column, dataType and constraint, each that is known */
        public array $diagnostics = [],
    ) {}

    /** Its severity, WARNING or NOTICE, where told. */
    public function severity(): ?string
    {
        return $this->diagnostics['severity'] ?? null;
    }
}
